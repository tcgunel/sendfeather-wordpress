<?php
/**
 * Uninstall routine: remove the plugin's stored settings.
 *
 * Nothing else is stored — no custom tables, no transients, no user meta —
 * so removing the option is the whole job, on every site of the network.
 *
 * @package Sendfeather\SMTP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
defined( 'ABSPATH' ) || exit;

if ( is_multisite() ) {
	$sendfeather_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $sendfeather_site_ids as $sendfeather_site_id ) {
		switch_to_blog( (int) $sendfeather_site_id );
		delete_option( 'sendfeather_settings' );
		restore_current_blog();
	}
} else {
	delete_option( 'sendfeather_settings' );
}
