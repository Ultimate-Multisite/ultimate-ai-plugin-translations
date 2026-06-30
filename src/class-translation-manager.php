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

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_Upgrader;

/**
 * Translation Manager class.
 *
 * @since 1.0.0
 */
class Translation_Manager {

    /**
     * API client instance.
     *
     * @since 1.0.0
     * @var Translation_API_Client
     */
    private Translation_API_Client $api_client;

    /**
     * Constructor.
     *
     * @since 1.0.0
     * @param Translation_API_Client $api_client API client instance.
     */
    public function __construct(Translation_API_Client $api_client) {
        $this->api_client = $api_client;
    }

    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     * @return void
     */
    public function init(): void {
        // Hook into translation API to provide AI translations.
        add_filter('translations_api', [$this, 'filter_translations_api'], 20, 3);

        // Allow WordPress to download packages from the translation server
        // even when it resolves to a private/reserved IP (e.g. local dev).
        // WordPress's SSRF protection blocks private IPs by default.
        add_filter('http_request_host_is_external', [$this, 'allow_translation_server_host'], 10, 2);

        // Note: no upgrader_pre_download hook needed — Traduttore serves static
        // zip files that WordPress's Language_Pack_Upgrader downloads natively.

        // Cron handler for the async cache refresh (scheduled by activate() and
        // on cache-miss). Must be registered before any wp_schedule_single_event()
        // call for this hook so the event always has a handler.
        add_action('sd_ai_lang_packs_refresh_cache', [$this, 'refresh_translations_cache']);

        // Schedule cleanup of old translation files.
        add_action('sd_ai_lang_packs_cleanup_old_translations', [$this, 'cleanup_old_translations']);

        if (!wp_next_scheduled('sd_ai_lang_packs_cleanup_old_translations')) {
            wp_schedule_event(time(), 'weekly', 'sd_ai_lang_packs_cleanup_old_translations');
        }

        // User-locale hook: when a user saves their profile with a different
        // language, schedule an async translation request for that locale.
        add_action('profile_update', [$this, 'schedule_translation_request_for_user']);
        add_action('user_register', [$this, 'schedule_translation_request_for_user']);
        add_action('sd_ai_lang_packs_request_user_locale', [$this, 'maybe_request_translations_for_user']);
    }

    /**
     * Refresh the translations cache (cron handler).
     *
     * Performs the slow per-plugin API/network work off the request path
     * and installs available AI language packs with WordPress's language
     * pack upgrader.
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
        $state = get_site_option('sd_ai_lang_packs_refresh_state', null);
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
        $chunk_size = (int) apply_filters('sd_ai_lang_packs_refresh_chunk_size', 25);
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
        update_site_option('sd_ai_lang_packs_refresh_state', $state);
        wp_schedule_single_event(time() + 1, 'sd_ai_lang_packs_refresh_cache');
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
        $installed    = wp_get_installed_translations('plugins');
        $available    = $this->get_available_translations_map();
        $site_locales = $this->get_site_locales();

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

            $needed = $this->get_needed_translations($textdomain, $slug, $installed, $available, $site_locales);
            if (empty($needed)) {
                continue;
            }

            $version = $plugin_data['Version'] ?? '1.0.0';

            $batch[$textdomain]       = [
                'textdomain' => $textdomain,
                'version'    => $version,
                'source'     => $this->get_plugin_source($plugin_data),
            ];
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
        $queued  = $response['queued'] ?? ($response['requested'] ?? []);
        $state['pending'] += is_countable($queued) ? count($queued) : (int) ($response['queue_length'] ?? 0);

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
                    'textdomain' => $textdomain,
                    'language'   => $locale,
                    'version'    => $version_map[$textdomain],
                    'updated'    => $entry['updated'] ?? current_time('mysql'),
                    'package'    => $entry['package_url'],
                    'autoupdate' => true,
                    'source'     => 'sd-ai-lang-pack',
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

        if (!empty($entries) && is_array($entries)) {
            $this->install_translation_packages($entries);
        }

        if (0 === $pending || !empty($entries)) {
            update_site_option('sd_ai_lang_packs_installed_translations', is_array($entries) ? $entries : []);
        }

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
            $cache_duration = (int) apply_filters('sd_ai_lang_packs_cache_duration', HOUR_IN_SECONDS);
            set_site_transient('sd_ai_lang_packs_translations_cache', $entries, $cache_duration);
        }

        update_site_option('sd_ai_lang_packs_last_check', current_time('mysql'));
        update_site_option('sd_ai_lang_packs_plugins_checked', $checked);
        update_site_option('sd_ai_lang_packs_pending_count', $pending);
        update_site_option('sd_ai_lang_packs_available_count', count($entries));
        set_site_transient('sd_ai_lang_packs_pending_count', $pending, DAY_IN_SECONDS);

        delete_site_option('sd_ai_lang_packs_refresh_state');
    }

    /**
     * Install available AI language packs through WordPress's upgrader API.
     *
     * @since 1.0.0
     * @param array<int, array<string, mixed>> $entries Translation package entries.
     * @return void
     */
    private function install_translation_packages(array $entries): void {
        if (empty($entries)) {
            return;
        }

        if (!class_exists('Language_Pack_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
            require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
            require_once ABSPATH . 'wp-admin/includes/class-language-pack-upgrader.php';
        }

        $upgrader = new \Language_Pack_Upgrader(new \Automatic_Upgrader_Skin());

        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['package']) || empty($entry['slug']) || empty($entry['language'])) {
                continue;
            }

