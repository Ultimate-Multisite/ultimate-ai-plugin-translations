<?php
/**
 * Translation Manager class
 *
 * Handles hooking into WordPress translation system and managing
 * AI translations from the Gratis AI Translation service.
 *
 * @package GratisAIPluginTranslations
 */

declare(strict_types=1);

namespace GratisAIPluginTranslations;

/**
 * Translation Manager class.
 *
 * @since 1.0.0
 */
class Translation_Manager {

    /**
     * Instance of this class.
     *
     * @since 1.0.0
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Cache of translation status for plugins.
     *
     * @since 1.0.0
     * @var array<string, array>
     */
    private array $translation_status_cache = [];

    /**
     * API client instance.
     *
     * @since 1.0.0
     * @var Translation_API_Client|null
     */
    private ?Translation_API_Client $api_client = null;

    /**
     * Get the singleton instance.
     *
     * @since 1.0.0
     * @return self
     */
    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    private function __construct() {
        $this->api_client = new Translation_API_Client();
    }

    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     * @return void
     */
    public function init(): void {
        // Hook into plugin update check to provide translation info.
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_translation_updates'], 20, 1);

        // Hook into translation API to provide AI translations.
        add_filter('translations_api', [$this, 'filter_translations_api'], 20, 3);

        // Hook into language pack upgrade process.
        add_filter('upgrader_pre_download', [$this, 'maybe_download_ai_translation'], 10, 4);

        // Add status indicators to update core page.
        add_action('admin_notices', [$this, 'display_translation_status_on_update_page']);

        // Schedule cleanup of old translation files.
        add_action('gratis_ai_pt_cleanup_old_translations', [$this, 'cleanup_old_translations']);

        if (!wp_next_scheduled('gratis_ai_pt_cleanup_old_translations')) {
            wp_schedule_event(time(), 'weekly', 'gratis_ai_pt_cleanup_old_translations');
        }
    }

    /**
     * Inject AI translation updates into plugin update transient.
     *
     * This hook fires when WordPress checks for plugin updates.
     * We check if any installed plugins need AI translations and
     * add them to the transient.
     *
     * @since 1.0.0
     * @param object|bool $transient The update_plugins transient value.
     * @return object|bool Modified transient.
     */
    public function inject_translation_updates($transient) {
        if (!is_object($transient)) {
            return $transient;
        }

        // Check if plugin is enabled.
        if (!$this->is_enabled()) {
            return $transient;
        }

        // Get all installed plugins.
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();

            foreach ($plugins as $plugin_file => $plugin_data) {
            $textdomain = $this->get_plugin_textdomain((string) $plugin_file, $plugin_data);
            $needed_translations = $this->get_needed_translations($textdomain);

            if (empty($needed_translations)) {
                continue;
            }

            // Check API for available AI translations.
            $ai_translations = $this->api_client->check_translations($textdomain, $plugin_data['Version'] ?? '1.0.0', $needed_translations);

            if (empty($ai_translations) || is_wp_error($ai_translations)) {
                // If translations aren't available yet, trigger generation.
                $this->api_client->request_translation_generation($textdomain, $plugin_data['Version'] ?? '1.0.0', $needed_translations);
                continue;
            }

            // Add translation packages to the transient.
            foreach ($ai_translations as $locale => $translation_data) {
                if (!isset($transient->translations)) {
                    $transient->translations = [];
                }

                $plugin_file_str = (string) $plugin_file;
                $slug = dirname($plugin_file_str) ?: sanitize_title($plugin_file_str);
                $transient->translations[] = [
                    'type'       => 'plugin',
                    'slug'       => (string) $slug,
                    'language'   => $locale,
                    'version'    => $plugin_data['Version'] ?? '1.0.0',
                    'updated'    => $translation_data['updated'] ?? current_time('mysql'),
                    'package'    => $translation_data['package_url'],
                    'autoupdate' => true,
                    'source'     => 'gratis-ai',
                ];
            }
        }

        return $transient;
    }

