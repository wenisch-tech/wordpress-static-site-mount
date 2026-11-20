<?php
/**
 * Small, dependency-free HTML helpers (split a document, read SEO data, strip nodes).
 *
 * Regex is used on purpose for the document frame, so the markup of JavaScript apps (Docusaurus)
 * is passed through byte for byte and hydration keeps working.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

class Stasimo_HTML {

	/**
	 * Split a full document into its parts.
	 *
	 * @return array{html_attrs:string, head:string, body_attrs:string, body:string}
	 */
	public static function split( $html ) {
		$out = array(
			'html_attrs' => '',
			'head'       => '',
			'body_attrs' => '',
			'body'       => $html,
		);
		if ( preg_match( '/<html([^>]*)>/is', $html, $m ) ) {
			$out['html_attrs'] = trim( $m[1] );
		}
		if ( preg_match( '/<head[^>]*>(.*?)<\/head>/is', $html, $m ) ) {
			$out['head'] = $m[1];
		}
		if ( preg_match( '/<body([^>]*)>(.*)<\/body>/is', $html, $m ) ) {
			$out['body_attrs'] = trim( $m[1] );
			$out['body']       = $m[2];
		} elseif ( preg_match( '/<body([^>]*)>(.*)$/is', $html, $m ) ) {
			$out['body_attrs'] = trim( $m[1] );
			$out['body']       = $m[2];
		}
		return $out;
	}

	public static function attrs( $tag ) {
		$attrs = array();
		if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $tag, $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $a ) {
				$val                         = isset( $a[4] ) && '' !== $a[4] ? $a[4] : ( isset( $a[3] ) && '' !== $a[3] ? $a[3] : $a[2] );
				$attrs[ strtolower( $a[1] ) ] = html_entity_decode( $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $attrs;
	}

	/**
	 * Read what search engines care about from a document head.
	 *
	 * @return array{title:string, description:string, robots:string, image:string, canonical:string}
	 */
	public static function seo( $head ) {
		$seo = array(
			'title'       => '',
			'description' => '',
			'robots'      => '',
			'image'       => '',
			'canonical'   => '',
		);
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $head, $m ) ) {
			$seo['title'] = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		if ( preg_match_all( '/<meta\s[^>]*>/is', $head, $metas ) ) {
			foreach ( $metas[0] as $tag ) {
				$a    = self::attrs( $tag );
				$name = isset( $a['name'] ) ? strtolower( $a['name'] ) : ( isset( $a['property'] ) ? strtolower( $a['property'] ) : '' );
				$val  = isset( $a['content'] ) ? $a['content'] : '';
				if ( 'description' === $name && '' === $seo['description'] ) {
					$seo['description'] = $val;
				} elseif ( 'og:description' === $name && '' === $seo['description'] ) {
					$seo['description'] = $val;
				} elseif ( 'robots' === $name ) {
					$seo['robots'] = strtolower( $val );
				} elseif ( 'og:image' === $name && '' === $seo['image'] ) {
					$seo['image'] = $val;
				}
			}
		}
		if ( preg_match( '/<link\s[^>]*rel=["\']?canonical["\']?[^>]*>/is', $head, $m ) ) {
			$a = self::attrs( $m[0] );
			if ( ! empty( $a['href'] ) ) {
				$seo['canonical'] = $a['href'];
			}
		}
		return $seo;
	}

	/**
	 * Remove head tags that the host page writes itself (SEO, charset, viewport).
	 */
	public static function clean_head( $head ) {
		$head = preg_replace( '/<title[^>]*>.*?<\/title>/is', '', $head );
		$head = preg_replace( '/<base\s[^>]*>/is', '', $head );
		$head = preg_replace_callback(
			'/<meta\s[^>]*>/is',
			function ( $m ) {
				$a    = Stasimo_HTML::attrs( $m[0] );
				$name = isset( $a['name'] ) ? strtolower( $a['name'] ) : ( isset( $a['property'] ) ? strtolower( $a['property'] ) : '' );
				if ( isset( $a['charset'] ) || isset( $a['http-equiv'] ) ) {
					return '';
				}
				if ( in_array( $name, array( 'description', 'robots', 'viewport' ), true ) ) {
					return '';
				}
				if ( 0 === strpos( $name, 'og:' ) || 0 === strpos( $name, 'twitter:' ) || 0 === strpos( $name, 'article:' ) ) {
					return '';
				}
				return $m[0];
			},
			$head
		);
		$head = preg_replace( '/<link\s[^>]*rel=["\']?canonical["\']?[^>]*>/is', '', $head );
		return $head;
	}

	/**
	 * Convert a simple selector list into XPath. Supported: tag, #id, .class and descendant spaces.
	 */
	public static function selector_to_xpath( $selectors ) {
		$parts = array();
		foreach ( explode( ',', $selectors ) as $sel ) {
			$sel = trim( $sel );
			if ( '' === $sel || preg_match( '/[>+~\[\]:()*]/', $sel ) ) {
				continue; // unsupported selector: skipped, never guessed.
			}
			$xp = '';
			foreach ( preg_split( '/\s+/', $sel ) as $compound ) {
				if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9]*)?(#[\w-]+)?((?:\.[\w-]+)*)$/', $compound, $m ) ) {
					$xp = '';
					break;
				}
				$tag  = '' !== $m[1] ? strtolower( $m[1] ) : '*';
				$step = '//' . $tag;
				if ( ! empty( $m[2] ) ) {
					$step .= "[@id='" . substr( $m[2], 1 ) . "']";
				}
				if ( ! empty( $m[3] ) ) {
					foreach ( array_filter( explode( '.', $m[3] ) ) as $cls ) {
						$step .= "[contains(concat(' ',normalize-space(@class),' '),' " . $cls . " ')]";
					}
				}
				$xp .= $step;
			}
			if ( '' !== $xp ) {
				$parts[] = $xp;
			}
		}
		return implode( ' | ', $parts );
	}

	/**
	 * Remove nodes by selector from an HTML fragment (static sites without JavaScript apps).
	 */
	public static function remove_nodes( $fragment, $selectors ) {
		$xpath_expr = self::selector_to_xpath( $selectors );
		if ( '' === $xpath_expr || ! class_exists( 'DOMDocument' ) ) {
			return $fragment;
		}
		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><div id="stasimo-root">' . $fragment . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp    = new DOMXPath( $dom );
		$nodes = $xp->query( $xpath_expr );
		if ( $nodes ) {
			foreach ( iterator_to_array( $nodes ) as $node ) {
				if ( $node->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
					$node->parentNode->removeChild( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				}
			}
		}
		$root = $dom->getElementById( 'stasimo-root' );
		if ( ! $root ) {
			return $fragment;
		}
		$out = '';
		foreach ( $root->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$out .= $dom->saveHTML( $child );
		}
		return $out;
	}
}
