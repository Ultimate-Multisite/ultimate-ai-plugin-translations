<?php
/**
 * Admin Settings class
 *
 * Renders a read-only status page for the plugin. All behaviour is
 * configured via filters (see README.md) — this page
 * never writes options.
 *
 * @package GratisAIPluginTranslations
 */

declare(strict_types=1);

namespace GratisAIPluginTranslations;

defined( 'ABSPATH' ) || exit;

/**
 * Admin Settings class.
 *
 * @since 1.0.0
 */
class Admin_Settings {

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
	public function __construct( Translation_API_Client $api_client ) {
		$this->api_client = $api_client;
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
			'network_admin_plugin_action_links_' . SD_AI_LANG_PACKS_BASENAME,
			[ $this, 'add_plugin_action_links' ]
		);
		add_filter(
			'plugin_action_links_' . SD_AI_LANG_PACKS_BASENAME,
			[ $this, 'add_plugin_action_links' ]
		);

		add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
		add_action( 'network_admin_notices', [ $this, 'display_admin_notices' ] );

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
			__( 'Superdav AI Plugin Translations', 'superdav-ai-language-packs' ),
			__( 'AI Translations', 'superdav-ai-language-packs' ),
			'manage_network_options',
			'superdav-ai-language-packs',
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
			__( 'Superdav AI Plugin Translations', 'superdav-ai-language-packs' ),
			__( 'AI Translations', 'superdav-ai-language-packs' ),
			'manage_options',
			'superdav-ai-language-packs',
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
			? network_admin_url( 'settings.php?page=superdav-ai-language-packs' )
			: admin_url( 'options-general.php?page=superdav-ai-language-packs' );

		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Status', 'superdav-ai-language-packs' )
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
		$api_status        = $this->api_client->check_api_status();
		$api_healthy       = ! is_wp_error( $api_status );
		$local             = $this->get_local_translation_details();
		$stats             = $this->get_translation_statistics( $local );
		$plugin_names      = $this->get_plugin_name_map();
		$pending           = (int) $stats['pending_count'];
		$refresh_status    = $this->get_refresh_status();
		$monitored_locales = $this->get_monitored_locales();
		$cron_disabled     = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		?>
		<div class="wrap sd-ai-lang-packs-status">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<style>
				.sd-ai-lang-packs-status .card {
					max-width: 960px;
					padding: 16px 20px;
				}
				.sd-ai-lang-packs-description {
					max-width: 800px;
					font-size: 14px;
					margin-bottom: 20px;
				}
				.sd-ai-lang-packs-status-line {
					display: flex;
					align-items: center;
					gap: 8px;
					margin: 0;
				}
				.sd-ai-lang-packs-status-line .dashicons-yes-alt {
					color: #00a32a;
				}
				.sd-ai-lang-packs-status-line .dashicons-warning {
					color: #d63638;
				}
				.sd-ai-lang-packs-status-line .dashicons-update {
					color: #2271b1;
				}
				.sd-ai-lang-packs-summary {
					display: grid;
					grid-template-columns: repeat( auto-fit, minmax( 140px, 1fr ) );
					gap: 16px;
					margin: 0 0 20px;
				}
				.sd-ai-lang-packs-stat {
					background: #f6f7f7;
					border: 1px solid #dcdcde;
					border-radius: 4px;
					padding: 12px;
					text-align: center;
				}
				.sd-ai-lang-packs-stat-number {
					font-size: 28px;
					font-weight: 600;
					line-height: 1.1;
				}
				.sd-ai-lang-packs-stat-label,
				.sd-ai-lang-packs-muted {
					color: #646970;
				}
				.sd-ai-lang-packs-stat-label {
					font-size: 13px;
					margin-top: 2px;
				}
				.sd-ai-lang-packs-progress {
					background: #f0f0f1;
					border-radius: 999px;
					height: 10px;
					overflow: hidden;
					margin: 12px 0;
				}
				.sd-ai-lang-packs-progress-bar {
					background: #2271b1;
					height: 10px;
				}
				.sd-ai-lang-packs-meta-list {
					display: grid;
					grid-template-columns: repeat( auto-fit, minmax( 220px, 1fr ) );
					gap: 8px 20px;
					margin: 12px 0 0;
				}
				.sd-ai-lang-packs-meta-list dt {
					font-weight: 600;
				}
				.sd-ai-lang-packs-meta-list dd {
					margin: 0;
				}
				.sd-ai-lang-packs-locale-list {
					margin-bottom: 0;
				}
				.sd-ai-lang-packs-table {
					margin-top: 4px;
				}
				.sd-ai-lang-packs-table .column-strings {
					text-align: right;
				}
			</style>

