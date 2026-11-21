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

class Stasimo_Renderer {

	/**
	 * Header or footer of the active block theme, as HTML. Empty for classic themes.
	 */
	public static function template_part( $slug ) {
		$html = '';
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$html = do_blocks( '<!-- wp:template-part {"slug":"' . esc_attr( $slug ) . '","theme":"' . esc_attr( get_stylesheet() ) . '","tagName":"div"} /-->' );
		}
		return (string) apply_filters( 'stasimo_' . $slug . '_html', $html );
	}

	/**
	 * @param array  $m        Mount (raw).
	 * @param string $file     Absolute HTML file.
	 * @param string $rel_url  URL path relative to the mount, as requested.
	 * @param int    $status   HTTP status (200 or 404).
	 */
	public static function render( array $m, $file, $rel_url, $status = 200 ) {
		$cfg   = Stasimo_Mounts::effective( $m );
		$html  = (string) Stasimo_Files::fs()->get_contents( $file );
		$parts = Stasimo_HTML::split( $html );
		$seo   = Stasimo_HTML::seo( $parts['head'] );

		$head = Stasimo_HTML::clean_head( $parts['head'] );
		$body = $parts['body'];
		if ( '' !== $cfg['remove_all'] ) {
			$body = Stasimo_HTML::remove_nodes( $body, $cfg['remove_all'] );
		}

		$canonical = Stasimo_Mounts::url( $m, $rel_url );
		$title     = '' !== $seo['title'] ? $seo['title'] : get_bloginfo( 'name' );
		if ( '' !== $m['title_suffix'] && false === strpos( $title, $m['title_suffix'] ) ) {
			$title .= $m['title_suffix'];
		}
		$title    = (string) apply_filters( 'stasimo_title', $title, $m, $rel_url );
		$desc     = (string) apply_filters( 'stasimo_description', $seo['description'], $m, $rel_url );
		$noindex  = $m['noindex'] || false !== strpos( $seo['robots'], 'noindex' ) || 200 !== $status;
		$image    = $seo['image'];
		if ( '' !== $image && ! preg_match( '#^https?://#i', $image ) ) {
			$image = 0 === strpos( $image, '/' ) ? home_url( $image ) : Stasimo_Mounts::url( $m, $image );
		}

		$seo_tags  = '<title>' . esc_html( $title ) . "</title>\n";
		$seo_tags .= '' !== $desc ? '<meta name="description" content="' . esc_attr( $desc ) . "\">\n" : '';
		if ( 200 === $status ) {
			$seo_tags .= '<link rel="canonical" href="' . esc_url( $canonical ) . "\">\n";
		}
		if ( $noindex ) {
			$seo_tags .= "<meta name=\"robots\" content=\"noindex, follow\">\n";
		}
		$seo_tags .= '<meta property="og:type" content="article">' . "\n";
		$seo_tags .= '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . "\">\n";
		$seo_tags .= '<meta property="og:title" content="' . esc_attr( $title ) . "\">\n";
		$seo_tags .= '' !== $desc ? '<meta property="og:description" content="' . esc_attr( $desc ) . "\">\n" : '';
		$seo_tags .= '<meta property="og:url" content="' . esc_url( $canonical ) . "\">\n";
		$seo_tags .= '' !== $image ? '<meta property="og:image" content="' . esc_url( $image ) . "\">\n" : '';
		$seo_tags .= '<meta name="twitter:card" content="' . ( '' !== $image ? 'summary_large_image' : 'summary' ) . "\">\n";

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

		// Theme parts are rendered first so their styles are queued before wp_head().
		$header = self::template_part( 'header' );
		$footer = self::template_part( 'footer' );

		// Our SEO replaces the theme's and SEO plugins' tags for this request.
		remove_action( 'wp_head', '_wp_render_title_tag', 1 );
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'wp_robots', 1 );
		add_filter( 'wpseo_frontend_presenters', '__return_empty_array', 99 );
		add_filter( 'rank_math/frontend/disable_integration', '__return_true' );

		// The attributes of the uploaded <html> and <body> tags are rebuilt and escaped (Stasimo_HTML::safe_attrs).
		$html_attrs = '' !== $parts['html_attrs'] ? Stasimo_HTML::safe_attrs( $parts['html_attrs'] ) : get_language_attributes();
		$body_attrs = Stasimo_HTML::safe_attrs( $parts['body_attrs'], 'stasimo-page' );

		status_header( $status );
		header( 'Content-Type: text/html; charset=UTF-8' );
		if ( $noindex ) {
			header( 'X-Robots-Tag: noindex, follow' );
		}

		/*
		 * The next echo calls print stored HTML on purpose and cannot be escaped: the product of this plugin is serving the
		 * uploaded static site as it is (its <head>, <body> and attributes), inside the theme. That content is trusted because only users
		 * with manage_options and unfiltered_html can deploy it (see Stasimo_Mounts::can_manage()). Everything the plugin builds
		 * itself ($seo_tags, scripts, styles) is escaped above or printed through core functions.
		 */
		echo "<!DOCTYPE html>\n<html " . $html_attrs . ">\n<head>\n<meta charset=\"utf-8\">\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes rebuilt with esc_attr() in Stasimo_HTML::safe_attrs().
		echo $seo_tags; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html()/esc_attr()/esc_url() above.
		if ( '' !== $bridge_js ) {
			wp_print_inline_script_tag( $bridge_js );
		}
		wp_head();
		echo $head . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the uploaded site's own <head> content.
		echo "</head>\n<body " . $body_attrs . ">\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes rebuilt with esc_attr() in Stasimo_HTML::safe_attrs().
		wp_body_open();
		if ( '' !== $body_bridge_js ) {
			wp_print_inline_script_tag( $body_bridge_js );
		}
		echo '<div class="stasimo-host stasimo-host-header">' . $header . "</div>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by core (do_blocks) from the theme's template part.
		echo '<div class="stasimo-content">' . $body . "</div>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the uploaded site's own <body>.
		echo '<div class="stasimo-host stasimo-host-footer">' . $footer . "</div>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by core (do_blocks) from the theme's template part.
		wp_footer();
		echo "\n</body>\n</html>\n";
	}
}
