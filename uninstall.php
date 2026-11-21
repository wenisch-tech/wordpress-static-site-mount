<?php
/**
 * Remove plugin data when the plugin is deleted. Mounted files are left on disk on purpose.
 *
 * @package StaticSiteMount
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$stasimo_mounts = get_option( 'stasimo_mounts', array() );
if ( is_array( $stasimo_mounts ) ) {
	foreach ( array_keys( $stasimo_mounts ) as $stasimo_slug ) {
		delete_option( 'stasimo_index_' . $stasimo_slug );
		delete_option( 'stasimo_lock_' . $stasimo_slug );
	}
}
delete_option( 'stasimo_mounts' );
delete_option( 'stasimo_rules_hash' );
