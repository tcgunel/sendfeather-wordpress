<?php
/**
 * Development-only overrides for the wp-env test site.
 *
 * Not part of the distributed plugin — this folder is excluded from the
 * release zip. It points the plugin at a local Sendfeather platform stack
 * (e.g. a local Sendfeather development install) instead of the hosted API.
 *
 * @package Sendfeather\SMTP\Dev
 */

defined( 'ABSPATH' ) || exit;

// The local platform stack reachable from the wp-env containers.
add_filter(
	'sendfeather_api_base_url',
	static function (): string {
		return 'http://host.docker.internal:8091/api';
	}
);

// Log wp_mail_failed details to the PHP error log so wp-cli tests can assert
// the failure path fires for API-mode transport and validation errors.
add_action(
	'wp_mail_failed',
	static function ( $error ): void {
		if ( $error instanceof WP_Error ) {
			fwrite( STDERR, 'SENDFEATHER_WP_MAIL_FAILED: ' . $error->get_error_message() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostics only, never shipped.
		}
	}
);
