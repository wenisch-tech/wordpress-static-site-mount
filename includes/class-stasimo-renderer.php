<?php
/**
 * Prints one static HTML page inside the active theme (block themes), with SEO tags written by the plugin.
 *
 * The original <body> markup is kept byte for byte (JavaScript apps such as Docusaurus hydrate
 * against it). The generator's own navbar/footer are hidden with CSS, or removed for plain sites.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints one static HTML page inside the active theme (block themes), with SEO tags written by the plugin.
 */
class Stasimo_Renderer {

	/**
	 * Header or footer of the active block theme, as HTML. Empty for classic themes.
	 *
	 * @param string $slug Template part slug, header or footer.
	 */
	public static function template_part( $slug ) {
		$html = '';
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$html = do_blocks( '<!-- wp:template-part {"slug":"' . esc_attr( $slug ) . '","theme":"' . esc_attr( get_stylesheet() ) . '","tagName":"div"} /-->' );
		}
		return (string) apply_filters( 'stasimo_' . $slug . '_html', $html );
	}

	/**
	 * Print one static HTML page inside the active theme.
	 *
	 * @param array  $m       Mount (raw).
	 * @param string $html    HTML of the page.
	 * @param string $rel_url URL path relative to the mount, as requested.
	 * @param int    $status  HTTP status (200 or 404).
	 */
	public static function render( array $m, $html, $rel_url, $status = 200 ) {
		$cfg   = Stasimo_Mounts::effective( $m );
		$html  = (string) $html;
		$parts = Stasimo_HTML::split( $html );
		$seo   = Stasimo_HTML::seo( $parts['head'] );

		// A trusted mount runs the site's own JavaScript and prints its markup as it is. Any other mount is filtered.
		$trusted = ! empty( $m['trusted'] );
		$head    = Stasimo_HTML::clean_head( $parts['head'] );
		$body    = $parts['body'];
		if ( '' !== $cfg['remove_all'] ) {
			$body = Stasimo_HTML::remove_nodes( $body, $cfg['remove_all'] );
		}

		$canonical = Stasimo_Mounts::url( $m, $rel_url );
		$title     = '' !== $seo['title'] ? $seo['title'] : get_bloginfo( 'name' );
		if ( '' !== $m['title_suffix'] && false === strpos( $title, $m['title_suffix'] ) ) {
			$title .= $m['title_suffix'];
		}
		$title   = (string) apply_filters( 'stasimo_title', $title, $m, $rel_url );
		$desc    = (string) apply_filters( 'stasimo_description', $seo['description'], $m, $rel_url );
		$noindex = $m['noindex'] || false !== strpos( $seo['robots'], 'noindex' ) || 200 !== $status;
		$image   = $seo['image'];
		if ( '' !== $image && ! preg_match( '#^https?://#i', $image ) ) {
			$image = 0 === strpos( $image, '/' ) ? home_url( $image ) : Stasimo_Mounts::url( $m, $image );
		}

		// Light/dark: make the generator follow the host theme's choice (printed with wp_print_inline_script_tag()).
		$bridge_js = '';
		if ( '' !== $m['host_theme_key'] ) {
			$gen       = $cfg['theme_key'];
			$bridge_js = '(function(){try{var h=' . wp_json_encode( $m['host_theme_key'] ) . ',g=' . wp_json_encode( $gen ) . ',d=document.documentElement,'
				. 't=localStorage.getItem(h)===\'dark\'?\'dark\':\'light\';d.setAttribute(\'data-theme\',t);'
				. ( '' !== $gen ? 'localStorage.setItem(g,t);new MutationObserver(function(){var v=d.getAttribute(\'data-theme\');if(v){localStorage.setItem(g,v);}}).observe(d,{attributes:true,attributeFilter:[\'data-theme\']});' : '' )
				. '}catch(e){}})();';
		}

		// Generators that keep the colour scheme on <body> (MkDocs Material): set it from the host's choice before first paint.
		$body_bridge_js = '';
		if ( ! empty( $cfg['body_attr'] ) && '' !== $m['host_theme_key'] ) {
			$ba             = $cfg['body_attr'];
			$body_bridge_js = '(function(){try{var d=localStorage.getItem(' . wp_json_encode( $m['host_theme_key'] ) . ')===\'dark\';'
				. 'document.body.setAttribute(' . wp_json_encode( $ba['name'] ) . ',d?' . wp_json_encode( $ba['dark'] ) . ':' . wp_json_encode( $ba['light'] ) . ');'
				. 'new MutationObserver(function(){var v=document.documentElement.getAttribute(\'data-theme\');if(v){document.body.setAttribute(' . wp_json_encode( $ba['name'] ) . ',v===\'dark\'?' . wp_json_encode( $ba['dark'] ) . ':' . wp_json_encode( $ba['light'] ) . ');}}).observe(document.documentElement,{attributes:true,attributeFilter:[\'data-theme\']});'
				. '}catch(e){}})();';
		}

		// The theme's header/footer sit inside the generator's <body>: give them a sane text baseline (themes can refine it via .stasimo-host).
		$css = (string) apply_filters( 'stasimo_host_css', '.stasimo-host{font-size:16px;line-height:1.5;font-weight:400;text-align:left}' );
		if ( '' !== $cfg['hide_all'] ) {
			$css .= $cfg['hide_all'] . '{display:none!important}';
		}
		$css .= $cfg['css'];
		if ( (int) $m['header_offset'] > 0 ) {
			$css .= '.stasimo-content{padding-top:' . (int) $m['header_offset'] . 'px}';
		}
		if ( '' !== $css ) {
			// Inline CSS goes through the style API. Selectors were cleaned in Stasimo_Mounts::clean_selectors(), no "</", "{" or ";" can get in.
			wp_register_style( 'stasimo-wrap', false, array(), STASIMO_VERSION );
			wp_enqueue_style( 'stasimo-wrap' );
			wp_add_inline_style( 'stasimo-wrap', $css );
		}

		if ( ! $trusted ) {
			// Filtered: the page's stylesheets and inline styles go through the style API, its scripts are not used at all.
			Stasimo_HTML::enqueue_head_assets( $parts['head'] . "\n" . $parts['body'], $canonical );
		}

		// Theme parts are rendered first so their styles are queued before wp_head().
		$header = self::template_part( 'header' );
		$footer = self::template_part( 'footer' );

		// Our SEO replaces the theme's and SEO plugins' tags for this request.
		remove_action( 'wp_head', '_wp_render_title_tag', 1 );
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'wp_robots', 1 );
		add_filter( 'wpseo_frontend_presenters', '__return_empty_array', 99 );
		add_filter( 'rank_math/frontend/disable_integration', '__return_true' );

		status_header( $status );
		header( 'Content-Type: text/html; charset=UTF-8' );
		if ( $noindex ) {
			header( 'X-Robots-Tag: noindex, follow' );
		}

		echo "<!DOCTYPE html>\n<html ";
		if ( '' !== $parts['html_attrs'] ) {
			// The attributes of the uploaded <html> tag are rebuilt and escaped while they are printed.
			Stasimo_HTML::print_attrs( $parts['html_attrs'], '', ! $trusted );
		} else {
			language_attributes();
		}
		echo ">\n<head>\n<meta charset=\"utf-8\">\n";
		self::print_seo( $title, $desc, $canonical, $noindex, $image, $status );
		if ( '' !== $bridge_js ) {
			wp_print_inline_script_tag( $bridge_js );
		}
		wp_head();
		if ( $trusted ) {
			// Trusted mount: its own head (scripts, styles, links) is printed as it is. See the note in print_content().
			echo $head . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted mount, deployed by a user with unfiltered_html.
		}
		echo "</head>\n<body ";
		Stasimo_HTML::print_attrs( $parts['body_attrs'], 'stasimo-page', ! $trusted );
		echo ">\n";
		wp_body_open();
		if ( '' !== $body_bridge_js ) {
			wp_print_inline_script_tag( $body_bridge_js );
		}
		echo '<div class="stasimo-host stasimo-host-header">' . $header . "</div>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by core (do_blocks) from the theme's own template part.
		echo '<div class="stasimo-content">';
		self::print_content( $body, $trusted );
		echo "</div>\n";
		echo '<div class="stasimo-host stasimo-host-footer">' . $footer . "</div>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by core (do_blocks) from the theme's own template part.
		wp_footer();
		echo "\n</body>\n</html>\n";
	}

	/**
	 * Print the body of the uploaded page.
	 *
	 * A filtered mount passes it through wp_kses(): scripts, styles, forms, frames and event handlers are removed. A trusted
	 * mount prints it as it is, because its purpose is to run a generated site such as Docusaurus or MkDocs. Only a user
	 * with manage_options and unfiltered_html can deploy to, or switch on, a trusted mount (see Stasimo_Mounts::can_manage()),
	 * the same trust WordPress gives to post content written by such a user.
	 *
	 * @param string $body    Body markup of the uploaded page.
	 * @param bool   $trusted Whether the mount is trusted.
	 */
	private static function print_content( $body, $trusted ) {
		if ( $trusted ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted mount, deployed by a user with unfiltered_html.
		} else {
			// wp_kses() removes the tags of a script but would leave its text behind, so those blocks are dropped first.
			// Styles and stylesheet links of the page were already queued through the style API.
			$body = preg_replace( '#<(script|style|template|noscript|object|embed)\b[^>]*>.*?</\1\s*>#is', '', $body );
			echo wp_kses( (string) $body, Stasimo_HTML::filtered_tags() );
		}
	}

	/**
	 * Print the title, description, canonical, robots, Open Graph and Twitter tags of a page, each escaped as it is printed.
	 *
	 * @param string $title     Page title.
	 * @param string $desc      Meta description.
	 * @param string $canonical Canonical URL.
	 * @param bool   $noindex   Whether search engines are asked to skip the page.
	 * @param string $image     Social image URL, or an empty string.
	 * @param int    $status    HTTP status of the response.
	 */
	private static function print_seo( $title, $desc, $canonical, $noindex, $image, $status ) {
		echo '<title>' . esc_html( $title ) . "</title>\n";
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . "\">\n";
		}
		if ( 200 === $status ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . "\">\n";
		}
		if ( $noindex ) {
			echo "<meta name=\"robots\" content=\"noindex, follow\">\n";
		}
		echo '<meta property="og:type" content="article">' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . "\">\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . "\">\n";
		if ( '' !== $desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . "\">\n";
		}
		echo '<meta property="og:url" content="' . esc_url( $canonical ) . "\">\n";
		if ( '' !== $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . "\">\n";
		}
		echo '<meta name="twitter:card" content="' . ( '' !== $image ? 'summary_large_image' : 'summary' ) . "\">\n";
	}
}
