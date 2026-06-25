<?php
/**
 * Plugin Name: Superdav AI Language Packs
 * Plugin URI: https://translate.ultimatemultisite.com
 * Description: AI-generated language packs for installed WordPress extensions when official translations are missing or incomplete.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * Author: Ultimate Multisite
 * Author URI: https://ultimatemultisite.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: superdav-ai-language-packs
 * Network: true
 *
 * @package GratisAIPluginTranslations
 */

declare(strict_types=1);

namespace GratisAIPluginTranslations;

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants.
define('GRATIS_AI_PT_VERSION', '1.0.0');
define('GRATIS_AI_PT_FILE', __FILE__);
define('GRATIS_AI_PT_DIR', plugin_dir_path(__FILE__));
define('GRATIS_AI_PT_URL', plugin_dir_url(__FILE__));
define('GRATIS_AI_PT_BASENAME', plugin_basename(__FILE__));

// Default API endpoint.
if (!defined('GRATIS_AI_PT_API_BASE')) {
    define('GRATIS_AI_PT_API_BASE', 'https://translate.ultimatemultisite.com/wp-json/gratis-ai-translations/v1');
}

// Autoloader.
spl_autoload_register(function ($class) {
    $prefix = __NAMESPACE__ . '\\';
    $base_dir = GRATIS_AI_PT_DIR . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . str_replace('\\', '/', strtolower(str_replace('_', '-', $relative_class))) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Initialize the plugin.
 *
 * @return void
 */
function init(): void
{
    // Check PHP version.
    if (version_compare(PHP_VERSION, '8.0', '<')) {
        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-error">
                <p><?php
                    printf(
                        /* translators: %s: Current PHP version. */
                        esc_html__('Superdav AI Language Packs requires PHP 8.0 or higher. You are running PHP %s.', 'superdav-ai-language-packs'),
                        esc_html(PHP_VERSION)
                    );
                ?></p>
            </div>
            <?php
        });
        return;
    }

    // Initialize components.
    Translation_Manager::instance()->init();
    Admin_Settings::instance()->init();

    // WP-CLI commands.
    if (defined('WP_CLI') && WP_CLI) {
        require_once GRATIS_AI_PT_DIR . 'src/class-cli.php';
        \WP_CLI::add_command('superdav-ai-language-packs', CLI::class);
    }
}

add_action('plugins_loaded', __NAMESPACE__ . '\\init', 20);

/**
 * Activation hook.
 *
 * Schedules an immediate async cache refresh so translation generation
 * starts right away instead of waiting for WordPress's next update-check
 * cycle (~12h). The handler is registered by Translation_Manager::init()
 * on plugins_loaded.
 *
 * @return void
 */
function activate(): void
{
    if (!wp_next_scheduled('gratis_ai_pt_refresh_cache')) {
        wp_schedule_single_event(time() + 5, 'gratis_ai_pt_refresh_cache');
    }
}

register_activation_hook(GRATIS_AI_PT_FILE, __NAMESPACE__ . '\\activate');

/**
 * Deactivation hook.
 *
 * @return void
 */
function deactivate(): void
{
    // Clear known transients.
    delete_site_transient('gratis_ai_pt_translations_cache');
    delete_site_transient('gratis_ai_pt_pending_count');
    delete_site_transient('gratis_ai_pt_api_status');

    $cache_keys = get_site_option('gratis_ai_pt_cache_keys', []);
    if (is_array($cache_keys)) {
        foreach ($cache_keys as $cache_key) {
            if (is_string($cache_key) && 0 === strpos($cache_key, 'gratis_ai_pt_')) {
                delete_site_transient($cache_key);
            }
        }
    }

    // Clean up options that aren't needed.
    delete_site_option('gratis_ai_pt_cache_keys');
    delete_site_option('gratis_ai_pt_api_status');
    delete_site_option('gratis_ai_pt_refresh_state');
    delete_site_option('gratis_ai_pt_last_check');
    delete_site_option('gratis_ai_pt_plugins_checked');
    delete_site_option('gratis_ai_pt_pending_count');
    delete_site_option('gratis_ai_pt_available_count');

    wp_clear_scheduled_hook('gratis_ai_pt_refresh_cache');
    wp_clear_scheduled_hook('gratis_ai_pt_cleanup_old_translations');
}

register_deactivation_hook(GRATIS_AI_PT_FILE, __NAMESPACE__ . '\\deactivate');
