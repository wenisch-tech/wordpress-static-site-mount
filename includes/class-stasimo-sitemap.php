<?php
/**
 * XML sitemap for mounted pages, announced to Yoast's index, robots.txt and core sitemaps.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

class Stasimo_Sitemap {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_filter( 'wpseo_sitemap_index', array( __CLASS__, 'yoast_index' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 20 );
	}

	public static function url() {
		return home_url( '/stasimo-pages.xml' );
	}

	private static function has_pages() {
		foreach ( Stasimo_Mounts::active() as $slug => $m ) {
			$m = wp_parse_args( $m, Stasimo_Mounts::defaults() );
			if ( $m['sitemap'] && ! $m['noindex'] && ! empty( Stasimo_Indexer::get( $slug )['pages'] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function lastmod() {
		$max = 0;
		foreach ( Stasimo_Mounts::active() as $slug => $m ) {
			foreach ( Stasimo_Indexer::get( $slug )['pages'] as $p ) {
				$max = max( $max, (int) $p['m'] );
			}
		}
		return $max ? gmdate( 'c', $max ) : '';
	}

	public static function yoast_index( $xml ) {
		if ( ! self::has_pages() ) {
			return $xml;
		}
		$lm = self::lastmod();
		return $xml . '<sitemap><loc>' . esc_url( self::url() ) . '</loc>' . ( $lm ? '<lastmod>' . $lm . '</lastmod>' : '' ) . '</sitemap>' . "\n";
	}

	public static function robots( $output ) {
		if ( self::has_pages() && false === strpos( $output, 'stasimo-pages.xml' ) ) {
			$output .= "\nSitemap: " . self::url() . "\n";
		}
		return $output;
	}

	public static function serve() {
		if ( ! get_query_var( 'stasimo_sitemap' ) ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( Stasimo_Mounts::active() as $slug => $m ) {
			$m = wp_parse_args( $m, Stasimo_Mounts::defaults() );
			if ( ! $m['sitemap'] || $m['noindex'] ) {
				continue;
			}
			foreach ( Stasimo_Indexer::get( $slug )['pages'] as $path => $p ) {
				if ( ! empty( $p['n'] ) ) {
					continue;
				}
				echo '<url><loc>' . esc_url( Stasimo_Mounts::url( $m, $path ) ) . '</loc><lastmod>' . esc_html( gmdate( 'c', (int) $p['m'] ) ) . "</lastmod></url>\n";
			}
		}
		echo '</urlset>';
		exit;
	}
}