			<p class="description sd-ai-lang-packs-description">
				<?php esc_html_e( 'Your plugins are translated automatically by AI whenever official translations are missing or incomplete. There is nothing to configure on this page — use it to review service health, background activity, detected locales, and installed AI language packs.', 'superdav-ai-language-packs' ); ?>
			</p>

			<div class="card">
				<h2><?php esc_html_e( 'Service Status', 'superdav-ai-language-packs' ); ?></h2>
				<?php if ( $api_healthy ) : ?>
					<p class="sd-ai-lang-packs-status-line">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'Translation service is online and ready.', 'superdav-ai-language-packs' ); ?>
					</p>
				<?php else : ?>
					<p class="sd-ai-lang-packs-status-line">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<?php esc_html_e( 'Translation service is currently unavailable. Translations will resume automatically when the service recovers.', 'superdav-ai-language-packs' ); ?>
					</p>
					<?php if ( is_wp_error( $api_status ) ) : ?>
						<p class="description"><code><?php echo esc_html( $api_status->get_error_message() ); ?></code></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Background Activity', 'superdav-ai-language-packs' ); ?></h2>
				<?php if ( 'running' === $refresh_status['status'] ) : ?>
					<p class="sd-ai-lang-packs-status-line">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php esc_html_e( 'A background translation scan is currently processing installed plugins.', 'superdav-ai-language-packs' ); ?>
					</p>
					<div class="sd-ai-lang-packs-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $refresh_status['progress'] ); ?>">
						<div class="sd-ai-lang-packs-progress-bar" style="width: <?php echo esc_attr( (string) $refresh_status['progress'] ); ?>%;"></div>
					</div>
					<p class="sd-ai-lang-packs-muted">
						<?php
						printf(
							/* translators: 1: Processed plugin count, 2: Total plugin count. */
							esc_html__( '%1$s of %2$s plugins processed in the current scan.', 'superdav-ai-language-packs' ),
							esc_html( number_format_i18n( (int) $refresh_status['processed'] ) ),
							esc_html( number_format_i18n( (int) $refresh_status['total'] ) )
						);
						?>
					</p>
				<?php elseif ( 'scheduled' === $refresh_status['status'] ) : ?>
					<p class="sd-ai-lang-packs-status-line">
						<span class="dashicons dashicons-clock" aria-hidden="true"></span>
						<?php esc_html_e( 'The next background translation scan is scheduled.', 'superdav-ai-language-packs' ); ?>
					</p>
				<?php else : ?>
					<p class="sd-ai-lang-packs-status-line">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'Background translation activity is idle. WordPress will schedule future scans automatically when translation checks or locale changes require them.', 'superdav-ai-language-packs' ); ?>
					</p>
				<?php endif; ?>

				<?php if ( $cron_disabled ) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'WP-Cron spawning is disabled. Make sure a server cron job runs wp-cron.php so background translation scans can finish.', 'superdav-ai-language-packs' ); ?></p>
					</div>
				<?php endif; ?>

				<dl class="sd-ai-lang-packs-meta-list">
					<div>
						<dt><?php esc_html_e( 'Last completed check', 'superdav-ai-language-packs' ); ?></dt>
						<dd><?php echo esc_html( $stats['last_check'] ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Plugins scanned last run', 'superdav-ai-language-packs' ); ?></dt>
						<dd><?php echo esc_html( number_format_i18n( (int) $stats['plugins_checked'] ) ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Next scheduled scan', 'superdav-ai-language-packs' ); ?></dt>
						<dd><?php echo esc_html( $this->format_scheduled_time( $refresh_status['next_scheduled'] ) ); ?></dd>
					</div>
				</dl>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Translation Progress', 'superdav-ai-language-packs' ); ?></h2>

				<div class="sd-ai-lang-packs-summary">
					<div class="sd-ai-lang-packs-stat">
						<div class="sd-ai-lang-packs-stat-number"><?php echo esc_html( number_format_i18n( count( $local ) ) ); ?></div>
						<div class="sd-ai-lang-packs-stat-label"><?php esc_html_e( 'translations active', 'superdav-ai-language-packs' ); ?></div>
					</div>
					<div class="sd-ai-lang-packs-stat">
						<div class="sd-ai-lang-packs-stat-number"><?php echo esc_html( number_format_i18n( (int) $stats['plugins_count'] ) ); ?></div>
						<div class="sd-ai-lang-packs-stat-label"><?php esc_html_e( 'plugins covered', 'superdav-ai-language-packs' ); ?></div>
					</div>
					<div class="sd-ai-lang-packs-stat">
						<div class="sd-ai-lang-packs-stat-number"><?php echo esc_html( number_format_i18n( (int) $stats['languages_count'] ) ); ?></div>
						<div class="sd-ai-lang-packs-stat-label"><?php esc_html_e( 'languages', 'superdav-ai-language-packs' ); ?></div>
					</div>
					<div class="sd-ai-lang-packs-stat">
						<div class="sd-ai-lang-packs-stat-number"><?php echo esc_html( number_format_i18n( $pending ) ); ?></div>
						<div class="sd-ai-lang-packs-stat-label"><?php esc_html_e( 'queued', 'superdav-ai-language-packs' ); ?></div>
					</div>
					<div class="sd-ai-lang-packs-stat">
						<div class="sd-ai-lang-packs-stat-number"><?php echo esc_html( number_format_i18n( (int) $stats['available_count'] ) ); ?></div>
						<div class="sd-ai-lang-packs-stat-label"><?php esc_html_e( 'available last check', 'superdav-ai-language-packs' ); ?></div>
					</div>
				</div>

				<?php if ( $pending > 0 ) : ?>
					<div class="notice notice-info inline">
						<p>
							<?php
							printf(
								esc_html(
									/* translators: %s: Number of queued translations. */
									_n(
										'%s translation is being generated by AI and will download automatically when ready.',
										'%s translations are being generated by AI and will download automatically when ready.',
										$pending,
										'superdav-ai-language-packs'
									)
								),
								esc_html( number_format_i18n( $pending ) )
							);
							echo ' ';
							esc_html_e( 'No action needed.', 'superdav-ai-language-packs' );
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $local ) ) : ?>
					<table class="widefat striped sd-ai-lang-packs-table">
						<caption class="screen-reader-text"><?php esc_html_e( 'Installed AI plugin language packs', 'superdav-ai-language-packs' ); ?></caption>
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Plugin', 'superdav-ai-language-packs' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Language', 'superdav-ai-language-packs' ); ?></th>
								<th scope="col" class="column-strings"><?php esc_html_e( 'Strings translated', 'superdav-ai-language-packs' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $local as $item ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $plugin_names[ $item['textdomain'] ] ?? $item['textdomain'] ); ?></strong><br>
										<span class="sd-ai-lang-packs-muted"><?php echo esc_html( $item['textdomain'] ); ?></span>
									</td>
									<td><?php echo esc_html( $this->format_locale_label( $item['locale'] ) ); ?></td>
									<td class="column-strings"><?php echo esc_html( number_format_i18n( $item['strings'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php elseif ( 0 === $pending ) : ?>
					<p class="sd-ai-lang-packs-muted">
						<?php esc_html_e( 'No AI translations have been downloaded yet. They will appear here after WordPress detects missing translations and the background scan completes.', 'superdav-ai-language-packs' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Locale Coverage', 'superdav-ai-language-packs' ); ?></h2>
				<p><?php esc_html_e( 'These non-English locales are currently detected from the site language, network site languages, and user profile language preferences.', 'superdav-ai-language-packs' ); ?></p>
				<?php if ( empty( $monitored_locales ) ) : ?>
					<p class="sd-ai-lang-packs-muted"><?php esc_html_e( 'Only English or site-default locales are currently detected, so no AI plugin language packs are needed.', 'superdav-ai-language-packs' ); ?></p>
				<?php else : ?>
					<ul class="sd-ai-lang-packs-locale-list">
						<?php foreach ( $monitored_locales as $locale => $locale_data ) : ?>
							<li>
								<strong><?php echo esc_html( $this->format_locale_label( $locale ) ); ?></strong>
								<span class="sd-ai-lang-packs-muted">
									<?php
									printf(
										/* translators: %s: Comma-separated locale sources. */
										esc_html__( 'Detected from: %s', 'superdav-ai-language-packs' ),
										esc_html( implode( ', ', array_map( [ $this, 'get_locale_source_label' ], array_keys( $locale_data['sources'] ) ) ) )
									);
									?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'How It Works', 'superdav-ai-language-packs' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'When WordPress checks for plugin updates, this plugin checks whether translations are needed.', 'superdav-ai-language-packs' ); ?></li>
					<li><?php esc_html_e( 'For any plugin without complete official translations, AI-generated translations are requested automatically.', 'superdav-ai-language-packs' ); ?></li>
					<li><?php esc_html_e( 'Translations are generated using advanced language models and delivered as standard WordPress language packs.', 'superdav-ai-language-packs' ); ?></li>
					<li><?php esc_html_e( 'Once downloaded, translations update automatically whenever a new plugin version is released.', 'superdav-ai-language-packs' ); ?></li>
					<li><?php esc_html_e( 'If official translations from WordPress.org become available, they automatically take precedence.', 'superdav-ai-language-packs' ); ?></li>
				</ol>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Privacy Notice', 'superdav-ai-language-packs' ); ?></h2>
				<p><?php esc_html_e( 'This plugin sends plugin metadata (name, version, textdomain), the requested locale, the site URL, and the WordPress version to the translation service. No personal data, user data, or site content is transmitted. Translations are cached on your server.', 'superdav-ai-language-packs' ); ?></p>
				<p class="sd-ai-lang-packs-muted">
					<a href="<?php echo esc_url( 'https://ultimatemultisite.com/terms' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms of Use', 'superdav-ai-language-packs' ); ?></a>
					<?php echo esc_html_x( '·', 'separator between external policy links', 'superdav-ai-language-packs' ); ?>
					<a href="<?php echo esc_url( 'https://ultimatemultisite.com/privacy' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy Policy', 'superdav-ai-language-packs' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Get translation statistics.
	 *
	 * @since 1.0.0
	 * @param array<int, array{textdomain: string, locale: string, strings: int}> $details Local translation details.
	 * @return array Statistics array.
	 */
	private function get_translation_statistics( array $details ): array {
		$plugins   = [];
		$languages = [];

		foreach ( $details as $detail ) {
			$plugins[]   = $detail['textdomain'];
			$languages[] = $detail['locale'];
		}

		$last_check_raw = get_site_option( 'sd_ai_lang_packs_last_check', null );

		return [
			'total_translations' => count( $details ),
			'plugins_count'      => count( array_unique( $plugins ) ),
			'languages_count'    => count( array_unique( $languages ) ),
			'plugins_checked'    => (int) get_site_option( 'sd_ai_lang_packs_plugins_checked', 0 ),
			'pending_count'      => (int) get_site_option( 'sd_ai_lang_packs_pending_count', 0 ),
			'available_count'    => (int) get_site_option( 'sd_ai_lang_packs_available_count', 0 ),
			'last_check'         => $last_check_raw
				? human_time_diff( (int) strtotime( (string) $last_check_raw ), time() ) . ' ' . __( 'ago', 'superdav-ai-language-packs' )
				: __( 'Never', 'superdav-ai-language-packs' ),
		];
	}

	/**
	 * Get background refresh status from the persisted cron state.
	 *
	 * @since 1.0.0
	 * @return array{status: string, processed: int, total: int, progress: int, next_scheduled: int|null}
	 */
	private function get_refresh_status(): array {
		$state          = get_site_option( 'sd_ai_lang_packs_refresh_state', null );
		$next_scheduled = wp_next_scheduled( 'sd_ai_lang_packs_refresh_cache' );
		$status         = $next_scheduled ? 'scheduled' : 'idle';
		$processed      = 0;
		$total          = 0;

		if ( is_array( $state ) && isset( $state['plugins'] ) && is_array( $state['plugins'] ) ) {
			$total     = count( $state['plugins'] );
			$processed = min( max( (int) ( $state['offset'] ?? 0 ), 0 ), $total );

			if ( $total > $processed ) {
				$status = 'running';
			}
		}

		$progress = $total > 0 ? (int) floor( ( $processed / $total ) * 100 ) : 0;
		$progress = min( max( $progress, 0 ), 100 );

		return [
			'status'         => $status,
			'processed'      => $processed,
			'total'          => $total,
			'progress'       => $progress,
			'next_scheduled' => $next_scheduled ? (int) $next_scheduled : null,
		];
	}

	/**
	 * Format a scheduled cron timestamp for display.
	 *
	 * @since 1.0.0
	 * @param int|null $timestamp Unix timestamp, or null when unscheduled.
	 * @return string Human-readable schedule state.
	 */
	private function format_scheduled_time( ?int $timestamp ): string {
		if ( empty( $timestamp ) ) {
			return __( 'Not scheduled', 'superdav-ai-language-packs' );
		}

		if ( $timestamp <= time() ) {
			return __( 'Due now', 'superdav-ai-language-packs' );
		}

		return sprintf(
			/* translators: %s: Human-readable time until the scheduled event. */
			__( 'in %s', 'superdav-ai-language-packs' ),
			human_time_diff( time(), $timestamp )
		);
	}

	/**
	 * Get non-English locales that can trigger AI plugin translations.
	 *
	 * Mirrors Translation_Manager locale discovery for status visibility while
	 * showing only the locales that require language packs.
	 *
	 * @since 1.0.0
	 * @return array<string, array{sources: array<string, bool>}> Locale details keyed by locale code.
	 */
	private function get_monitored_locales(): array {
		$locales = [];

		$this->add_locale_source( $locales, get_locale(), 'site' );

		$user_ids = get_users(
			[
				'fields'       => 'ID',
				'meta_key'     => 'locale',
				'meta_compare' => 'EXISTS',
			]
		);

		foreach ( $user_ids as $user_id ) {
			$user_locale = get_user_meta( (int) $user_id, 'locale', true );
			if ( is_string( $user_locale ) ) {
				$this->add_locale_source( $locales, $user_locale, 'user' );
			}
		}

		if ( is_multisite() && function_exists( 'get_sites' ) ) {
			$site_ids = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);

			foreach ( $site_ids as $site_id ) {
				$site_locale = get_blog_option( (int) $site_id, 'WPLANG', '' );
				if ( is_string( $site_locale ) ) {
					$this->add_locale_source( $locales, $site_locale, 'network_site' );
				}
			}
		}

		ksort( $locales );

		return $locales;
	}

	/**
	 * Add a locale source to the locale summary.
	 *
	 * @since 1.0.0
	 * @param array<string, array{sources: array<string, bool>}> $locales Locale details, mutated in place.
	 * @param string                                             $locale  Locale code.
	 * @param string                                             $source  Source key.
	 * @return void
	 */
	private function add_locale_source( array &$locales, string $locale, string $source ): void {
		$locale = trim( $locale );
		if ( ! $this->is_translation_locale( $locale ) ) {
			return;
		}

		if ( ! isset( $locales[ $locale ] ) ) {
			$locales[ $locale ] = [ 'sources' => [] ];
		}

		$locales[ $locale ]['sources'][ $source ] = true;
	}

	/**
	 * Check whether a locale needs translated language packs.
	 *
	 * @since 1.0.0
	 * @param string $locale Locale code.
	 * @return bool True when AI translations can be requested for the locale.
	 */
	private function is_translation_locale( string $locale ): bool {
		return '' !== $locale && ! in_array( $locale, [ 'en_US', 'en', 'site-default' ], true );
	}

	/**
	 * Get a translated label for a locale source key.
	 *
	 * @since 1.0.0
	 * @param string $source Source key.
	 * @return string Human-readable source label.
	 */
	private function get_locale_source_label( string $source ): string {
		switch ( $source ) {
			case 'site':
				return __( 'site language', 'superdav-ai-language-packs' );
			case 'network_site':
				return __( 'network site language', 'superdav-ai-language-packs' );
			case 'user':
				return __( 'user profile language', 'superdav-ai-language-packs' );
			default:
				return __( 'unknown source', 'superdav-ai-language-packs' );
		}
	}

	/**
	 * Format a locale code with a readable language name when possible.
	 *
	 * @since 1.0.0
	 * @param string $locale Locale code.
	 * @return string Locale label.
	 */
	private function format_locale_label( string $locale ): string {
		$display_name = '';
		$locale_tag   = str_replace( '_', '-', $locale );

		if ( class_exists( '\Locale' ) ) {
			$display_name = (string) \Locale::getDisplayName( $locale_tag, str_replace( '_', '-', get_user_locale() ) );
		}

		if ( '' === $display_name || strtolower( $display_name ) === strtolower( $locale ) ) {
			$display_name = $this->get_language_name_fallback( $locale );
		}

		if ( '' === $display_name ) {
			return $locale;
		}

		return sprintf(
			/* translators: 1: Language display name, 2: Locale code. */
			__( '%1$s (%2$s)', 'superdav-ai-language-packs' ),
			$display_name,
			$locale
		);
	}

	/**
	 * Fallback language names for common locales when PHP intl is unavailable.
	 *
	 * @since 1.0.0
	 * @param string $locale Locale code.
	 * @return string Language name, or empty string when unknown.
	 */
	private function get_language_name_fallback( string $locale ): string {
		$language_code = strtolower( substr( $locale, 0, 2 ) );
		$names         = [
			'cs' => __( 'Czech', 'superdav-ai-language-packs' ),
			'da' => __( 'Danish', 'superdav-ai-language-packs' ),
			'de' => __( 'German', 'superdav-ai-language-packs' ),
			'el' => __( 'Greek', 'superdav-ai-language-packs' ),
			'es' => __( 'Spanish', 'superdav-ai-language-packs' ),
			'fi' => __( 'Finnish', 'superdav-ai-language-packs' ),
			'fr' => __( 'French', 'superdav-ai-language-packs' ),
			'hu' => __( 'Hungarian', 'superdav-ai-language-packs' ),
			'it' => __( 'Italian', 'superdav-ai-language-packs' ),
			'ja' => __( 'Japanese', 'superdav-ai-language-packs' ),
			'nl' => __( 'Dutch', 'superdav-ai-language-packs' ),
			'pl' => __( 'Polish', 'superdav-ai-language-packs' ),
			'pt' => __( 'Portuguese', 'superdav-ai-language-packs' ),
			'ro' => __( 'Romanian', 'superdav-ai-language-packs' ),
			'ru' => __( 'Russian', 'superdav-ai-language-packs' ),
			'sv' => __( 'Swedish', 'superdav-ai-language-packs' ),
			'tr' => __( 'Turkish', 'superdav-ai-language-packs' ),
			'zh' => __( 'Chinese', 'superdav-ai-language-packs' ),
		];

		return $names[ $language_code ] ?? '';
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

		$seen = [];
		foreach ( $this->get_installed_ai_translation_entries() as $entry ) {
			$textdomain = (string) ( $entry['textdomain'] ?? $entry['slug'] ?? '' );
			$locale     = (string) ( $entry['language'] ?? '' );

			if ( '' === $textdomain || '' === $locale ) {
				continue;
			}

			$file = $this->find_translation_file( $textdomain, $locale, (string) ( $entry['slug'] ?? '' ) );
			if ( null === $file ) {
				continue;
			}

			$key = $textdomain . '|' . $locale;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$details[] = [
				'textdomain' => $textdomain,
				'locale'     => $locale,
				'strings'    => $this->count_mo_strings( $file ),
			];
		}

		// Back-compat only: show any old suffixed files from earlier builds.
		$legacy_files = glob( $languages_dir . '/*-gratis-ai.mo' ) ?: [];
		foreach ( $legacy_files as $file ) {
			if ( ! preg_match( '/^(.+)-([a-z]{2,3}(?:_[A-Z]{2,3})?)-gratis-ai\.mo$/', basename( $file ), $matches ) ) {
				continue;
			}

			$key = $matches[1] . '|' . $matches[2];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

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
	 * Get AI translation entries recorded by the refresh/install process.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	private function get_installed_ai_translation_entries(): array {
		$entries = get_site_option( 'sd_ai_lang_packs_installed_translations', [] );

		if ( empty( $entries ) ) {
			$entries = get_site_transient( 'sd_ai_lang_packs_translations_cache' );
		}

		return is_array( $entries ) ? array_values( $entries ) : [];
	}

	/**
	 * Find the installed .mo file for an AI translation entry.
	 *
	 * Current packages are normal WordPress language packs, named
	 * {textdomain}-{locale}.mo. The slug fallback handles older cached entries
	 * that did not store textdomain separately, and the -gratis-ai candidate is
	 * retained only for legacy files.
	 *
	 * @since 1.0.0
	 * @param string $textdomain Plugin textdomain.
	 * @param string $locale     Locale code.
	 * @param string $slug       Plugin slug fallback.
	 * @return string|null Absolute .mo path, or null if no file exists.
	 */
	private function find_translation_file( string $textdomain, string $locale, string $slug = '' ): ?string {
		$languages_dir = WP_CONTENT_DIR . '/languages/plugins';
		$candidates    = [
			$languages_dir . '/' . $textdomain . '-' . $locale . '.mo',
		];

		if ( '' !== $slug && $slug !== $textdomain ) {
			$candidates[] = $languages_dir . '/' . $slug . '-' . $locale . '.mo';
		}

		$candidates[] = $languages_dir . '/' . $textdomain . '-' . $locale . '-gratis-ai.mo';

		foreach ( $candidates as $candidate ) {
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Count the number of translated strings in a .mo binary file.
	 *
	 * Uses WordPress's bundled MO parser so file handling stays compatible
	 * with the configured filesystem implementation.
	 *
	 * @since 1.0.0
	 * @param string $mo_file Absolute path to the .mo file.
	 * @return int Number of translated strings, or 0 on read/parse failure.
	 */
	private function count_mo_strings( string $mo_file ): int {
		if ( ! is_readable( $mo_file ) ) {
			return 0;
		}

		$mtime     = filemtime( $mo_file );
		$size      = filesize( $mo_file );
		$cache_key = 'mo_strings_' . md5( $mo_file . '|' . (string) $mtime . '|' . (string) $size );
		$cached    = wp_cache_get( $cache_key, 'sd_ai_lang_packs' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		if ( ! class_exists( 'MO' ) ) {
			require_once ABSPATH . WPINC . '/pomo/mo.php';
		}

		$mo = new \MO();
		if ( ! $mo->import_from_file( $mo_file ) ) {
			wp_cache_set( $cache_key, 0, 'sd_ai_lang_packs', HOUR_IN_SECONDS );
			return 0;
		}

		$count = count( $mo->entries );
		wp_cache_set( $cache_key, $count, 'sd_ai_lang_packs', HOUR_IN_SECONDS );

		return $count;
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

		$pending_count = (int) get_site_option( 'sd_ai_lang_packs_pending_count', 0 );
		if ( $pending_count <= 0 ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php
				printf(
					esc_html(
						/* translators: %s: Number of pending translations. */
						_n(
							'Superdav AI Plugin Translations: %s translation is being generated and will be available shortly.',
							'Superdav AI Plugin Translations: %s translations are being generated and will be available shortly.',
							$pending_count,
							'superdav-ai-language-packs'
						)
					),
					esc_html( number_format_i18n( $pending_count ) )
				);
				?>
			</p>
		</div>
		<?php
	}
}
