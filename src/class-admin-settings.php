<?php
/**
 * Admin Settings class
 *
 * Handles the plugin settings page and configuration options.
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
        // Add network admin menu (multisite) or regular admin menu.
        if (is_multisite()) {
            add_action('network_admin_menu', [$this, 'add_network_admin_menu']);
            add_action('network_admin_edit_gratis-ai-pt-settings', [$this, 'save_network_settings']);
        } else {
            add_action('admin_menu', [$this, 'add_admin_menu']);
        }

        // Add settings link to plugin page.
        add_filter(
            'network_admin_plugin_action_links_' . GRATIS_AI_PT_BASENAME,
            [$this, 'add_plugin_action_links']
        );
        add_filter(
            'plugin_action_links_' . GRATIS_AI_PT_BASENAME,
            [$this, 'add_plugin_action_links']
        );

        // Admin notices.
        add_action('admin_notices', [$this, 'display_admin_notices']);
        add_action('network_admin_notices', [$this, 'display_admin_notices']);

        // Register settings.
        add_action('admin_init', [$this, 'register_settings']);
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
            [$this, 'render_settings_page']
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
            [$this, 'render_settings_page']
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
        $settings_url = is_multisite()
            ? network_admin_url('settings.php?page=gratis-ai-plugin-translations')
            : admin_url('options-general.php?page=gratis-ai-plugin-translations');

        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url($settings_url),
            esc_html__('Settings', 'gratis-ai-plugin-translations')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Register settings.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_settings(): void {
        // No settings API registration needed for multisite.
        // We handle saving manually for network-wide consistency.
    }

    /**
     * Save network settings.
     *
     * @since 1.0.0
     * @return void
     */
    public function save_network_settings(): void {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('You do not have permission to save these settings.', 'gratis-ai-plugin-translations'));
        }

        check_admin_referer('gratis-ai-pt-settings');

        // Save settings.
        $enabled = isset($_POST['gratis_ai_pt_enabled']);
        $fill_incomplete = isset($_POST['gratis_ai_pt_fill_incomplete']);
        $api_base = isset($_POST['gratis_ai_pt_api_base'])
            ? sanitize_url(wp_unslash($_POST['gratis_ai_pt_api_base']))
            : GRATIS_AI_PT_API_BASE;

        $cache_duration = isset($_POST['gratis_ai_pt_cache_duration'])
            ? absint($_POST['gratis_ai_pt_cache_duration'])
            : 3600;

        update_site_option('gratis_ai_pt_enabled', $enabled);
        update_site_option('gratis_ai_pt_fill_incomplete', $fill_incomplete);
        update_site_option('gratis_ai_pt_api_base', $api_base);
        update_site_option('gratis_ai_pt_cache_duration', $cache_duration);

        // Clear cache.
        $this->api_client->clear_cache();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'    => 'gratis-ai-plugin-translations',
                    'updated' => 'true',
                ],
                network_admin_url('settings.php')
            )
        );
        exit;
    }

    /**
     * Render settings page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_settings_page(): void {
        // Get current settings.
        $enabled = get_site_option('gratis_ai_pt_enabled', true);
        $fill_incomplete = get_site_option('gratis_ai_pt_fill_incomplete', true);
        $api_base = get_site_option('gratis_ai_pt_api_base', GRATIS_AI_PT_API_BASE);
        $cache_duration = get_site_option('gratis_ai_pt_cache_duration', 3600);

        // Get API status.
        $api_status = $this->api_client->check_api_status();
        $api_healthy = !is_wp_error($api_status);

        // Get statistics.
        $stats = $this->get_translation_statistics();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (isset($_GET['updated']) && sanitize_text_field(wp_unslash($_GET['updated'])) === 'true') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved successfully.', 'gratis-ai-plugin-translations'); ?></p>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('API Status', 'gratis-ai-plugin-translations'); ?></h2>
                <p>
                    <?php if ($api_healthy) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <?php esc_html_e('Translation API is operational.', 'gratis-ai-plugin-translations'); ?>
                        <?php if (isset($api_status['version'])) : ?>
                            <?php
                            printf(
                                /* translators: %s: API version number */
                                esc_html__('Version: %s', 'gratis-ai-plugin-translations'),
                                esc_html($api_status['version'])
                            );
                            ?>
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                        <?php esc_html_e('Translation API is currently unavailable.', 'gratis-ai-plugin-translations'); ?>
                        <?php if (is_wp_error($api_status)) : ?>
                            <br>
                            <code><?php echo esc_html($api_status->get_error_message()); ?></code>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>

            <form method="post"
                action="<?php echo esc_url(is_multisite() ? 'edit.php?action=gratis-ai-pt-settings' : 'options.php'); ?>">
                <?php wp_nonce_field('gratis-ai-pt-settings'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <?php esc_html_e('Enable AI Translations', 'gratis-ai-plugin-translations'); ?>
                            </th>
                            <td>
                                <label for="gratis_ai_pt_enabled">
                                    <input type="checkbox" id="gratis_ai_pt_enabled"
                                        name="gratis_ai_pt_enabled" value="1"
                                        <?php checked($enabled); ?>>
                                    <?php esc_html_e('Automatically download AI translations when official translations are missing', 'gratis-ai-plugin-translations'); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <?php esc_html_e('Fill Incomplete Translations', 'gratis-ai-plugin-translations'); ?>
                            </th>
                            <td>
                                <label for="gratis_ai_pt_fill_incomplete">
                                    <input type="checkbox" id="gratis_ai_pt_fill_incomplete"
                                        name="gratis_ai_pt_fill_incomplete" value="1"
                                        <?php checked($fill_incomplete); ?>>
                                    <?php esc_html_e('Provide AI translations to fill gaps in incomplete official translations', 'gratis-ai-plugin-translations'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('If enabled, AI translations will be used for strings missing from official translations.', 'gratis-ai-plugin-translations'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <?php esc_html_e('API Base URL', 'gratis-ai-plugin-translations'); ?>
                            </th>
                            <td>
                                <input type="url" id="gratis_ai_pt_api_base"
                                    name="gratis_ai_pt_api_base"
                                    value="<?php echo esc_url($api_base); ?>"
                                    class="regular-text"
                                    placeholder="<?php echo esc_attr(GRATIS_AI_PT_API_BASE); ?>">
                                <p class="description">
                                    <?php esc_html_e('The base URL for the translation API. Leave empty to use the default.', 'gratis-ai-plugin-translations'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <?php esc_html_e('Cache Duration', 'gratis-ai-plugin-translations'); ?>
                            </th>
                            <td>
                                <select id="gratis_ai_pt_cache_duration"
                                    name="gratis_ai_pt_cache_duration">
                                    <option value="300" <?php selected($cache_duration, 300); ?>>
                                        <?php esc_html_e('5 minutes', 'gratis-ai-plugin-translations'); ?>
                                    </option>
                                    <option value="900" <?php selected($cache_duration, 900); ?>>
                                        <?php esc_html_e('15 minutes', 'gratis-ai-plugin-translations'); ?>
                                    </option>
                                    <option value="1800" <?php selected($cache_duration, 1800); ?>>
                                        <?php esc_html_e('30 minutes', 'gratis-ai-plugin-translations'); ?>
                                    </option>
                                    <option value="3600" <?php selected($cache_duration, 3600); ?>>
                                        <?php esc_html_e('1 hour', 'gratis-ai-plugin-translations'); ?>
                                    </option>
                                    <option value="7200" <?php selected($cache_duration, 7200); ?>>
                                        <?php esc_html_e('2 hours', 'gratis-ai-plugin-translations'); ?>
                                    </option>
                                    <option value="21600" <?php selected($cache_duration, 21600); ?>>
                                        <?php esc_html_e('6 hours', 'gratis-ai-plugin-translations'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('How long to cache translation availability checks.', 'gratis-ai-plugin-translations'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(); ?>
            </form>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Translation Statistics', 'gratis-ai-plugin-translations'); ?></h2>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><?php esc_html_e('Total AI Translations', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo esc_html($stats['total_translations']); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Plugins with AI Translations', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo esc_html($stats['plugins_count']); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Languages Covered', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo esc_html($stats['languages_count']); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Last Update Check', 'gratis-ai-plugin-translations'); ?></td>
                            <td><?php echo esc_html($stats['last_check']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('How It Works', 'gratis-ai-plugin-translations'); ?></h2>
                <ol>
                    <li><?php esc_html_e('When WordPress checks for plugin updates, this plugin checks if translations are needed.', 'gratis-ai-plugin-translations'); ?></li>
                    <li><?php esc_html_e('If a plugin lacks translations or has incomplete translations, it requests AI-generated translations from our server.', 'gratis-ai-plugin-translations'); ?></li>
                    <li><?php esc_html_e('The AI translations are generated on-demand using advanced language models.', 'gratis-ai-plugin-translations'); ?></li>
                    <li><?php esc_html_e('Translations are cached locally and only updated when new versions of plugins are released.', 'gratis-ai-plugin-translations'); ?></li>
                    <li><?php esc_html_e('If official translations from wordpress.org become available, they will take precedence over AI translations.', 'gratis-ai-plugin-translations'); ?></li>
                </ol>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Privacy Notice', 'gratis-ai-plugin-translations'); ?></h2>
                <p>
                    <?php esc_html_e('This plugin sends plugin metadata (name, version, textdomain) to our translation server to generate translations. No personal data or site content is transmitted. Translations are cached on your server.', 'gratis-ai-plugin-translations'); ?>
                </p>
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
        $last_check = get_site_option('gratis_ai_pt_last_check', null);

        if (is_dir($languages_dir)) {
            $files = glob($languages_dir . '/*-gratis-ai.mo');
            $total = count($files);

            foreach ($files as $file) {
                if (preg_match('/(.+)-([a-z]{2,3}(?:_[A-Z]{2})?)-gratis-ai\.mo$/', basename($file), $matches)) {
                    $plugins[] = $matches[1];
                    $languages[] = $matches[2];
                }
            }
        }

        return [
            'total_translations' => $total,
            'plugins_count'      => count(array_unique($plugins)),
            'languages_count'    => count(array_unique($languages)),
            'last_check'         => $last_check
                ? human_time_diff(strtotime($last_check), time()) . ' ' . __('ago', 'gratis-ai-plugin-translations')
                : __('Never', 'gratis-ai-plugin-translations'),
        ];
    }

    /**
     * Display admin notices.
     *
     * @since 1.0.0
     * @return void
     */
    public function display_admin_notices(): void {
        // Only show on update-core.php.
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'update-core-network' && $screen->id !== 'update-core') {
            return;
        }

        // Check if there are pending AI translations.
        $pending_count = get_site_option('gratis_ai_pt_pending_count', 0);

        if ($pending_count > 0) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php
                    printf(
                        /* translators: %d: Number of pending translations */
                        esc_html__('Gratis AI Plugin Translations: %d translation(s) are being generated and will be available shortly.', 'gratis-ai-plugin-translations'),
                        intval($pending_count)
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }
}
