<?php
/**
 * WP-CLI commands.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage static site mounts.
 */
class Stasimo_CLI {

	/**
	 * List mounts.
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = array();
		foreach ( Stasimo_Mounts::all() as $slug => $m ) {
			$m      = wp_parse_args( $m, Stasimo_Mounts::defaults() );
			$idx    = Stasimo_Indexer::get( $slug );
			$rows[] = array(
				'slug'   => $slug,
				'url'    => Stasimo_Mounts::url( $m ),
				'folder' => Stasimo_Mounts::dir( $m ),
				'pages'  => count( $idx['pages'] ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'slug', 'url', 'folder', 'pages' ) );
	}

	/**
	 * Create or update a mount.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Short name.
	 *
	 * --prefix=<prefix>
	 * : URL prefix, e.g. docs/my-project
	 *
	 * [--preset=<preset>]
	 * : docusaurus or generic.
	 *
	 * [--dir=<dir>]
	 * : Folder name inside the static-sites base folder (uploads) (absolute paths need the stasimo_allow_external_dirs filter).
	 *
	 * [--header-offset=<px>]
	 * : Space for a fixed theme header.
	 *
	 * [--host-theme-key=<key>]
	 * : localStorage key of the host theme's light/dark choice.
	 */
	public function add( $args, $assoc ) {
		$raw = array(
			'slug'           => $args[0],
			'prefix'         => isset( $assoc['prefix'] ) ? $assoc['prefix'] : '',
			'preset'         => isset( $assoc['preset'] ) ? $assoc['preset'] : 'docusaurus',
			'dir'            => isset( $assoc['dir'] ) ? $assoc['dir'] : '',
			'header_offset'  => isset( $assoc['header-offset'] ) ? $assoc['header-offset'] : 0,
			'host_theme_key' => isset( $assoc['host-theme-key'] ) ? $assoc['host-theme-key'] : '',
		);
		$m = Stasimo_Mounts::sanitize( $raw );
		if ( is_wp_error( $m ) ) {
			WP_CLI::error( $m->get_error_message() );
		}
		$all              = Stasimo_Mounts::all();
		$all[ $m['slug'] ] = $m;
		Stasimo_Mounts::save_all( $all );
		Stasimo_Mounts::prepare_base_dir();
		WP_CLI::success( 'Mount saved: ' . Stasimo_Mounts::url( $m ) );
	}

	/**
	 * Re-index one mount, or all.
	 *
	 * ## OPTIONS
	 *
	 * [<slug>]
	 * : Mount to index (default: all).
	 */
	public function reindex( $args ) {
		$all = Stasimo_Mounts::all();
		foreach ( $all as $slug => $m ) {
			if ( ! empty( $args[0] ) && $args[0] !== $slug ) {
				continue;
			}
			$r = Stasimo_Indexer::reindex( wp_parse_args( $m, Stasimo_Mounts::defaults() ) );
			if ( is_wp_error( $r ) ) {
				WP_CLI::warning( $slug . ': ' . $r->get_error_message() );
			} else {
				WP_CLI::success( $slug . ': ' . $r['count'] . ' pages' );
			}
		}
	}

	/**
	 * Replace a mount's files with a zip file or folder.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Mount.
	 *
	 * <path>
	 * : Zip file or folder.
	 */
	public function deploy( $args ) {
		$m = Stasimo_Mounts::get( $args[0] );
		if ( ! $m ) {
			WP_CLI::error( 'Unknown mount.' );
		}
		$r = is_dir( $args[1] ) ? Stasimo_Deployer::deploy_dir( $m, $args[1] ) : Stasimo_Deployer::deploy_zip( $m, $args[1] );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( sprintf( '%d files, %d pages indexed.', $r['files'], $r['pages'] ) );
	}
}