    /**
     * Filter translations API results.
     *
     * @since 1.0.0
     * @param array|bool $result The API result.
     * @param string     $type   The type of translations being requested ('plugins' or 'themes').
     * @param object     $args   Arguments used to query for plugin translations.
     * @return array|bool Modified result.
     */
    public function filter_translations_api($result, string $type, $args) {
        if ($type !== 'plugins') {
            return $result;
        }

        if (!is_array($result)) {
            $result = [];
        }

        if (!$this->is_enabled()) {
            return $result;
        }

        // Process each plugin to check for AI translations.
        if (!empty($args->slugs)) {
            foreach ($args->slugs as $slug) {
                $plugin_file = $this->get_plugin_file_from_slug($slug);
                if (!$plugin_file) {
                    continue;
                }

                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
                $textdomain = $this->get_plugin_textdomain($plugin_file, $plugin_data);
                $needed_translations = $this->get_needed_translations($textdomain);

                if (empty($needed_translations)) {
                    continue;
                }

                // Get AI translations from API.
                $ai_translations = $this->api_client->check_translations(
                    $textdomain,
                    $plugin_data['Version'] ?? '1.0.0',
                    $needed_translations
                );

                if (!empty($ai_translations) && !is_wp_error($ai_translations)) {
                    foreach ($ai_translations as $locale => $translation_data) {
                        if (!isset($result['translations'])) {
                            $result['translations'] = [];
                        }

                        $result['translations'][] = [
                            'type'       => 'plugin',
                            'slug'       => $slug,
                            'language'   => $locale,
                            'version'    => $plugin_data['Version'] ?? '1.0.0',
                            'updated'    => $translation_data['updated'] ?? current_time('mysql'),
                            'package'    => $translation_data['package_url'],
                            'autoupdate' => true,
                        ];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Maybe download AI translation package.
     *
     * @since 1.0.0
     * @param bool        $reply    Whether to bail without returning the package.
     * @param string      $package  The package file name.
     * @param WP_Upgrader $upgrader The WP_Upgrader instance.
     * @param array       $hook_extra Extra arguments passed to hooked filters.
     * @return bool|string|WP_Error False or WP_Error on failure, path to local file on success.
     */
    public function maybe_download_ai_translation($reply, string $package, $upgrader, $hook_extra) {
        // Check if this is an AI translation package.
        if (strpos($package, GRATIS_AI_PT_API_BASE) !== 0) {
            return $reply;
        }

        if (!is_a($upgrader, 'Language_Pack_Upgrader')) {
            return $reply;
        }

        // Download the translation package.
        $download_file = download_url($package);

        if (is_wp_error($download_file)) {
            return $download_file;
        }

        return $download_file;
    }

    /**
     * Get the plugin textdomain from plugin data.
     *
     * @since 1.0.0
     * @param string $plugin_file Plugin file path.
     * @param array  $plugin_data Plugin data.
     * @return string Textdomain.
     */
    private function get_plugin_textdomain(string $plugin_file, array $plugin_data): string {
        // Check if TextDomain is explicitly defined.
        if (!empty($plugin_data['TextDomain'])) {
            return $plugin_data['TextDomain'];
        }

        // Fallback to plugin slug.
        $slug = dirname($plugin_file);
        if ('.' === $slug || empty($slug)) {
            $slug = basename($plugin_file, '.php');
        }

        return sanitize_title($slug);
    }

    /**
     * Get plugin file from slug.
     *
     * @since 1.0.0
     * @param string $slug Plugin slug.
     * @return string|null Plugin file path or null.
     */
    private function get_plugin_file_from_slug(string $slug): ?string {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        foreach ($plugins as $plugin_file => $plugin_data) {
            $plugin_file_str = (string) $plugin_file;
            $plugin_slug = dirname($plugin_file_str);
            if ('.' === $plugin_slug) {
                $plugin_slug = basename($plugin_file_str, '.php');
            }

            if ($plugin_slug === $slug) {
                return $plugin_file_str;
            }
        }

        return null;
    }

    /**
     * Get needed translations for a plugin.
     *
     * Checks which translations are needed based on:
     * 1. Site language
     * 2. Installed translations and their completeness
     * 3. Whether to fill incomplete translations
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @return array Array of locale codes that need AI translations.
     */
    private function get_needed_translations(string $textdomain): array {
        // Get site languages (for multisite) or just the site locale.
        $needed_locales = [];
        $site_locale = get_locale();

        if (is_multisite()) {
            // Get all unique locales from all sites.
            global $wpdb;
            $locales = $wpdb->get_col("SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = 'WPLANG'");
            $needed_locales = array_filter(array_unique(array_merge([$site_locale], $locales)));
        } else {
            $needed_locales = [$site_locale];
        }

        if (empty($needed_locales)) {
            $needed_locales = ['en_US'];
        }

        // Filter out English (no translation needed).
        $needed_locales = array_diff($needed_locales, ['en_US', 'en']);

        // Check installed translations.
        $installed_translations = wp_get_installed_translations('plugins');
        $needed = [];
        $fill_incomplete = get_site_option('gratis_ai_pt_fill_incomplete', true);

        foreach ($needed_locales as $locale) {
            // Check if we have official translations from wordpress.org.
            $has_official = $this->has_official_translation($textdomain, $locale);

            if (!$has_official) {
                // No official translation, AI translation needed.
                $needed[] = $locale;
            } elseif ($fill_incomplete) {
                // Check if official translation is 100% complete.
                $completeness = $this->get_translation_completeness($textdomain, $locale, $installed_translations);

                if ($completeness < 100) {
                    $needed[] = $locale;
                }
            }
        }

        return array_unique($needed);
    }

    /**
     * Check if a plugin has an official translation on wordpress.org.
     *
     * @since 1.0.0
     * @param string $textdomain Plugin textdomain.
     * @param string $locale     Locale code.
     * @return bool True if official translation exists.
     */
    private function has_official_translation(string $textdomain, string $locale): bool {
        // Check cache first.
        $cache_key = 'gratis_ai_pt_official_' . md5($textdomain . $locale);
        $cached = get_site_transient($cache_key);

        if (false !== $cached) {
            return (bool) $cached;
        }

        // Query wordpress.org translation API.
        $url = sprintf(
            'https://api.wordpress.org/translations/plugins/1.0/?slug=%s',
            urlencode($textdomain)
        );

        $response = wp_remote_get($url, ['timeout' => 10]);

        if (is_wp_error($response)) {
            set_site_transient($cache_key, false, HOUR_IN_SECONDS);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['translations'])) {
            set_site_transient($cache_key, false, HOUR_IN_SECONDS);
            return false;
        }

        foreach ($data['translations'] as $translation) {
            if ($translation['language'] === $locale) {
                set_site_transient($cache_key, true, HOUR_IN_SECONDS);
                return true;
            }
        }

        set_site_transient($cache_key, false, HOUR_IN_SECONDS);
        return false;
    }

    /**
     * Get translation completeness percentage.
     *
     * @since 1.0.0
     * @param string $textdomain           Plugin textdomain.
     * @param string $locale               Locale code.
     * @param array  $installed_translations Installed translations data.
     * @return int Completeness percentage.
     */
    private function get_translation_completeness(string $textdomain, string $locale, array $installed_translations): int {
        if (!isset($installed_translations[$textdomain][$locale])) {
            return 0;
        }

        $translation_data = $installed_translations[$textdomain][$locale];

        // If we have completeness info, use it.
        if (isset($translation_data['completeness'])) {
            return (int) $translation_data['completeness'];
        }

        // Default to assuming it's complete if it exists.
        return 100;
    }

    /**
     * Check if the plugin is enabled.
     *
     * @since 1.0.0
     * @return bool True if enabled.
     */
    private function is_enabled(): bool {
        return (bool) get_site_option('gratis_ai_pt_enabled', true);
    }

    /**
     * Cleanup old AI translation files.
     *
     * @since 1.0.0
     * @return void
     */
    public function cleanup_old_translations(): void {
        $languages_dir = WP_CONTENT_DIR . '/languages/plugins';

        if (!is_dir($languages_dir)) {
            return;
        }

        // Find all AI translation files (marked with -gratis-ai suffix).
        $files = glob($languages_dir . '/*-gratis-ai.mo');

        foreach ($files as $file) {
            $file_time = filemtime($file);

            // Delete files older than 30 days.
            if ($file_time && (time() - $file_time) > (30 * DAY_IN_SECONDS)) {
                @unlink($file);

                // Also delete the .po file if it exists.
                $po_file = str_replace('.mo', '.po', $file);
                if (file_exists($po_file)) {
                    @unlink($po_file);
                }
            }
        }
    }

    /**
     * Display translation status on update core page.
     *
     * @since 1.0.0
     * @return void
     */
    public function display_translation_status_on_update_page(): void {
        // Only show on update-core.php.
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'update-core') {
            return;
        }

        // Check if plugin is enabled.
        if (!$this->is_enabled()) {
            return;
        }

        // Get pending translations count.
        $pending_count = get_site_transient('gratis_ai_pt_pending_count');
        $completed_count = get_site_transient('gratis_ai_pt_completed_today');

        if (!$pending_count && !$completed_count) {
            return;
        }

        echo '<div class="notice notice-info inline">';
        echo '<p><strong>' . esc_html__('AI Plugin Translations', 'gratis-ai-plugin-translations') . '</strong></p>';
        
        if ($pending_count > 0) {
            echo '<p>';
            printf(
                /* translators: %d: Number of pending translations */
                esc_html(
                    sprintf(
                        _n(
                            'Currently requesting %s AI translation.',
                            'Currently requesting %s AI translations.',
                            $pending_count,
                            'gratis-ai-plugin-translations'
                        ),
                        number_format_i18n($pending_count)
                    )
                )
            );
            echo '</p>';
        }

        if ($completed_count > 0) {
            echo '<p>';
            printf(
                /* translators: %d: Number of completed translations */
                esc_html(
                    sprintf(
                        _n(
                            '%s AI translation completed today.',
                            '%s AI translations completed today.',
                            $completed_count,
                            'gratis-ai-plugin-translations'
                        ),
                        number_format_i18n($completed_count)
                    )
                )
            );
            echo '</p>';
        }

        echo '<p><small>';
        esc_html_e('AI translations fill gaps when official translations from wordpress.org are missing or incomplete.', 'gratis-ai-plugin-translations');
        echo '</small></p>';
        
        echo '</div>';
    }
}
