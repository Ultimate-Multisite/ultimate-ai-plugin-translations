<?php
/**
 * Plugin Name: Superdav AI Language Packs
 * Plugin URI: https://github.com/Ultimate-Multisite/superdav-ai-language-packs
 * Description: Automatically provides AI-generated translations for WordPress plugins when official translations are missing or incomplete from translate.wordpress.org.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
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
define('SD_AI_LANG_PACKS_VERSION', '1.0.0');
define('SD_AI_LANG_PACKS_FILE', __FILE__);
define('SD_AI_LANG_PACKS_DIR', plugin_dir_path(__FILE__));
define('SD_AI_LANG_PACKS_URL', plugin_dir_url(__FILE__));
define('SD_AI_LANG_PACKS_BASENAME', plugin_basename(__FILE__));

// Default API endpoint.
if (!defined('SD_AI_LANG_PACKS_API_BASE')) {
    define('SD_AI_LANG_PACKS_API_BASE', 'https://translate.ultimatemultisite.com/wp-json/sd-ai-lang-pack/v1');
}

/**
 * Initialize the plugin.
 *
 * @return void
 */
function init(): void
{
    require_once SD_AI_LANG_PACKS_DIR . 'src/class-admin-settings.php';
    require_once SD_AI_LANG_PACKS_DIR . 'src/class-translation-api-client.php';
    require_once SD_AI_LANG_PACKS_DIR . 'src/class-translation-manager.php';
    // Initialize components.
    $api_client = new Translation_API_Client();

    (new Translation_Manager($api_client))->init();
    (new Admin_Settings($api_client))->init();
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
    if (!wp_next_scheduled('sd_ai_lang_packs_refresh_cache')) {
        wp_schedule_single_event(time() + 5, 'sd_ai_lang_packs_refresh_cache');
    }
}

register_activation_hook(SD_AI_LANG_PACKS_FILE, __NAMESPACE__ . '\\activate');

/**
 * Deactivation hook.
 *
 * @return void
 */
function deactivate(): void
{
    // Clear known transients.
    delete_site_transient('sd_ai_lang_packs_translations_cache');
    delete_site_transient('sd_ai_lang_packs_pending_count');
    delete_site_transient('sd_ai_lang_packs_api_status');

    $cache_keys = get_site_option('sd_ai_lang_packs_cache_keys', []);
    if (is_array($cache_keys)) {
        foreach ($cache_keys as $cache_key) {
            if (is_string($cache_key) && 0 === strpos($cache_key, 'sd_ai_lang_packs_')) {
                delete_site_transient($cache_key);
            }
        }
    }

    // Clean up options that aren't needed.
    delete_site_option('sd_ai_lang_packs_cache_keys');
    delete_site_option('sd_ai_lang_packs_api_status');
    delete_site_option('sd_ai_lang_packs_refresh_state');
    delete_site_option('sd_ai_lang_packs_last_check');
    delete_site_option('sd_ai_lang_packs_plugins_checked');
    delete_site_option('sd_ai_lang_packs_pending_count');
    delete_site_option('sd_ai_lang_packs_available_count');
    delete_site_option('sd_ai_lang_packs_installed_translations');

    wp_clear_scheduled_hook('sd_ai_lang_packs_refresh_cache');
    wp_clear_scheduled_hook('sd_ai_lang_packs_cleanup_old_translations');
}

register_deactivation_hook(SD_AI_LANG_PACKS_FILE, __NAMESPACE__ . '\\deactivate');
