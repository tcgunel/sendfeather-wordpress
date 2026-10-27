<?php
/**
 * Settings screen and test-email handler.
 *
 * Registers Settings → Sendfeather with the WordPress Settings API so option
 * saving, nonce verification, and capability checks are handled by core. The
 * only custom request handler is the admin-ajax test email, which verifies
 * its own nonce and capability before sending anything.
 *
 * @package Sendfeather\SMTP
 */

namespace Sendfeather\SMTP;

defined( 'ABSPATH' ) || exit;

/**
 * Settings registration, rendering, and sanitization.
 */
final class Settings {

	/**
	 * Single option key holding every plugin setting.
	 *
	 * @var string
	 */
	public const OPTION = 'sendfeather_settings';

	/**
	 * Settings page slug used by the admin menu and screen checks.
	 *
	 * @var string
	 */
	public const PAGE = 'sendfeather';

	/**
	 * Supported connection modes.
	 *
	 * @var array<int, string>
	 */
	public const MODES = array( 'api', 'smtp' );

	/**
	 * Ports Sendfeather accepts SMTP submissions on.
	 *
	 * @var array<int, int>
	 */
	public const SMTP_PORTS = array( 2525, 587, 465 );

	/**
	 * Encryption choices, stored values.
	 *
	 * @var array<int, string>
	 */
	public const ENCRYPTION = array( 'starttls', 'tls', 'none' );

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'mode'            => 'api',
			'api_key'         => '',
			'smtp_host'       => 'smtp.sendfeather.com',
			'smtp_port'       => 2525,
			'smtp_encryption' => 'starttls',
			'smtp_username'   => '',
			'smtp_password'   => '',
			'from_email'      => '',
			'from_name'       => '',
			'force_from'      => true,
			'reply_to'        => '',
		);
	}

	/**
	 * Current settings, defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		static $settings = null;

		if ( null === $settings ) {
			$stored = get_option( self::OPTION, array() );

			if ( ! is_array( $stored ) ) {
				$stored = array();
			}

			$settings = wp_parse_args( $stored, self::defaults() );

			$settings['mode']            = in_array( $settings['mode'], self::MODES, true ) ? $settings['mode'] : 'api';
			$settings['smtp_port']       = (int) $settings['smtp_port'];
			$settings['smtp_encryption'] = in_array( $settings['smtp_encryption'], self::ENCRYPTION, true ) ? $settings['smtp_encryption'] : 'starttls';
			$settings['force_from']      = (bool) $settings['force_from'];
		}

		return $settings;
	}

	/**
	 * Hook the admin screen, the Settings API registration, and the test
	 * email handler.
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sendfeather_send_test_email', array( __CLASS__, 'handle_test_email' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( SENDFEATHER_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add the settings page under Settings.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'Sendfeather SMTP settings', 'sendfeather' ),
			__( 'Sendfeather', 'sendfeather' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * "Settings" link on the Plugins list row.
	 *
	 * @param array<int, string> $links Existing action links.
	 * @return array<int, string>
	 */
	public static function action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'sendfeather' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Register the option, sections, and fields with the Settings API.
	 */
	public static function register(): void {
		register_setting(
			'sendfeather',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'sendfeather_connection',
			__( 'Connection', 'sendfeather' ),
			array( __CLASS__, 'render_connection_intro' ),
			'sendfeather'
		);

		add_settings_field(
			'mode',
			__( 'Connection mode', 'sendfeather' ),
			array( __CLASS__, 'render_mode_field' ),
			'sendfeather',
			'sendfeather_connection'
		);
		add_settings_field(
			'api_key',
			__( 'API key', 'sendfeather' ),
			array( __CLASS__, 'render_api_key_field' ),
			'sendfeather',
			'sendfeather_connection',
			array( 'mode' => 'api', 'label_for' => 'sendfeather-api-key' )
		);
		add_settings_field(
			'smtp_host',
			__( 'SMTP host', 'sendfeather' ),
			array( __CLASS__, 'render_smtp_host_field' ),
			'sendfeather',
			'sendfeather_connection',
			array( 'mode' => 'smtp', 'label_for' => 'sendfeather-smtp-host' )
		);
		add_settings_field(
			'smtp_port',
			__( 'SMTP port', 'sendfeather' ),
			array( __CLASS__, 'render_smtp_port_field' ),
			'sendfeather',
			'sendfeather_connection',
			array( 'mode' => 'smtp', 'label_for' => 'sendfeather-smtp-port' )
		);
		add_settings_field(
			'smtp_encryption',
			__( 'Encryption', 'sendfeather' ),
			array( __CLASS__, 'render_smtp_encryption_field' ),
			'sendfeather',
			'sendfeather_connection',
			array( 'mode' => 'smtp', 'label_for' => 'sendfeather-smtp-encryption' )
		);
		add_settings_field(
			'smtp_username',
			__( 'SMTP username', 'sendfeather' ),
			array( __CLASS__, 'render_smtp_username_field' ),
			'sendfeather',
			'sendfeather_connection',
			array( 'mode' => 'smtp', 'label_for' => 'sendfeather-smtp-username' )
		);
		add_settings_field(
			'smtp_password',
			__( 'SMTP password', 'sendfeather' ),
			array( __CLASS__, 'render_smtp_password_field' ),
			'sendfeather',
			'sendfeather_connection',
			array( 'mode' => 'smtp', 'label_for' => 'sendfeather-smtp-password' )
		);

		add_settings_section(
			'sendfeather_sender',
			__( 'Sender', 'sendfeather' ),
			array( __CLASS__, 'render_sender_intro' ),
			'sendfeather'
		);

		add_settings_field(
			'from_email',
			__( 'From email', 'sendfeather' ),
			array( __CLASS__, 'render_from_email_field' ),
			'sendfeather',
			'sendfeather_sender',
			array( 'label_for' => 'sendfeather-from-email' )
		);
		add_settings_field(
			'from_name',
			__( 'From name', 'sendfeather' ),
			array( __CLASS__, 'render_from_name_field' ),
			'sendfeather',
			'sendfeather_sender',
			array( 'label_for' => 'sendfeather-from-name' )
		);
		add_settings_field(
			'force_from',
			__( 'From address', 'sendfeather' ),
			array( __CLASS__, 'render_force_from_field' ),
			'sendfeather',
			'sendfeather_sender'
		);
		add_settings_field(
			'reply_to',
			__( 'Reply-to', 'sendfeather' ),
			array( __CLASS__, 'render_reply_to_field' ),
			'sendfeather',
			'sendfeather_sender',
			array( 'label_for' => 'sendfeather-reply-to' )
		);

		add_settings_section(
			'sendfeather_test',
			__( 'Test email', 'sendfeather' ),
			array( __CLASS__, 'render_test_section' ),
			'sendfeather'
		);
	}

	/**
	 * Sanitize and validate everything submitted from the settings form.
	 *
	 * Runs inside options.php, which has already verified the settings nonce
	 * and the manage_options capability. Problems surface as settings errors
	 * and the previous value is kept where a field is invalid.
	 *
	 * @param mixed $input Raw submitted option value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$current = self::get();

		if ( ! is_array( $input ) ) {
			return $current;
		}

		$clean = $current;

		$mode = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : $current['mode'];

		if ( ! in_array( $mode, self::MODES, true ) ) {
			add_settings_error( self::OPTION, 'sendfeather_mode', __( 'Unknown connection mode; kept the previous one.', 'sendfeather' ), 'error' );
			$mode = $current['mode'];
		}

		$clean['mode'] = $mode;

		$clean['api_key'] = isset( $input['api_key'] ) ? sanitize_text_field( wp_unslash( $input['api_key'] ) ) : '';

		if ( 'api' === $mode && '' === $clean['api_key'] ) {
			add_settings_error( self::OPTION, 'sendfeather_api_key', __( 'API key mode needs a key or email will not send. Create one under API keys in the Sendfeather dashboard.', 'sendfeather' ), 'error' );
		}

		$host = isset( $input['smtp_host'] ) ? sanitize_text_field( wp_unslash( $input['smtp_host'] ) ) : '';

		if ( '' === $host ) {
			$host = self::defaults()['smtp_host'];
		}

		$clean['smtp_host'] = $host;

		$port = isset( $input['smtp_port'] ) ? absint( $input['smtp_port'] ) : 0;

		if ( ! in_array( $port, self::SMTP_PORTS, true ) ) {
			add_settings_error( self::OPTION, 'sendfeather_smtp_port', __( 'Unknown SMTP port; kept the previous one.', 'sendfeather' ), 'error' );
			$port = (int) $current['smtp_port'];
		}

		$clean['smtp_port'] = $port;

		$encryption = isset( $input['smtp_encryption'] ) ? sanitize_key( $input['smtp_encryption'] ) : $current['smtp_encryption'];

		if ( ! in_array( $encryption, self::ENCRYPTION, true ) ) {
			$encryption = $current['smtp_encryption'];
		}

		$clean['smtp_encryption'] = $encryption;

		$clean['smtp_username'] = isset( $input['smtp_username'] ) ? sanitize_text_field( wp_unslash( $input['smtp_username'] ) ) : '';
		$clean['smtp_password'] = isset( $input['smtp_password'] ) ? sanitize_text_field( wp_unslash( $input['smtp_password'] ) ) : '';

		if ( 'smtp' === $mode && ( '' === $clean['smtp_username'] || '' === $clean['smtp_password'] ) ) {
			add_settings_error( self::OPTION, 'sendfeather_smtp_credentials', __( 'SMTP mode needs a username and password or email will not send. Create an SMTP user in the Sendfeather dashboard.', 'sendfeather' ), 'error' );
		}

		$from_email = isset( $input['from_email'] ) ? sanitize_email( wp_unslash( $input['from_email'] ) ) : '';

		if ( '' !== $from_email && ! is_email( $from_email ) ) {
			add_settings_error( self::OPTION, 'sendfeather_from_email', __( 'The From email is not a valid address; kept the previous one.', 'sendfeather' ), 'error' );
			$from_email = (string) $current['from_email'];
		}

		$clean['from_email'] = $from_email;

		$clean['from_name']  = isset( $input['from_name'] ) ? sanitize_text_field( wp_unslash( $input['from_name'] ) ) : '';
		$clean['force_from'] = ! empty( $input['force_from'] );

		$reply_to = isset( $input['reply_to'] ) ? sanitize_email( wp_unslash( $input['reply_to'] ) ) : '';

		if ( '' !== $reply_to && ! is_email( $reply_to ) ) {
			add_settings_error( self::OPTION, 'sendfeather_reply_to', __( 'The reply-to address is not a valid address; kept the previous one.', 'sendfeather' ), 'error' );
			$reply_to = (string) $current['reply_to'];
		}

		$clean['reply_to'] = $reply_to;

		return $clean;
	}

	/**
	 * Enqueue the admin stylesheet and script on the plugin's own screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'sendfeather-admin',
			plugins_url( 'assets/css/admin.css', SENDFEATHER_PLUGIN_FILE ),
			array(),
			SENDFEATHER_VERSION
		);

		wp_enqueue_script(
			'sendfeather-admin',
			plugins_url( 'assets/js/admin.js', SENDFEATHER_PLUGIN_FILE ),
			array(),
			SENDFEATHER_VERSION,
			true
		);

		wp_localize_script(
			'sendfeather-admin',
			'sendfeatherAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'sendfeather_test_email' ),
				'messages' => array(
					'sending'      => __( 'Sending…', 'sendfeather' ),
					'networkError' => __( 'The request failed before Sendfeather answered. Check this site’s network connection and try again.', 'sendfeather' ),
					'missingEmail' => __( 'Enter an email address to send the test to.', 'sendfeather' ),
					'show'         => __( 'Show', 'sendfeather' ),
					'hide'         => __( 'Hide', 'sendfeather' ),
				),
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap sendfeather-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="sendfeather-intro">
				<?php
				printf(
					/* translators: %s: link to the Sendfeather SMTP documentation. */
					esc_html__( 'Sendfeather delivers your WordPress email and shows every message’s journey from accepted to delivered. Need an account or credentials? Start with the %s.', 'sendfeather' ),
					'<a href="' . esc_url( 'https://sendfeather.com/docs/smtp' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Sendfeather SMTP guide', 'sendfeather' ) . '</a>'
				);
				?>
			</p>
			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
				<?php
				settings_fields( 'sendfeather' );
				do_settings_sections( 'sendfeather' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Connection section intro.
	 */
	public static function render_connection_intro(): void {
		echo '<p>' . esc_html__( 'Choose how WordPress hands messages to Sendfeather. API key mode needs one key and only outbound HTTPS; SMTP mode speaks the standard submission protocol.', 'sendfeather' ) . '</p>';
	}

	/**
	 * Sender section intro.
	 */
	public static function render_sender_intro(): void {
		echo '<p>' . esc_html__( 'What recipients see. Sendfeather only accepts mail from addresses on domains you have verified in the dashboard.', 'sendfeather' ) . '</p>';
	}

	/**
	 * Mode radio group.
	 */
	public static function render_mode_field(): void {
		$settings = self::get();
		?>
		<fieldset>
			<label class="sendfeather-radio">
				<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[mode]" value="api" <?php checked( 'api', $settings['mode'] ); ?> />
				<span>
					<strong><?php echo esc_html__( 'Sendfeather API (API key)', 'sendfeather' ); ?></strong><br />
					<?php echo esc_html__( 'Recommended. One key, no SMTP negotiation, works on hosts that block all SMTP ports.', 'sendfeather' ); ?>
				</span>
			</label>
			<label class="sendfeather-radio">
				<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[mode]" value="smtp" <?php checked( 'smtp', $settings['mode'] ); ?> />
				<span>
					<strong><?php echo esc_html__( 'SMTP credentials', 'sendfeather' ); ?></strong><br />
					<?php echo esc_html__( 'Speaks SMTP to smtp.sendfeather.com — use it if you specifically need SMTP.', 'sendfeather' ); ?>
				</span>
			</label>
		</fieldset>
		<?php
	}

	/**
	 * API key field.
	 */
	public static function render_api_key_field(): void {
		$settings = self::get();
		?>
		<div class="sendfeather-field" data-sendfeather-mode="api">
			<input type="password" autocomplete="off" class="regular-text" id="sendfeather-api-key" name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" value="<?php echo esc_attr( (string) $settings['api_key'] ); ?>" />
			<button type="button" class="button button-secondary" data-sendfeather-reveal="sendfeather-api-key"><?php echo esc_html__( 'Show', 'sendfeather' ); ?></button>
			<p class="description">
				<?php echo esc_html__( 'Create an API key in the dashboard under API keys with the “send” scope. The key is shown once, at creation.', 'sendfeather' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * SMTP host field.
	 */
	public static function render_smtp_host_field(): void {
		$settings = self::get();
		?>
		<div class="sendfeather-field" data-sendfeather-mode="smtp">
			<input type="text" class="regular-text" id="sendfeather-smtp-host" name="<?php echo esc_attr( self::OPTION ); ?>[smtp_host]" value="<?php echo esc_attr( (string) $settings['smtp_host'] ); ?>" />
			<p class="description">
				<?php echo esc_html__( 'Default: smtp.sendfeather.com. Change it only if Sendfeather asked you to.', 'sendfeather' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * SMTP port field.
	 */
	public static function render_smtp_port_field(): void {
		$settings = self::get();
		$ports    = array(
			2525 => __( '2525 — STARTTLS (recommended; works when 587 is blocked)', 'sendfeather' ),
			587  => __( '587 — STARTTLS', 'sendfeather' ),
			465  => __( '465 — TLS from the first byte', 'sendfeather' ),
		);
		?>
		<div class="sendfeather-field" data-sendfeather-mode="smtp">
			<select id="sendfeather-smtp-port" name="<?php echo esc_attr( self::OPTION ); ?>[smtp_port]">
				<?php foreach ( $ports as $port => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $port ); ?>" <?php selected( (int) $settings['smtp_port'], $port ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	/**
	 * SMTP encryption field.
	 */
	public static function render_smtp_encryption_field(): void {
		$settings   = self::get();
		$encryption = array(
			'starttls' => __( 'STARTTLS (ports 2525 and 587)', 'sendfeather' ),
			'tls'      => __( 'TLS/SSL (port 465)', 'sendfeather' ),
			'none'     => __( 'None (not recommended)', 'sendfeather' ),
		);
		?>
		<div class="sendfeather-field" data-sendfeather-mode="smtp">
			<select id="sendfeather-smtp-encryption" name="<?php echo esc_attr( self::OPTION ); ?>[smtp_encryption]">
				<?php foreach ( $encryption as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['smtp_encryption'], $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description">
				<?php echo esc_html__( 'Match the port: STARTTLS upgrades the connection on 2525 or 587; port 465 is TLS from the first byte.', 'sendfeather' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * SMTP username field.
	 */
	public static function render_smtp_username_field(): void {
		$settings = self::get();
		?>
		<div class="sendfeather-field" data-sendfeather-mode="smtp">
			<input type="text" autocomplete="off" class="regular-text" id="sendfeather-smtp-username" name="<?php echo esc_attr( self::OPTION ); ?>[smtp_username]" value="<?php echo esc_attr( (string) $settings['smtp_username'] ); ?>" />
			<p class="description">
				<?php echo esc_html__( 'From SMTP → SMTP users in the Sendfeather dashboard.', 'sendfeather' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * SMTP password field.
	 */
	public static function render_smtp_password_field(): void {
		$settings = self::get();
		?>
		<div class="sendfeather-field" data-sendfeather-mode="smtp">
			<input type="password" autocomplete="off" class="regular-text" id="sendfeather-smtp-password" name="<?php echo esc_attr( self::OPTION ); ?>[smtp_password]" value="<?php echo esc_attr( (string) $settings['smtp_password'] ); ?>" />
			<button type="button" class="button button-secondary" data-sendfeather-reveal="sendfeather-smtp-password"><?php echo esc_html__( 'Show', 'sendfeather' ); ?></button>
			<p class="description">
				<?php echo esc_html__( 'The password shown once when the SMTP user was created. Lost it? Create a new SMTP user.', 'sendfeather' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * From email field.
	 */
	public static function render_from_email_field(): void {
		$settings = self::get();
		?>
		<input type="email" class="regular-text" id="sendfeather-from-email" name="<?php echo esc_attr( self::OPTION ); ?>[from_email]" value="<?php echo esc_attr( (string) $settings['from_email'] ); ?>" />
		<p class="description">
			<?php echo esc_html__( 'Example: hello@yourdomain.com. It must be on a domain that shows Verified in the Sendfeather dashboard.', 'sendfeather' ); ?>
		</p>
		<?php
	}

	/**
	 * From name field.
	 */
	public static function render_from_name_field(): void {
		$settings = self::get();
		?>
		<input type="text" class="regular-text" id="sendfeather-from-name" name="<?php echo esc_attr( self::OPTION ); ?>[from_name]" value="<?php echo esc_attr( (string) $settings['from_name'] ); ?>" />
		<p class="description">
			<?php echo esc_html__( 'The display name recipients see, for example your shop or site name.', 'sendfeather' ); ?>
		</p>
		<?php
	}

	/**
	 * Force-from checkbox.
	 */
	public static function render_force_from_field(): void {
		$settings = self::get();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[force_from]" value="1" <?php checked( $settings['force_from'] ); ?> />
			<?php echo esc_html__( 'Force the From email above', 'sendfeather' ); ?>
		</label>
		<p class="description">
			<?php echo esc_html__( 'Overwrites any From address set by WordPress, your theme, or other plugins. Recommended: without it, contact forms and shops send as addresses your domain may not authorize, and Sendfeather refuses them.', 'sendfeather' ); ?>
		</p>
		<?php
	}

	/**
	 * Reply-to field.
	 */
	public static function render_reply_to_field(): void {
		$settings = self::get();
		?>
		<input type="email" class="regular-text" id="sendfeather-reply-to" name="<?php echo esc_attr( self::OPTION ); ?>[reply_to]" value="<?php echo esc_attr( (string) $settings['reply_to'] ); ?>" />
		<p class="description">
			<?php echo esc_html__( 'Optional. When set, replies to your email go here instead of to the From address.', 'sendfeather' ); ?>
		</p>
		<?php
	}

	/**
	 * Test email section: address input, button, inline result.
	 */
	public static function render_test_section(): void {
		?>
		<p>
			<?php echo esc_html__( 'Sends one real email through the saved settings, so save your changes first. Use a mailbox you can read — the inbox of an address at a provider other than your own domain tells you the most.', 'sendfeather' ); ?>
		</p>
		<p class="sendfeather-test">
			<label class="screen-reader-text" for="sendfeather-test-email"><?php echo esc_html__( 'Test email recipient', 'sendfeather' ); ?></label>
			<input type="email" class="regular-text" id="sendfeather-test-email" name="sendfeather_test_email" value="<?php echo esc_attr( (string) wp_get_current_user()->user_email ); ?>" />
			<button type="button" class="button button-primary" id="sendfeather-test-button"><?php echo esc_html__( 'Send test email', 'sendfeather' ); ?></button>
			<span class="spinner sendfeather-test-spinner"></span>
		</p>
		<div class="sendfeather-test-result" id="sendfeather-test-result" role="status" aria-live="polite" hidden></div>
		<?php
	}

	/**
	 * Admin-ajax: send the test email and report the result.
	 *
	 * Nonce and capability are verified before anything else happens; the
	 * response is sanitized output only.
	 */
	public static function handle_test_email(): void {
		check_ajax_referer( 'sendfeather_test_email', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to send test emails.', 'sendfeather' ) ), 403 );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid email address to send the test to.', 'sendfeather' ) ) );
		}

		$failures = array();

		$collect = static function ( $error ) use ( &$failures ): void {
			if ( $error instanceof \WP_Error ) {
				foreach ( $error->get_error_messages() as $message ) {
					$failures[] = $message;
				}
			}
		};

		add_action( 'wp_mail_failed', $collect );

		$sent = wp_mail(
			$email,
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Sendfeather test email', 'sendfeather' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			sprintf(
				/* translators: 1: site URL, 2: current date and time. */
				__( "This is a test email from %1\$s.\n\nIf you are reading it, WordPress handed the message to Sendfeather and Sendfeather accepted it. Open Sendfeather → Activity to see what happened next.\n\nSent at %2\$s.", 'sendfeather' ),
				home_url( '/' ),
				gmdate( 'Y-m-d H:i:s' ) . ' UTC'
			)
		);

		remove_action( 'wp_mail_failed', $collect );

		if ( $sent ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %s: recipient email address. */
						__( 'Sent. WordPress accepted a message to %s. Check that inbox, then look it up in Sendfeather → Activity for the delivery timeline.', 'sendfeather' ),
						$email
					),
				)
			);
		}

		$detail = $failures ? implode( ' ', array_unique( $failures ) ) : __( 'WordPress did not accept the message, and no error detail was reported.', 'sendfeather' );

		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: %s: error detail from the transport. */
					__( 'Not sent. %s', 'sendfeather' ),
					$detail
				),
			)
		);
	}
}
