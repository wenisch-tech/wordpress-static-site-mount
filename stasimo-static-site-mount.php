<?php
/**
 * Plugin Name:       Stasimo - Static Site Mount
 * Plugin URI:        https://github.com/wenisch-tech/wordpress-static-site-mount
 * Description:       Mount static site builds (Docusaurus, MkDocs, Hugo, plain HTML) at a URL path, wrapped in your theme's header and footer, with SEO tags and sitemap entries. Deploy from CI with one request.
 * Version:           0.3.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Jean-Fabian Wenisch
 * Author URI:        https://wenisch.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stasimo-static-site-mount
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

define( 'STASIMO_VERSION', '0.3.0' );
define( 'STASIMO_FILE', __FILE__ );
define( 'STASIMO_DIR', plugin_dir_path( __FILE__ ) );

require_once STASIMO_DIR . 'includes/class-stasimo-upgrade.php';
require_once STASIMO_DIR . 'includes/class-stasimo-files.php';
require_once STASIMO_DIR . 'includes/class-stasimo-mounts.php';
require_once STASIMO_DIR . 'includes/class-stasimo-html.php';
require_once STASIMO_DIR . 'includes/class-stasimo-indexer.php';
require_once STASIMO_DIR . 'includes/class-stasimo-renderer.php';
require_once STASIMO_DIR . 'includes/class-stasimo-server.php';
require_once STASIMO_DIR . 'includes/class-stasimo-sitemap.php';
require_once STASIMO_DIR . 'includes/class-stasimo-deployer.php';
require_once STASIMO_DIR . 'includes/class-stasimo-rest.php';
require_once STASIMO_DIR . 'includes/class-stasimo-admin.php';

Stasimo_Upgrade::init();
Stasimo_Server::init();
Stasimo_Sitemap::init();
Stasimo_Rest::init();
Stasimo_Admin::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once STASIMO_DIR . 'includes/class-stasimo-cli.php';
	WP_CLI::add_command( 'stasimo', 'Stasimo_CLI' );
}

register_activation_hook(
	__FILE__,
	function () {
		Stasimo_Mounts::prepare_base_dir();
		Stasimo_Server::register_rewrites();
		flush_rewrite_rules( false );
		update_option( 'stasimo_rules_hash', Stasimo_Mounts::rules_hash(), false );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules( false );
	}
);
