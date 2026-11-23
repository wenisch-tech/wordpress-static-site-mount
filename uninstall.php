<?php
/**
 * Remove all plugin data when the plugin is deleted: options, the files table and the media folder in uploads.
 *
 * @package StaticSiteMount
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove this plugin's data from the current site.
 */
function stasimo_uninstall_site() {
	global $wpdb;
	$mounts = get_option( 'stasimo_mounts', array() );
	if ( is_array( $mounts ) ) {
		foreach ( array_keys( $mounts ) as $slug ) {
			delete_option( 'stasimo_index_' . $slug );
			delete_option( 'stasimo_lock_' . $slug );
			delete_option( 'stasimo_build_' . $slug );
		}
	}
	delete_option( 'stasimo_mounts' );
	delete_option( 'stasimo_rules_hash' );
	delete_option( 'stasimo_db_version' );

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}stasimo_files" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange

	$up = wp_upload_dir( null, false );
	if ( ! empty( $up['basedir'] ) ) {
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() && $wp_filesystem ) {
			$wp_filesystem->rmdir( untrailingslashit( $up['basedir'] ) . '/stasimo-static-site-mount', true );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $stasimo_site ) {
		switch_to_blog( $stasimo_site );
		stasimo_uninstall_site();
		restore_current_blog();
	}
} else {
	stasimo_uninstall_site();
}
