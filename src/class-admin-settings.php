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
		if ( null === self::$instance ) {
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
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', [ $this, 'add_network_admin_menu' ] );
		} else {
			add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		}

		add_filter(
			'network_admin_plugin_action_links_' . GRATIS_AI_PT_BASENAME,
			[ $this, 'add_plugin_action_links' ]
		);
		add_filter(
			'plugin_action_links_' . GRATIS_AI_PT_BASENAME,
			[ $this, 'add_plugin_action_links' ]
		);

		add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
		add_action( 'network_admin_notices', [ $this, 'display_admin_notices' ] );

		// "Check for updates now" action handler.
		add_action( 'admin_post_gratis_ai_pt_refresh', [ $this, 'handle_refresh_action' ] );
	}

	/**
	 * Handle the "Check for updates now" button submission.
	 *
	 * Runs a fresh WordPress plugin update check (same as the core Updates
	 * page) then schedules an immediate async AI translation refresh.
	 *
	 * The update check is synchronous — it calls out to api.wordpress.org
	 * and takes a few seconds — but that is expected here, identical to
	 * what WordPress itself does when you click "Check Again" on the
	 * Updates screen. The AI translation refresh is kept async via cron
	 * because it queries every plugin against the translation server.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_refresh_action(): void {
		$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'gratis-ai-plugin-translations' ) );
		}
		check_admin_referer( 'gratis_ai_pt_refresh' );

		// Force a fresh WordPress plugin update check (mirrors the core
		// "Check Again" button on the Updates page).
		delete_site_transient( 'update_plugins' );
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		wp_update_plugins();

		// Clear the cached AI translation results so the next refresh
		// recomputes fully against the fresh update-check data.
		delete_site_transient( 'gratis_ai_pt_translations_cache' );

		// Schedule the async AI refresh (replace any existing).
		wp_clear_scheduled_hook( 'gratis_ai_pt_refresh_cache' );
		wp_schedule_single_event( time() + 1, 'gratis_ai_pt_refresh_cache' );

		// Nudge WP Cron so the event runs in the background instead of
		// waiting for the next page load. Non-blocking.
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		$redirect = is_multisite()
			? network_admin_url( 'settings.php?page=gratis-ai-plugin-translations&refreshed=1' )
			: admin_url( 'options-general.php?page=gratis-ai-plugin-translations&refreshed=1' );
		wp_safe_redirect( $redirect );
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
			__( 'Gratis AI Translations', 'gratis-ai-plugin-translations' ),
			__( 'AI Translations', 'gratis-ai-plugin-translations' ),
			'manage_network_options',
			'gratis-ai-plugin-translations',
			[ $this, 'render_status_page' ]
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
			__( 'Gratis AI Translations', 'gratis-ai-plugin-translations' ),
			__( 'AI Translations', 'gratis-ai-plugin-translations' ),
			'manage_options',
			'gratis-ai-plugin-translations',
			[ $this, 'render_status_page' ]
		);
	}

	/**
	 * Add plugin action links.
	 *
	 * @since 1.0.0
	 * @param array $links Existing action links.
	 * @return array Modified action links.
	 */
	public function add_plugin_action_links( array $links ): array {
		$url = is_multisite()
			? network_admin_url( 'settings.php?page=gratis-ai-plugin-translations' )
			: admin_url( 'options-general.php?page=gratis-ai-plugin-translations' );

		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Status', 'gratis-ai-plugin-translations' )
		);

		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Render the status page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_status_page(): void {
		$api_status   = $this->api_client->check_api_status();
		$api_healthy  = ! is_wp_error( $api_status );
		$stats        = $this->get_translation_statistics();
		$local        = $this->get_local_translation_details();
		$plugin_names = $this->get_plugin_name_map();
		$pending      = (int) $stats['pending_count'];
		$action_url   = admin_url( 'admin-post.php' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$just_refreshed = isset( $_GET['refreshed'] ) && '1' === $_GET['refreshed'];
		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html( get_admin_page_title() ); ?>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline-block;margin-left:12px;">
					<?php wp_nonce_field( 'gratis_ai_pt_refresh' ); ?>
					<input type="hidden" name="action" value="gratis_ai_pt_refresh">
					<button type="submit" class="page-title-action">
						<?php esc_html_e( 'Check for updates now', 'gratis-ai-plugin-translations' ); ?>
					</button>
				</form>
			</h1>

			<?php if ( $just_refreshed ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Update check complete. AI translation refresh is running in the background — reload this page in a few seconds to see the latest status.', 'gratis-ai-plugin-translations' ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description" style="max-width:800px;font-size:14px;margin-bottom:20px;">
				<?php esc_html_e( 'Your plugins are being translated automatically by AI whenever official translations are missing or incomplete. There is nothing to configure — translations are requested and downloaded in the background. Use this page to check progress.', 'gratis-ai-plugin-translations' ); ?>
			</p>

			<div class="card" style="max-width:800px;margin-top:20px;padding:12px 20px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Service Status', 'gratis-ai-plugin-translations' ); ?></h2>
				<?php if ( $api_healthy ) : ?>
					<p style="margin:0;">
						<span class="dashicons dashicons-yes-alt" style="color:#00a32a;vertical-align:middle;"></span>
						<?php esc_html_e( 'Translation service is online and ready.', 'gratis-ai-plugin-translations' ); ?>
					</p>
				<?php else : ?>
					<p style="margin:0;">
						<span class="dashicons dashicons-warning" style="color:#d63638;vertical-align:middle;"></span>
						<?php esc_html_e( 'Translation service is currently unavailable. Translations will resume automatically when the service recovers.', 'gratis-ai-plugin-translations' ); ?>
					</p>
					<?php if ( is_wp_error( $api_status ) ) : ?>
						<p><code><?php echo esc_html( $api_status->get_error_message() ); ?></code></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="card" style="max-width:800px;margin-top:20px;padding:12px 20px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Translation Progress', 'gratis-ai-plugin-translations' ); ?></h2>

				<div style="display:flex;gap:32px;margin-bottom:20px;flex-wrap:wrap;">
					<div style="text-align:center;">
						<div style="font-size:28px;font-weight:600;line-height:1.1;"><?php echo esc_html( number_format_i18n( count( $local ) ) ); ?></div>
						<div style="color:#646970;font-size:13px;margin-top:2px;"><?php esc_html_e( 'translations active', 'gratis-ai-plugin-translations' ); ?></div>
					</div>
					<div style="text-align:center;">
						<div style="font-size:28px;font-weight:600;line-height:1.1;"><?php echo esc_html( number_format_i18n( (int) $stats['plugins_count'] ) ); ?></div>
						<div style="color:#646970;font-size:13px;margin-top:2px;"><?php esc_html_e( 'plugins covered', 'gratis-ai-plugin-translations' ); ?></div>
					</div>
					<div style="text-align:center;">
						<div style="font-size:28px;font-weight:600;line-height:1.1;"><?php echo esc_html( number_format_i18n( (int) $stats['languages_count'] ) ); ?></div>
						<div style="color:#646970;font-size:13px;margin-top:2px;"><?php esc_html_e( 'languages', 'gratis-ai-plugin-translations' ); ?></div>
					</div>
					<?php if ( $pending > 0 ) : ?>
						<div style="text-align:center;">
							<div style="font-size:28px;font-weight:600;line-height:1.1;color:#2271b1;"><?php echo esc_html( number_format_i18n( $pending ) ); ?></div>
							<div style="color:#646970;font-size:13px;margin-top:2px;"><?php esc_html_e( 'queued', 'gratis-ai-plugin-translations' ); ?></div>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( $pending > 0 ) : ?>
					<div class="notice notice-info inline" style="margin:0 0 16px;padding:8px 12px;">
						<p style="margin:0;">
							<?php
							printf(
								/* translators: %d: Number of queued translations */
								esc_html(
									_n(
										'%d translation is being generated by AI and will download automatically when ready.',
										'%d translations are being generated by AI and will download automatically when ready.',
										$pending,
										'gratis-ai-plugin-translations'
									)
								),
								$pending
							);
							echo ' ';
							esc_html_e( 'No action needed.', 'gratis-ai-plugin-translations' );
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $local ) ) : ?>
					<table class="widefat striped" style="margin-top:4px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Plugin', 'gratis-ai-plugin-translations' ); ?></th>
								<th><?php esc_html_e( 'Language', 'gratis-ai-plugin-translations' ); ?></th>
								<th style="text-align:right;"><?php esc_html_e( 'Strings translated', 'gratis-ai-plugin-translations' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $local as $item ) : ?>
								<tr>
									<td><?php echo esc_html( $plugin_names[ $item['textdomain'] ] ?? $item['textdomain'] ); ?></td>
									<td><?php echo esc_html( $item['locale'] ); ?></td>
									<td style="text-align:right;"><?php echo esc_html( number_format_i18n( $item['strings'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php elseif ( 0 === $pending ) : ?>
					<p style="color:#646970;font-style:italic;margin:0;">
						<?php esc_html_e( 'No AI translations downloaded yet. Click "Check for updates now" to start.', 'gratis-ai-plugin-translations' ); ?>
					</p>
				<?php endif; ?>

				<p style="margin-top:12px;margin-bottom:0;color:#646970;font-size:12px;">
					<?php
					printf(
						/* translators: 1: Human-readable time since last check (e.g. "5 minutes ago"), 2: Number of plugins scanned */
						esc_html__( 'Last checked: %1$s — %2$s plugins scanned.', 'gratis-ai-plugin-translations' ),
						esc_html( $stats['last_check'] ),
						esc_html( number_format_i18n( (int) $stats['plugins_checked'] ) )
					);
					?>
				</p>
			</div>

			<div class="card" style="max-width:800px;margin-top:20px;padding:12px 20px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'How It Works', 'gratis-ai-plugin-translations' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'When WordPress checks for plugin updates, this plugin checks whether translations are needed.', 'gratis-ai-plugin-translations' ); ?></li>
					<li><?php esc_html_e( 'For any plugin without complete official translations, AI-generated translations are requested automatically.', 'gratis-ai-plugin-translations' ); ?></li>
					<li><?php esc_html_e( 'Translations are generated using advanced language models and delivered as standard WordPress language packs.', 'gratis-ai-plugin-translations' ); ?></li>
					<li><?php esc_html_e( 'Once downloaded, translations update automatically whenever a new plugin version is released.', 'gratis-ai-plugin-translations' ); ?></li>
					<li><?php esc_html_e( 'If official translations from WordPress.org become available, they automatically take precedence.', 'gratis-ai-plugin-translations' ); ?></li>
				</ol>
			</div>

			<div class="card" style="max-width:800px;margin-top:20px;padding:12px 20px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Privacy Notice', 'gratis-ai-plugin-translations' ); ?></h2>
				<p style="margin:0;"><?php esc_html_e( 'This plugin sends the following data to the translation server: plugin metadata (name, version, textdomain), the site URL, and the WordPress version. No personal data or site content is transmitted. Translations are cached on your server.', 'gratis-ai-plugin-translations' ); ?></p>
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
		$total         = 0;
		$plugins       = [];
		$languages     = [];

		if ( is_dir( $languages_dir ) ) {
			$files = glob( $languages_dir . '/*-gratis-ai.mo' ) ?: [];
			$total = count( $files );

			foreach ( $files as $file ) {
				if ( preg_match( '/(.+)-([a-z]{2,3}(?:_[A-Z]{2})?)-gratis-ai\.mo$/', basename( $file ), $matches ) ) {
					$plugins[]   = $matches[1];
					$languages[] = $matches[2];
				}
			}
		}

		$last_check_raw = get_site_option( 'gratis_ai_pt_last_check', null );

		return [
			'total_translations' => $total,
			'plugins_count'      => count( array_unique( $plugins ) ),
			'languages_count'    => count( array_unique( $languages ) ),
			'plugins_checked'    => (int) get_site_option( 'gratis_ai_pt_plugins_checked', 0 ),
			'pending_count'      => (int) get_site_option( 'gratis_ai_pt_pending_count', 0 ),
			'available_count'    => (int) get_site_option( 'gratis_ai_pt_available_count', 0 ),
			'last_check'         => $last_check_raw
				? human_time_diff( (int) strtotime( (string) $last_check_raw ), time() ) . ' ' . __( 'ago', 'gratis-ai-plugin-translations' )
				: __( 'Never', 'gratis-ai-plugin-translations' ),
		];
	}

	/**
	 * Get details for each locally installed AI translation file.
	 *
	 * Returns one entry per .mo file, with the textdomain, locale, and
	 * string count read from the binary .mo header. Sorted by textdomain
	 * then locale so the table is stable across page loads.
	 *
	 * @since 1.0.0
	 * @return array<int, array{textdomain: string, locale: string, strings: int}>
	 */
	private function get_local_translation_details(): array {
		$languages_dir = WP_CONTENT_DIR . '/languages/plugins';
		$details       = [];

		if ( ! is_dir( $languages_dir ) ) {
			return $details;
		}

		$files = glob( $languages_dir . '/*-gratis-ai.mo' ) ?: [];

		foreach ( $files as $file ) {
			$basename = basename( $file, '-gratis-ai.mo' );
			// Filename pattern: {textdomain}-{locale}, e.g. woocommerce-de_DE
			if ( ! preg_match( '/^(.+)-([a-z]{2,3}(?:_[A-Z]{2,3})?)$/', $basename, $matches ) ) {
				continue;
			}
			$details[] = [
				'textdomain' => $matches[1],
				'locale'     => $matches[2],
				'strings'    => $this->count_mo_strings( $file ),
			];
		}

		usort(
			$details,
			static function ( array $a, array $b ): int {
				$cmp = strcmp( $a['textdomain'], $b['textdomain'] );
				return 0 !== $cmp ? $cmp : strcmp( $a['locale'], $b['locale'] );
			}
		);

		return $details;
	}

	/**
	 * Count the number of translated strings in a .mo binary file.
	 *
	 * Reads only the 12-byte file header to extract N (the string count)
	 * without loading the full file into memory. The header entry (empty
	 * msgid) is excluded, so the returned value reflects actual content strings.
	 *
	 * MO format: 4-byte magic | 4-byte revision | 4-byte N (string count).
	 * Magic 0xde120495 = little-endian; 0x950412de = big-endian.
	 *
	 * @since 1.0.0
	 * @param string $mo_file Absolute path to the .mo file.
	 * @return int Number of translated strings, or 0 on read/parse failure.
	 */
	private function count_mo_strings( string $mo_file ): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = @fopen( $mo_file, 'rb' );
		if ( ! $fp ) {
			return 0;
		}
		$header = fread( $fp, 12 );
		fclose( $fp );

		if ( false === $header || strlen( $header ) < 12 ) {
			return 0;
		}

		$magic     = substr( $header, 0, 4 );
		$le_magic  = "\xde\x12\x04\x95";
		$be_magic  = "\x95\x04\x12\xde";

		if ( $magic === $le_magic ) {
			$unpacked = unpack( 'V', substr( $header, 8, 4 ) );
		} elseif ( $magic === $be_magic ) {
			$unpacked = unpack( 'N', substr( $header, 8, 4 ) );
		} else {
			return 0;
		}

		// Subtract 1: the MO header entry (empty msgid) is counted in N.
		return max( 0, (int) ( $unpacked[1] ?? 0 ) - 1 );
	}

	/**
	 * Build a textdomain-to-display-name map from installed plugins.
	 *
	 * Used to show friendly plugin names in the translation table rather
	 * than raw textdomain strings.
	 *
	 * @since 1.0.0
	 * @return array<string, string> Map of textdomain => plugin display name.
	 */
	private function get_plugin_name_map(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$map = [];
		foreach ( get_plugins() as $plugin_data ) {
			$textdomain = (string) ( $plugin_data['TextDomain'] ?? '' );
			if ( '' !== $textdomain ) {
				$map[ $textdomain ] = (string) ( $plugin_data['Name'] ?? $textdomain );
			}
		}

		return $map;
	}

	/**
	 * Display admin notices on update-core pages.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function display_admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || ( $screen->id !== 'update-core-network' && $screen->id !== 'update-core' ) ) {
			return;
		}

		$pending_count = (int) get_site_option( 'gratis_ai_pt_pending_count', 0 );
		if ( $pending_count <= 0 ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %d: Number of pending translations */
					esc_html__( 'Gratis AI Plugin Translations: %d translation(s) are being generated and will be available shortly.', 'gratis-ai-plugin-translations' ),
					$pending_count
				);
				?>
			</p>
		</div>
		<?php
	}
}