            $package = (string) $entry['package'];
            if (!$this->is_trusted_package_url($package)) {
                continue;
            }

            $language_update = (object) [
                'type'       => 'plugin',
                'slug'       => (string) $entry['slug'],
                'language'   => (string) $entry['language'],
                'version'    => (string) ($entry['version'] ?? ''),
                'updated'    => (string) ($entry['updated'] ?? current_time('mysql')),
                'package'    => $package,
                'autoupdate' => true,
            ];

            $upgrader->upgrade($language_update, ['clear_update_cache' => false]);
        }
    }

    /**
     * Check whether a language-pack URL belongs to the configured API host.
     *
     * @since 1.0.0
     * @param string $package_url Package URL returned by the translation API.
     * @return bool True when the package URL host matches the API host.
     */
    private function is_trusted_package_url(string $package_url): bool {
        $api_base       = (string) apply_filters('sd_ai_lang_packs_api_base', SD_AI_LANG_PACKS_API_BASE);
        $package_scheme = wp_parse_url($package_url, PHP_URL_SCHEME);
        $package_host   = wp_parse_url($package_url, PHP_URL_HOST);
        $api_scheme     = wp_parse_url($api_base, PHP_URL_SCHEME);
        $api_host       = wp_parse_url($api_base, PHP_URL_HOST);

        return is_string($package_scheme)
            && is_string($package_host)
            && is_string($api_scheme)
            && is_string($api_host)
            && strtolower($package_scheme) === strtolower($api_scheme)
            && strtolower($package_host) === strtolower($api_host);
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
        $cached = get_site_transient('sd_ai_lang_packs_translations_cache');
        if (false === $cached) {
            if (!wp_next_scheduled('sd_ai_lang_packs_refresh_cache')) {
                wp_schedule_single_event(time() + 5, 'sd_ai_lang_packs_refresh_cache');
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
     * @param array  $available    Optional pre-built [slug => [locale => true]] map.
     * @param array  $site_locales Optional pre-fetched locales needed by the site/network/users.
     * @return array Array of locale codes that need AI translations.
     */
    private function get_needed_translations(
        string $textdomain,
        string $slug = '',
        array $installed = [],
        array $available = [],
        array $site_locales = []
    ): array {
        $needed_locales = !empty($site_locales) ? $site_locales : $this->get_site_locales();

        // Filter out English/site-default markers (no translation needed).
        $needed_locales = array_diff($needed_locales, ['en_US', 'en', 'site-default']);

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
        $fill_incomplete = (bool) apply_filters('sd_ai_lang_packs_fill_incomplete', true);

        foreach ($needed_locales as $locale) {
            // Check if we have official translations from wordpress.org.
            // Pass both textdomain (for installed map) and slug (for available
            // translations, which are keyed by plugin slug).
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
     * Check if a plugin has an official translation, using WordPress APIs.
     *
     * Two sources:
     *
     * 1. wp_get_installed_translations('plugins') — translations already
     *    on disk, keyed by textdomain. If installed, it's official.
     * 2. wp_get_translation_updates() — populated by WordPress's normal
     *    update-check cycle and keyed by plugin slug.
     *
     * Anything not in either cache is treated as missing → AI fills the
     * gap. WordPress will overwrite our AI .mo if/when wp.org publishes
     * an official one (core's Language_Pack_Upgrader runs after ours).
     *
     * @since 1.2.0
     * @param string $textdomain Plugin textdomain (keys the installed-translations map).
     * @param string $slug       Plugin slug / folder name. Pass '' when unknown.
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

        // Available translations from wp.org are indexed by plugin slug,
        // which may differ from textdomain.
        $lookup_keys = array_filter(array_unique([$slug, $textdomain]));
        foreach ($lookup_keys as $key) {
            if (isset($available[$key][$locale])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a [slug => [locale => true]] map from WordPress translation data.
     *
     * @since 1.2.0
     * @return array<string, array<string, bool>>
     */
    private function get_available_translations_map(): array {
        $map = [];

        if (!function_exists('wp_get_translation_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $translation_updates = wp_get_translation_updates();
        if (empty($translation_updates)) {
            return $map;
        }

        foreach ($translation_updates as $entry) {
            if (!is_object($entry) || 'plugin' !== ($entry->type ?? '')) {
                continue;
            }
            if (empty($entry->slug) || empty($entry->language)) {
                continue;
            }
            $map[(string) $entry->slug][(string) $entry->language] = true;
        }

        return $map;
    }

    /**
     * Get all locales needed by the site, network, and user profiles.
     *
     * @since 1.0.0
     * @return array<int, string> Locale codes.
     */
    private function get_site_locales(): array {
        static $cached_locales = null;

        if (null !== $cached_locales) {
            return $cached_locales;
        }

        $locales = [get_locale()];

        $user_ids = get_users(['fields' => 'ID']);
        foreach ($user_ids as $user_id) {
            $user_locale = get_user_meta((int) $user_id, 'locale', true);
            if (is_string($user_locale) && '' !== $user_locale) {
                $locales[] = $user_locale;
            }
        }

        if (is_multisite() && function_exists('get_sites')) {
            $site_ids = get_sites(
                [
                    'fields' => 'ids',
                    'number' => 0,
                ]
            );

            foreach ($site_ids as $site_id) {
                $site_locale = get_blog_option((int) $site_id, 'WPLANG', '');
                if (is_string($site_locale) && '' !== $site_locale) {
                    $locales[] = $site_locale;
                }
            }
        }

        $locales = array_values(array_filter(array_unique($locales)));

        $cached_locales = empty($locales) ? ['en_US'] : $locales;

        return $cached_locales;
    }

    /**
     * Infer whether a plugin uses WordPress.org or another update source.
     *
     * @since 1.0.0
     * @param array $plugin_data Plugin header data from get_plugins().
     * @return string Source label for the translation API.
     */
    private function get_plugin_source(array $plugin_data): string {
        $update_uri = (string) ($plugin_data['UpdateURI'] ?? '');

        return '' === $update_uri ? 'wporg' : 'premium';
    }

    /**
     * Get translation completeness percentage.
     *
     * When a .po file is installed locally, parses it to count how many
     * strings have non-empty translations. WordPress's installed-translations
     * metadata does NOT include a completeness field, so without reading
     * the actual .po file the code would always assume 100% — silently
     * skipping plugins with partial official translations.
     *
     * @since 1.0.0
     * @param string $textdomain           Plugin textdomain.
     * @param string $locale               Locale code.
     * @param array  $installed_translations Installed translations data.
     * @return int Completeness percentage (0-100).
     */
    private function get_translation_completeness(string $textdomain, string $locale, array $installed_translations): int {
        if (!isset($installed_translations[$textdomain][$locale])) {
            return 0;
        }

        // Try to determine actual completeness by parsing the .po file.
        $po_file = WP_LANG_DIR . '/plugins/' . $textdomain . '-' . $locale . '.po';

        if (file_exists($po_file)) {
            return $this->count_po_completeness($po_file);
        }

        // .mo exists but .po is missing (can happen after manual cleanup).
        // Can't verify completeness — assume incomplete so fill_incomplete
        // can send it to the server for gap-filling.
        return 99;
    }

    /**
     * Count translation completeness by parsing a .po file.
     *
     * Reads the .po file and counts msgid/msgstr pairs. A msgid with a
     * non-empty msgstr (or at least one non-empty msgstr[] for plurals)
     * is counted as translated. The PO header entry (empty msgid) is
     * excluded from the count.
     *
     * @since 1.2.0
     * @param string $po_file Absolute path to the .po file.
     * @return int Completeness percentage (0-100).
     */
    private function count_po_completeness(string $po_file): int {
        // Use WP's PO parser for reliable multi-line & plural handling.
        if (!class_exists('PO')) {
            require_once ABSPATH . WPINC . '/pomo/po.php';
        }

        $po = new \PO();
        if (!$po->import_from_file($po_file)) {
            return 0;
        }

        $total      = 0;
        $translated = 0;

        foreach ($po->entries as $entry) {
            // Skip fuzzy entries in the count — they aren't served to users.
            if (!empty($entry->flags) && in_array('fuzzy', $entry->flags, true)) {
                $total++;
                continue;
            }

            $total++;

            // Check if at least the first translation form is non-empty.
            if (!empty($entry->translations[0])) {
                $translated++;
            }
        }

        if ($total === 0) {
            return 100; // Empty PO = nothing to translate.
        }

        return (int) round(($translated / $total) * 100);
    }

    /**
     * Allow WordPress to download from the translation server's host.
     *
     * WordPress's SSRF protection blocks HTTP requests to private/reserved
     * IPs. In development environments where the translation server resolves
     * to a LAN address, this filter marks the server's hostname as external
     * so download_url() and wp_safe_remote_get() succeed.
     *
     * In production this is a no-op since the server resolves to a public IP.
     *
     * @since 1.2.0
     * @param bool   $is_external Whether the host is external.
     * @param string $host        The hostname being checked.
     * @return bool True if the host matches the translation server.
     */
    public function allow_translation_server_host( bool $is_external, string $host ): bool {
        if ( $is_external ) {
            return true;
        }

        $server_host = wp_parse_url( SD_AI_LANG_PACKS_API_BASE, PHP_URL_HOST );
        if ( $host === $server_host ) {
            return true;
        }

        return false;
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
        return (bool) apply_filters('sd_ai_lang_packs_enabled', true);
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
        if (!wp_next_scheduled('sd_ai_lang_packs_request_user_locale', [$user_id])) {
            wp_schedule_single_event(time() + 5, 'sd_ai_lang_packs_request_user_locale', [$user_id]);
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
        $marker = 'sd_ai_lang_packs_user_locale_' . md5($locale);
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

        // Clean up only legacy AI translation files that used the old
        // non-standard -gratis-ai suffix. Current language packs use normal
        // WordPress filenames ({textdomain}-{locale}.mo), so deleting by age
        // would risk removing active or official translations.
        $files = glob($languages_dir . '/*-gratis-ai.mo');

        foreach ($files as $file) {
            $file_time = filemtime($file);

            // Delete files older than 30 days.
            if ($file_time && (time() - $file_time) > (30 * DAY_IN_SECONDS)) {
                wp_delete_file($file);

                // Also delete the .po file if it exists.
                $po_file = str_replace('.mo', '.po', $file);
                if (file_exists($po_file)) {
                    wp_delete_file($po_file);
                }
            }
        }
    }

}
