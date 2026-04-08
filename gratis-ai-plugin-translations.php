<?php
/**
 * Plugin Name: Gratis AI Plugin Translations
 * Plugin URI: https://translate.ultimatemultisite.com
 * Description: Automatically provides AI-generated translations for WordPress plugins when official translations are missing or incomplete from translate.wordpress.org.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * Author: Ultimate Multisite
 * Author URI: https://ultimatemultisite.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gratis-ai-plugin-translations
 * Domain Path: /languages
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
                        esc_html__('Gratis AI Plugin Translations requires PHP 8.0 or higher. You are running PHP %s.', 'gratis-ai-plugin-translations'),
                        esc_html(PHP_VERSION)
                    );
                ?></p>
            </div>
            <?php
        });
        return;
    }

    // Load translations.
    load_plugin_textdomain(
        'gratis-ai-plugin-translations',
        false,
        dirname(GRATIS_AI_PT_BASENAME) . '/languages/'
    );

    // Initialize components.
    Translation_Manager::instance()->init();
    Admin_Settings::instance()->init();

    // WP-CLI commands.
    if (defined('WP_CLI') && WP_CLI) {
        require_once GRATIS_AI_PT_DIR . 'src/class-cli.php';
        \WP_CLI::add_command('gratis-ai-translations', CLI::class);
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
    // Clear transients.
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '%_transient_gratis_ai_pt_%'");
    $wpdb->query("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '%_transient_timeout_gratis_ai_pt_%'");

    // Clean up options that aren't needed.
    delete_site_option('gratis_ai_pt_api_status');
}

register_deactivation_hook(GRATIS_AI_PT_FILE, __NAMESPACE__ . '\\deactivate');
