<?php
/**
 * Admin Settings class
 *
 * Renders a read-only status page for the plugin. All behaviour is
 * configured via filters (see README / SERVER-API.md) — this page
 * never writes options.
 *
 * @package GratisAIPluginTranslations
 */

declare(strict_types=1);

namespace GratisAIPluginTranslations;

/**
 * Admin Settings class.
 *
 * @since 1.0.0
 */
class Admin_Settings {

    /**
     * Instance of this class.
     *
     * @since 1.0.0
     * @var self|null
     */
    private static ?self $instance = null;

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
        if (is_multisite()) {
            add_action('network_admin_menu', [$this, 'add_network_admin_menu']);
        } else {
            add_action('admin_menu', [$this, 'add_admin_menu']);
        }

        add_filter(
            'network_admin_plugin_action_links_' . GRATIS_AI_PT_BASENAME,
            [$this, 'add_plugin_action_links']
        );
        add_filter(
            'plugin_action_links_' . GRATIS_AI_PT_BASENAME,
            [$this, 'add_plugin_action_links']
        );

        add_action('admin_notices', [$this, 'display_admin_notices']);
        add_action('network_admin_notices', [$this, 'display_admin_notices']);

        // "Refresh now" action handler.
        add_action('admin_post_gratis_ai_pt_refresh', [$this, 'handle_refresh_action']);
    }

    /**
     * Handle the "Refresh now" button submission.
     *
     * Schedules an immediate async refresh and redirects back to the
     * status page. We don't run the refresh inline — it makes slow
     * HTTP calls to wp.org and the translation server and would block
     * the admin request.
     *
     * @since 1.0.0
     * @return void
     */
    public function handle_refresh_action(): void {
        $cap = is_multisite() ? 'manage_network_options' : 'manage_options';
        if (!current_user_can($cap)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'gratis-ai-plugin-translations'));
        }
        check_admin_referer('gratis_ai_pt_refresh');

        // Clear the cached results so the next refresh recomputes fully.
        delete_site_transient('gratis_ai_pt_translations_cache');

        // Schedule the async refresh (replace any existing).
        wp_clear_scheduled_hook('gratis_ai_pt_refresh_cache');
        wp_schedule_single_event(time() + 1, 'gratis_ai_pt_refresh_cache');

        // Nudge WP Cron so the event runs in the background instead of
        // waiting for the next page load. Non-blocking.
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }

        $redirect = is_multisite()
            ? network_admin_url('settings.php?page=gratis-ai-plugin-translations&refreshed=1')
            : admin_url('options-general.php?page=gratis-ai-plugin-translations&refreshed=1');
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Add network admin menu.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_network_admin_menu(): void {
        add_submenu_page(
            'settings.php',
            __('Gratis AI Translations', 'gratis-ai-plugin-translations'),
            __('AI Translations', 'gratis-ai-plugin-translations'),
            'manage_network_options',
            'gratis-ai-plugin-translations',
            [$this, 'render_status_page']
        );
    }

    /**
     * Add admin menu.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_admin_menu(): void {
        add_options_page(
            __('Gratis AI Translations', 'gratis-ai-plugin-translations'),
            __('AI Translations', 'gratis-ai-plugin-translations'),
            'manage_options',
            'gratis-ai-plugin-translations',
            [$this, 'render_status_page']
        );
    }

    /**
     * Add plugin action links.
     *
     * @since 1.0.0
     * @param array $links Existing action links.
     * @return array Modified action links.
     */
    public function add_plugin_action_links(array $links): array {
        $url = is_multisite()
            ? network_admin_url('settings.php?page=gratis-ai-plugin-translations')
            : admin_url('options-general.php?page=gratis-ai-plugin-translations');

        $link = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('Status', 'gratis-ai-plugin-translations')
        );

        array_unshift($links, $link);
        return $links;
    }

    /**
     * Render the read-only status page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_status_page(): void {
        $api_status = $this->api_client->check_api_status();
        $api_healthy = !is_wp_error($api_status);

        $stats = $this->get_translation_statistics();

        /** @var string $api_base Current API base (filterable). */
        $api_base = (string) apply_filters('gratis_ai_pt_api_base', GRATIS_AI_PT_API_BASE);
        $enabled = (bool) apply_filters('gratis_ai_pt_enabled', true);
        $fill_incomplete = (bool) apply_filters('gratis_ai_pt_fill_incomplete', true);
        $cache_duration = (int) apply_filters('gratis_ai_pt_cache_duration', HOUR_IN_SECONDS);

        $action_url = admin_url('admin-post.php');
        $just_refreshed = isset($_GET['refreshed']) && '1' === $_GET['refreshed'];
        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html(get_admin_page_title()); ?>
                <form method="post" action="<?php echo esc_url($action_url); ?>" style="display:inline-block;margin-left:12px;">
                    <?php wp_nonce_field('gratis_ai_pt_refresh'); ?>
                    <input type="hidden" name="action" value="gratis_ai_pt_refresh">
                    <button type="submit" class="page-title-action">
                        <?php esc_html_e('Refresh now', 'gratis-ai-plugin-translations'); ?>
                    </button>
                </form>
            </h1>

            <?php if ($just_refreshed) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Refresh scheduled. Results will appear here within a few seconds — reload the page to see the updated status.', 'gratis-ai-plugin-translations'); ?></p>
                </div>
            <?php endif; ?>

            <p class="description">
                <?php esc_html_e('This plugin is activate-and-forget. It runs automatically in the background — there is nothing to configure here. Developers can adjust behaviour via filters (see the plugin readme).', 'gratis-ai-plugin-translations'); ?>
            </p>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('API Status', 'gratis-ai-plugin-translations'); ?></h2>
                <p>
                    <?php if ($api_healthy) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <?php esc_html_e('Translation API is operational.', 'gratis-ai-plugin-translations'); ?>
                        <?php if (is_array($api_status) && isset($api_status['version'])) : ?>
                            <?php
                            printf(
                                /* translators: %s: API version number */
                                esc_html__('Version: %s', 'gratis-ai-plugin-translations'),
                                esc_html((string) $api_status['version'])
                            );
                            ?>
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                        <?php esc_html_e('Translation API is currently unavailable.', 'gratis-ai-plugin-translations'); ?>
                        <?php if (is_wp_error($api_status)) : ?>
                            <br><code><?php echo esc_html($api_status->get_error_message()); ?></code>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
                <p><small><?php
                    /* translators: %s: API base URL */
                    printf(esc_html__('Endpoint: %s', 'gratis-ai-plugin-translations'), '<code>' . esc_html($api_base) . '</code>');
                ?></small></p>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Status', 'gratis-ai-plugin-translations'); ?></h2>
                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <td><?php esc_html_e('Enabled', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo $enabled ? esc_html__('Yes', 'gratis-ai-plugin-translations') : esc_html__('No (disabled via filter)', 'gratis-ai-plugin-translations'); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Fill incomplete official translations', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo $fill_incomplete ? esc_html__('Yes', 'gratis-ai-plugin-translations') : esc_html__('No', 'gratis-ai-plugin-translations'); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Cache duration', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo esc_html(human_time_diff(0, $cache_duration)); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Last update check', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo esc_html($stats['last_check']); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Plugins checked', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo esc_html((string) $stats['plugins_checked']); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Translations pending generation', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo esc_html((string) $stats['pending_count']); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Translations available', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo esc_html((string) $stats['available_count']); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Local AI translation files', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo esc_html((string) $stats['total_translations']); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Plugins with local AI translations', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo esc_html((string) $stats['plugins_count']); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Languages covered locally', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo esc_html((string) $stats['languages_count']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('How It Works', 'gratis-ai-plugin-translations'); ?></h2>
                <ol>
                    <li><?php esc_html_e('When WordPress checks for plugin updates, this plugin checks if translations are needed.', 'gratis-ai-plugin-translations'); ?></li>
                    <li><?php esc_html_e('If a plugin lacks translations or has incomplete translations, it requests AI-generated translations from the server.', 'gratis-ai-plugin-translations'); ?></li>
                    <li><?php esc_html_e('The AI translations are generated on-demand using advanced language models.', 'gratis-ai-plugin-translations'); ?></li>
                    <li><?php esc_html_e('Translations are cached locally and only updated when new versions of plugins are released.', 'gratis-ai-plugin-translations'); ?></li>
                    <li><?php esc_html_e('If official translations from wordpress.org become available, they will take precedence over AI translations.', 'gratis-ai-plugin-translations'); ?></li>
                </ol>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Privacy Notice', 'gratis-ai-plugin-translations'); ?></h2>
                <p><?php esc_html_e('This plugin sends the following data to the translation server for each refresh request: plugin metadata (name, version, textdomain), the site URL, and the WordPress version. No personal data or site content is transmitted. Translations are cached on your server.', 'gratis-ai-plugin-translations'); ?></p>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Developer Filters', 'gratis-ai-plugin-translations'); ?></h2>
                <p><?php esc_html_e('All behaviour is adjustable via filters. Drop the following into an mu-plugin as needed:', 'gratis-ai-plugin-translations'); ?></p>
                <pre style="background:#f6f7f7;padding:12px;overflow:auto;"><code>add_filter( 'gratis_ai_pt_enabled',          '__return_true' );
add_filter( 'gratis_ai_pt_fill_incomplete',  '__return_true' );
add_filter( 'gratis_ai_pt_api_base',         function () { return 'https://your.server/wp-json/gratis-ai-translations/v1'; } );
add_filter( 'gratis_ai_pt_cache_duration',   function () { return HOUR_IN_SECONDS; } );</code></pre>
            </div>
        </div>
        <?php
    }

    /**
     * Get translation statistics.
     *
     * @since 1.0.0
     * @return array Statistics array.
     */
    private function get_translation_statistics(): array {
        $languages_dir = WP_CONTENT_DIR . '/languages/plugins';
        $total = 0;
        $plugins = [];
        $languages = [];

        if (is_dir($languages_dir)) {
            $files = glob($languages_dir . '/*-gratis-ai.mo') ?: [];
            $total = count($files);

            foreach ($files as $file) {
                if (preg_match('/(.+)-([a-z]{2,3}(?:_[A-Z]{2})?)-gratis-ai\.mo$/', basename($file), $matches)) {
                    $plugins[] = $matches[1];
                    $languages[] = $matches[2];
                }
            }
        }

        $last_check_raw = get_site_option('gratis_ai_pt_last_check', null);

        return [
            'total_translations' => $total,
            'plugins_count'      => count(array_unique($plugins)),
            'languages_count'    => count(array_unique($languages)),
            'plugins_checked'    => (int) get_site_option('gratis_ai_pt_plugins_checked', 0),
            'pending_count'      => (int) get_site_option('gratis_ai_pt_pending_count', 0),
            'available_count'    => (int) get_site_option('gratis_ai_pt_available_count', 0),
            'last_check'         => $last_check_raw
                ? human_time_diff((int) strtotime((string) $last_check_raw), time()) . ' ' . __('ago', 'gratis-ai-plugin-translations')
                : __('Never', 'gratis-ai-plugin-translations'),
        ];
    }

    /**
     * Display admin notices on update-core pages.
     *
     * @since 1.0.0
     * @return void
     */
    public function display_admin_notices(): void {
        $screen = get_current_screen();
        if (!$screen || ($screen->id !== 'update-core-network' && $screen->id !== 'update-core')) {
            return;
        }

        $pending_count = (int) get_site_option('gratis_ai_pt_pending_count', 0);
        if ($pending_count <= 0) {
            return;
        }
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: %d: Number of pending translations */
                    esc_html__('Gratis AI Plugin Translations: %d translation(s) are being generated and will be available shortly.', 'gratis-ai-plugin-translations'),
                    $pending_count
                );
                ?>
            </p>
        </div>
        <?php
    }
}
