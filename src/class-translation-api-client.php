<?php
/**
 * Translation API Client class
 *
 * Handles communication with the Gratis AI Translation API server.
 *
 * @package GratisAIPluginTranslations
 */

declare(strict_types=1);

namespace GratisAIPluginTranslations;

/**
 * Translation API Client class.
 *
 * @since 1.0.0
 */
class Translation_API_Client {

    /**
     * Site option that tracks dynamic cache keys created by this client.
     *
     * @since 1.0.0
     * @var string
     */
    private const CACHE_KEYS_OPTION = 'gratis_ai_pt_cache_keys';

    /**
     * API base URL.
     *
     * @since 1.0.0
     * @var string
     */
    private string $api_base;

    /**
     * Request timeout in seconds.
     *
     * @since 1.0.0
     * @var int
     */
    private int $timeout;

    /**
     * Cache duration in seconds.
     *
     * @since 1.0.0
     * @var int
     */
    private int $cache_duration;

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        /**
         * Filter the translation API base URL.
         *
         * @since 1.0.0
         * @param string $api_base Default from GRATIS_AI_PT_API_BASE constant.
         */
        $this->api_base = (string) apply_filters('gratis_ai_pt_api_base', GRATIS_AI_PT_API_BASE);
        $this->timeout = 30;
        /**
         * Filter the cache duration (seconds) for API responses.
         *
         * @since 1.0.0
         * @param int $seconds Default 1 hour.
         */
        $this->cache_duration = (int) apply_filters('gratis_ai_pt_cache_duration', HOUR_IN_SECONDS);
    }

    /**
     * Batch check + auto-queue translations for many plugins in one call.
     *
     * Note: auto_approve defaults to false - all requests require server-side
     * approval before processing begins. Set auto_approve: true to bypass.
     *
     * @since 1.2.0
     * @param array $plugins Array of ['textdomain' => string, 'version' => string].
     * @param array $locales Locales to query for every plugin.
     * @return array|\WP_Error {
     *     'results' => textdomain-keyed map of locale-keyed translation entries,
     *     'requested'  => list of [textdomain, locale] pairs waiting for approval,
     *     'queue_length' => server pending queue length,
     * }
     */
    public function batch_check_translations(array $plugins, array $locales) {
        if (empty($plugins) || empty($locales)) {
            return ['results' => [], 'requested' => [], 'queue_length' => 0];
        }

        $endpoint = $this->api_base . '/batch-check-translations';

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout'   => $this->timeout,
                'headers'   => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'      => wp_json_encode([
                    'plugins'    => array_values($plugins),
                    'locales'    => array_values($locales),
                    'auto_approve' => false,  // Default: require approval
                    'site_url'   => get_site_url(),
                    'wp_version' => get_bloginfo('version'),
                ]),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new \WP_Error(
                'api_error',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __('Batch translation API returned error code: %d', 'superdav-ai-language-packs'),
                    $status_code
                )
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return new \WP_Error('invalid_response', __('Invalid response from batch endpoint', 'superdav-ai-language-packs'));
        }

        return [
            'results'      => $body['results'] ?? [],
            'requested'    => $body['requested'] ?? [],
            'queue_length' => (int) ($body['queue_length'] ?? 0),
        ];
    }

    /**
     * Get translation status for a plugin.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @param string $locale     Locale code.
     * @return array|WP_Error    Translation status or WP_Error.
     */
    public function get_translation_status(string $textdomain, string $version, string $locale) {
        $cache_key = 'gratis_ai_pt_status_' . md5($textdomain . $version . $locale);
        $cached = get_site_transient($cache_key);

        if (false !== $cached) {
            return $cached;
        }

        $endpoint = $this->api_base . '/translation-status';

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout'   => $this->timeout,
                'headers'   => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'      => wp_json_encode([
                    'textdomain' => $textdomain,
                    'version'    => $version,
                    'locale'     => $locale,
                ]),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return new \WP_Error(
                'api_error',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __('Translation API returned error code: %d', 'superdav-ai-language-packs'),
                    $status_code
                )
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error(
                'json_error',
                __('Failed to parse API response', 'superdav-ai-language-packs')
            );
        }

        if (!$this->is_associative_response($data)) {
            return new \WP_Error(
                'invalid_response',
                __('Invalid response from translation status endpoint', 'superdav-ai-language-packs')
            );
        }

        // Cache for a shorter period for status checks.
        $this->remember_cache_key($cache_key);
        set_site_transient($cache_key, $data, MINUTE_IN_SECONDS * 5);

        return $data;
    }

    /**
     * Check API health/status.
     *
     * @since 1.0.0
     * @return array|WP_Error API status information or WP_Error.
     */
    public function check_api_status() {
        $cache_key = 'gratis_ai_pt_api_status';
        $cached = get_site_transient($cache_key);

        if (false !== $cached) {
            return $cached;
        }

        $endpoint = $this->api_base . '/health';

        $response = wp_remote_get(
            $endpoint,
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return new \WP_Error(
                'api_unavailable',
                __('Translation API is currently unavailable', 'superdav-ai-language-packs')
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error(
                'json_error',
                __('Failed to parse API response', 'superdav-ai-language-packs')
            );
        }

        // Cache health status for 5 minutes.
        set_site_transient($cache_key, $data, MINUTE_IN_SECONDS * 5);

        return $data;
    }

    /**
     * Clear all cached API responses.
     *
     * @since 1.0.0
     * @return void
     */
    public function clear_cache(): void {
        delete_site_transient('gratis_ai_pt_api_status');
        delete_site_transient('gratis_ai_pt_translations_cache');
        delete_site_transient('gratis_ai_pt_pending_count');

        $cache_keys = get_site_option(self::CACHE_KEYS_OPTION, []);
        if (is_array($cache_keys)) {
            foreach ($cache_keys as $cache_key) {
                if (is_string($cache_key) && 0 === strpos($cache_key, 'gratis_ai_pt_')) {
                    delete_site_transient($cache_key);
                }
            }
        }

        delete_site_option(self::CACHE_KEYS_OPTION);
    }

    /**
     * Track a dynamic transient key so cache clearing can use WordPress APIs.
     *
     * @since 1.0.0
     * @param string $cache_key Cache key to remember.
     * @return void
     */
    private function remember_cache_key(string $cache_key): void {
        $cache_keys = get_site_option(self::CACHE_KEYS_OPTION, []);
        if (!is_array($cache_keys)) {
            $cache_keys = [];
        }

        $cache_keys[] = $cache_key;
        $cache_keys = array_values(array_unique(array_filter($cache_keys, 'is_string')));

        update_site_option(self::CACHE_KEYS_OPTION, $cache_keys);
    }

    /**
     * Confirm a decoded API response is an object-shaped array.
     *
     * @since 1.2.0
     * @param mixed $data Decoded JSON response payload.
     * @return bool True when the response can be cached and returned.
     */
    private function is_associative_response($data): bool {
        if (!is_array($data) || [] === $data) {
            return false;
        }

        return array_keys($data) !== range(0, count($data) - 1);
    }
}
