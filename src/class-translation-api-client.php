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
     * Replaces N calls to check_translations() + N calls to
     * request_translation_generation() with a single round trip.
     *
     * @since 1.2.0
     * @param array $plugins Array of ['textdomain' => string, 'version' => string].
     * @param array $locales Locales to query for every plugin.
     * @return array|\WP_Error {
     *     'results' => textdomain-keyed map of locale-keyed translation entries,
     *     'queued'  => list of newly-queued [textdomain, locale] pairs,
     *     'queue_length' => server queue length,
     * }
     */
    public function batch_check_translations(array $plugins, array $locales) {
        if (empty($plugins) || empty($locales)) {
            return ['results' => [], 'queued' => [], 'queue_length' => 0];
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
                    'auto_queue' => true,
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
                    __('Batch translation API returned error code: %d', 'gratis-ai-plugin-translations'),
                    $status_code
                )
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return new \WP_Error('invalid_response', __('Invalid response from batch endpoint', 'gratis-ai-plugin-translations'));
        }

        return [
            'results'      => $body['results'] ?? [],
            'queued'       => $body['queued'] ?? [],
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
                    __('Translation API returned error code: %d', 'gratis-ai-plugin-translations'),
                    $status_code
                )
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error(
                'json_error',
                __('Failed to parse API response', 'gratis-ai-plugin-translations')
            );
        }

        // Cache for a shorter period for status checks.
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
                __('Translation API is currently unavailable', 'gratis-ai-plugin-translations')
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error(
                'json_error',
                __('Failed to parse API response', 'gratis-ai-plugin-translations')
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
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                '%_transient_gratis_ai_pt_%'
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                '%_transient_timeout_gratis_ai_pt_%'
            )
        );
    }
}
