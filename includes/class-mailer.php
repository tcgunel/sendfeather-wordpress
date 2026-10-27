<?php
/**
 * The mail transport: routes wp_mail() through Sendfeather.
 *
 * API mode hooks `pre_wp_mail` and posts to the Sendfeather API, returning
 * true/false for the wp_mail() result and firing `wp_mail_failed` on errors.
 * SMTP mode configures WP's own PHPMailer instance via `phpmailer_init`, so
 * core's send loop and error handling stay in charge.
 *
 * @package Sendfeather\SMTP
 */

namespace Sendfeather\SMTP;

defined( 'ABSPATH' ) || exit;

/**
 * Mail routing for both connection modes.
 */
final class Mailer {

	/**
	 * The most attachments the API accepts per message.
	 *
	 * @var int
	 */
	public const MAX_ATTACHMENTS = 25;

	/**
	 * The attachment size ceiling the API enforces, in bytes (30 MB).
	 *
	 * @var int
	 */
	public const MAX_ATTACHMENT_BYTES = 31457280;

	/**
	 * Header names the API reserves for itself. Anything a caller sets with
	 * one of these names is dropped rather than forwarded, because the API
	 * rejects the whole message when it sees one.
	 *
	 * @var array<int, string>
	 */
	public const RESERVED_HEADERS = array(
		'bcc',
		'cc',
		'content-transfer-encoding',
		'content-type',
		'date',
		'dkim-signature',
		'from',
		'message-id',
		'mime-version',
		'received',
		'reply-to',
		'return-path',
		'sender',
		'subject',
		'to',
	);

	/**
	 * Register the transport for the configured mode.
	 */
	public static function boot(): void {
		$settings = Settings::get();

		if ( 'smtp' === $settings['mode'] ) {
			add_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ) );

