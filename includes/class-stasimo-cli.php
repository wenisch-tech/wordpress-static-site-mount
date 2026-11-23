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
	public function list_() {
		$rows = array();
		foreach ( Stasimo_Mounts::all() as $slug => $m ) {
			$m      = wp_parse_args( $m, Stasimo_Mounts::defaults() );
			$idx    = Stasimo_Indexer::get( $slug );
			$rows[] = array(
				'slug'  => $slug,
				'url'   => Stasimo_Mounts::url( $m ),
				'build' => Stasimo_Store::current( $slug ),
				'pages' => count( $idx['pages'] ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'slug', 'url', 'build', 'pages' ) );
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
	 * : docusaurus, mkdocs-material or generic.
	 *
	 * [--header-offset=<px>]
	 * : Space for a fixed theme header.
	 *
	 * [--host-theme-key=<key>]
	 * : localStorage key of the host theme's light/dark choice.
	 *
	 * [--trusted]
	 * : Run the site's own JavaScript and print its markup as it is (needed for Docusaurus, MkDocs). Without it the pages are filtered.
	 *
	 * @param array $args  Positional arguments: the mount name.
	 * @param array $assoc Named options.
	 */
	public function add( $args, $assoc ) {
		$raw = array(
			'slug'           => $args[0],
			'prefix'         => isset( $assoc['prefix'] ) ? $assoc['prefix'] : '',
			'preset'         => isset( $assoc['preset'] ) ? $assoc['preset'] : 'docusaurus',
			'header_offset'  => isset( $assoc['header-offset'] ) ? $assoc['header-offset'] : 0,
			'host_theme_key' => isset( $assoc['host-theme-key'] ) ? $assoc['host-theme-key'] : '',
			'trusted'        => ! empty( $assoc['trusted'] ),
		);
		$m   = Stasimo_Mounts::sanitize( $raw );
		if ( is_wp_error( $m ) ) {
			WP_CLI::error( $m->get_error_message() );
		}
		$all               = Stasimo_Mounts::all();
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
	 *
	 * @param array $args Positional arguments: an optional mount name.
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
	 *
	 * @param array $args Positional arguments: the mount name and the zip file or folder.
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
