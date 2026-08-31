<?php
/**
 * Email logger: records every wp_mail() call, tracks failures, supports resend,
 * automatic retries and failure alerts.
 *
 * @package FlowSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowSMTP_Logger {

	/**
	 * Stack of log row ids for emails currently being sent (LIFO so nested
	 * wp_mail() calls resolve to the correct row).
	 *
	 * @var int[]
	 */
	private $log_id_stack = array();

	/**
	 * Whether the current send is a resend (skip duplicate logging).
	 *
	 * @var bool
	 */
	private $is_resend = false;

	/**
	 * Whether we are inside an explicit test-email send.
	 *
	 * @var bool
	 */
	private $test_context = false;

	/**
	 * Whether the next logged email body must be redacted (sensitive content).
	 *
	 * @var bool
	 */
	private $redact_next = false;

	/**
	 * Whether an alert email is currently being sent (recursion guard).
	 *
	 * @var bool
	 */
	private $is_alerting = false;

	public function __construct() {
		add_filter( 'wp_mail', array( $this, 'capture_mail' ), PHP_INT_MAX );
		add_filter( 'pre_wp_mail', array( $this, 'on_pre_wp_mail' ), PHP_INT_MAX, 2 );
		add_action( 'wp_mail_succeeded', array( $this, 'on_mail_succeeded' ), 10, 1 );
		add_action( 'wp_mail_failed', array( $this, 'on_mail_failed' ), 10, 1 );

		// Automatic retry of failed sends (WP-Cron).
		add_action( 'flowsmtp_retry_email', array( $this, 'retry_email' ) );

		// Password reset emails contain live reset links: never store their body.
		add_filter( 'retrieve_password_message', array( $this, 'flag_sensitive' ), PHP_INT_MAX );

		// Daily retention cleanup.
		add_action( 'flowsmtp_daily_cleanup', array( $this, 'cleanup_old_logs' ) );
		if ( ! wp_next_scheduled( 'flowsmtp_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'flowsmtp_daily_cleanup' );
		}
	}

	/**
	 * Table name helper.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'flowsmtp_email_log';
	}

	/**
	 * Toggle the explicit test-email context.
	 *
	 * @param bool $on Whether a test email is being sent.
	 */
	public function set_test_context( $on ) {
		$this->test_context = (bool) $on;
	}

	/**
	 * Mark the next captured email as sensitive (body will be redacted).
	 *
	 * @param string $message Password reset message (unchanged).
	 * @return string
	 */
	public function flag_sensitive( $message ) {
		$this->redact_next = true;
		return $message;
	}

	/**
	 * Log outgoing mail before it is sent.
	 *
	 * @param array $atts wp_mail() attributes.
	 * @return array Unmodified attributes.
	 */
	public function capture_mail( $atts ) {
		$settings = FlowSMTP::get_settings();

		if ( empty( $settings['logging'] ) || $this->is_resend ) {
			$this->redact_next = false;
			return $atts;
		}

		global $wpdb;

		$to          = is_array( $atts['to'] ) ? implode( ', ', $atts['to'] ) : (string) $atts['to'];
		$headers     = isset( $atts['headers'] ) ? ( is_array( $atts['headers'] ) ? implode( "\n", $atts['headers'] ) : (string) $atts['headers'] ) : '';
		$attachments = isset( $atts['attachments'] ) ? ( is_array( $atts['attachments'] ) ? implode( "\n", $atts['attachments'] ) : (string) $atts['attachments'] ) : '';

		$message = isset( $atts['message'] ) ? (string) $atts['message'] : '';
		if ( empty( $settings['log_body'] ) ) {
			$message = __( '[Body not logged — body logging is disabled in FlowSMTP settings.]', 'flow-smtp' );
		} elseif ( $this->redact_next ) {
			$message = __( '[Redacted — this email contains a password reset link.]', 'flow-smtp' );
		}
		$this->redact_next = false;

		$wpdb->insert(
			self::table(),
			array(
				'mail_to'     => $to,
				'subject'     => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
				'message'     => $message,
				'headers'     => $headers,
				'attachments' => $attachments,
				'status'      => 'pending',
				'is_test'     => $this->test_context ? 1 : 0,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		$this->log_id_stack[] = (int) $wpdb->insert_id;

		return $atts;
	}

	/**
	 * Resolve the log row when another plugin short-circuits wp_mail() via
	 * pre_wp_mail, so rows never get stuck in 'pending'.
	 *
	 * @param null|bool $short_circuit Short-circuit return value.
	 * @param array     $atts          Mail attributes.
	 * @return null|bool Unchanged value.
	 */
	public function on_pre_wp_mail( $short_circuit, $atts ) {
		if ( null !== $short_circuit && ! empty( $this->log_id_stack ) ) {
			$id = array_pop( $this->log_id_stack );
			if ( $short_circuit ) {
				$this->update_status( $id, 'sent', __( 'Handled by another plugin (pre_wp_mail).', 'flow-smtp' ) );
			} else {
				$this->update_status( $id, 'failed', __( 'Blocked or handled by another plugin (pre_wp_mail).', 'flow-smtp' ) );
			}
		}
		return $short_circuit;
	}

	/**
	 * Mark the current log entry as sent.
	 *
	 * @param array $mail_data Mail data from wp_mail_succeeded.
	 */
	public function on_mail_succeeded( $mail_data ) {
		$id = array_pop( $this->log_id_stack );
		if ( $id ) {
			$this->update_status( $id, 'sent' );
		}
	}

	/**
	 * Mark the current log entry as failed, store the error, queue a retry
	 * or send a failure alert when no retry will happen.
	 *
	 * @param WP_Error $error Error from wp_mail_failed.
	 */
	public function on_mail_failed( $error ) {
		$id = array_pop( $this->log_id_stack );
		if ( $id ) {
			$this->update_status( $id, 'failed', $error->get_error_message() );
			if ( ! $this->maybe_schedule_retry( $id ) ) {
				$this->maybe_send_alert( $id );
			}
		}
	}

	/**
	 * Schedule an automatic retry for a failed email if enabled and the
	 * attempt budget is not exhausted. Exponential backoff: 5, 10, 20 min…
	 *
	 * @param int $id Log id.
	 * @return bool Whether a retry was scheduled (or already pending).
	 */
	public function maybe_schedule_retry( $id ) {
		$settings = FlowSMTP::get_settings();

		if ( empty( $settings['auto_retry'] ) ) {
			return false;
		}

		$log = $this->get( $id );
		if ( ! $log || $log->is_test ) {
			return false; // Never auto-retry test emails.
		}

		$max = min( 10, max( 0, (int) $settings['max_retries'] ) );
		if ( (int) $log->retries >= $max ) {
			return false;
		}

		/**
		 * Filter the retry delay in seconds.
		 *
		 * @param int    $delay Delay in seconds.
		 * @param object $log   Log row.
		 */
		$delay = (int) apply_filters( 'flowsmtp_retry_delay', 5 * MINUTE_IN_SECONDS * pow( 2, (int) $log->retries ), $log );

		if ( ! wp_next_scheduled( 'flowsmtp_retry_email', array( (int) $id ) ) ) {
			wp_schedule_single_event( time() + $delay, 'flowsmtp_retry_email', array( (int) $id ) );
		}

		return true;
	}

	/**
	 * WP-Cron callback: retry a failed email; reschedule or alert on failure.
	 *
	 * @param int $id Log id.
	 */
	public function retry_email( $id ) {
		$id  = (int) $id;
		$log = $this->get( $id );

		// Only retry rows that are still failed (user may have resent manually).
		if ( ! $log || 'failed' !== $log->status ) {
			return;
		}

		$result = $this->resend( $id );

		if ( true !== $result && ! $this->maybe_schedule_retry( $id ) ) {
			$this->maybe_send_alert( $id );
		}
	}

	/**
	 * Notify the site owner that an email has permanently failed.
	 *
	 * Sends an email alert and/or posts to a Slack-compatible webhook.
	 * Throttled to one alert per 5 minutes to avoid alert storms; never fires
	 * for test emails; guarded against recursion when the alert email itself
	 * fails to send.
	 *
	 * @param int $id Log id.
	 */
	private function maybe_send_alert( $id ) {
		if ( $this->is_alerting ) {
			return;
		}

		$settings = FlowSMTP::get_settings();
		if ( empty( $settings['alerts'] ) ) {
			return;
		}

		$log = $this->get( $id );
		if ( ! $log || $log->is_test ) {
			return;
		}

		if ( get_transient( 'flowsmtp_alert_lock' ) ) {
			return;
		}
		set_transient( 'flowsmtp_alert_lock', 1, 5 * MINUTE_IN_SECONDS );

		$site = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		/* translators: 1: site name, 2: email subject. */
		$subject = sprintf( __( '[%1$s] Email delivery failed: %2$s', 'flow-smtp' ), $site, $log->subject );

		$lines = array(
			sprintf( __( 'An email could not be delivered on %s.', 'flow-smtp' ), home_url() ),
			'',
			sprintf( __( 'To: %s', 'flow-smtp' ), $log->mail_to ),
			sprintf( __( 'Subject: %s', 'flow-smtp' ), $log->subject ),
			sprintf( __( 'Error: %s', 'flow-smtp' ), $log->error_message ),
			sprintf( __( 'Attempts: %d', 'flow-smtp' ), (int) $log->retries + 1 ),
			sprintf( __( 'Date: %s', 'flow-smtp' ), $log->created_at ),
			'',
			sprintf( __( 'Review it here: %s', 'flow-smtp' ), admin_url( 'admin.php?page=flow-smtp&tab=failed' ) ),
		);
		$body  = implode( "\n", $lines );

		if ( ! empty( $settings['alert_email'] ) && is_email( $settings['alert_email'] ) ) {
			$this->is_alerting = true;
			wp_mail( $settings['alert_email'], $subject, $body );
			$this->is_alerting = false;
		}

		if ( ! empty( $settings['alert_webhook'] ) ) {
			wp_remote_post(
				$settings['alert_webhook'],
				array(
					'timeout' => 5,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array( 'text' => $subject . "\n" . $body ) ),
				)
			);
		}
	}

	/**
	 * Update the status of a log row.
	 *
	 * @param int    $id     Log id.
	 * @param string $status sent|failed|pending.
	 * @param string $error  Optional error message.
	 */
	public function update_status( $id, $status, $error = '' ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status'        => $status,
				'error_message' => $error,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get a single log row.
	 *
	 * @param int $id Log id.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	/**
	 * Query log rows.
	 *
	 * @param array $args status, search, per_page, page, orderby, order.
	 * @return array{items: array, total: int}
	 */
	public function query( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'   => '',
			'search'   => '',
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( $args['status'] && in_array( $args['status'], array( 'sent', 'failed', 'pending' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(mail_to LIKE %s OR subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$orderby = in_array( $args['orderby'], array( 'created_at', 'subject', 'status', 'mail_to' ), true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$offset  = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );

		$count_sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$items_sql  = 'SELECT * FROM ' . self::table() . " WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params_all = array_merge( $params, array( (int) $args['per_page'], $offset ) );
		$items      = $wpdb->get_results( $wpdb->prepare( $items_sql, $params_all ) );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Dashboard stats.
	 *
	 * @return array
	 */
	public function get_stats() {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS total FROM ' . self::table() . ' GROUP BY status', OBJECT_K );

		return array(
			'sent'    => isset( $rows['sent'] ) ? (int) $rows['sent']->total : 0,
			'failed'  => isset( $rows['failed'] ) ? (int) $rows['failed']->total : 0,
			'pending' => isset( $rows['pending'] ) ? (int) $rows['pending']->total : 0,
		);
	}

	/**
	 * Per-day sent/failed/pending counts for the last N days (site timezone),
	 * with empty days filled in so charts have a continuous axis.
	 *
	 * @param int $days Number of days including today.
	 * @return array<string, array{sent:int, failed:int, pending:int}> Keyed by Y-m-d.
	 */
	public function get_daily_stats( $days = 14 ) {
		global $wpdb;

		$days = max( 1, (int) $days );
		$now  = current_time( 'timestamp' );

		$data = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$data[ gmdate( 'Y-m-d', $now - $i * DAY_IN_SECONDS ) ] = array(
				'sent'    => 0,
				'failed'  => 0,
				'pending' => 0,
			);
		}

		$since = gmdate( 'Y-m-d 00:00:00', $now - ( $days - 1 ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(created_at) AS day, status, COUNT(*) AS total FROM ' . self::table() . ' WHERE created_at >= %s GROUP BY DATE(created_at), status',
				$since
			)
		);

		foreach ( $rows as $row ) {
			if ( isset( $data[ $row->day ][ $row->status ] ) ) {
				$data[ $row->day ][ $row->status ] = (int) $row->total;
			}
		}

		return $data;
	}

	/**
	 * Most frequent failure messages.
	 *
	 * @param int $limit Max rows.
	 * @return array Rows with error_message and total.
	 */
	public function get_top_errors( $limit = 5 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT error_message, COUNT(*) AS total FROM ' . self::table() . " WHERE status = 'failed' AND error_message <> '' GROUP BY error_message ORDER BY total DESC, error_message ASC LIMIT %d",
				max( 1, (int) $limit )
			)
		);
	}

	/**
	 * Resend a logged email.
	 *
	 * @param int $id Log id.
	 * @return true|WP_Error
	 */
	public function resend( $id ) {
		$log = $this->get( $id );

		if ( ! $log ) {
			return new WP_Error( 'flowsmtp_not_found', __( 'Log entry not found.', 'flow-smtp' ) );
		}

		$headers     = $log->headers ? explode( "\n", $log->headers ) : array();
		$attachments = $log->attachments ? array_filter( explode( "\n", $log->attachments ), 'file_exists' ) : array();

		$this->is_resend = true;
		$sent            = wp_mail( $log->mail_to, $log->subject, $log->message, $headers, $attachments );
		$this->is_resend = false;

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET retries = retries + 1 WHERE id = %d', $id ) );

		if ( $sent ) {
			$this->update_status( $id, 'sent' );
			return true;
		}

		return new WP_Error( 'flowsmtp_resend_failed', __( 'Resend failed. Check the SMTP settings and the error log.', 'flow-smtp' ) );
	}

	/**
	 * Delete log rows.
	 *
	 * @param int[] $ids Log ids.
	 * @return int Rows deleted.
	 */
	public function delete( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE id IN ({$placeholders})", $ids ) );
	}

	/**
	 * Remove logs older than the configured retention window.
	 */
	public function cleanup_old_logs() {
		$settings  = FlowSMTP::get_settings();
		$retention = (int) $settings['log_retention'];

		if ( $retention <= 0 ) {
			return; // 0 = keep forever.
		}

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE created_at < %s',
				gmdate( 'Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS )
			)
		);
	}
}
