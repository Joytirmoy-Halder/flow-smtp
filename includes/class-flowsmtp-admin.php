<?php
/**
 * Admin UI: overview, settings, email logs, failed emails, deliverability, test email.
 *
 * @package FlowSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowSMTP_Admin {

	const PAGE_SLUG = 'flow-smtp';

	/**
	 * @var FlowSMTP_Logger
	 */
	private $logger;

	public function __construct( FlowSMTP_Logger $logger ) {
		$this->logger = $logger;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_flowsmtp_send_test', array( $this, 'ajax_send_test' ) );
		add_action( 'wp_ajax_flowsmtp_resend', array( $this, 'ajax_resend' ) );
		add_action( 'wp_ajax_flowsmtp_delete_logs', array( $this, 'ajax_delete_logs' ) );
		add_action( 'wp_ajax_flowsmtp_view_log', array( $this, 'ajax_view_log' ) );
		add_action( 'wp_ajax_flowsmtp_preview_send', array( $this, 'ajax_preview_send' ) );
		add_action( 'wp_ajax_flowsmtp_check_domain', array( $this, 'ajax_check_domain' ) );
		add_action( 'admin_post_flowsmtp_export_csv', array( $this, 'export_csv' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'FlowSMTP', 'flow-smtp' ),
			__( 'FlowSMTP', 'flow-smtp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-email-alt',
			80
		);
	}

	public function register_settings() {
		register_setting(
			'flowsmtp_settings_group',
			FLOWSMTP_OPTION_KEY,
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	/**
	 * Sanitize settings before save.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$old = FlowSMTP::get_settings();

		$clean                = array();
		$clean['provider']    = isset( $input['provider'] ) && FlowSMTP_Providers::exists( sanitize_key( $input['provider'] ) ) ? sanitize_key( $input['provider'] ) : 'custom';
		$clean['mailer_type'] = isset( $input['mailer_type'] ) && in_array( $input['mailer_type'], array( 'smtp', 'api' ), true ) ? $input['mailer_type'] : 'smtp';
		$clean['host']        = isset( $input['host'] ) ? sanitize_text_field( $input['host'] ) : '';
		$clean['port']        = isset( $input['port'] ) ? absint( $input['port'] ) : 587;
		$clean['encryption']  = isset( $input['encryption'] ) && in_array( $input['encryption'], array( 'none', 'ssl', 'tls' ), true ) ? $input['encryption'] : 'tls';
		$clean['auth']        = empty( $input['auth'] ) ? 0 : 1;
		$clean['username']    = isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '';

		// Keep the old password if the field was left as the mask.
		if ( isset( $input['password'] ) && '' !== $input['password'] && '********' !== $input['password'] ) {
			$clean['password'] = FlowSMTP_Mailer::encrypt( $input['password'] );
		} elseif ( isset( $old['password'] ) && '' !== $old['password'] ) {
			// Re-encrypt legacy (0.1.0 XOR) values with the current scheme on save.
			$is_current        = 0 === strpos( $old['password'], 'fsm1:' ) || 0 === strpos( $old['password'], 'fsm2:' );
			$clean['password'] = $is_current ? $old['password'] : FlowSMTP_Mailer::encrypt( FlowSMTP_Mailer::decrypt( $old['password'] ) );
		} else {
			$clean['password'] = '';
		}

		// Same mask handling for the API key (stored encrypted).
		if ( isset( $input['api_key'] ) && '' !== $input['api_key'] && '********' !== $input['api_key'] ) {
			$clean['api_key'] = FlowSMTP_Mailer::encrypt( $input['api_key'] );
		} else {
			$clean['api_key'] = isset( $old['api_key'] ) ? $old['api_key'] : '';
		}
		$clean['api_domain'] = isset( $input['api_domain'] ) ? sanitize_text_field( $input['api_domain'] ) : '';

		// Fallback SMTP connection.
		$clean['fallback']            = empty( $input['fallback'] ) ? 0 : 1;
		$clean['fallback_host']       = isset( $input['fallback_host'] ) ? sanitize_text_field( $input['fallback_host'] ) : '';
		$clean['fallback_port']       = isset( $input['fallback_port'] ) ? absint( $input['fallback_port'] ) : 587;
		$clean['fallback_encryption'] = isset( $input['fallback_encryption'] ) && in_array( $input['fallback_encryption'], array( 'none', 'ssl', 'tls' ), true ) ? $input['fallback_encryption'] : 'tls';
		$clean['fallback_auth']       = empty( $input['fallback_auth'] ) ? 0 : 1;
		$clean['fallback_username']   = isset( $input['fallback_username'] ) ? sanitize_text_field( $input['fallback_username'] ) : '';
		if ( isset( $input['fallback_password'] ) && '' !== $input['fallback_password'] && '********' !== $input['fallback_password'] ) {
			$clean['fallback_password'] = FlowSMTP_Mailer::encrypt( $input['fallback_password'] );
		} else {
			$clean['fallback_password'] = isset( $old['fallback_password'] ) ? $old['fallback_password'] : '';
		}

		$clean['from_email']     = isset( $input['from_email'] ) && is_email( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : $old['from_email'];
		$clean['from_name']      = isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '';
		$clean['force_from']     = empty( $input['force_from'] ) ? 0 : 1;
		$clean['auto_plaintext'] = empty( $input['auto_plaintext'] ) ? 0 : 1;

		// Test mode (email interception).
		$clean['test_mode']        = empty( $input['test_mode'] ) ? 0 : 1;
		$clean['test_mode_action'] = isset( $input['test_mode_action'] ) && in_array( $input['test_mode_action'], array( 'redirect', 'log' ), true ) ? $input['test_mode_action'] : 'redirect';
		$clean['test_mode_to']     = isset( $input['test_mode_to'] ) && is_email( $input['test_mode_to'] ) ? sanitize_email( $input['test_mode_to'] ) : get_option( 'admin_email' );

		$allowlist = isset( $input['test_mode_allowlist'] ) ? (string) $input['test_mode_allowlist'] : '';
		$entries   = array();
		foreach ( preg_split( '/[\r\n,;]+/', $allowlist ) as $entry ) {
			$entry = sanitize_text_field( trim( $entry ) );
			if ( '' !== $entry ) {
				$entries[] = $entry;
			}
		}
		$clean['test_mode_allowlist'] = implode( "\n", $entries );

		// Engagement tracking.
		$clean['track_opens']  = empty( $input['track_opens'] ) ? 0 : 1;
		$clean['track_clicks'] = empty( $input['track_clicks'] ) ? 0 : 1;

		$clean['logging']       = empty( $input['logging'] ) ? 0 : 1;
		$clean['log_body']      = empty( $input['log_body'] ) ? 0 : 1;
		$clean['log_retention'] = isset( $input['log_retention'] ) ? absint( $input['log_retention'] ) : 30;
		$clean['auto_retry']    = empty( $input['auto_retry'] ) ? 0 : 1;
		$clean['max_retries']   = isset( $input['max_retries'] ) ? min( 10, absint( $input['max_retries'] ) ) : 3;
		$clean['alerts']        = empty( $input['alerts'] ) ? 0 : 1;
		$clean['alert_email']   = isset( $input['alert_email'] ) && is_email( $input['alert_email'] ) ? sanitize_email( $input['alert_email'] ) : get_option( 'admin_email' );
		$clean['alert_webhook'] = isset( $input['alert_webhook'] ) ? esc_url_raw( trim( $input['alert_webhook'] ) ) : '';

		// Data retention on uninstall.
		$clean['uninstall_data'] = isset( $input['uninstall_data'] ) && 'delete' === $input['uninstall_data'] ? 'delete' : 'keep';

		return $clean;
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'flowsmtp-admin', FLOWSMTP_URL . 'assets/css/admin.css', array( 'dashicons' ), FLOWSMTP_VERSION );
		wp_enqueue_script( 'flowsmtp-admin', FLOWSMTP_URL . 'assets/js/admin.js', array( 'jquery' ), FLOWSMTP_VERSION, true );

		wp_localize_script(
			'flowsmtp-admin',
			'FlowSMTP',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'flowsmtp_admin' ),
				'presets'     => FlowSMTP_Providers::all(),
				'currentUser' => wp_get_current_user()->user_email,
				'i18n'        => array(
					'sending'       => __( 'Sending…', 'flow-smtp' ),
					'resending'     => __( 'Resending…', 'flow-smtp' ),
					'checking'      => __( 'Checking…', 'flow-smtp' ),
					'confirmDelete' => __( 'Delete the selected log entries? This cannot be undone.', 'flow-smtp' ),
					'docs'          => __( 'Setup guide', 'flow-smtp' ),
					'missingFile'   => __( 'File missing', 'flow-smtp' ),
					'rendered'      => __( 'Rendered', 'flow-smtp' ),
					'source'        => __( 'Source', 'flow-smtp' ),
					'plainText'     => __( 'Plain text', 'flow-smtp' ),
					'sendPreview'   => __( 'Send preview', 'flow-smtp' ),
					'previewIntro'  => __( 'Send a copy of this email to:', 'flow-smtp' ),
					'requestFailed' => __( 'Request failed. Please try again.', 'flow-smtp' ),
				),
			)
		);
	}

	/**
	 * Render the admin page with tab navigation.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'overview'       => __( 'Overview', 'flow-smtp' ),
			'settings'       => __( 'Settings', 'flow-smtp' ),
			'logs'           => __( 'Email Logs', 'flow-smtp' ),
			'failed'         => __( 'Failed Emails', 'flow-smtp' ),
			'deliverability' => __( 'Deliverability', 'flow-smtp' ),
			'test'           => __( 'Send Test', 'flow-smtp' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}

		$stats = $this->logger->get_stats();
		?>
		<div class="wrap flowsmtp-wrap">
			<div class="flowsmtp-header">
				<div class="flowsmtp-brand">
					<span class="flowsmtp-logo dashicons dashicons-email-alt"></span>
					<div>
						<h1>FlowSMTP</h1>
						<p><?php esc_html_e( 'Reliable SMTP delivery, logging & auditing for WordPress.', 'flow-smtp' ); ?></p>
					</div>
				</div>
				<div class="flowsmtp-stats">
					<div class="flowsmtp-stat is-sent"><span><?php echo esc_html( number_format_i18n( $stats['sent'] ) ); ?></span><?php esc_html_e( 'Sent', 'flow-smtp' ); ?></div>
					<div class="flowsmtp-stat is-failed"><span><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></span><?php esc_html_e( 'Failed', 'flow-smtp' ); ?></div>
					<div class="flowsmtp-stat is-pending"><span><?php echo esc_html( number_format_i18n( $stats['pending'] ) ); ?></span><?php esc_html_e( 'Pending', 'flow-smtp' ); ?></div>
				</div>
			</div>

			<nav class="flowsmtp-tabs">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $key ) ); ?>" class="flowsmtp-tab <?php echo $tab === $key ? 'is-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="flowsmtp-card">
				<?php
				switch ( $tab ) {
					case 'settings':
						$this->render_settings_tab();
						break;
					case 'logs':
						$this->render_logs( '' );
						break;
					case 'failed':
						$this->render_logs( 'failed' );
						break;
					case 'deliverability':
						$this->render_deliverability_tab();
						break;
					case 'test':
						$this->render_test_tab();
						break;
					default:
						$this->render_overview_tab( $stats );
				}
				?>
			</div>
		</div>
		<div id="flowsmtp-modal" class="flowsmtp-modal" hidden>
			<div class="flowsmtp-modal-backdrop"></div>
			<div class="flowsmtp-modal-dialog">
				<button type="button" class="flowsmtp-modal-close" aria-label="Close">&times;</button>
				<div class="flowsmtp-modal-content"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Overview tab: 14-day stacked delivery chart, success rate, top errors.
	 *
	 * @param array $stats Aggregate stats from the logger.
	 */
	private function render_overview_tab( $stats ) {
		$daily  = $this->logger->get_daily_stats( 14 );
		$errors = $this->logger->get_top_errors( 5 );

		$max = 1;
		foreach ( $daily as $counts ) {
			$max = max( $max, $counts['sent'] + $counts['failed'] + $counts['pending'] );
		}

		$total_finished = $stats['sent'] + $stats['failed'];
		$success_rate   = $total_finished > 0 ? round( ( $stats['sent'] / $total_finished ) * 100, 1 ) : null;
		?>
		<h2><?php esc_html_e( 'Last 14 Days', 'flow-smtp' ); ?></h2>
		<?php if ( null !== $success_rate ) : ?>
			<p class="flowsmtp-muted">
				<?php
				/* translators: %s: percentage. */
				echo esc_html( sprintf( __( 'All-time delivery success rate: %s%%', 'flow-smtp' ), number_format_i18n( $success_rate, 1 ) ) );
				?>
			</p>
		<?php endif; ?>

		<div class="flowsmtp-chart" role="img" aria-label="<?php esc_attr_e( 'Emails per day for the last 14 days', 'flow-smtp' ); ?>">
			<?php foreach ( $daily as $day => $counts ) : ?>
				<?php
				$sent_h    = (int) round( ( $counts['sent'] / $max ) * 110 );
				$failed_h  = (int) round( ( $counts['failed'] / $max ) * 110 );
				$pending_h = (int) round( ( $counts['pending'] / $max ) * 110 );
				$title     = sprintf(
					/* translators: 1: date, 2: sent count, 3: failed count, 4: pending count. */
					__( '%1$s — Sent: %2$d, Failed: %3$d, Pending: %4$d', 'flow-smtp' ),
					mysql2date( 'M j', $day . ' 00:00:00' ),
					$counts['sent'],
					$counts['failed'],
					$counts['pending']
				);
				?>
				<div class="flowsmtp-chart-col" title="<?php echo esc_attr( $title ); ?>">
					<div class="flowsmtp-chart-bars">
						<?php if ( $pending_h > 0 ) : ?><span class="flowsmtp-bar is-pending" style="height:<?php echo esc_attr( max( 2, $pending_h ) ); ?>px"></span><?php endif; ?>
						<?php if ( $failed_h > 0 || $counts['failed'] > 0 ) : ?><span class="flowsmtp-bar is-failed" style="height:<?php echo esc_attr( max( 2, $failed_h ) ); ?>px"></span><?php endif; ?>
						<?php if ( $sent_h > 0 || $counts['sent'] > 0 ) : ?><span class="flowsmtp-bar is-sent" style="height:<?php echo esc_attr( max( 2, $sent_h ) ); ?>px"></span><?php endif; ?>
					</div>
					<span class="flowsmtp-chart-label"><?php echo esc_html( mysql2date( 'j', $day . ' 00:00:00' ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<div class="flowsmtp-legend">
			<span class="flowsmtp-legend-item is-sent"><?php esc_html_e( 'Sent', 'flow-smtp' ); ?></span>
			<span class="flowsmtp-legend-item is-failed"><?php esc_html_e( 'Failed', 'flow-smtp' ); ?></span>
			<span class="flowsmtp-legend-item is-pending"><?php esc_html_e( 'Pending', 'flow-smtp' ); ?></span>
		</div>

		<h2><?php esc_html_e( 'Most Frequent Errors', 'flow-smtp' ); ?></h2>
		<?php if ( empty( $errors ) ) : ?>
			<p class="flowsmtp-muted"><?php esc_html_e( 'No failures recorded. Nice and healthy!', 'flow-smtp' ); ?></p>
		<?php else : ?>
			<ul class="flowsmtp-checklist flowsmtp-errorlist">
				<?php foreach ( $errors as $error ) : ?>
					<li>
						<span class="flowsmtp-badge is-failed"><?php echo esc_html( number_format_i18n( (int) $error->total ) ); ?>&times;</span>
						<span class="flowsmtp-check-detail"><?php echo esc_html( wp_trim_words( $error->error_message, 30 ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p><a class="flowsmtp-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=failed' ) ); ?>"><?php esc_html_e( 'Review Failed Emails', 'flow-smtp' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	private function render_settings_tab() {
		$s = FlowSMTP::get_settings();
		?>
		<form method="post" action="options.php" class="flowsmtp-form">
			<?php settings_fields( 'flowsmtp_settings_group' ); ?>

			<h2><?php esc_html_e( 'Provider', 'flow-smtp' ); ?></h2>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'Email Provider', 'flow-smtp' ); ?></span>
					<select name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[provider]" id="flowsmtp-provider">
						<?php foreach ( FlowSMTP_Providers::all() as $slug => $preset ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['provider'], $slug ); ?>><?php echo esc_html( $preset['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Sending Method', 'flow-smtp' ); ?></span>
					<select name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[mailer_type]" id="flowsmtp-mailer-type">
						<option value="smtp" <?php selected( $s['mailer_type'], 'smtp' ); ?>><?php esc_html_e( 'SMTP', 'flow-smtp' ); ?></option>
						<option value="api" <?php selected( $s['mailer_type'], 'api' ); ?>><?php esc_html_e( 'HTTP API (SendGrid, Mailgun, Brevo)', 'flow-smtp' ); ?></option>
					</select>
				</label>
			</div>
			<p class="flowsmtp-muted" id="flowsmtp-provider-note" hidden></p>
			<p class="flowsmtp-muted"><?php esc_html_e( 'HTTP API sending bypasses SMTP entirely — ideal when your host blocks outbound SMTP ports (25/465/587). It is available for SendGrid, Mailgun and Brevo. Other providers always use SMTP.', 'flow-smtp' ); ?></p>

			<h2><?php esc_html_e( 'API Credentials', 'flow-smtp' ); ?></h2>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'API Key', 'flow-smtp' ); ?></span>
					<?php if ( defined( 'FLOWSMTP_API_KEY' ) ) : ?>
						<input type="password" value="" placeholder="<?php esc_attr_e( 'Defined in wp-config.php', 'flow-smtp' ); ?>" disabled />
					<?php else : ?>
						<input type="password" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[api_key]" value="<?php echo $s['api_key'] ? '********' : ''; ?>" autocomplete="new-password" />
					<?php endif; ?>
				</label>
				<label>
					<span><?php esc_html_e( 'Mailgun Sending Domain (Mailgun only)', 'flow-smtp' ); ?></span>
					<input type="text" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[api_domain]" value="<?php echo esc_attr( $s['api_domain'] ); ?>" placeholder="mg.example.com" />
				</label>
			</div>
			<p class="flowsmtp-muted"><?php esc_html_e( 'Only used when the sending method is HTTP API. The key is stored encrypted; define FLOWSMTP_API_KEY in wp-config.php to keep it out of the database entirely.', 'flow-smtp' ); ?></p>

			<h2><?php esc_html_e( 'SMTP Server', 'flow-smtp' ); ?></h2>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'SMTP Host', 'flow-smtp' ); ?></span>
					<input type="text" id="flowsmtp-host" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[host]" value="<?php echo esc_attr( $s['host'] ); ?>" placeholder="smtp.example.com" />
				</label>
				<label>
					<span><?php esc_html_e( 'Port', 'flow-smtp' ); ?></span>
					<input type="number" id="flowsmtp-port" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[port]" value="<?php echo esc_attr( $s['port'] ); ?>" placeholder="587" />
				</label>
				<label>
					<span><?php esc_html_e( 'Encryption', 'flow-smtp' ); ?></span>
					<select id="flowsmtp-encryption" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[encryption]">
						<option value="tls" <?php selected( $s['encryption'], 'tls' ); ?>>TLS (587)</option>
						<option value="ssl" <?php selected( $s['encryption'], 'ssl' ); ?>>SSL (465)</option>
						<option value="none" <?php selected( $s['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'flow-smtp' ); ?></option>
					</select>
				</label>
			</div>

			<h2><?php esc_html_e( 'Authentication', 'flow-smtp' ); ?></h2>
			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[auth]" value="1" <?php checked( $s['auth'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Use SMTP authentication', 'flow-smtp' ); ?>
			</label>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'Username', 'flow-smtp' ); ?></span>
					<input type="text" id="flowsmtp-username" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[username]" value="<?php echo esc_attr( $s['username'] ); ?>" autocomplete="off" />
				</label>
				<label>
					<span><?php esc_html_e( 'Password', 'flow-smtp' ); ?></span>
					<?php if ( defined( 'FLOWSMTP_SMTP_PASSWORD' ) ) : ?>
						<input type="password" value="" placeholder="<?php esc_attr_e( 'Defined in wp-config.php', 'flow-smtp' ); ?>" disabled />
					<?php else : ?>
						<input type="password" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[password]" value="<?php echo $s['password'] ? '********' : ''; ?>" autocomplete="new-password" />
					<?php endif; ?>
				</label>
			</div>
			<p class="flowsmtp-muted"><?php esc_html_e( 'Security tip: define FLOWSMTP_SMTP_PASSWORD in wp-config.php and the password never touches the database. FLOWSMTP_ENCRYPTION_KEY can also be defined to decouple stored secrets from the WordPress salts.', 'flow-smtp' ); ?></p>

			<h2><?php esc_html_e( 'Fallback Connection', 'flow-smtp' ); ?></h2>
			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[fallback]" value="1" <?php checked( $s['fallback'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Retry through a backup SMTP connection when the primary fails', 'flow-smtp' ); ?>
			</label>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'Fallback SMTP Host', 'flow-smtp' ); ?></span>
					<input type="text" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[fallback_host]" value="<?php echo esc_attr( $s['fallback_host'] ); ?>" placeholder="smtp.backup-provider.com" />
				</label>
				<label>
					<span><?php esc_html_e( 'Port', 'flow-smtp' ); ?></span>
					<input type="number" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[fallback_port]" value="<?php echo esc_attr( $s['fallback_port'] ); ?>" placeholder="587" />
				</label>
				<label>
					<span><?php esc_html_e( 'Encryption', 'flow-smtp' ); ?></span>
					<select name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[fallback_encryption]">
						<option value="tls" <?php selected( $s['fallback_encryption'], 'tls' ); ?>>TLS (587)</option>
						<option value="ssl" <?php selected( $s['fallback_encryption'], 'ssl' ); ?>>SSL (465)</option>
						<option value="none" <?php selected( $s['fallback_encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'flow-smtp' ); ?></option>
					</select>
				</label>
			</div>
			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[fallback_auth]" value="1" <?php checked( $s['fallback_auth'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Fallback connection uses authentication', 'flow-smtp' ); ?>
			</label>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'Fallback Username', 'flow-smtp' ); ?></span>
					<input type="text" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[fallback_username]" value="<?php echo esc_attr( $s['fallback_username'] ); ?>" autocomplete="off" />
				</label>
				<label>
					<span><?php esc_html_e( 'Fallback Password', 'flow-smtp' ); ?></span>
					<input type="password" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[fallback_password]" value="<?php echo $s['fallback_password'] ? '********' : ''; ?>" autocomplete="new-password" />
				</label>
			</div>
			<p class="flowsmtp-muted"><?php esc_html_e( 'If the primary connection (or API) fails, the email is immediately retried once through this backup connection before scheduled retries kick in. Use a different provider for the fallback so one outage cannot take both down.', 'flow-smtp' ); ?></p>

			<h2><?php esc_html_e( 'Sender', 'flow-smtp' ); ?></h2>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'From Email', 'flow-smtp' ); ?></span>
					<input type="email" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>" />
				</label>
				<label>
					<span><?php esc_html_e( 'From Name', 'flow-smtp' ); ?></span>
					<input type="text" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>" />
				</label>
			</div>
			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[force_from]" value="1" <?php checked( $s['force_from'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Force this From address for all outgoing email', 'flow-smtp' ); ?>
			</label>
			<p class="flowsmtp-muted"><?php esc_html_e( 'Deliverability tip: the From domain should match the domain your SMTP provider signs (DKIM), or your emails may land in spam.', 'flow-smtp' ); ?></p>

			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[auto_plaintext]" value="1" <?php checked( $s['auto_plaintext'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Add a plain-text alternative to HTML emails', 'flow-smtp' ); ?>
			</label>
			<p class="flowsmtp-muted">
				<?php esc_html_e( 'HTML-only messages trip SpamAssassin\'s MIME_HTML_ONLY rule and score worse with the large mailbox providers. When enabled, FlowSMTP derives a plain-text version of any HTML email that does not already supply one, so a proper multipart/alternative message is sent. Recommended, especially for contact form notifications.', 'flow-smtp' ); ?>
				<?php if ( defined( 'FLOWSMTP_AUTO_PLAINTEXT' ) ) : ?>
					<br /><strong><?php esc_html_e( 'The FLOWSMTP_AUTO_PLAINTEXT constant in wp-config.php currently overrides this setting.', 'flow-smtp' ); ?></strong>
				<?php endif; ?>
			</p>

			<h2><?php esc_html_e( 'Test Mode', 'flow-smtp' ); ?></h2>
			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[test_mode]" value="1" <?php checked( $s['test_mode'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Intercept all outgoing email (staging / development mode)', 'flow-smtp' ); ?>
			</label>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'What to do with intercepted email', 'flow-smtp' ); ?></span>
					<select name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[test_mode_action]">
						<option value="redirect" <?php selected( $s['test_mode_action'], 'redirect' ); ?>><?php esc_html_e( 'Redirect to a single address', 'flow-smtp' ); ?></option>
						<option value="log" <?php selected( $s['test_mode_action'], 'log' ); ?>><?php esc_html_e( 'Log only — do not deliver', 'flow-smtp' ); ?></option>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Redirect all email to', 'flow-smtp' ); ?></span>
					<input type="email" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[test_mode_to]" value="<?php echo esc_attr( $s['test_mode_to'] ); ?>" />
				</label>
			</div>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'Allowlist (one address or domain per line)', 'flow-smtp' ); ?></span>
					<textarea name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[test_mode_allowlist]" rows="4" placeholder="you@example.com&#10;yourcompany.com"><?php echo esc_textarea( $s['test_mode_allowlist'] ); ?></textarea>
				</label>
			</div>
			<p class="flowsmtp-muted"><?php esc_html_e( 'Perfect for staging sites and site clones: customers never receive email from a copy of your site. Intercepted emails get a “Test Mode” subject prefix, a banner listing the original recipients, and their Cc/Bcc headers are stripped. Addresses or domains on the allowlist are still delivered normally, and the FlowSMTP “Send Test” tab always bypasses test mode so you can still verify your configuration.', 'flow-smtp' ); ?></p>

			<h2><?php esc_html_e( 'Open & Click Tracking', 'flow-smtp' ); ?></h2>
			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[track_opens]" value="1" <?php checked( $s['track_opens'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Track opens (adds an invisible 1×1 pixel to HTML emails)', 'flow-smtp' ); ?>
			</label>
			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[track_clicks]" value="1" <?php checked( $s['track_clicks'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Track clicks (links are rewritten through a signed redirect)', 'flow-smtp' ); ?>
			</label>
			<p class="flowsmtp-muted"><?php esc_html_e( 'Tracking applies to HTML emails only and stores nothing personal — no IP addresses or user agents, just open/click counters and timestamps on the log entry. Click links are signed, so the redirect can never be abused to point somewhere else. Note that many mail clients block remote images, so open rates are always an underestimate, and privacy laws in your region may require disclosure or consent.', 'flow-smtp' ); ?></p>

			<h2><?php esc_html_e( 'Logging', 'flow-smtp' ); ?></h2>
			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[logging]" value="1" <?php checked( $s['logging'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Log all outgoing emails', 'flow-smtp' ); ?>
			</label>
			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[log_body]" value="1" <?php checked( $s['log_body'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Log full email bodies (password reset emails are always redacted)', 'flow-smtp' ); ?>
			</label>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'Keep logs for (days, 0 = forever)', 'flow-smtp' ); ?></span>
					<input type="number" min="0" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[log_retention]" value="<?php echo esc_attr( $s['log_retention'] ); ?>" />
				</label>
			</div>

			<h2><?php esc_html_e( 'Delivery Retries', 'flow-smtp' ); ?></h2>
			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[auto_retry]" value="1" <?php checked( $s['auto_retry'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Automatically retry failed emails (exponential backoff: 5, 10, 20 minutes…)', 'flow-smtp' ); ?>
			</label>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'Maximum retry attempts', 'flow-smtp' ); ?></span>
					<input type="number" min="0" max="10" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[max_retries]" value="<?php echo esc_attr( $s['max_retries'] ); ?>" />
				</label>
			</div>
			<p class="flowsmtp-muted"><?php esc_html_e( 'Retries run in the background via WP-Cron. Test emails are never retried automatically.', 'flow-smtp' ); ?></p>

			<h2><?php esc_html_e( 'Failure Alerts', 'flow-smtp' ); ?></h2>
			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[alerts]" value="1" <?php checked( $s['alerts'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Notify me when an email permanently fails (after all retries)', 'flow-smtp' ); ?>
			</label>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'Alert email address', 'flow-smtp' ); ?></span>
					<input type="email" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[alert_email]" value="<?php echo esc_attr( $s['alert_email'] ); ?>" />
				</label>
				<label>
					<span><?php esc_html_e( 'Webhook URL (Slack / Discord compatible, optional)', 'flow-smtp' ); ?></span>
					<input type="url" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[alert_webhook]" value="<?php echo esc_attr( $s['alert_webhook'] ); ?>" placeholder="<?php esc_attr_e( 'Slack or Discord incoming webhook URL', 'flow-smtp' ); ?>" />
				</label>
			</div>
			<p class="flowsmtp-muted"><?php esc_html_e( 'Alerts are throttled to one per 5 minutes. Tip: if your SMTP server is down, the alert email may also fail — a webhook is the more reliable channel.', 'flow-smtp' ); ?></p>

			<h2><?php esc_html_e( 'Uninstall', 'flow-smtp' ); ?></h2>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'When this plugin is deleted', 'flow-smtp' ); ?></span>
					<select name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[uninstall_data]">
						<option value="keep" <?php selected( $s['uninstall_data'], 'keep' ); ?>><?php esc_html_e( 'Keep all settings and email logs (recommended)', 'flow-smtp' ); ?></option>
						<option value="delete" <?php selected( $s['uninstall_data'], 'delete' ); ?>><?php esc_html_e( 'Delete all FlowSMTP data permanently', 'flow-smtp' ); ?></option>
					</select>
				</label>
			</div>
			<p class="flowsmtp-muted"><?php esc_html_e( 'Keeping data means you can deactivate, update or reinstall FlowSMTP without losing your configuration or your email audit trail. Choosing to delete removes the settings, the log table and all scheduled retries when the plugin is deleted from the Plugins screen — this cannot be undone, so export your logs to CSV first if you may need them.', 'flow-smtp' ); ?></p>

			<p class="flowsmtp-actions"><button type="submit" class="flowsmtp-btn is-primary"><?php esc_html_e( 'Save Settings', 'flow-smtp' ); ?></button></p>
		</form>
		<?php
	}

	private function render_deliverability_tab() {
		$s      = FlowSMTP::get_settings();
		$domain = is_email( $s['from_email'] ) ? substr( strrchr( $s['from_email'], '@' ), 1 ) : '';
		?>
		<h2><?php esc_html_e( 'Domain Health Check', 'flow-smtp' ); ?></h2>
		<p class="flowsmtp-muted"><?php esc_html_e( 'Checks the DNS records that decide whether your email lands in the inbox or in spam. Run this against your From address domain.', 'flow-smtp' ); ?></p>
		<div class="flowsmtp-grid">
			<label>
				<span><?php esc_html_e( 'Domain', 'flow-smtp' ); ?></span>
				<input type="text" id="flowsmtp-check-domain" value="<?php echo esc_attr( $domain ); ?>" placeholder="example.com" />
			</label>
			<label>
				<span><?php esc_html_e( 'DKIM selector (optional)', 'flow-smtp' ); ?></span>
				<input type="text" id="flowsmtp-check-selector" placeholder="<?php esc_attr_e( 'e.g. google, selector1, k1', 'flow-smtp' ); ?>" />
			</label>
		</div>
		<p class="flowsmtp-actions"><button type="button" id="flowsmtp-run-check" class="flowsmtp-btn is-primary"><?php esc_html_e( 'Run Check', 'flow-smtp' ); ?></button></p>
		<div id="flowsmtp-check-results" hidden></div>
		<p class="flowsmtp-muted"><?php esc_html_e( 'For a full spam-score test including content analysis, send a test email to a mail-tester.com address from the Send Test tab.', 'flow-smtp' ); ?></p>
		<?php
	}

	private function render_test_tab() {
		?>
		<h2><?php esc_html_e( 'Send a Test Email', 'flow-smtp' ); ?></h2>
		<p class="flowsmtp-muted"><?php esc_html_e( 'Verify your configuration by sending a real email through your server or provider API. The result is also recorded in the email log. Test emails always bypass test mode.', 'flow-smtp' ); ?></p>
		<div class="flowsmtp-grid">
			<label>
				<span><?php esc_html_e( 'Recipient', 'flow-smtp' ); ?></span>
				<input type="email" id="flowsmtp-test-to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Format', 'flow-smtp' ); ?></span>
				<select id="flowsmtp-test-html">
					<option value="1"><?php esc_html_e( 'HTML', 'flow-smtp' ); ?></option>
					<option value="0"><?php esc_html_e( 'Plain text', 'flow-smtp' ); ?></option>
				</select>
			</label>
		</div>
		<p class="flowsmtp-actions"><button type="button" id="flowsmtp-send-test" class="flowsmtp-btn is-primary"><?php esc_html_e( 'Send Test Email', 'flow-smtp' ); ?></button></p>
		<div id="flowsmtp-test-result" class="flowsmtp-notice" hidden></div>
		<p class="flowsmtp-muted"><?php esc_html_e( 'Tip: send a test to a mail-tester.com address to check your SPF, DKIM and DMARC setup and spam score.', 'flow-smtp' ); ?></p>
		<?php
	}

	/**
	 * Parse the stored attachments column into a display-friendly list.
	 *
	 * Handles serialized arrays, JSON arrays and newline/comma separated paths.
	 *
	 * @param mixed $raw Raw column value.
	 * @return array[] List of arrays with name, path, size and exists keys.
	 */
	private static function parse_attachments( $raw ) {
		if ( empty( $raw ) ) {
			return array();
		}

		$list = maybe_unserialize( $raw );

		if ( is_string( $list ) ) {
			$decoded = json_decode( $list, true );
			$list    = is_array( $decoded ) ? $decoded : preg_split( '/[\r\n,]+/', $list );
		}

		if ( ! is_array( $list ) ) {
			return array();
		}

		$items = array();

		foreach ( $list as $path ) {
			if ( ! is_string( $path ) ) {
				continue;
			}

			$path = trim( $path );
			if ( '' === $path ) {
				continue;
			}

			$exists = @file_exists( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$size   = $exists ? @filesize( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			$items[] = array(
				'name'   => basename( $path ),
				'path'   => $path,
				'size'   => false !== $size ? size_format( (int) $size ) : '',
				'exists' => (bool) $exists,
			);
		}

		return $items;
	}

	/**
	 * Whether the stored headers declare an HTML body.
	 *
	 * @param mixed $headers Stored headers.
	 * @return bool
	 */
	private static function headers_are_html( $headers ) {
		if ( is_array( $headers ) ) {
			$headers = implode( "\n", $headers );
		}

		return false !== stripos( (string) $headers, 'text/html' );
	}

	/**
	 * Render the logs table.
	 *
	 * @param string $status Filter by status ('' = all).
	 */
	private function render_logs( $status ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable

		$per_page = 20;
		$result   = $this->logger->query(
			array(
				'status'   => $status,
				'search'   => $search,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);

		$total_pages = (int) ceil( $result['total'] / $per_page );

		$base_args = array(
			'page' => self::PAGE_SLUG,
			'tab'  => 'failed' === $status ? 'failed' : 'logs',
		);
		if ( '' !== $search ) {
			$base_args['s'] = $search;
		}
		$base_url = add_query_arg( $base_args, admin_url( 'admin.php' ) );

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'flowsmtp_export_csv',
					'status' => $status,
					's'      => $search,
				),
				admin_url( 'admin-post.php' )
			),
			'flowsmtp_export'
		);
		?>
		<div class="flowsmtp-table-toolbar">
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="<?php echo 'failed' === $status ? 'failed' : 'logs'; ?>" />
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search recipient or subject…', 'flow-smtp' ); ?>" />
				<button type="submit" class="flowsmtp-btn"><?php esc_html_e( 'Search', 'flow-smtp' ); ?></button>
			</form>
			<div class="flowsmtp-toolbar-actions">
				<a href="<?php echo esc_url( $export_url ); ?>" class="flowsmtp-btn"><?php esc_html_e( 'Export CSV', 'flow-smtp' ); ?></a>
				<button type="button" class="flowsmtp-btn is-danger" id="flowsmtp-delete-selected"><?php esc_html_e( 'Delete Selected', 'flow-smtp' ); ?></button>
			</div>
		</div>

		<table class="flowsmtp-table">
			<thead>
				<tr>
					<th class="col-check"><input type="checkbox" id="flowsmtp-check-all" /></th>
					<th><?php esc_html_e( 'Recipient', 'flow-smtp' ); ?></th>
					<th><?php esc_html_e( 'Subject', 'flow-smtp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'flow-smtp' ); ?></th>
					<th><?php esc_html_e( 'Date', 'flow-smtp' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'flow-smtp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $result['items'] ) ) : ?>
					<tr><td colspan="6" class="flowsmtp-empty"><?php esc_html_e( 'No emails logged yet.', 'flow-smtp' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $result['items'] as $row ) : ?>
						<?php
						$files  = self::parse_attachments( $row->attachments );
						$opens  = isset( $row->opens ) ? (int) $row->opens : 0;
						$clicks = isset( $row->clicks ) ? (int) $row->clicks : 0;
						?>
						<tr>
							<td class="col-check"><input type="checkbox" class="flowsmtp-check" value="<?php echo esc_attr( $row->id ); ?>" /></td>
							<td><?php echo esc_html( $row->mail_to ); ?></td>
							<td>
								<?php echo esc_html( wp_trim_words( $row->subject, 10 ) ); ?>
								<?php if ( $row->is_test ) : ?><span class="flowsmtp-badge is-test"><?php esc_html_e( 'Test', 'flow-smtp' ); ?></span><?php endif; ?>
								<?php if ( $files ) : ?>
									<span class="flowsmtp-clip" title="<?php echo esc_attr( implode( ', ', wp_list_pluck( $files, 'name' ) ) ); ?>">
										<span class="dashicons dashicons-paperclip"></span><?php echo esc_html( count( $files ) ); ?>
									</span>
								<?php endif; ?>
								<?php if ( $opens > 0 ) : ?>
									<span class="flowsmtp-clip" title="<?php echo esc_attr( sprintf( /* translators: %s: date and time. */ __( 'First opened %s', 'flow-smtp' ), mysql2date( 'M j, Y g:i a', $row->first_open_at ) ) ); ?>">
										<span class="dashicons dashicons-visibility"></span><?php echo esc_html( $opens ); ?>
									</span>
								<?php endif; ?>
								<?php if ( $clicks > 0 ) : ?>
									<span class="flowsmtp-clip" title="<?php echo esc_attr( sprintf( /* translators: %s: URL. */ __( 'Last link clicked: %s', 'flow-smtp' ), (string) $row->last_click_url ) ); ?>">
										<span class="dashicons dashicons-admin-links"></span><?php echo esc_html( $clicks ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<span class="flowsmtp-badge is-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span>
								<?php if ( 'failed' === $row->status && $row->error_message ) : ?>
									<span class="flowsmtp-error" title="<?php echo esc_attr( $row->error_message ); ?>">&#9432;</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( mysql2date( 'M j, Y g:i a', $row->created_at ) ); ?></td>
							<td class="col-actions">
								<button type="button" class="flowsmtp-link flowsmtp-view" data-id="<?php echo esc_attr( $row->id ); ?>"><?php esc_html_e( 'View', 'flow-smtp' ); ?></button>
								<button type="button" class="flowsmtp-link flowsmtp-resend" data-id="<?php echo esc_attr( $row->id ); ?>"><?php esc_html_e( 'Resend', 'flow-smtp' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="flowsmtp-pagination">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => $base_url . '%_%',
							'format'  => '&paged=%#%',
							'current' => $paged,
							'total'   => $total_pages,
						)
					)
				);
				?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Stream the (filtered) email log as a CSV download.
	 */
	public function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'flow-smtp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'flowsmtp_export' );

		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=flowsmtp-logs-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'ID', 'To', 'Subject', 'Status', 'Test', 'Retries', 'Attachments', 'Opens', 'Clicks', 'Error', 'Created (site time)', 'Updated (site time)' ) );

		$page     = 1;
		$per_page = 500;

		do {
			$result = $this->logger->query(
				array(
					'status'   => $status,
					'search'   => $search,
					'per_page' => $per_page,
					'page'     => $page,
				)
			);

			foreach ( $result['items'] as $row ) {
				$files = self::parse_attachments( $row->attachments );

				fputcsv(
					$out,
					array(
						(int) $row->id,
						self::csv_safe( $row->mail_to ),
						self::csv_safe( $row->subject ),
						$row->status,
						$row->is_test ? 'yes' : 'no',
						(int) $row->retries,
						self::csv_safe( implode( ' | ', wp_list_pluck( $files, 'name' ) ) ),
						isset( $row->opens ) ? (int) $row->opens : 0,
						isset( $row->clicks ) ? (int) $row->clicks : 0,
						self::csv_safe( $row->error_message ),
						$row->created_at,
						$row->updated_at,
					)
				);
			}

			$page++;
		} while ( count( $result['items'] ) === $per_page );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@fclose( $out );
		exit;
	}

	/**
	 * Guard against CSV formula injection in spreadsheet apps.
	 *
	 * @param string $value Raw cell value.
	 * @return string
	 */
	private static function csv_safe( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/* ---------------------------------------------------------------------
	 * AJAX handlers
	 * ------------------------------------------------------------------- */

	private function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'flowsmtp_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'flow-smtp' ) ), 403 );
		}
	}

	public function ajax_send_test() {
		$this->verify_ajax();

		$to   = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		$html = ! empty( $_POST['html'] );

		// Test emails must always go out, even while test mode intercepts everything else.
		FlowSMTP_Test_Mode::$bypass = true;
		$result                     = flowsmtp()->mailer->send_test_email( $to, $html );
		FlowSMTP_Test_Mode::$bypass = false;

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	public function ajax_resend() {
		$this->verify_ajax();

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$result = $this->logger->resend( $id );

		if ( true === $result ) {
			wp_send_json_success( array( 'message' => __( 'Email resent successfully.', 'flow-smtp' ) ) );
		}
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	public function ajax_delete_logs() {
		$this->verify_ajax();

		$ids     = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		$deleted = $this->logger->delete( $ids );

		wp_send_json_success( array( 'message' => sprintf( _n( '%d entry deleted.', '%d entries deleted.', $deleted, 'flow-smtp' ), $deleted ) ) );
	}

	public function ajax_view_log() {
		$this->verify_ajax();

		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$log = $this->logger->get( $id );

		if ( ! $log ) {
			wp_send_json_error( array( 'message' => __( 'Log entry not found.', 'flow-smtp' ) ) );
		}

		$is_html = self::headers_are_html( $log->headers );

		wp_send_json_success(
			array(
				'to'          => $log->mail_to,
				'subject'     => $log->subject,
				// Sanitized server-side; additionally rendered inside a sandboxed iframe client-side.
				'message'     => wp_kses_post( $log->message ),
				// Raw stored body for the source view; escaped as text client-side.
				'raw'         => (string) $log->message,
				'isHtml'      => $is_html,
				'format'      => $is_html ? __( 'HTML', 'flow-smtp' ) : __( 'Plain text', 'flow-smtp' ),
				'headers'     => $log->headers,
				'status'      => $log->status,
				'error'       => $log->error_message,
				'date'        => mysql2date( 'M j, Y g:i a', $log->created_at ),
				'retries'     => (int) $log->retries,
				'opens'       => isset( $log->opens ) ? (int) $log->opens : 0,
				'clicks'      => isset( $log->clicks ) ? (int) $log->clicks : 0,
				'lastClick'   => isset( $log->last_click_url ) ? (string) $log->last_click_url : '',
				'attachments' => self::parse_attachments( $log->attachments ),
			)
		);
	}

	/**
	 * Send a copy of a logged email to an arbitrary address for previewing.
	 *
	 * The body and content type are preserved; the subject is prefixed so the
	 * copy is never mistaken for the original. Attachments are not re-attached.
	 */
	public function ajax_preview_send() {
		$this->verify_ajax();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';

		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'flow-smtp' ) ) );
		}

		$log = $this->logger->get( $id );
		if ( ! $log ) {
			wp_send_json_error( array( 'message' => __( 'Log entry not found.', 'flow-smtp' ) ) );
		}

		$headers = array();
		if ( self::headers_are_html( $log->headers ) ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}

		$error   = '';
		$capture = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error->get_error_message();
		};

		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail(
			$to,
			/* translators: %s: original email subject. */
			sprintf( __( '[Preview] %s', 'flow-smtp' ), $log->subject ),
			$log->message,
			$headers
		);
		remove_action( 'wp_mail_failed', $capture );

		if ( $sent ) {
			wp_send_json_success(
				array(
					/* translators: %s: recipient email address. */
					'message' => sprintf( __( 'Preview sent to %s.', 'flow-smtp' ), $to ),
				)
			);
		}

		wp_send_json_error( array( 'message' => $error ? $error : __( 'The preview could not be sent.', 'flow-smtp' ) ) );
	}

	public function ajax_check_domain() {
		$this->verify_ajax();

		$domain   = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		$selector = isset( $_POST['selector'] ) ? sanitize_text_field( wp_unslash( $_POST['selector'] ) ) : '';

		$checks = FlowSMTP_Deliverability::check( $domain, $selector );

		if ( is_wp_error( $checks ) ) {
			wp_send_json_error( array( 'message' => $checks->get_error_message() ) );
		}

		// From-alignment hint: SMTP username domain vs checked domain.
		$settings = FlowSMTP::get_settings();
		if ( false !== strpos( $settings['username'], '@' ) ) {
			$user_domain = strtolower( substr( strrchr( $settings['username'], '@' ), 1 ) );
			if ( $user_domain && strtolower( trim( $domain ) ) !== $user_domain ) {
				$checks[] = array(
					'id'     => 'alignment',
					'label'  => __( 'From alignment', 'flow-smtp' ),
					'status' => 'warn',
					/* translators: 1: SMTP username domain, 2: checked domain. */
					'detail' => sprintf( __( 'Your SMTP username domain (%1$s) differs from the checked domain (%2$s). Make sure your provider is authorized to send for %2$s (SPF include and DKIM key).', 'flow-smtp' ), $user_domain, $domain ),
				);
			}
		}

		wp_send_json_success( array( 'checks' => $checks ) );
	}
}
