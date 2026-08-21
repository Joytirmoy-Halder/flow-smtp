<?php
/**
 * Admin UI: settings, email logs, failed emails, test email.
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

		$clean               = array();
		$clean['host']       = isset( $input['host'] ) ? sanitize_text_field( $input['host'] ) : '';
		$clean['port']       = isset( $input['port'] ) ? absint( $input['port'] ) : 587;
		$clean['encryption'] = isset( $input['encryption'] ) && in_array( $input['encryption'], array( 'none', 'ssl', 'tls' ), true ) ? $input['encryption'] : 'tls';
		$clean['auth']       = empty( $input['auth'] ) ? 0 : 1;
		$clean['username']   = isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '';

		// Keep the old password if the field was left as the mask.
		if ( isset( $input['password'] ) && '' !== $input['password'] && '********' !== $input['password'] ) {
			$clean['password'] = FlowSMTP_Mailer::encrypt( $input['password'] );
		} else {
			$clean['password'] = isset( $old['password'] ) ? $old['password'] : '';
		}

		$clean['from_email']    = isset( $input['from_email'] ) && is_email( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : $old['from_email'];
		$clean['from_name']     = isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '';
		$clean['force_from']    = empty( $input['force_from'] ) ? 0 : 1;
		$clean['logging']       = empty( $input['logging'] ) ? 0 : 1;
		$clean['log_retention'] = isset( $input['log_retention'] ) ? absint( $input['log_retention'] ) : 30;

		return $clean;
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'flowsmtp-admin', FLOWSMTP_URL . 'assets/css/admin.css', array(), FLOWSMTP_VERSION );
		wp_enqueue_script( 'flowsmtp-admin', FLOWSMTP_URL . 'assets/js/admin.js', array( 'jquery' ), FLOWSMTP_VERSION, true );

		wp_localize_script(
			'flowsmtp-admin',
			'FlowSMTP',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'flowsmtp_admin' ),
				'i18n'    => array(
					'sending'   => __( 'Sending…', 'flow-smtp' ),
					'resending' => __( 'Resending…', 'flow-smtp' ),
					'confirmDelete' => __( 'Delete the selected log entries? This cannot be undone.', 'flow-smtp' ),
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

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'settings' => __( 'Settings', 'flow-smtp' ),
			'logs'     => __( 'Email Logs', 'flow-smtp' ),
			'failed'   => __( 'Failed Emails', 'flow-smtp' ),
			'test'     => __( 'Send Test', 'flow-smtp' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
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
					case 'logs':
						$this->render_logs( '' );
						break;
					case 'failed':
						$this->render_logs( 'failed' );
						break;
					case 'test':
						$this->render_test_tab();
						break;
					default:
						$this->render_settings_tab();
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

	private function render_settings_tab() {
		$s = FlowSMTP::get_settings();
		?>
		<form method="post" action="options.php" class="flowsmtp-form">
			<?php settings_fields( 'flowsmtp_settings_group' ); ?>

			<h2><?php esc_html_e( 'SMTP Server', 'flow-smtp' ); ?></h2>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'SMTP Host', 'flow-smtp' ); ?></span>
					<input type="text" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[host]" value="<?php echo esc_attr( $s['host'] ); ?>" placeholder="smtp.example.com" />
				</label>
				<label>
					<span><?php esc_html_e( 'Port', 'flow-smtp' ); ?></span>
					<input type="number" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[port]" value="<?php echo esc_attr( $s['port'] ); ?>" placeholder="587" />
				</label>
				<label>
					<span><?php esc_html_e( 'Encryption', 'flow-smtp' ); ?></span>
					<select name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[encryption]">
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
					<input type="text" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[username]" value="<?php echo esc_attr( $s['username'] ); ?>" autocomplete="off" />
				</label>
				<label>
					<span><?php esc_html_e( 'Password', 'flow-smtp' ); ?></span>
					<input type="password" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[password]" value="<?php echo $s['password'] ? '********' : ''; ?>" autocomplete="new-password" />
				</label>
			</div>

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

			<h2><?php esc_html_e( 'Logging', 'flow-smtp' ); ?></h2>
			<label class="flowsmtp-toggle">
				<input type="checkbox" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[logging]" value="1" <?php checked( $s['logging'], 1 ); ?> />
				<span class="flowsmtp-slider"></span>
				<?php esc_html_e( 'Log all outgoing emails', 'flow-smtp' ); ?>
			</label>
			<div class="flowsmtp-grid">
				<label>
					<span><?php esc_html_e( 'Keep logs for (days, 0 = forever)', 'flow-smtp' ); ?></span>
					<input type="number" min="0" name="<?php echo esc_attr( FLOWSMTP_OPTION_KEY ); ?>[log_retention]" value="<?php echo esc_attr( $s['log_retention'] ); ?>" />
				</label>
			</div>

			<p class="flowsmtp-actions"><button type="submit" class="flowsmtp-btn is-primary"><?php esc_html_e( 'Save Settings', 'flow-smtp' ); ?></button></p>
		</form>
		<?php
	}

	private function render_test_tab() {
		?>
		<h2><?php esc_html_e( 'Send a Test Email', 'flow-smtp' ); ?></h2>
		<p class="flowsmtp-muted"><?php esc_html_e( 'Verify your SMTP configuration by sending a real email through your server. The result is also recorded in the email log.', 'flow-smtp' ); ?></p>
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
		<?php
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
		$base_url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . ( 'failed' === $status ? 'failed' : 'logs' ) );
		?>
		<div class="flowsmtp-table-toolbar">
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="<?php echo 'failed' === $status ? 'failed' : 'logs'; ?>" />
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search recipient or subject…', 'flow-smtp' ); ?>" />
				<button type="submit" class="flowsmtp-btn"><?php esc_html_e( 'Search', 'flow-smtp' ); ?></button>
			</form>
			<button type="button" class="flowsmtp-btn is-danger" id="flowsmtp-delete-selected"><?php esc_html_e( 'Delete Selected', 'flow-smtp' ); ?></button>
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
						<tr>
							<td class="col-check"><input type="checkbox" class="flowsmtp-check" value="<?php echo esc_attr( $row->id ); ?>" /></td>
							<td><?php echo esc_html( $row->mail_to ); ?></td>
							<td>
								<?php echo esc_html( wp_trim_words( $row->subject, 10 ) ); ?>
								<?php if ( $row->is_test ) : ?><span class="flowsmtp-badge is-test"><?php esc_html_e( 'Test', 'flow-smtp' ); ?></span><?php endif; ?>
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

		$result = flowsmtp()->mailer->send_test_email( $to, $html );

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

		wp_send_json_success(
			array(
				'to'      => $log->mail_to,
				'subject' => $log->subject,
				'message' => wp_kses_post( $log->message ),
				'headers' => $log->headers,
				'status'  => $log->status,
				'error'   => $log->error_message,
				'date'    => mysql2date( 'M j, Y g:i a', $log->created_at ),
				'retries' => (int) $log->retries,
			)
		);
	}
}
