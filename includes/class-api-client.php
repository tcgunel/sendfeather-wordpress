<?php
/**
 * HTTP client for the Sendfeather send API.
 *
 * One endpoint: POST {base}/emails with a Bearer API key.
 *
 * @package Sendfeather\SMTP
 */

namespace Sendfeather\SMTP;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the Sendfeather API.
 */
final class Api_Client {

	/**
	 * Default API base URL. Filterable so a development or staging install
	 * can point at a different Sendfeather deployment.
	 *
	 * @var string
	 */
	public const DEFAULT_BASE_URL = 'https://sendfeather.com/api';

	/**
	 * How long to wait for the API, in seconds.
	 *
	 * @var int
	 */
	public const TIMEOUT = 15;

	/**
	 * Send one message.
	 *
	 * @param array<string, mixed> $payload Validated send payload.
	 * @return array{ok: bool, id: string}|\WP_Error Result, or an error explaining the failure.
	 */
	public static function send( array $payload ) {
		$settings = Settings::get();
		$key      = (string) $settings['api_key'];

		if ( '' === $key ) {
			return new \WP_Error(
				'sendfeather_missing_api_key',
				__( 'No Sendfeather API key is saved. Add one under Settings → Sendfeather.', 'sendfeather' )
			);
		}

		/**
		 * Filters the Sendfeather API base URL.
		 *
		 * @param string $base_url Default https://sendfeather.com/api.
		 */
		$base_url = (string) apply_filters( 'sendfeather_api_base_url', self::DEFAULT_BASE_URL );

		if ( ! preg_match( '#^https?://#i', $base_url ) ) {
			return new \WP_Error(
				'sendfeather_invalid_api_url',
				__( 'The configured Sendfeather API URL must start with http:// or https://.', 'sendfeather' )
			);
		}

		$url = trailingslashit( $base_url ) . 'emails';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'User-Agent'    => 'Sendfeather-SMTP/' . SENDFEATHER_VERSION . '; ' . home_url( '/' ),
				),
				'body'    => (string) wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'sendfeather_http_error',
				sprintf(
					/* translators: 1: API host, 2: transport error message. */
					__( 'Could not reach Sendfeather at %1$s: %2$s', 'sendfeather' ),
					$url,
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'ok' => true,
				'id' => is_array( $body ) && isset( $body['id'] ) ? (string) $body['id'] : '',
			);
		}

		return new \WP_Error(
			'sendfeather_api_error',
			self::error_message( $status, $body ),
			array( 'status' => $status )
		);
	}

	/**
	 * Turn an API error response into one readable sentence.
	 *
	 * @param int   $status HTTP status code returned.
	 * @param mixed $body   Decoded JSON body, when there was one.
	 * @return string
	 */
	private static function error_message( int $status, $body ): string {
		$message = is_array( $body ) && isset( $body['message'] ) && is_string( $body['message'] ) ? $body['message'] : '';
		$details = array();

		if ( is_array( $body ) && isset( $body['errors'] ) && is_array( $body['errors'] ) ) {
			foreach ( $body['errors'] as $messages ) {
				foreach ( (array) $messages as $detail ) {
					if ( is_string( $detail ) ) {
						$details[] = $detail;
					}

					if ( count( $details ) >= 3 ) {
						break 2;
					}
				}
			}
		}

		switch ( true ) {
			case 401 === $status || 403 === $status:
				$summary = __( 'Sendfeather rejected the API key. Check the key and that it has the “send” scope.', 'sendfeather' );
				break;
			case 429 === $status:
				$summary = __( 'Sendfeather rate-limited this message. It can retry in a moment.', 'sendfeather' );
				break;
			case $status >= 500:
				$summary = __( 'Sendfeather had a server error. Try again shortly.', 'sendfeather' );
				break;
			default:
				$summary = sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Sendfeather refused the message (HTTP %d).', 'sendfeather' ),
					$status
				);
		}

		$parts = array_values( array_unique( array_filter( array( $summary, $message, implode( ' ', array_unique( $details ) ) ) ) ) );

		return implode( ' ', $parts );
	}
}
