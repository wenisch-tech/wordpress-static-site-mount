<?php
/**
 * Remove plugin data when the plugin is deleted. Mounted files are left on disk on purpose.
 *
 * @package StaticSiteMount
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mounts = get_option( 'ssm_mounts', array() );
if ( is_array( $mounts ) ) {
	foreach ( array_keys( $mounts ) as $slug ) {
		delete_option( 'ssm_index_' . $slug );
		delete_option( 'ssm_lock_' . $slug );
	}
}
delete_option( 'ssm_mounts' );
delete_option( 'ssm_rules_hash' );
