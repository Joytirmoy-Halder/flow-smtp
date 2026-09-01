<?php
/**
 * WP-CLI commands.
 *
 * Usage: wp flowsmtp <command>
 *
 * @package FlowSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage FlowSMTP from the command line.
 */
class FlowSMTP_CLI {

	/**
	 * Logger instance.
	 *
	 * @return FlowSMTP_Logger
	 */
	private function logger() {
		return flowsmtp()->logger;
	}

	/**
	 * Send a test email through the configured connection.
	 *
	 * Test emails always bypass Test Mode, so this verifies real delivery.
	 *
	 * ## OPTIONS
	 *
	 * <to>
	 * : Recipient email address.
	 *
	 * [--plain]
	 * : Send a plain-text email instead of HTML.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowsmtp test you@example.com
	 *     wp flowsmtp test you@example.com --plain
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function test( $args, $assoc_args ) {
		$to = isset( $args[0] ) ? sanitize_email( $args[0] ) : '';

		if ( ! is_email( $to ) ) {
			WP_CLI::error( 'Please provide a valid recipient email address.' );
		}

		$html = ! WP_CLI\Utils\get_flag_value( $assoc_args, 'plain', false );

		FlowSMTP_Test_Mode::$bypass = true;
		$result                     = flowsmtp()->mailer->send_test_email( $to, $html );
		FlowSMTP_Test_Mode::$bypass = false;

		if ( ! empty( $result['success'] ) ) {
			WP_CLI::success( $result['message'] );
			return;
		}

		WP_CLI::error( $result['message'] );
	}

	/**
	 * Show the current configuration and delivery statistics.
	 *
	 * Secrets are never printed — only whether they are set.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowsmtp status
	 *     wp flowsmtp status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		$s     = FlowSMTP::get_settings();
		$stats = $this->logger()->get_stats();

		$rows = array(
			array(
				'setting' => 'version',
				'value'   => FLOWSMTP_VERSION,
			),
			array(
				'setting' => 'sending method',
				'value'   => 'api' === $s['mailer_type'] ? 'HTTP API' : 'SMTP',
			),
			array(
				'setting' => 'provider',
				'value'   => $s['provider'],
			),
			array(
				'setting' => 'host',
				'value'   => $s['host'] . ( $s['host'] ? ':' . $s['port'] . ' (' . $s['encryption'] . ')' : '' ),
			),
			array(
				'setting' => 'username',
				'value'   => $s['username'],
			),
			array(
				'setting' => 'password set',
				'value'   => ( $s['password'] || defined( 'FLOWSMTP_SMTP_PASSWORD' ) ) ? 'yes' : 'no',
			),
			array(
				'setting' => 'api key set',
				'value'   => ( $s['api_key'] || defined( 'FLOWSMTP_API_KEY' ) ) ? 'yes' : 'no',
			),
			array(
				'setting' => 'from',
				'value'   => trim( $s['from_name'] . ' <' . $s['from_email'] . '>' ),
			),
			array(
				'setting' => 'fallback connection',
				'value'   => $s['fallback'] ? ( $s['fallback_host'] ? $s['fallback_host'] : 'enabled (no host set)' ) : 'off',
			),
			array(
				'setting' => 'test mode',
				'value'   => $s['test_mode'] ? $s['test_mode_action'] : 'off',
			),
			array(
				'setting' => 'tracking',
				'value'   => implode( ', ', array_filter( array( $s['track_opens'] ? 'opens' : '', $s['track_clicks'] ? 'clicks' : '' ) ) ) ?: 'off',
			),
			array(
				'setting' => 'logging',
				'value'   => $s['logging'] ? 'on (' . ( $s['log_retention'] ? $s['log_retention'] . ' days' : 'forever' ) . ')' : 'off',
			),
			array(
				'setting' => 'auto retry',
				'value'   => $s['auto_retry'] ? 'up to ' . $s['max_retries'] . ' attempts' : 'off',
			),
			array(
				'setting' => 'logged: sent',
				'value'   => (string) $stats['sent'],
			),
			array(
				'setting' => 'logged: failed',
				'value'   => (string) $stats['failed'],
			),
			array(
				'setting' => 'logged: pending',
				'value'   => (string) $stats['pending'],
			),
		);

		if ( is_multisite() && class_exists( 'FlowSMTP_Multisite' ) ) {
			$rows[] = array(
				'setting' => 'network enforced',
				'value'   => FlowSMTP_Multisite::is_enforced() ? 'yes' : 'no',
			);
		}

		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		WP_CLI\Utils\format_items( $format, $rows, array( 'setting', 'value' ) );
	}

	/**
	 * List logged emails.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status.
	 * ---
	 * options:
	 *   - sent
	 *   - failed
	 *   - pending
	 * ---
	 *
	 * [--search=<term>]
	 * : Filter by recipient or subject.
	 *
	 * [--limit=<number>]
	 * : How many entries to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowsmtp logs
	 *     wp flowsmtp logs --status=failed --limit=50
	 *     wp flowsmtp logs --search=invoice --format=csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function logs( $args, $assoc_args ) {
		$limit  = max( 1, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 20 ) );
		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$result = $this->logger()->query(
			array(
				'status'   => (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'status', '' ),
				'search'   => (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'search', '' ),
				'per_page' => $limit,
				'page'     => 1,
			)
		);

		if ( empty( $result['items'] ) ) {
			WP_CLI::log( 'No matching log entries.' );
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::log( implode( ' ', wp_list_pluck( $result['items'], 'id' ) ) );
			return;
		}

		$rows = array();
		foreach ( $result['items'] as $row ) {
			$rows[] = array(
				'id'      => (int) $row->id,
				'to'      => $row->mail_to,
				'subject' => $row->subject,
				'status'  => $row->status,
				'retries' => (int) $row->retries,
				'opens'   => isset( $row->opens ) ? (int) $row->opens : 0,
				'clicks'  => isset( $row->clicks ) ? (int) $row->clicks : 0,
				'date'    => $row->created_at,
				'error'   => $row->error_message,
			);
		}

		WP_CLI\Utils\format_items(
			$format,
			$rows,
			array( 'id', 'to', 'subject', 'status', 'retries', 'opens', 'clicks', 'date', 'error' )
		);

		WP_CLI::log( sprintf( 'Showing %d of %d matching entries.', count( $rows ), (int) $result['total'] ) );
	}

	/**
	 * Resend one or more logged emails.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more log entry IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowsmtp resend 42
	 *     wp flowsmtp resend 42 43 44
	 *     wp flowsmtp resend $(wp flowsmtp logs --status=failed --format=ids)
	 *
	 * @param array $args Positional arguments.
	 */
	public function resend( $args ) {
		$sent   = 0;
		$failed = 0;

		foreach ( $args as $id ) {
			$id     = absint( $id );
			$result = $this->logger()->resend( $id );

			if ( true === $result ) {
				$sent++;
				WP_CLI::log( sprintf( '#%d resent.', $id ) );
				continue;
			}

			$failed++;
			WP_CLI::warning( sprintf( '#%d failed: %s', $id, $result->get_error_message() ) );
		}

		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( '%d resent, %d failed.', $sent, $failed ), false );
			WP_CLI::halt( 1 );
		}

		WP_CLI::success( sprintf( '%d email(s) resent.', $sent ) );
	}

	/**
	 * Delete old log entries using the retention policy.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<number>]
	 * : Override the configured retention period for this run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowsmtp cleanup
	 *     wp flowsmtp cleanup --days=7
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cleanup( $args, $assoc_args ) {
		$days = WP_CLI\Utils\get_flag_value( $assoc_args, 'days', null );

		$override = null;
		if ( null !== $days ) {
			$days = absint( $days );
			if ( $days < 1 ) {
				WP_CLI::error( '--days must be 1 or greater.' );
			}

			$override = static function ( $settings ) use ( $days ) {
				$settings['log_retention'] = $days;
				return $settings;
			};
			add_filter( 'flowsmtp_settings', $override, 99 );
		}

		$this->logger()->cleanup_old_logs();

		if ( $override ) {
			remove_filter( 'flowsmtp_settings', $override, 99 );
		}

		WP_CLI::success( 'Log cleanup complete.' );
	}

	/**
	 * Delete log entries.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Specific log entry IDs to delete.
	 *
	 * [--status=<status>]
	 * : Delete every entry with this status instead of specific IDs.
	 * ---
	 * options:
	 *   - sent
	 *   - failed
	 *   - pending
	 * ---
	 *
	 * [--all]
	 * : Delete the entire email log.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowsmtp delete 12 13
	 *     wp flowsmtp delete --status=sent
	 *     wp flowsmtp delete --all --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ) {
		$status = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'status', '' );
		$all    = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );

		if ( ! $args && ! $status && ! $all ) {
			WP_CLI::error( 'Pass one or more IDs, or use --status=<status> or --all.' );
		}

		if ( $args ) {
			$deleted = $this->logger()->delete( array_map( 'absint', $args ) );
			WP_CLI::success( sprintf( '%d entry/entries deleted.', $deleted ) );
			return;
		}

		WP_CLI::confirm(
			$all ? 'Delete the ENTIRE email log?' : sprintf( 'Delete every log entry with status "%s"?', $status ),
			$assoc_args
		);

		$deleted = 0;

		// Batch through the log so huge tables do not exhaust memory.
		do {
			$result = $this->logger()->query(
				array(
					'status'   => $all ? '' : $status,
					'per_page' => 500,
					'page'     => 1,
				)
			);

			if ( empty( $result['items'] ) ) {
				break;
			}

			$deleted += $this->logger()->delete( array_map( 'absint', wp_list_pluck( $result['items'], 'id' ) ) );
		} while ( ! empty( $result['items'] ) );

		WP_CLI::success( sprintf( '%d entry/entries deleted.', $deleted ) );
	}

	/**
	 * Check the SPF, DKIM and DMARC records for a domain.
	 *
	 * ## OPTIONS
	 *
	 * [<domain>]
	 * : Domain to check. Defaults to the From address domain.
	 *
	 * [--selector=<selector>]
	 * : DKIM selector to look up (e.g. google, selector1, k1).
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowsmtp check
	 *     wp flowsmtp check example.com --selector=google
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function check( $args, $assoc_args ) {
		$settings = FlowSMTP::get_settings();
		$domain   = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : '';

		if ( '' === $domain && is_email( $settings['from_email'] ) ) {
			$domain = substr( strrchr( $settings['from_email'], '@' ), 1 );
		}

		$checks = FlowSMTP_Deliverability::check( $domain, (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'selector', '' ) );

		if ( is_wp_error( $checks ) ) {
			WP_CLI::error( $checks->get_error_message() );
		}

		$rows = array();
		foreach ( $checks as $check ) {
			$rows[] = array(
				'check'  => $check['label'],
				'status' => strtoupper( $check['status'] ),
				'detail' => $check['detail'],
			);
		}

		WP_CLI\Utils\format_items(
			WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			$rows,
			array( 'check', 'status', 'detail' )
		);
	}
}
