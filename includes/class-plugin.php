<?php
/**
 * Plugin bootstrap.
 *
 * Wires the settings screen and the mail transport together. The transport
 * registers itself with WordPress on `plugins_loaded` so every later wp_mail()
 * call — core, themes, WooCommerce, contact forms — goes through it.
 *
 * @package Sendfeather\SMTP
 */

namespace Sendfeather\SMTP;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Boot the plugin.
	 */
	public static function boot(): void {
		register_activation_hook( SENDFEATHER_PLUGIN_FILE, array( __CLASS__, 'activate' ) );

		Settings::boot();
		Mailer::boot();
	}

	/**
	 * Activation: persist the default settings the first time the plugin runs.
	 *
	 * Idempotent — reactivating must never overwrite configured values.
	 */
	public static function activate(): void {
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}
	}
}
