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

/**
 * Small, dependency-free HTML helpers: split a document, read SEO data, rebuild tag attributes, strip nodes.
 */
class Stasimo_HTML {

	/**
	 * Split a full document into its parts.
	 *
	 * @param string $html Complete HTML document.
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

	/**
	 * Read the attributes of one tag as a name to decoded value map.
	 *
	 * @param string $tag Markup of the tag.
	 * @return array
	 */
	public static function attrs( $tag ) {
		$attrs = array();
		if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $tag, $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $a ) {
				$val                          = isset( $a[4] ) && '' !== $a[4] ? $a[4] : ( isset( $a[3] ) && '' !== $a[3] ? $a[3] : $a[2] );
				$attrs[ strtolower( $a[1] ) ] = html_entity_decode( $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $attrs;
	}

	/**
	 * Print the attributes of a tag, rebuilt from scratch: every name and value goes through esc_attr() as it is printed,
	 * so what comes out for <html> and <body> is escaped even though the file it came from is not.
	 *
	 * @param string $raw       Attribute text from the original tag.
	 * @param string $add_class Optional class to add.
	 * @param bool   $filtered  True in a filtered mount: event handler attributes (onload and the like) are dropped.
	 */
	public static function print_attrs( $raw, $add_class = '', $filtered = false ) {
		$attrs = array();
		if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>=`]+)))?/', (string) $raw, $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $a ) {
				$val = '';
				foreach ( array( 4, 3, 2 ) as $k ) {
					if ( isset( $a[ $k ] ) && '' !== $a[ $k ] ) {
						$val = $a[ $k ];
						break;
					}
				}
				$attrs[ $a[1] ] = html_entity_decode( $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		if ( '' !== $add_class ) {
			$attrs['class'] = trim( ( isset( $attrs['class'] ) ? $attrs['class'] . ' ' : '' ) . $add_class );
		}
		$first = true;
		foreach ( $attrs as $name => $val ) {
			if ( $filtered && 0 === stripos( $name, 'on' ) ) {
				continue;
			}
			echo $first ? '' : ' ';
			echo esc_attr( $name );
			if ( '' !== $val || 'class' === $name || preg_match( '/^(lang|dir|id|style|data-|aria-)/i', $name ) ) {
				echo '="' . esc_attr( $val ) . '"';
			}
			$first = false;
		}
	}

	/**
	 * The HTML a filtered mount may contain: everything WordPress allows in post content, plus buttons, responsive images
	 * and simple SVG shapes. Scripts, styles, forms, frames and event handler attributes are not part of it.
	 *
	 * @return array Allowed tags and attributes for wp_kses().
	 */
	public static function filtered_tags() {
		$g     = array(
			'class'            => true,
			'id'               => true,
			'style'            => true,
			'title'            => true,
			'role'             => true,
			'lang'             => true,
			'dir'              => true,
			'aria-hidden'      => true,
			'aria-label'       => true,
			'aria-labelledby'  => true,
			'aria-describedby' => true,
			'data-*'           => true,
		);
		$paint = array(
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'fill-rule'       => true,
			'clip-rule'       => true,
			'opacity'         => true,
			'transform'       => true,
		);
		$extra = array(
			'button'   => $g + array(
				'type'     => true,
				'disabled' => true,
				'name'     => true,
				'value'    => true,
			),
			'picture'  => $g,
			'source'   => $g + array(
				'src'    => true,
				'srcset' => true,
				'sizes'  => true,
				'media'  => true,
				'type'   => true,
			),
			'svg'      => $g + $paint + array(
				'xmlns'               => true,
				'viewbox'             => true,
				'width'               => true,
				'height'              => true,
				'focusable'           => true,
				'preserveaspectratio' => true,
			),
			'g'        => $g + $paint,
			'path'     => $g + $paint + array( 'd' => true ),
			'circle'   => $g + $paint + array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			),
			'ellipse'  => $g + $paint + array(
				'cx' => true,
				'cy' => true,
				'rx' => true,
				'ry' => true,
			),
			'rect'     => $g + $paint + array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
			),
			'line'     => $g + $paint + array(
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			),
			'polyline' => $g + $paint + array( 'points' => true ),
			'polygon'  => $g + $paint + array( 'points' => true ),
		);
		return apply_filters( 'stasimo_filtered_html', array_merge( wp_kses_allowed_html( 'post' ), $extra ) );
	}

	/**
	 * Absolute http(s) URL for a link found in an uploaded page, or an empty string if it is not safe to use.
	 *
	 * @param string $href     URL as written in the page.
	 * @param string $page_url Public URL of the page that contains it.
	 * @return string
	 */
	private static function resolve_url( $href, $page_url ) {
		$href = trim( $href );
		if ( '' === $href || ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) && ! preg_match( '#^https?://#i', $href ) ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $href ) ) {
			$abs = $href;
		} elseif ( 0 === strpos( $href, '//' ) ) {
			$abs = set_url_scheme( $href );
		} elseif ( 0 === strpos( $href, '/' ) ) {
			$abs = home_url( $href );
		} else {
			$base = (string) strtok( $page_url, '?#' );
			$abs  = substr( $base, 0, (int) strrpos( $base, '/' ) + 1 ) . $href;
		}
		if ( ! preg_match( '#^(https?://[^/?\#]+)([^?\#]*)(.*)$#i', $abs, $u ) ) {
			return '';
		}
		$path = array();
		foreach ( explode( '/', $u[2] ) as $seg ) {
			if ( '..' === $seg ) {
				array_pop( $path );
			} elseif ( '.' !== $seg ) {
				$path[] = $seg;
			}
		}
		return esc_url_raw( $u[1] . implode( '/', $path ) . $u[3], array( 'http', 'https' ) );
	}

	/**
	 * Queue the stylesheets and inline styles of an uploaded page through the WordPress style API, so wp_head() prints
	 * them escaped. This is what a filtered mount does instead of printing the page's own head.
	 *
	 * @param string $head     Markup to take stylesheets and styles from: the document head and body.
	 * @param string $page_url Public URL of the page.
	 */
	public static function enqueue_head_assets( $head, $page_url ) {
		$i = 0;
		if ( preg_match_all( '/<link\s[^>]*>/is', $head, $links ) ) {
			foreach ( $links[0] as $tag ) {
				$a = self::attrs( $tag );
				if ( empty( $a['href'] ) || 'stylesheet' !== strtolower( trim( isset( $a['rel'] ) ? $a['rel'] : '' ) ) ) {
					continue;
				}
				$href = self::resolve_url( $a['href'], $page_url );
				if ( '' === $href ) {
					continue;
				}
				$media = isset( $a['media'] ) && preg_match( '/^[a-z0-9 ,()\-:.<>=]+$/i', $a['media'] ) ? $a['media'] : 'all';
				wp_enqueue_style( 'stasimo-site-' . ( ++$i ), $href, array(), STASIMO_VERSION, $media );
			}
		}
		$css = '';
		if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $head, $blocks ) ) {
			foreach ( $blocks[1] as $block ) {
				$css .= wp_strip_all_tags( $block ) . "\n";
			}
		}
		if ( '' !== trim( $css ) ) {
			wp_register_style( 'stasimo-site-inline', false, array(), STASIMO_VERSION );
			wp_enqueue_style( 'stasimo-site-inline' );
			wp_add_inline_style( 'stasimo-site-inline', $css );
		}
	}

	/**
	 * Read what search engines care about from a document head.
	 *
	 * @param string $head Content of the document head.
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
	 *
	 * @param string $head Content of the document head.
	 * @return string
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
	 *
	 * @param string $selectors Comma separated simple selectors.
	 * @return string
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
	 *
	 * @param string $fragment  HTML fragment.
	 * @param string $selectors Comma separated simple selectors.
	 * @return string
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
		// The DOM extension's own property names (parentNode, childNodes) are camelCase and cannot be renamed.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$xp    = new DOMXPath( $dom );
		$nodes = $xp->query( $xpath_expr );
		if ( $nodes ) {
			foreach ( iterator_to_array( $nodes ) as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
		$root = $dom->getElementById( 'stasimo-root' );
		if ( ! $root ) {
			return $fragment;
		}
		$out = '';
		foreach ( $root->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $out;
	}
}
