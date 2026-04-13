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

        // Note: no upgrader_pre_download hook needed — Traduttore serves static
        // zip files that WordPress's Language_Pack_Upgrader downloads natively.

        // Cron handler for the async cache refresh (scheduled by activate() and
        // on cache-miss). Must be registered before any wp_schedule_single_event()
        // call for this hook so the event always has a handler.
        add_action('gratis_ai_pt_refresh_cache', [$this, 'refresh_translations_cache']);

        // Schedule cleanup of old translation files.
        add_action('gratis_ai_pt_cleanup_old_translations', [$this, 'cleanup_old_translations']);

        if (!wp_next_scheduled('gratis_ai_pt_cleanup_old_translations')) {
            wp_schedule_event(time(), 'weekly', 'gratis_ai_pt_cleanup_old_translations');
        }

        // User-locale hook: when a user saves their profile with a different
        // language, schedule an async translation request for that locale.
        add_action('profile_update', [$this, 'schedule_translation_request_for_user']);
        add_action('user_register', [$this, 'schedule_translation_request_for_user']);
        add_action('gratis_ai_pt_request_user_locale', [$this, 'maybe_request_translations_for_user']);
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

        // Use cached results from the async cron job. NEVER make HTTP calls
        // synchronously here — this hook fires inside admin page loads and
        // would block update-core.php for minutes (504s) on sites with many
        // plugins. The cron handler (gratis_ai_pt_refresh_cache) populates
        // this cache; if missing, schedule it and return the transient as-is.
        $cached = get_site_transient('gratis_ai_pt_translations_cache');

        if (false === $cached) {
            if (!wp_next_scheduled('gratis_ai_pt_refresh_cache')) {
                wp_schedule_single_event(time() + 5, 'gratis_ai_pt_refresh_cache');
            }
            return $transient;
        }

        if (!is_array($cached) || empty($cached)) {
            return $transient;
        }

        foreach ($cached as $entry) {
            if (!isset($transient->translations)) {
                $transient->translations = [];
            }
            $transient->translations[] = $entry;
        }

        return $transient;
    }

    /**
     * Refresh the translations cache (cron handler).
     *
     * Performs the slow per-plugin API/network work off the request path
     * and stores the resulting list of translation entries in a transient
     * for inject_translation_updates() to consume.
     *
     * @since 1.0.0
     * @return void
     */
    public function refresh_translations_cache(): void {
        if (!$this->is_enabled()) {
            return;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = array_keys(get_plugins());
        sort($plugins);

        // Resume from prior chunk state, or start fresh.
        $state = get_site_option('gratis_ai_pt_refresh_state', null);
        $is_resume = is_array($state)
            && isset($state['plugins'], $state['offset'])
            && $state['plugins'] === $plugins;

        if (!$is_resume) {
            $state = [
                'plugins'    => $plugins,
                'offset'     => 0,
                'entries'    => [],
                'pending'    => 0,
                'started_at' => current_time('mysql'),
            ];
        }

        /**
         * Filter the chunk size for the refresh cron.
         *
         * Each cron event processes this many plugins, then reschedules
         * itself if more remain. Keep low enough that one batch finishes
         * well under PHP's max_execution_time.
         *
         * @since 1.2.0
         * @param int $chunk_size Default 25.
         */
        $chunk_size = (int) apply_filters('gratis_ai_pt_refresh_chunk_size', 25);
        $chunk_size = max(1, min(100, $chunk_size));

        $slice = array_slice($plugins, $state['offset'], $chunk_size);
        if (empty($slice)) {
            $this->finalize_refresh($state);
            return;
        }

        $this->process_refresh_chunk($slice, $state);

        $state['offset'] += count($slice);
        if ($state['offset'] >= count($plugins)) {
            $this->finalize_refresh($state);
            return;
        }

        // More work to do — persist state and reschedule the next chunk.
        update_site_option('gratis_ai_pt_refresh_state', $state);
        wp_schedule_single_event(time() + 1, 'gratis_ai_pt_refresh_cache');
    }

    /**
     * Process one chunk of plugins via the batch endpoint.
     *
     * @since 1.2.0
     * @param array $plugin_files Plugin file paths to process this chunk.
     * @param array &$state        Refresh state (entries / pending mutated).
     * @return void
     */
    private function process_refresh_chunk(array $plugin_files, array &$state): void {
        $all_plugins = get_plugins();
        $installed   = wp_get_installed_translations('plugins');
        $available   = $this->get_available_translations_map();

        // Build the per-chunk request: textdomain → {version, slug, file}.
        // Track every locale needed across the chunk so we can send a single
        // request with the union of locales.
        $batch        = [];   // textdomain => ['textdomain'=>..., 'version'=>...]
        $needed_map   = [];   // textdomain => locales[]
        $slug_map     = [];   // textdomain => slug
        $version_map  = [];   // textdomain => version
        $locale_union = [];

        foreach ($plugin_files as $plugin_file) {
            $plugin_data = $all_plugins[$plugin_file] ?? null;
            if (!$plugin_data) {
                continue;
            }
            $textdomain = $this->get_plugin_textdomain((string) $plugin_file, $plugin_data);

            // Compute slug before get_needed_translations() so it can be passed
            // to has_official_translation() for the slug-keyed $available map.
            $slug = dirname((string) $plugin_file);
            if ('.' === $slug || '' === $slug) {
                // Single-file plugin (e.g. hello.php) — dirname returns '.'.
                // Derive slug from the filename without extension.
                $slug = sanitize_title(basename((string) $plugin_file, '.php'));
            }

            $needed = $this->get_needed_translations($textdomain, $slug, $installed, $available);
            if (empty($needed)) {
                continue;
            }

            $version = $plugin_data['Version'] ?? '1.0.0';

            $batch[$textdomain]       = ['textdomain' => $textdomain, 'version' => $version];
            $needed_map[$textdomain]  = $needed;
            $slug_map[$textdomain]    = $slug;
            $version_map[$textdomain] = $version;
            $locale_union             = array_merge($locale_union, $needed);
        }

        if (empty($batch)) {
            return;
        }

        $locale_union = array_values(array_unique($locale_union));
        $response = $this->api_client->batch_check_translations(array_values($batch), $locale_union);

        if (is_wp_error($response)) {
            // Soft-fail: count everything as pending so the user sees activity.
            foreach ($needed_map as $td => $locales) {
                $state['pending'] += count($locales);
            }
            return;
        }

        $results = $response['results'] ?? [];
        $queued  = $response['queued'] ?? [];
        $state['pending'] += count($queued);

        foreach ($results as $textdomain => $by_locale) {
            if (!isset($needed_map[$textdomain])) {
                continue;
            }
            foreach ($by_locale as $locale => $entry) {
                // Only count locales the client actually wants for this plugin.
                if (!in_array($locale, $needed_map[$textdomain], true)) {
                    continue;
                }
                if (empty($entry['package_url'])) {
                    continue;
                }
                $state['entries'][] = [
                    'type'       => 'plugin',
                    'slug'       => $slug_map[$textdomain],
                    'language'   => $locale,
                    'version'    => $version_map[$textdomain],
                    'updated'    => $entry['updated'] ?? current_time('mysql'),
                    'package'    => $entry['package_url'],
                    'autoupdate' => true,
                    'source'     => 'gratis-ai',
                ];
            }
        }
    }

    /**
     * Persist final cache + stats and clear the chunk state.
     *
     * The translations cache is only written when the refresh is fully
     * complete (pending === 0). While translations are still being generated
     * on the server, we update the stats counters so the status page stays
     * accurate, but we leave the existing cache in place rather than
     * replacing it with an incomplete snapshot that would cause already-available
     * packages to disappear until the next successful full refresh.
     *
     * @since 1.2.0
     * @param array $state Completed refresh state.
     * @return void
     */
    private function finalize_refresh(array $state): void {
        $entries = $state['entries'] ?? [];
        $pending = (int) ($state['pending'] ?? 0);
        $checked = count($state['plugins'] ?? []);

        // Only cache the translation list when there are no server-side pending
        // items. If the server queued work, leave the existing cache intact so
        // plugins with package_url from a previous run remain visible.
        if (0 === $pending) {
            /**
             * Filter the cache duration (seconds) for the translations result set.
             *
             * @since 1.2.0
             * @param int $seconds Default 1 hour.
             */
            $cache_duration = (int) apply_filters('gratis_ai_pt_cache_duration', HOUR_IN_SECONDS);
            set_site_transient('gratis_ai_pt_translations_cache', $entries, $cache_duration);
        }

        update_site_option('gratis_ai_pt_last_check', current_time('mysql'));
        update_site_option('gratis_ai_pt_plugins_checked', $checked);
        update_site_option('gratis_ai_pt_pending_count', $pending);
        update_site_option('gratis_ai_pt_available_count', count($entries));
        set_site_transient('gratis_ai_pt_pending_count', $pending, DAY_IN_SECONDS);

        delete_site_option('gratis_ai_pt_refresh_state');
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
        if ($type !== 'plugins' || !$this->is_enabled()) {
            return $result;
        }

        if (!is_array($result)) {
            $result = [];
        }

        // Serve from the cache populated by refresh_translations_cache().
        // Never make sync API calls on this hook — it fires during admin
        // requests and would block. If the cache is empty, schedule a
        // refresh and return whatever we have.
        $cached = get_site_transient('gratis_ai_pt_translations_cache');
        if (false === $cached) {
            if (!wp_next_scheduled('gratis_ai_pt_refresh_cache')) {
                wp_schedule_single_event(time() + 5, 'gratis_ai_pt_refresh_cache');
            }
            return $result;
        }
        if (!is_array($cached) || empty($cached)) {
            return $result;
        }

        $wanted_slugs = !empty($args->slugs) ? (array) $args->slugs : null;

        foreach ($cached as $entry) {
            if ($wanted_slugs !== null && !in_array($entry['slug'] ?? '', $wanted_slugs, true)) {
                continue;
            }
            if (!isset($result['translations'])) {
                $result['translations'] = [];
            }
            $result['translations'][] = $entry;
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
     * @param string $textdomain Plugin textdomain (keys installed-translations map).
     * @param string $slug       Plugin slug / folder name (keys available-translations map).
     * @param array  $installed  Optional pre-fetched wp_get_installed_translations('plugins').
     * @param array  $available  Optional pre-built [slug => [locale => true]] map.
     * @return array Array of locale codes that need AI translations.
     */
    private function get_needed_translations(string $textdomain, string $slug = '', array $installed = [], array $available = []): array {
        // Get site languages (for multisite) or just the site locale.
        $needed_locales = [get_locale()];

        // Include all user-profile locales (users can override site language).
        global $wpdb;
        $user_locales = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'locale' AND meta_value != ''"
        );
        if (!empty($user_locales)) {
            $needed_locales = array_merge($needed_locales, $user_locales);
        }

        if (is_multisite()) {
            $site_locales = $wpdb->get_col("SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = 'WPLANG'");
            $needed_locales = array_merge($needed_locales, $site_locales);
        }

        $needed_locales = array_filter(array_unique($needed_locales));

        if (empty($needed_locales)) {
            $needed_locales = ['en_US'];
        }

        // Filter out English (no translation needed).
        $needed_locales = array_diff($needed_locales, ['en_US', 'en']);

        // Use prefetched maps when supplied (chunked refresh path); otherwise
        // fetch them lazily for ad-hoc callers.
        $installed_translations = !empty($installed) ? $installed : wp_get_installed_translations('plugins');
        if (empty($available)) {
            $available = $this->get_available_translations_map();
        }
        $needed = [];
        /**
         * Filter whether to fill gaps in incomplete official translations.
         *
         * @since 1.0.0
         * @param bool $fill_incomplete Default true.
         */
        $fill_incomplete = (bool) apply_filters('gratis_ai_pt_fill_incomplete', true);

        foreach ($needed_locales as $locale) {
            // Check if we have official translations from wordpress.org.
            // Pass both textdomain (for installed map) and slug (for available
            // map from update_plugins transient, which is keyed by plugin slug).
            $has_official = $this->has_official_translation($textdomain, $slug, $locale, $installed_translations, $available);

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
     * Check if a plugin has an official translation, using only WP's caches.
     *
     * Never calls api.wordpress.org. Two sources:
     *
     * 1. wp_get_installed_translations('plugins') — translations already
     *    on disk, keyed by textdomain. If installed, it's official.
     * 2. The 'update_plugins' site transient — populated by WP's normal
     *    update-check cycle, contains a `translations` array of available
     *    plugin translation updates from wp.org, keyed by plugin slug.
     *
     * Anything not in either cache is treated as missing → AI fills the
     * gap. WordPress will overwrite our AI .mo if/when wp.org publishes
     * an official one (core's Language_Pack_Upgrader runs after ours).
     *
     * @since 1.2.0
     * @param string $textdomain Plugin textdomain (keys the installed-translations map).
     * @param string $slug       Plugin slug / folder name (keys the available map from
     *                           update_plugins transient). Pass '' when unknown.
     * @param string $locale     Locale code.
     * @param array  $installed  Pre-fetched wp_get_installed_translations('plugins').
     * @param array  $available  Pre-built [slug => [locale => true]] map.
     * @return bool True if WordPress already knows of an official translation.
     */
    private function has_official_translation(
        string $textdomain,
        string $slug,
        string $locale,
        array $installed = [],
        array $available = []
    ): bool {
        // Installed translations on disk are indexed by textdomain.
        if (isset($installed[$textdomain][$locale])) {
            return true;
        }

        // Available translations from wp.org (update_plugins transient) are
        // indexed by plugin slug, which may differ from textdomain.
        $lookup_keys = array_filter(array_unique([$slug, $textdomain]));
        foreach ($lookup_keys as $key) {
            if (isset($available[$key][$locale])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a [textdomain => [locale => true]] map from the update_plugins
     * transient's translations array. Cheap; runs once per refresh batch.
     *
     * @since 1.2.0
     * @return array
     */
    private function get_available_translations_map(): array {
        $map = [];
        $transient = get_site_transient('update_plugins');
        if (!is_object($transient) || empty($transient->translations)) {
            return $map;
        }
        foreach ($transient->translations as $entry) {
            if (empty($entry['slug']) || empty($entry['language'])) {
                continue;
            }
            $map[(string) $entry['slug']][(string) $entry['language']] = true;
        }
        return $map;
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
        /**
         * Filter whether the plugin is enabled.
         *
         * Allows site owners to disable via code (e.g. a mu-plugin) without
         * a settings page. Default: true.
         *
         * @since 1.0.0
         * @param bool $enabled Default true.
         */
        return (bool) apply_filters('gratis_ai_pt_enabled', true);
    }

    /**
     * Schedule async translation request after profile change.
     *
     * Defers the slow API/network work to wp-cron so the profile save
     * request stays fast. Cron fires within seconds on the next page load.
     *
     * @since 1.0.0
     * @param int $user_id User ID.
     * @return void
     */
    public function schedule_translation_request_for_user(int $user_id): void {
        if (!$this->is_enabled()) {
            return;
        }
        $locale = get_user_meta($user_id, 'locale', true);
        if (empty($locale) || in_array($locale, ['en_US', 'en', 'site-default'], true)) {
            return;
        }
        if (!wp_next_scheduled('gratis_ai_pt_request_user_locale', [$user_id])) {
            wp_schedule_single_event(time() + 5, 'gratis_ai_pt_request_user_locale', [$user_id]);
        }
    }

    /**
     * Request AI translations after a user profile change.
     *
     * Fires when a user updates their profile (including the language
     * preference). Detects new locales and immediately asks the API
     * to generate translations for all installed plugins.
     *
     * @since 1.0.0
     * @param int $user_id User ID.
     * @return void
     */
    public function maybe_request_translations_for_user(int $user_id): void {
        if (!$this->is_enabled()) {
            return;
        }

        $locale = get_user_meta($user_id, 'locale', true);
        if (empty($locale) || in_array($locale, ['en_US', 'en', 'site-default'], true)) {
            return;
        }

        // De-dupe: only fire once per locale per day.
        $marker = 'gratis_ai_pt_user_locale_' . md5($locale);
        if (get_site_transient($marker)) {
            return;
        }
        set_site_transient($marker, 1, DAY_IN_SECONDS);

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed = wp_get_installed_translations('plugins');
        $available = $this->get_available_translations_map();

        // Build one batch request for every plugin missing this locale.
        $batch = [];
        foreach (get_plugins() as $plugin_file => $plugin_data) {
            $textdomain = $this->get_plugin_textdomain((string) $plugin_file, $plugin_data);
            $slug       = dirname((string) $plugin_file);
            if ('.' === $slug || '' === $slug) {
                $slug = sanitize_title(basename((string) $plugin_file, '.php'));
            }
            if ($this->has_official_translation($textdomain, $slug, $locale, $installed, $available)) {
                continue;
            }
            $batch[] = [
                'textdomain' => $textdomain,
                'version'    => $plugin_data['Version'] ?? '1.0.0',
            ];
        }

        if (empty($batch)) {
            return;
        }

        // Single batched call — server auto-queues missing locales.
        $this->api_client->batch_check_translations($batch, [$locale]);
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

}