			// Core validates the From address via `is_email()` before
			// `phpmailer_init` fires and bails out of wp_mail() when it
			// fails — which a default like `wordpress@localhost` does. Feed
			// the forced From through core's own filters so the address is
			// right before that check runs.
			add_filter( 'wp_mail_from', array( __CLASS__, 'force_from_email' ) );
			add_filter( 'wp_mail_from_name', array( __CLASS__, 'force_from_name' ) );
		} else {
			add_filter( 'pre_wp_mail', array( __CLASS__, 'send_via_api' ), 10, 2 );
		}
	}

	/**
	 * `wp_mail_from` filter: the configured From email when forcing is on.
	 *
	 * @param string $email Core's derived From address.
	 * @return string
	 */
	public static function force_from_email( $email ) {
		$settings = Settings::get();

		if ( $settings['force_from'] && '' !== (string) $settings['from_email'] ) {
			return (string) $settings['from_email'];
		}

		return (string) $email;
	}

	/**
	 * `wp_mail_from_name` filter: the configured From name when set.
	 *
	 * @param string $name Core's derived From name.
	 * @return string
	 */
	public static function force_from_name( $name ) {
		$settings = Settings::get();

		if ( '' !== (string) $settings['from_name'] ) {
			return (string) $settings['from_name'];
		}

		return (string) $name;
	}

	/**
	 * Short-circuit wp_mail() and deliver through the Sendfeather API.
	 *
	 * @param mixed                $return Non-null when an earlier filter already handled the mail.
	 * @param array<string, mixed> $atts   wp_mail() attributes: to, subject, message, headers, attachments.
	 * @return mixed True on success, false on failure, or the untouched $return.
	 */
	public static function send_via_api( $return, $atts ) {
		if ( null !== $return ) {
			return $return;
		}

		$atts     = (array) $atts;
		$settings = Settings::get();

		$to = self::split_address_list( isset( $atts['to'] ) ? $atts['to'] : '' );

		if ( ! $to ) {
			return self::fail( __( 'wp_mail() was called without recipients.', 'sendfeather' ) );
		}

		$parsed  = self::parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$subject = isset( $atts['subject'] ) ? trim( (string) $atts['subject'] ) : '';

		if ( '' === $subject ) {
			$subject = '(no subject)';
		}

		if ( self::text_length( $subject ) > 998 ) {
			$subject = self::text_substr( $subject, 0, 998 );
		}

		$message      = isset( $atts['message'] ) ? (string) $atts['message'] : '';
		$content_type = (string) $parsed['content_type'];

		if ( '' === $content_type ) {
			$content_type = (string) apply_filters( 'wp_mail_content_type', 'text/plain' );
		}

		if ( false !== stripos( $content_type, 'text/html' ) ) {
			$html = $message;
			$text = trim( wp_strip_all_tags( $message ) );
		} else {
			$html = '';
			$text = $message;
		}

		if ( '' === $html && '' === $text ) {
			// The API requires a body; keep core's tolerance for empty messages.
			$text = ' ';
		}

		if ( $settings['force_from'] && '' !== (string) $settings['from_email'] ) {
			$from = self::format_address( (string) $settings['from_email'], (string) $settings['from_name'] );
		} elseif ( '' !== (string) $parsed['from'] ) {
			$from = (string) $parsed['from'];
		} else {
			$from = self::default_from();
		}

		$reply_to = '' !== (string) $settings['reply_to'] ? (string) $settings['reply_to'] : (string) $parsed['reply_to'];

		$attachment_paths = isset( $atts['attachments'] ) ? $atts['attachments'] : array();

		if ( ! is_array( $attachment_paths ) ) {
			$attachment_paths = array( $attachment_paths );
		}

		$attachment_paths = array_values( array_filter( array_map( 'strval', $attachment_paths ) ) );

		if ( count( $attachment_paths ) > self::MAX_ATTACHMENTS ) {
			return self::fail(
				sprintf(
					/* translators: %d: maximum number of attachments. */
					__( 'Sendfeather accepts at most %d attachments per message.', 'sendfeather' ),
					self::MAX_ATTACHMENTS
				)
			);
		}

		$attachments = self::read_attachments( $attachment_paths );

		if ( is_wp_error( $attachments ) ) {
			return self::fail( $attachments->get_error_message() );
		}

		$payload = array(
			'from'    => $from,
			'to'      => $to,
			'subject' => $subject,
		);

		if ( '' !== $html ) {
			$payload['html'] = $html;
		}

		if ( '' !== $text ) {
			$payload['text'] = $text;
		}

		if ( $parsed['cc'] ) {
			$payload['cc'] = $parsed['cc'];
		}

		if ( $parsed['bcc'] ) {
			$payload['bcc'] = $parsed['bcc'];
		}

		if ( '' !== $reply_to ) {
			$payload['reply_to'] = $reply_to;
		}

		if ( $parsed['headers'] ) {
			$payload['headers'] = $parsed['headers'];
		}

		if ( $attachments ) {
			$payload['attachments'] = $attachments;
		}

		$result = Api_Client::send( $payload );

		if ( is_wp_error( $result ) ) {
			return self::fail( $result->get_error_message() );
		}

		return true;
	}

	/**
	 * Configure WP's PHPMailer for Sendfeather SMTP.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer The mailer core is about to use.
	 */
	public static function configure_phpmailer( $phpmailer ): void {
		if ( ! $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
			return;
		}

		$settings = Settings::get();

		$phpmailer->isSMTP();
		$phpmailer->Host       = trim( (string) $settings['smtp_host'] );
		$phpmailer->Port       = (int) $settings['smtp_port'];
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Username   = (string) $settings['smtp_username'];
		$phpmailer->Password   = (string) $settings['smtp_password'];

		switch ( (string) $settings['smtp_encryption'] ) {
			case 'tls':
				$phpmailer->SMTPSecure  = 'ssl';
				$phpmailer->SMTPAutoTLS = true;
				break;
			case 'none':
				$phpmailer->SMTPSecure  = '';
				$phpmailer->SMTPAutoTLS = false;
				break;
			case 'starttls':
			default:
				$phpmailer->SMTPSecure  = 'tls';
				$phpmailer->SMTPAutoTLS = true;
				break;
		}

		if ( $settings['force_from'] && '' !== (string) $settings['from_email'] ) {
			$phpmailer->setFrom( (string) $settings['from_email'], (string) $settings['from_name'], false );
		}

		if ( '' !== (string) $settings['reply_to'] ) {
			$phpmailer->clearReplyTos();
			$phpmailer->addReplyTo( (string) $settings['reply_to'] );
		}
	}

	/**
	 * One readable failure: fire wp_mail_failed the way core does and return false.
	 *
	 * @param string $message Human-readable reason.
	 * @return bool Always false.
	 */
	private static function fail( string $message ): bool {
		/**
		 * Fires when a message could not be handed to Sendfeather, with the
		 * same contract as core's own wp_mail_failed action.
		 *
		 * @param \WP_Error $error Error explaining the failure.
		 */
		do_action( 'wp_mail_failed', new \WP_Error( 'sendfeather_send_failed', $message ) );

		return false;
	}

	/**
	 * The From address when neither the plugin settings nor the message
	 * supply one — mirrors core's own default and filters.
	 *
	 * @return string "Name <email>" or just the address.
	 */
	private static function default_from(): string {
		$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );

		if ( ! is_string( $sitename ) || '' === $sitename ) {
			$sitename = 'localhost';
		}

		if ( 0 === strpos( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, 4 );
		}

		/**
		 * Mirrors the core wp_mail_from / wp_mail_from_name filters so
		 * sites that customise their sender keep working in API mode.
		 */
		$from_email = (string) apply_filters( 'wp_mail_from', 'wordpress@' . $sitename );
		$from_name  = (string) apply_filters( 'wp_mail_from_name', 'WordPress' );

		return self::format_address( $from_email, $from_name );
	}

	/**
	 * Combine a name and address into the "Name <email>" form the API accepts.
	 *
	 * @param string $email Address.
	 * @param string $name  Display name, may be empty.
	 * @return string
	 */
	private static function format_address( string $email, string $name ): string {
		$name = trim( str_replace( array( '<', '>', "\r", "\n" ), '', $name ) );

		if ( '' === $name ) {
			return $email;
		}

		return $name . ' <' . $email . '>';
	}

	/**
	 * Split a comma-separated (or array) address list into trimmed addresses.
	 *
	 * @param mixed  $value     Address string or array of them.
	 * @param string $separator Separator inside a string value.
	 * @return array<int, string>
	 */
	private static function split_address_list( $value, string $separator = ',' ): array {
		if ( is_array( $value ) ) {
			$value = implode( $separator, array_map( 'strval', $value ) );
		}

		if ( ! is_string( $value ) ) {
			return array();
		}

		$addresses = array();

		foreach ( explode( $separator, $value ) as $address ) {
			$address = trim( $address );

			if ( '' !== $address ) {
				$addresses[] = $address;
			}
		}

		return $addresses;
	}

	/**
	 * Read wp_mail() headers into the pieces the API payload needs.
	 *
	 * Only headers the API allows are forwarded; the reserved ones are
	 * consumed by their dedicated payload fields instead.
	 *
	 * @param string|array<int, string> $headers Raw wp_mail() headers.
	 * @return array{from: string, reply_to: string, cc: array<int, string>, bcc: array<int, string>, content_type: string, headers: array<string, string>}
	 */
	private static function parse_headers( $headers ): array {
		$result = array(
			'from'         => '',
			'reply_to'     => '',
			'cc'           => array(),
			'bcc'          => array(),
			'content_type' => '',
			'headers'      => array(),
		);

		if ( is_string( $headers ) ) {
			$lines = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		} elseif ( is_array( $headers ) ) {
			$lines = $headers;
		} else {
			$lines = array();
		}

		foreach ( $lines as $line ) {
			if ( ! is_string( $line ) ) {
				continue;
			}

			$line  = trim( $line );
			$parts = explode( ':', $line, 2 );

			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$name  = trim( $parts[0] );
			$value = trim( $parts[1] );
			$key   = strtolower( $name );

			switch ( $key ) {
				case 'from':
					$result['from'] = $value;
					break;
				case 'reply-to':
					$result['reply_to'] = $value;
					break;
				case 'cc':
					$result['cc'] = array_merge( $result['cc'], self::split_address_list( $value ) );
					break;
				case 'bcc':
					$result['bcc'] = array_merge( $result['bcc'], self::split_address_list( $value ) );
					break;
				case 'content-type':
					$result['content_type'] = $value;
					break;
				default:
					if ( in_array( $key, self::RESERVED_HEADERS, true ) ) {
						break;
					}

					$name  = (string) preg_replace( '/[^A-Za-z0-9-]/', '', $name );
					$value = (string) preg_replace( '/[\r\n]+/', ' ', $value );

					if ( '' === $name || '' === $value ) {
						break;
					}

					if ( self::text_length( $value ) > 998 || count( $result['headers'] ) >= 50 ) {
						break;
					}

					$result['headers'][ $name ] = $value;
					break;
			}
		}

		return $result;
	}

	/**
	 * Read attachment files into the API's base64 attachment shape.
	 *
	 * @param array<int, string> $paths Attachment file paths.
	 * @return array<int, array{filename: string, content: string, content_type: string}>|\WP_Error
	 */
	private static function read_attachments( array $paths ) {
		$attachments = array();
		$total_bytes = 0;

		if ( ! $paths ) {
			return $attachments;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		foreach ( $paths as $path ) {
			$path = trim( $path );

			if ( '' === $path ) {
				continue;
			}

			if ( ! $wp_filesystem || ! $wp_filesystem->exists( $path ) ) {
				return new \WP_Error(
					'sendfeather_attachment_missing',
					sprintf(
						/* translators: %s: attachment file path. */
						__( 'Attachment not found or unreadable: %s', 'sendfeather' ),
						$path
					)
				);
			}

			$contents = $wp_filesystem->get_contents( $path );

			if ( ! is_string( $contents ) || '' === $contents ) {
				return new \WP_Error(
					'sendfeather_attachment_unreadable',
					sprintf(
						/* translators: %s: attachment file path. */
						__( 'Could not read attachment: %s', 'sendfeather' ),
						$path
					)
				);
			}

			$total_bytes += strlen( $contents );

			if ( $total_bytes > self::MAX_ATTACHMENT_BYTES ) {
				return new \WP_Error(
					'sendfeather_attachments_too_large',
					__( 'Attachments may not exceed 30 MB per message.', 'sendfeather' )
				);
			}

			$filetype = wp_check_filetype( wp_basename( $path ) );

			$attachments[] = array(
				'filename'     => wp_basename( $path ),
				'content'      => base64_encode( $contents ),
				'content_type' => ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream',
			);
		}

		return $attachments;
	}

	/**
	 * Multibyte-safe string length, with a plain-strlen fallback.
	 *
	 * @param string $value Value to measure.
	 * @return int
	 */
	private static function text_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Multibyte-safe substring, with a plain-substr fallback.
	 *
	 * @param string $value  Value to cut.
	 * @param int    $start  Start offset.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private static function text_substr( string $value, int $start, int $length ): string {
		return function_exists( 'mb_substr' ) ? (string) mb_substr( $value, $start, $length, 'UTF-8' ) : substr( $value, $start, $length );
	}
}
