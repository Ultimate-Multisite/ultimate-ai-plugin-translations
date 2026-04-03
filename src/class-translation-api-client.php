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
        $this->api_base = GRATIS_AI_PT_API_BASE;
        $this->timeout = 30;
        $this->cache_duration = (int) get_site_option('gratis_ai_pt_cache_duration', 3600);
    }

    /**
     * Check for available translations via Traduttore's GlotPress API.
     *
     * Queries the Traduttore translation API on the translate server to see
     * which locales have built language packs. Returns only the locales
     * the caller requested.
     *
     * @since 1.1.0
     * @param string $textdomain    Plugin textdomain.
     * @param string $version       Plugin version (unused — Traduttore returns all sets).
     * @param array  $locales       Array of locale codes to check.
     * @return array|WP_Error       Locale-keyed array of available translations or WP_Error.
     */
    public function check_translations(string $textdomain, string $version, array $locales) {
        if (empty($locales)) {
            return [];
        }

        // Check cache first.
        $cache_key = 'gratis_ai_pt_check_' . md5($textdomain . implode(',', $locales));
        $cached = get_site_transient($cache_key);

        if (false !== $cached) {
            return $cached;
        }

        // Query Traduttore's GlotPress API route.
        // Project path follows GlotPress convention: plugins/{textdomain}
        $translate_host = wp_parse_url($this->api_base, PHP_URL_SCHEME) . '://'
            . wp_parse_url($this->api_base, PHP_URL_HOST);
        $endpoint = $translate_host . '/api/translations/plugins/' . $textdomain;

        $response = wp_remote_get(
            $endpoint,
            [
                'timeout' => $this->timeout,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 404) {
            // Project doesn't exist yet in GlotPress — no translations available.
            return [];
        }

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

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['translations'])) {
            return new \WP_Error(
                'json_error',
                __('Failed to parse API response', 'gratis-ai-plugin-translations')
            );
        }

        // Transform Traduttore's array into a locale-keyed map,
        // filtering to only the locales the caller requested.
        $result = [];
        $locales_flip = array_flip($locales);

        foreach ($data['translations'] as $translation) {
            $lang = $translation['language'] ?? '';
            if (isset($locales_flip[$lang])) {
                $result[$lang] = [
                    'package_url' => $translation['package'],
                    'updated'     => $translation['updated'] ?? '',
                ];
            }
        }

        // Cache the result.
        set_site_transient($cache_key, $result, $this->cache_duration);

        return $result;
    }

    /**
     * Request translation generation for a plugin.
     *
     * This triggers the AI translation process on the server.
     * The server will queue the job and generate translations asynchronously.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @param array  $locales    Array of locale codes to translate.
     * @return bool|WP_Error     True on success, WP_Error on failure.
     */
    public function request_translation_generation(string $textdomain, string $version, array $locales) {
        if (empty($locales)) {
            return true;
        }

        $endpoint = $this->api_base . '/request-translation';

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
                    'locales'    => $locales,
                    'site_url'   => get_site_url(),
                    'wp_version' => get_bloginfo('version'),
                    'priority'   => $this->calculate_priority($textdomain),
                ]),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 202) {
            // Request accepted, queued for processing.
            return true;
        }

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

        return true;
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
     * Report translation quality feedback.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $version    Plugin version.
     * @param string $locale     Locale code.
     * @param string $feedback   Feedback type ('good', 'bad', 'report').
     * @param string $details    Optional details about the feedback.
     * @return bool|WP_Error     True on success, WP_Error on failure.
     */
    public function report_feedback(string $textdomain, string $version, string $locale, string $feedback, string $details = '') {
        $endpoint = $this->api_base . '/feedback';

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
                    'feedback'   => $feedback,
                    'details'    => $details,
                    'site_url'   => get_site_url(),
                ]),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if (!in_array($status_code, [200, 202], true)) {
            return new \WP_Error(
                'api_error',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __('Translation API returned error code: %d', 'gratis-ai-plugin-translations'),
                    $status_code
                )
            );
        }

        return true;
    }

    /**
     * Calculate translation priority based on plugin popularity.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @return int Priority level (1-10, higher = more priority).
     */
    private function calculate_priority(string $textdomain): int {
        // Default priority.
        $priority = 5;

        // Check if plugin is from wordpress.org (typically more popular).
        $api_url = "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug={$textdomain}";
        $response = wp_remote_get($api_url, ['timeout' => 5]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (isset($data['active_installs'])) {
                // Boost priority for popular plugins.
                if ($data['active_installs'] > 1000000) {
                    $priority = 10;
                } elseif ($data['active_installs'] > 100000) {
                    $priority = 8;
                } elseif ($data['active_installs'] > 10000) {
                    $priority = 7;
                }
            }
        }

        // Allow filtering.
        return (int) apply_filters('gratis_ai_pt_translation_priority', $priority, $textdomain);
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
