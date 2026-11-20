<?php
/**
 * One-time move of settings stored by versions before 0.3.0 (option prefix "ssm_") to the "stasimo_" prefix.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

class Stasimo_Upgrade {

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'run' ), 1 );
	}

	public static function run() {
		$old = get_option( 'ssm_mounts', null );
		if ( null === $old ) {
			return;
		}
		if ( false === get_option( 'stasimo_mounts', false ) && is_array( $old ) ) {
			update_option( 'stasimo_mounts', $old, false );
			foreach ( array_keys( $old ) as $slug ) {
				$index = get_option( 'ssm_index_' . $slug, null );
				if ( null !== $index ) {
					update_option( 'stasimo_index_' . $slug, $index, false );
				}
			}
			update_option( 'stasimo_rules_hash', '', false ); // rewrite rules are rebuilt on the next request.
		}
		foreach ( is_array( $old ) ? array_keys( $old ) : array() as $slug ) {
			delete_option( 'ssm_index_' . $slug );
			delete_option( 'ssm_lock_' . $slug );
		}
		delete_option( 'ssm_mounts' );
		delete_option( 'ssm_rules_hash' );
	}
}
