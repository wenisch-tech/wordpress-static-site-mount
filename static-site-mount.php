<?php
/**
 * Plugin Name:       Static Site Mount
 * Plugin URI:        https://github.com/wenisch-tech/wordpress-static-site-mount
 * Description:       Mount static site builds (Docusaurus, MkDocs, Hugo, plain HTML) at a URL path, wrapped in your theme's header and footer, with SEO tags and sitemap entries. Deploy from CI with one request.
 * Version:           0.2.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Jean-Fabian Wenisch
 * Author URI:        https://wenisch.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       static-site-mount
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

define( 'SSM_VERSION', '0.2.0' );
define( 'SSM_FILE', __FILE__ );
define( 'SSM_DIR', plugin_dir_path( __FILE__ ) );

require_once SSM_DIR . 'includes/class-ssm-mounts.php';
require_once SSM_DIR . 'includes/class-ssm-html.php';
require_once SSM_DIR . 'includes/class-ssm-indexer.php';
require_once SSM_DIR . 'includes/class-ssm-renderer.php';
require_once SSM_DIR . 'includes/class-ssm-server.php';
require_once SSM_DIR . 'includes/class-ssm-sitemap.php';
require_once SSM_DIR . 'includes/class-ssm-deployer.php';
require_once SSM_DIR . 'includes/class-ssm-rest.php';
require_once SSM_DIR . 'includes/class-ssm-admin.php';

SSM_Server::init();
SSM_Sitemap::init();
SSM_Rest::init();
SSM_Admin::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SSM_DIR . 'includes/class-ssm-cli.php';
	WP_CLI::add_command( 'static-site-mount', 'SSM_CLI' );
}

register_activation_hook(
	__FILE__,
	function () {
		SSM_Mounts::prepare_base_dir();
		SSM_Server::register_rewrites();
		flush_rewrite_rules( false );
		update_option( 'ssm_rules_hash', SSM_Mounts::rules_hash(), false );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules( false );
	}
);
