<?php
/**
 * WP-CLI Commands for Gratis AI Plugin Translations
 *
 * @package GratisAIPluginTranslations
 */

declare(strict_types=1);

namespace GratisAIPluginTranslations;

/**
 * WP-CLI commands class.
 *
 * @since 1.0.0
 */
class CLI {

    /**
     * API client instance.
     *
     * @since 1.0.0
     * @var Translation_API_Client|null
     */
    private ?Translation_API_Client $api_client = null;

    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->api_client = new Translation_API_Client();
    }

    /**
     * Check the status of the translation API.
     *
     * ## OPTIONS
     *
     * [--verbose]
     * : Show detailed information.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-translations status
     *     wp gratis-ai-translations status --verbose
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function status(array $args, array $assoc_args): void {
        $verbose = isset($assoc_args['verbose']);

        \WP_CLI::log('Checking translation API status...');

        $status = $this->api_client->check_api_status();

        if (is_wp_error($status)) {
            \WP_CLI::error($status->get_error_message());
            return;
        }

        \WP_CLI::success('Translation API is operational');

        if ($verbose && is_array($status)) {
            foreach ($status as $key => $value) {
                if (is_array($value)) {
                    \WP_CLI::log("{$key}:");
                    foreach ($value as $k => $v) {
                        \WP_CLI::log("  - {$k}: {$v}");
                    }
                } else {
                    \WP_CLI::log("{$key}: {$value}");
                }
            }
        }
    }

    /**
     * Check for available translations for a specific plugin.
     *
     * ## OPTIONS
     *
     * <plugin>
     * : The plugin textdomain or file path.
     *
     * [--locale=<locale>]
     * : Specific locale to check (defaults to site locale).
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-translations check woocommerce
     *     wp gratis-ai-translations check woocommerce --locale=es_ES
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function check(array $args, array $assoc_args): void {
        $plugin_identifier = $args[0];
        $locale = $assoc_args['locale'] ?? get_locale();

        // Find plugin file.
        $plugin_file = $this->find_plugin_file($plugin_identifier);

        if (!$plugin_file) {
            \WP_CLI::error("Plugin not found: {$plugin_identifier}");
            return;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
        $textdomain = $plugin_data['TextDomain'] ?: dirname($plugin_file);
        $version = $plugin_data['Version'] ?: '1.0.0';

        \WP_CLI::log("Checking translations for: {$plugin_data['Name']}");
        \WP_CLI::log("Textdomain: {$textdomain}");
        \WP_CLI::log("Version: {$version}");
        \WP_CLI::log("Locale: {$locale}");

        $response = $this->api_client->batch_check_translations(
            [['textdomain' => $textdomain, 'version' => $version]],
            [$locale]
        );

        if (is_wp_error($response)) {
            \WP_CLI::error($response->get_error_message());
            return;
        }

        $entry = $response['results'][$textdomain][$locale] ?? null;
        if ($entry && !empty($entry['package_url'])) {
            \WP_CLI::success("AI translation is available for {$locale}");
            \WP_CLI::log("Package URL: {$entry['package_url']}");
            \WP_CLI::log('Updated: ' . ($entry['updated'] ?? 'unknown'));
            return;
        }

        if ($entry && isset($entry['status'])) {
            \WP_CLI::log("Translation status: {$entry['status']}");
            if (isset($entry['queue_position'])) {
                \WP_CLI::log("Queue position: {$entry['queue_position']}");
            }
            return;
        }

        if (!empty($response['queued'])) {
            \WP_CLI::success("Translation generation queued for {$locale}.");
            return;
        }

        \WP_CLI::warning("No AI translation available for {$locale}");
    }

    /**
     * Request translation generation for a plugin.
     *
     * ## OPTIONS
     *
     * <plugin>
     * : The plugin textdomain or file path.
     *
     * [--locale=<locale>]
     * : Specific locale to request (defaults to all site locales).
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-translations request woocommerce
     *     wp gratis-ai-translations request woocommerce --locale=de_DE
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function request(array $args, array $assoc_args): void {
        $plugin_identifier = $args[0];
        $locale = $assoc_args['locale'] ?? null;

        // Find plugin file.
        $plugin_file = $this->find_plugin_file($plugin_identifier);

        if (!$plugin_file) {
            \WP_CLI::error("Plugin not found: {$plugin_identifier}");
            return;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
        $textdomain = $plugin_data['TextDomain'] ?: dirname($plugin_file);
        $version = $plugin_data['Version'] ?: '1.0.0';

        $locales = $locale ? [$locale] : $this->get_site_locales();

        \WP_CLI::log("Requesting translations for: {$plugin_data['Name']}");
        \WP_CLI::log("Textdomain: {$textdomain}");
        \WP_CLI::log("Version: {$version}");
        \WP_CLI::log("Locales: " . implode(', ', $locales));

        $response = $this->api_client->batch_check_translations(
            [['textdomain' => $textdomain, 'version' => $version]],
            $locales
        );

        if (is_wp_error($response)) {
            \WP_CLI::error($response->get_error_message());
            return;
        }

        $queued = count($response['queued'] ?? []);
        \WP_CLI::success(sprintf('Translation generation requested (%d locale(s) queued).', $queued));
    }

    /**
     * List plugins that have AI translations available.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Accepts: table, json, csv, yaml. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-translations list
     *     wp gratis-ai-translations list --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function list(array $args, array $assoc_args): void {
        $format = $assoc_args['format'] ?? 'table';

        $languages_dir = WP_CONTENT_DIR . '/languages/plugins';

        if (!is_dir($languages_dir)) {
            \WP_CLI::warning('No translations directory found');
            return;
        }

        $files = glob($languages_dir . '/*-gratis-ai.mo');

        if (empty($files)) {
            \WP_CLI::log('No AI translations found');
            return;
        }

        $translations = [];

        foreach ($files as $file) {
            if (preg_match('/(.+)-([a-z]{2,3}(?:_[A-Z]{2})?)-gratis-ai\.mo$/', basename($file), $matches)) {
                $translations[] = [
                    'plugin'   => $matches[1],
                    'locale'   => $matches[2],
                    'modified' => date('Y-m-d H:i:s', filemtime($file)),
                    'size'     => size_format(filesize($file)),
                ];
            }
        }

        $formatter = new \WP_CLI\Formatter(
            $assoc_args,
            ['plugin', 'locale', 'modified', 'size'],
            'translations'
        );

        $formatter->display_items($translations);
    }

    /**
     * Clear the translation cache.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-translations clear-cache
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function clear_cache(array $args, array $assoc_args): void {
        \WP_CLI::log('Clearing translation cache...');

        $this->api_client->clear_cache();

        // Also clear WordPress translation cache.
        delete_site_transient('gratis_ai_pt_api_status');

        \WP_CLI::success('Translation cache cleared');
    }

    /**
     * Get translation status for a plugin and locale.
     *
     * ## OPTIONS
     *
     * <plugin>
     * : The plugin textdomain or file path.
     *
     * <locale>
     * : The locale code.
     *
     * ## EXAMPLES
     *
     *     wp gratis-ai-translations status-plugin woocommerce de_DE
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function status_plugin(array $args, array $assoc_args): void {
        $plugin_identifier = $args[0];
        $locale = $args[1];

        // Find plugin file.
        $plugin_file = $this->find_plugin_file($plugin_identifier);

        if (!$plugin_file) {
            \WP_CLI::error("Plugin not found: {$plugin_identifier}");
            return;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
        $textdomain = $plugin_data['TextDomain'] ?: dirname($plugin_file);
        $version = $plugin_data['Version'] ?: '1.0.0';

        $status = $this->api_client->get_translation_status($textdomain, $version, $locale);

        if (is_wp_error($status)) {
            \WP_CLI::error($status->get_error_message());
            return;
        }

        \WP_CLI::log("Translation status for {$plugin_data['Name']} ({$locale}):");

        foreach ($status as $key => $value) {
            if (is_array($value)) {
                \WP_CLI::log("{$key}:");
                foreach ($value as $k => $v) {
                    \WP_CLI::log("  - {$k}: {$v}");
                }
            } else {
                \WP_CLI::log("{$key}: {$value}");
            }
        }
    }

    /**
     * Find plugin file from identifier.
     *
     * @since 1.0.0
     * @param string $identifier Plugin textdomain or file path.
     * @return string|null Plugin file path or null.
     */
    private function find_plugin_file(string $identifier): ?string {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        // First, try exact match.
        if (isset($plugins[$identifier])) {
            return $identifier;
        }

        // Try matching by textdomain or slug.
        foreach ($plugins as $plugin_file => $plugin_data) {
            $plugin_file_str = (string) $plugin_file;
            $slug = dirname($plugin_file_str);
            if ('.' === $slug) {
                $slug = basename($plugin_file_str, '.php');
            }

            if ($slug === $identifier || $plugin_data['TextDomain'] === $identifier) {
                return $plugin_file_str;
            }
        }

        return null;
    }

    /**
     * Get all site locales.
     *
     * @since 1.0.0
     * @return array Array of locale codes.
     */
    private function get_site_locales(): array {
        $locales = [get_locale()];

        if (is_multisite()) {
            global $wpdb;
            $site_locales = $wpdb->get_col("SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = 'WPLANG'");
            $locales = array_merge($locales, $site_locales);
        }

        $locales = array_filter(array_unique($locales));
        $locales = array_diff($locales, ['en_US', 'en']);

        return empty($locales) ? [get_locale()] : $locales;
    }
}
