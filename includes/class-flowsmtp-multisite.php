<?php
/**
 * Multisite / network support.
 *
 * Adds a Network Admin settings screen so one SMTP connection can be shared
 * across every site in the network, keeps per-site logs isolated, and creates
 * the log table automatically when a new site is added to the network.
 *
 * @package FlowSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowSMTP_Multisite {

	const OPTION_KEY = 'flowsmtp_network_settings';
	const PAGE_SLUG  = 'flow-smtp-network';

	/**
	 * Settings that the network admin can lock for all sites.
	 *
	 * @var string[]
	 */
	private static $managed_keys = array(
		'provider',
		'mailer_type',
		'host',
		'port',
		'encryption',
		'auth',
		'username',
		'password',
		'api_key',
		'api_domain',
		'from_email',
		'from_name',
		'force_from',
	);

	public function __construct() {
		add_filter( 'flowsmtp_settings', array( $this, 'apply_network_settings' ) );

		add_action( 'network_admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_flowsmtp_save_network', array( $this, 'save' ) );
		add_action( 'admin_notices', array( $this, 'managed_notice' ) );

		// Create the log table for sites added after the plugin was activated.
		add_action( 'wp_initialize_site', array( $this, 'on_new_site' ), 20, 1 );
	}

	/**
	 * Network settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_network_settings() {
		$network = get_network();

		$defaults = array(
			'enforce'     => 0,
			'provider'    => 'custom',
			'mailer_type' => 'smtp',
			'host'        => '',
			'port'        => 587,
			'encryption'  => 'tls',
			'auth'        => 1,
			'username'    => '',
			'password'    => '',
			'api_key'     => '',
			'api_domain'  => '',
			'from_email'  => get_site_option( 'admin_email' ),
			'from_name'   => $network ? $network->site_name : '',
			'force_from'  => 1,
		);

		return wp_parse_args( (array) get_site_option( self::OPTION_KEY, array() ), $defaults );
	}

	/**
	 * Whether the network connection is enforced for all sites.
	 *
	 * @return bool
	 */
	public static function is_enforced() {
		$network = self::get_network_settings();

		return ! empty( $network['enforce'] );
	}

	/**
	 * Avoid feeding network values into a per-site save.
	 *
	 * Without this, saving the per-site settings form while enforcement is on
	 * would copy the network connection (including the encrypted password) into
	 * the site option.
	 *
	 * @return bool
	 */
	private static function is_saving_site_settings() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only context check; the save itself is nonce-checked by options.php.
		return isset( $_POST['option_page'] ) && 'flowsmtp_settings_group' === $_POST['option_page'];
	}

	/**
	 * Override the connection portion of the site settings with network values.
	 *
	 * Per-site preferences (logging, retention, retries, alerts, test mode,
	 * tracking) are deliberately left to each site.
	 *
	 * @param array $settings Site settings.
	 * @return array
	 */
	public function apply_network_settings( $settings ) {
		if ( ! self::is_enforced() || self::is_saving_site_settings() ) {
			return $settings;
		}

		$network = self::get_network_settings();

		foreach ( self::$managed_keys as $key ) {
			if ( isset( $network[ $key ] ) ) {
				$settings[ $key ] = $network[ $key ];
			}
		}

		return $settings;
	}

	/**
	 * Tell site admins their connection is managed by the network.
	 */
	public function managed_notice() {
		if ( ! self::is_enforced() || is_network_admin() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, FlowSMTP_Admin::PAGE_SLUG ) ) {
			return;
		}

		echo '<div class="notice notice-info"><p>' .
			esc_html__( 'The mail connection and sender for this site are managed network-wide by FlowSMTP. Changes you make to the provider, server, credentials or sender fields will not take effect. Logging, retries, alerts, test mode and tracking remain under this site’s control.', 'flow-smtp' ) .
			'</p></div>';
	}

	/**
	 * Create the log table for a newly created site.
	 *
	 * @param WP_Site $site New site object.
	 */
	public function on_new_site( $site ) {
		if ( ! function_exists( 'flowsmtp_create_tables' ) ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Only if the plugin is active network-wide; otherwise activation on that
		// site will create the table itself.
		if ( ! is_plugin_active_for_network( plugin_basename( FLOWSMTP_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		flowsmtp_create_tables();
		if ( function_exists( 'flowsmtp_seed_defaults' ) ) {
			flowsmtp_seed_defaults();
		}
		restore_current_blog();
	}

	public function register_menu() {
		add_submenu_page(
			'settings.php',
			__( 'FlowSMTP Network Settings', 'flow-smtp' ),
			__( 'FlowSMTP', 'flow-smtp' ),
			'manage_network_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Save the network settings.
	 */
	public function save() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'flow-smtp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'flowsmtp_network_save' );

		$input = isset( $_POST[ self::OPTION_KEY ] ) ? (array) wp_unslash( $_POST[ self::OPTION_KEY ] ) : array();
		$old   = self::get_network_settings();

		$clean = array(
			'enforce'     => empty( $input['enforce'] ) ? 0 : 1,
			'provider'    => isset( $input['provider'] ) && FlowSMTP_Providers::exists( sanitize_key( $input['provider'] ) ) ? sanitize_key( $input['provider'] ) : 'custom',
			'mailer_type' => isset( $input['mailer_type'] ) && in_array( $input['mailer_type'], array( 'smtp', 'api' ), true ) ? $input['mailer_type'] : 'smtp',
			'host'        => isset( $input['host'] ) ? sanitize_text_field( $input['host'] ) : '',
			'port'        => isset( $input['port'] ) ? absint( $input['port'] ) : 587,
			'encryption'  => isset( $input['encryption'] ) && in_array( $input['encryption'], array( 'none', 'ssl', 'tls' ), true ) ? $input['encryption'] : 'tls',
			'auth'        => empty( $input['auth'] ) ? 0 : 1,
			'username'    => isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '',
			'api_domain'  => isset( $input['api_domain'] ) ? sanitize_text_field( $input['api_domain'] ) : '',
			'from_email'  => isset( $input['from_email'] ) && is_email( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : $old['from_email'],
			'from_name'   => isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '',
			'force_from'  => empty( $input['force_from'] ) ? 0 : 1,
		);

		// Secrets: keep the stored value when the field was left as the mask.
		foreach ( array( 'password', 'api_key' ) as $secret ) {
			if ( isset( $input[ $secret ] ) && '' !== $input[ $secret ] && '********' !== $input[ $secret ] ) {
				$clean[ $secret ] = FlowSMTP_Mailer::encrypt( $input[ $secret ] );
			} else {
				$clean[ $secret ] = isset( $old[ $secret ] ) ? $old[ $secret ] : '';
			}
		}

		update_site_option( self::OPTION_KEY, $clean );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => '1',
				),
				network_admin_url( 'settings.php' )
			)
		);
		exit;
	}

	/**
	 * Render the network settings screen.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$n   = self::get_network_settings();
		$key = self::OPTION_KEY;
		?>
		<div class="wrap flowsmtp-wrap">
			<h1><?php esc_html_e( 'FlowSMTP Network Settings', 'flow-smtp' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Network settings saved.', 'flow-smtp' ); ?></p></div>
			<?php endif; ?>

			<p class="flowsmtp-muted"><?php esc_html_e( 'Configure one mail connection for every site in this network. Each site keeps its own email log, retries, alerts, test mode and tracking preferences.', 'flow-smtp' ); ?></p>

			<div class="flowsmtp-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flowsmtp-form">
					<input type="hidden" name="action" value="flowsmtp_save_network" />
					<?php wp_nonce_field( 'flowsmtp_network_save' ); ?>

					<label class="flowsmtp-toggle">
						<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[enforce]" value="1" <?php checked( $n['enforce'], 1 ); ?> />
						<span class="flowsmtp-slider"></span>
						<?php esc_html_e( 'Use these settings for every site in the network (site admins cannot override them)', 'flow-smtp' ); ?>
					</label>

					<h2><?php esc_html_e( 'Provider', 'flow-smtp' ); ?></h2>
					<div class="flowsmtp-grid">
						<label>
							<span><?php esc_html_e( 'Email Provider', 'flow-smtp' ); ?></span>
							<select name="<?php echo esc_attr( $key ); ?>[provider]">
								<?php foreach ( FlowSMTP_Providers::all() as $slug => $preset ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $n['provider'], $slug ); ?>><?php echo esc_html( $preset['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'Sending Method', 'flow-smtp' ); ?></span>
							<select name="<?php echo esc_attr( $key ); ?>[mailer_type]">
								<option value="smtp" <?php selected( $n['mailer_type'], 'smtp' ); ?>><?php esc_html_e( 'SMTP', 'flow-smtp' ); ?></option>
								<option value="api" <?php selected( $n['mailer_type'], 'api' ); ?>><?php esc_html_e( 'HTTP API', 'flow-smtp' ); ?></option>
							</select>
						</label>
					</div>

					<h2><?php esc_html_e( 'SMTP Server', 'flow-smtp' ); ?></h2>
					<div class="flowsmtp-grid">
						<label>
							<span><?php esc_html_e( 'SMTP Host', 'flow-smtp' ); ?></span>
							<input type="text" name="<?php echo esc_attr( $key ); ?>[host]" value="<?php echo esc_attr( $n['host'] ); ?>" placeholder="smtp.example.com" />
						</label>
						<label>
							<span><?php esc_html_e( 'Port', 'flow-smtp' ); ?></span>
							<input type="number" name="<?php echo esc_attr( $key ); ?>[port]" value="<?php echo esc_attr( $n['port'] ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'Encryption', 'flow-smtp' ); ?></span>
							<select name="<?php echo esc_attr( $key ); ?>[encryption]">
								<option value="tls" <?php selected( $n['encryption'], 'tls' ); ?>>TLS (587)</option>
								<option value="ssl" <?php selected( $n['encryption'], 'ssl' ); ?>>SSL (465)</option>
								<option value="none" <?php selected( $n['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'flow-smtp' ); ?></option>
							</select>
						</label>
					</div>

					<h2><?php esc_html_e( 'Credentials', 'flow-smtp' ); ?></h2>
					<label class="flowsmtp-toggle">
						<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[auth]" value="1" <?php checked( $n['auth'], 1 ); ?> />
						<span class="flowsmtp-slider"></span>
						<?php esc_html_e( 'Use SMTP authentication', 'flow-smtp' ); ?>
					</label>
					<div class="flowsmtp-grid">
						<label>
							<span><?php esc_html_e( 'Username', 'flow-smtp' ); ?></span>
							<input type="text" name="<?php echo esc_attr( $key ); ?>[username]" value="<?php echo esc_attr( $n['username'] ); ?>" autocomplete="off" />
						</label>
						<label>
							<span><?php esc_html_e( 'Password', 'flow-smtp' ); ?></span>
							<input type="password" name="<?php echo esc_attr( $key ); ?>[password]" value="<?php echo $n['password'] ? '********' : ''; ?>" autocomplete="new-password" />
						</label>
						<label>
							<span><?php esc_html_e( 'API Key (HTTP API only)', 'flow-smtp' ); ?></span>
							<input type="password" name="<?php echo esc_attr( $key ); ?>[api_key]" value="<?php echo $n['api_key'] ? '********' : ''; ?>" autocomplete="new-password" />
						</label>
						<label>
							<span><?php esc_html_e( 'Mailgun Sending Domain', 'flow-smtp' ); ?></span>
							<input type="text" name="<?php echo esc_attr( $key ); ?>[api_domain]" value="<?php echo esc_attr( $n['api_domain'] ); ?>" placeholder="mg.example.com" />
						</label>
					</div>

					<h2><?php esc_html_e( 'Sender', 'flow-smtp' ); ?></h2>
					<div class="flowsmtp-grid">
						<label>
							<span><?php esc_html_e( 'From Email', 'flow-smtp' ); ?></span>
							<input type="email" name="<?php echo esc_attr( $key ); ?>[from_email]" value="<?php echo esc_attr( $n['from_email'] ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'From Name', 'flow-smtp' ); ?></span>
							<input type="text" name="<?php echo esc_attr( $key ); ?>[from_name]" value="<?php echo esc_attr( $n['from_name'] ); ?>" />
						</label>
					</div>
					<label class="flowsmtp-toggle">
						<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[force_from]" value="1" <?php checked( $n['force_from'], 1 ); ?> />
						<span class="flowsmtp-slider"></span>
						<?php esc_html_e( 'Force this From address on all sites', 'flow-smtp' ); ?>
					</label>
					<p class="flowsmtp-muted"><?php esc_html_e( 'Tip: on a network, using one authenticated sender domain for every site is usually the most reliable setup — sites sending as their own unverified domains are the most common cause of spam placement.', 'flow-smtp' ); ?></p>

					<p class="flowsmtp-actions"><button type="submit" class="flowsmtp-btn is-primary"><?php esc_html_e( 'Save Network Settings', 'flow-smtp' ); ?></button></p>
				</form>
			</div>
		</div>
		<?php
	}
}
