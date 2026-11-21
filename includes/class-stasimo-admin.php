<?php
/**
 * Admin screens: top-level "Static Sites" menu with Mounts (list, detail, add) and About.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens: the top-level Static Sites menu with Mounts (list, add, detail) and About.
 */
class Stasimo_Admin {

	const PAGE       = 'stasimo';
	const PAGE_ABOUT = 'stasimo-about';

	/**
	 * Register the menu, the stylesheet, the form handlers and the plugin action link.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_stasimo_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_stasimo_reindex', array( __CLASS__, 'reindex' ) );
		add_action( 'admin_post_stasimo_toggle', array( __CLASS__, 'toggle' ) );
		add_action( 'admin_post_stasimo_delete', array( __CLASS__, 'delete' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( STASIMO_FILE ), array( __CLASS__, 'links' ) );
	}

	/* ------------------------------------------------------------------ menu */

	/**
	 * Add a Mounts link to this plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Mounts', 'stasimo-static-site-mount' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the Static Sites menu with its Mounts and About screens.
	 */
	public static function menu() {
		add_menu_page( __( 'Static Sites', 'stasimo-static-site-mount' ), __( 'Static Sites', 'stasimo-static-site-mount' ), 'manage_options', self::PAGE, array( __CLASS__, 'page_mounts' ), 'dashicons-networking', 58 );
		add_submenu_page( self::PAGE, __( 'Mounts', 'stasimo-static-site-mount' ), __( 'Mounts', 'stasimo-static-site-mount' ), 'manage_options', self::PAGE, array( __CLASS__, 'page_mounts' ) );
		add_submenu_page( self::PAGE, __( 'About Stasimo', 'stasimo-static-site-mount' ), __( 'About', 'stasimo-static-site-mount' ), 'manage_options', self::PAGE_ABOUT, array( __CLASS__, 'page_about' ) );
	}

	/**
	 * Load the admin stylesheet on this plugin's screens only.
	 *
	 * @param string $hook Hook suffix of the current admin page.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'stasimo' ) ) {
			return;
		}
		wp_enqueue_style( 'stasimo-admin', plugins_url( 'assets/admin.css', STASIMO_FILE ), array(), STASIMO_VERSION );
	}

	/* --------------------------------------------------------------- helpers */

	/**
	 * Admin URL of the Mounts screen, with extra query arguments.
	 *
	 * @param array $args Query arguments to add.
	 * @return string
	 */
	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Nonce-protected admin-post URL for an action on one mount.
	 *
	 * @param string $action Action name.
	 * @param string $slug   Mount name.
	 * @return string
	 */
	private static function action_url( $action, $slug ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . '&slug=' . rawurlencode( $slug ) ), $action . '_' . $slug );
	}

	/**
	 * Redirect to the Mounts screen with a message, then stop.
	 *
	 * @param string $notice Message to show.
	 * @param string $type   Either success or error.
	 * @param array  $args   Extra query arguments.
	 */
	private static function back( $notice, $type = 'success', $args = array() ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					$args,
					array(
						'stasimo_notice' => $notice,
						'stasimo_type'   => $type,
					)
				),
				self::url( $args )
			)
		);
		exit;
	}

	/**
	 * Stop the request unless the current user may manage mounts.
	 */
	private static function guard() {
		if ( ! Stasimo_Mounts::can_manage() ) {
			wp_die( esc_html__( 'Not allowed. Managing mounts needs the manage_options and unfiltered_html capabilities, because a mounted build can contain JavaScript.', 'stasimo-static-site-mount' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Print the message passed along in a redirect, if there is one.
	 */
	private static function notice() {
		// Display-only message after a redirect; escaped on output, no state change.
		// phpcs:disable WordPress.Security.NonceVerification
		$notice = isset( $_GET['stasimo_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['stasimo_notice'] ) ) : '';
		$type   = isset( $_GET['stasimo_type'] ) && 'error' === sanitize_key( wp_unslash( (string) $_GET['stasimo_type'] ) ) ? 'error' : 'success';
		// phpcs:enable WordPress.Security.NonceVerification
		if ( $notice ) {
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
	}

	/**
	 * Status of a mount as a pair of CSS modifier and label.
	 *
	 * @param array $m Mount.
	 * @return string[]
	 */
	private static function status( array $m ) {
		if ( ! $m['enabled'] ) {
			return array( 'off', __( 'Disabled', 'stasimo-static-site-mount' ) );
		}
		if ( ! is_dir( Stasimo_Mounts::dir( $m ) ) ) {
			return array( 'warn', __( 'No files yet', 'stasimo-static-site-mount' ) );
		}
		return array( 'on', __( 'Active', 'stasimo-static-site-mount' ) );
	}

	/**
	 * Escaped HTML of a mount's status badge.
	 *
	 * @param array $m Mount.
	 * @return string
	 */
	private static function badge( array $m ) {
		$s = self::status( $m );
		return '<span class="stasimo-badge stasimo-badge--' . esc_attr( $s[0] ) . '">' . esc_html( $s[1] ) . '</span>';
	}

	/* -------------------------------------------------------------- handlers */

	/**
	 * Handle the add and edit form: validate, check conflicts and store the mount.
	 */
	public static function save() {
		self::guard();
		check_admin_referer( 'stasimo_save' );
		$raw      = isset( $_POST['stasimo'] ) && is_array( $_POST['stasimo'] ) ? map_deep( wp_unslash( $_POST['stasimo'] ), 'sanitize_text_field' ) : array(); // Every field is cleaned again in Stasimo_Mounts::sanitize().
		$original = isset( $_POST['stasimo_original'] ) ? sanitize_title( wp_unslash( $_POST['stasimo_original'] ) ) : '';
		$all      = Stasimo_Mounts::all();
		if ( '' !== $original && isset( $all[ $original ] ) ) {
			$raw['slug']    = $original; // the name of an existing mount does not change.
			$raw['enabled'] = isset( $all[ $original ]['enabled'] ) ? $all[ $original ]['enabled'] : true;
		}
		$m = Stasimo_Mounts::sanitize( $raw );
		if ( is_wp_error( $m ) ) {
			self::back( $m->get_error_message(), 'error', '' !== $original ? array( 'mount' => $original ) : array( 'action' => 'new' ) );
		}
		if ( '' === $original && isset( $all[ $m['slug'] ] ) ) {
			self::back( __( 'A mount with this name already exists.', 'stasimo-static-site-mount' ), 'error', array( 'action' => 'new' ) );
		}
		$conflict = Stasimo_Mounts::conflict( $m );
		if ( '' !== $conflict ) {
			self::back( $conflict, 'error', '' !== $original ? array( 'mount' => $original ) : array( 'action' => 'new' ) );
		}
		$all[ $m['slug'] ] = $m;
		Stasimo_Mounts::save_all( $all );
		Stasimo_Mounts::prepare_base_dir();
		$presets = Stasimo_Mounts::presets();
		$message = '' === $original ? __( 'Mount created. Upload the build next (see Deploy below).', 'stasimo-static-site-mount' ) : __( 'Saved.', 'stasimo-static-site-mount' );
		if ( ! $m['trusted'] && ! empty( $presets[ $m['preset'] ]['scripts'] ) ) {
			$message .= ' ' . __( 'This type needs its own JavaScript for search and menus: set Page content to Trusted if you want that.', 'stasimo-static-site-mount' );
		}
		self::back( $message, 'success', array( 'mount' => $m['slug'] ) );
	}

	/**
	 * Handle the re-index button of a mount.
	 */
	public static function reindex() {
		self::guard();
		$slug = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : '';
		check_admin_referer( 'stasimo_reindex_' . $slug );
		$m = Stasimo_Mounts::get( $slug );
		if ( ! $m ) {
			self::back( __( 'Unknown mount.', 'stasimo-static-site-mount' ), 'error' );
		}
		$r = Stasimo_Indexer::reindex( $m );
		if ( is_wp_error( $r ) ) {
			self::back( $r->get_error_message(), 'error', array( 'mount' => $slug ) );
		}
		/* translators: %d pages */
		self::back( sprintf( __( 'Indexed %d pages.', 'stasimo-static-site-mount' ), $r['count'] ), 'success', array( 'mount' => $slug ) );
	}

	/**
	 * Handle the enable and disable button of a mount.
	 */
	public static function toggle() {
		self::guard();
		$slug = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : '';
		check_admin_referer( 'stasimo_toggle_' . $slug );
		$all = Stasimo_Mounts::all();
		if ( ! isset( $all[ $slug ] ) ) {
			self::back( __( 'Unknown mount.', 'stasimo-static-site-mount' ), 'error' );
		}
		$now                     = ! ( isset( $all[ $slug ]['enabled'] ) ? $all[ $slug ]['enabled'] : true );
		$all[ $slug ]['enabled'] = $now;
		Stasimo_Mounts::save_all( $all );
		$back = isset( $_GET['to'] ) && 'detail' === sanitize_key( wp_unslash( $_GET['to'] ) ) ? array( 'mount' => $slug ) : array();
		self::back( $now ? __( 'Mount enabled. Its pages are live again.', 'stasimo-static-site-mount' ) : __( 'Mount disabled. Its URLs now show a normal 404; the files stay on disk.', 'stasimo-static-site-mount' ), 'success', $back );
	}

	/**
	 * Handle the remove button of a mount. Its files stay on disk.
	 */
	public static function delete() {
		self::guard();
		$slug = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : '';
		check_admin_referer( 'stasimo_delete_' . $slug );
		$all = Stasimo_Mounts::all();
		unset( $all[ $slug ] );
		Stasimo_Mounts::save_all( $all );
		delete_option( Stasimo_Indexer::option_name( $slug ) );
		self::back( __( 'Mount removed. Its files were left on disk.', 'stasimo-static-site-mount' ) );
	}

	/* ------------------------------------------------------------ list view */

	/**
	 * Mounts screen: the list, the add form or the detail view of one mount.
	 */
	public static function page_mounts() {
		self::guard();
		// Display-only: picks which screen to show, nothing is saved here (saving goes through check_admin_referer()).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['action'] ) && 'new' === sanitize_key( wp_unslash( $_GET['action'] ) ) ? 'new' : '';
		$slug = isset( $_GET['mount'] ) ? sanitize_title( wp_unslash( $_GET['mount'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap stasimo-wrap">';
		self::notice();
		if ( 'new' === $view ) {
			self::view_form( null );
		} elseif ( '' !== $slug && Stasimo_Mounts::get( $slug ) ) {
			self::view_form( Stasimo_Mounts::get( $slug ) );
		} else {
			self::view_list();
		}
		echo '</div>';
	}

	/**
	 * Print the list of mounts, or the empty state.
	 */
	private static function view_list() {
		$mounts = Stasimo_Mounts::all();
		?>
		<div class="stasimo-head">
			<h1><?php esc_html_e( 'Static Sites', 'stasimo-static-site-mount' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( self::url( array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add mount', 'stasimo-static-site-mount' ); ?></a>
		</div>
		<p class="stasimo-sub"><?php esc_html_e( 'A mount publishes a folder of static HTML (a documentation site, a blog, a landing page) at a URL of this site, inside your theme.', 'stasimo-static-site-mount' ); ?></p>

		<?php if ( ! $mounts ) : ?>
			<div class="stasimo-empty">
				<h2><?php esc_html_e( 'No mounts yet', 'stasimo-static-site-mount' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Build your site with the mount\'s URL as its base path.', 'stasimo-static-site-mount' ); ?></li>
					<li><?php esc_html_e( 'Add a mount here and choose where it appears.', 'stasimo-static-site-mount' ); ?></li>
					<li><?php esc_html_e( 'Upload the build (CI, WP-CLI or files), and it is live.', 'stasimo-static-site-mount' ); ?></li>
				</ol><br>
				<a class="button button-primary button-hero" href="<?php echo esc_url( self::url( array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add your first mount', 'stasimo-static-site-mount' ); ?></a>
				<a class="button button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_ABOUT ) ); ?>"><?php esc_html_e( 'How it works', 'stasimo-static-site-mount' ); ?></a>
			</div>
			<?php
			return;
		endif;
		$presets = Stasimo_Mounts::presets();
		?>
		<table class="widefat striped stasimo-table">
			<thead><tr>
				<th><?php esc_html_e( 'Mount', 'stasimo-static-site-mount' ); ?></th>
				<th><?php esc_html_e( 'Type', 'stasimo-static-site-mount' ); ?></th>
				<th><?php esc_html_e( 'Pages', 'stasimo-static-site-mount' ); ?></th>
				<th><?php esc_html_e( 'Last indexed', 'stasimo-static-site-mount' ); ?></th>
				<th><?php esc_html_e( 'Status', 'stasimo-static-site-mount' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $mounts as $slug => $m ) :
				$m   = wp_parse_args( $m, Stasimo_Mounts::defaults() );
				$idx = Stasimo_Indexer::get( $slug );
				?>
				<tr class="<?php echo $m['enabled'] ? '' : 'stasimo-off'; ?>">
					<td>
						<div class="stasimo-name"><a href="<?php echo esc_url( self::url( array( 'mount' => $slug ) ) ); ?>"><?php echo esc_html( $slug ); ?></a></div>
						<div class="stasimo-url"><?php echo esc_html( '/' . $m['prefix'] . '/' ); ?></div>
					</td>
					<td><?php echo esc_html( isset( $presets[ $m['preset'] ] ) ? $presets[ $m['preset'] ]['label'] : $m['preset'] ); ?></td>
					<td><?php echo esc_html( (string) count( $idx['pages'] ) ); ?></td>
					<td><?php echo $idx['time'] ? esc_html( human_time_diff( $idx['time'] ) . ' ' . __( 'ago', 'stasimo-static-site-mount' ) ) : '&ndash;'; ?></td>
					<td><?php echo wp_kses( self::badge( $m ), self::allowed_html() ); ?></td>
					<td>
						<div class="stasimo-actions">
							<a class="button" href="<?php echo esc_url( self::url( array( 'mount' => $slug ) ) ); ?>"><?php esc_html_e( 'Details', 'stasimo-static-site-mount' ); ?></a>
							<a class="button" href="<?php echo esc_url( self::action_url( 'stasimo_toggle', $slug ) ); ?>"><?php echo $m['enabled'] ? esc_html__( 'Disable', 'stasimo-static-site-mount' ) : esc_html__( 'Enable', 'stasimo-static-site-mount' ); ?></a>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ----------------------------------------------- detail / new (one form) */

	/** The form controls and badges this screen prints itself (values are escaped when built, this is the final filter). */
	private static function allowed_html() {
		$field = array(
			'type'        => true,
			'class'       => true,
			'name'        => true,
			'value'       => true,
			'id'          => true,
			'placeholder' => true,
			'min'         => true,
			'max'         => true,
			'step'        => true,
			'checked'     => true,
			'readonly'    => true,
			'disabled'    => true,
			'rows'        => true,
		);
		return array(
			'input'    => $field,
			'select'   => $field,
			'option'   => array(
				'value'    => true,
				'selected' => true,
			),
			'textarea' => $field,
			'label'    => array(
				'for'   => true,
				'class' => true,
			),
			'span'     => array( 'class' => true ),
			'div'      => array( 'class' => true ),
			'code'     => array(),
			'br'       => array(),
		);
	}

	/**
	 * Print one row of the settings form.
	 *
	 * @param string $label Row label.
	 * @param string $help  Help text below the field.
	 * @param string $input Markup of the form control, escaped by the caller.
	 */
	private static function field_row( $label, $help, $input ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . wp_kses( $input, self::allowed_html() );
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Print the add form (no mount given) or the detail view of a mount.
	 *
	 * @param array|null $m Mount, or null for a new one.
	 */
	private static function view_form( $m ) {
		$is_new  = null === $m;
		$m       = wp_parse_args( $is_new ? array() : $m, Stasimo_Mounts::defaults() );
		$presets = Stasimo_Mounts::presets();
		$slug    = $m['slug'];
		$n       = 'stasimo';
		?>
		<p class="stasimo-crumb"><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All mounts', 'stasimo-static-site-mount' ); ?></a></p>
		<div class="stasimo-head">
			<h1><?php echo $is_new ? esc_html__( 'Add mount', 'stasimo-static-site-mount' ) : esc_html( $slug ); ?></h1>
			<?php
			if ( ! $is_new ) {
				echo wp_kses( self::badge( $m ), self::allowed_html() );
			}
			?>
		</div>
		<?php if ( ! $is_new ) : ?>
			<p class="stasimo-sub"><a href="<?php echo esc_url( Stasimo_Mounts::url( $m ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Stasimo_Mounts::url( $m ) ); ?></a></p>
		<?php endif; ?>

		<div class="stasimo-grid">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="stasimo_save">
				<input type="hidden" name="stasimo_original" value="<?php echo esc_attr( $is_new ? '' : $slug ); ?>">
				<?php wp_nonce_field( 'stasimo_save' ); ?>

				<div class="stasimo-card">
					<h2><?php esc_html_e( 'Where it lives', 'stasimo-static-site-mount' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_row(
							__( 'Name', 'stasimo-static-site-mount' ),
							__( 'A short name, also the default folder name. Cannot be changed later.', 'stasimo-static-site-mount' ),
							'<input type="text" class="regular-text" name="' . esc_attr( $n ) . '[slug]" value="' . esc_attr( $slug ) . '" placeholder="my-project" ' . ( $is_new ? '' : 'readonly' ) . '>'
						);
						self::field_row(
							__( 'URL prefix', 'stasimo-static-site-mount' ),
							__( 'Build your site with this as its base URL, for example Docusaurus baseUrl "/docs/my-project/" or MkDocs site_url ending in /docs/my-project/.', 'stasimo-static-site-mount' ),
							'<div class="stasimo-prefix"><code>' . esc_html( home_url( '/' ) ) . '</code><input type="text" class="regular-text" name="' . esc_attr( $n ) . '[prefix]" value="' . esc_attr( $m['prefix'] ) . '" placeholder="docs/my-project"></div>'
						);
						$opts = '';
						foreach ( $presets as $key => $p ) {
							$opts .= '<option value="' . esc_attr( $key ) . '" ' . selected( $m['preset'], $key, false ) . '>' . esc_html( $p['label'] ) . '</option>';
						}
						self::field_row( __( 'Type', 'stasimo-static-site-mount' ), __( 'Which generator built the site. It decides which parts (navbar, footer) are replaced by your theme.', 'stasimo-static-site-mount' ), '<select name="' . esc_attr( $n ) . '[preset]">' . $opts . '</select>' );
						$trusted_select = '<select name="' . esc_attr( $n ) . '[trusted]">'
							. '<option value="0" ' . selected( $m['trusted'], false, false ) . '>' . esc_html__( 'Filtered (recommended)', 'stasimo-static-site-mount' ) . '</option>'
							. '<option value="1" ' . selected( $m['trusted'], true, false ) . '>' . esc_html__( 'Trusted', 'stasimo-static-site-mount' ) . '</option></select>';
						self::field_row(
							__( 'Page content', 'stasimo-static-site-mount' ),
							__( 'Filtered removes scripts, forms, frames and event handlers from the pages and shows what is left inside your theme. Trusted runs the site\'s own JavaScript and prints its markup as it is, which Docusaurus, MkDocs and similar generators need. Only choose Trusted for sites you built yourself.', 'stasimo-static-site-mount' ),
							$trusted_select
						);
						self::field_row(
							__( 'Folder', 'stasimo-static-site-mount' ),
							sprintf( /* translators: %s default folder */ __( 'Optional. Empty means %s', 'stasimo-static-site-mount' ), Stasimo_Mounts::base_dir() . '/' . ( $slug ? $slug : '<name>' ) ),
							'<input type="text" class="large-text" name="' . esc_attr( $n ) . '[dir]" value="' . esc_attr( $m['dir'] ) . '">'
						);
						?>
					</table>
				</div>

				<div class="stasimo-card">
					<h2><?php esc_html_e( 'Look and feel', 'stasimo-static-site-mount' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_row( __( 'Space for a fixed header (px)', 'stasimo-static-site-mount' ), __( 'Only needed when your theme\'s header stays fixed at the top. Pushes the content down by this much.', 'stasimo-static-site-mount' ), '<input type="number" min="0" max="400" name="' . esc_attr( $n ) . '[header_offset]" value="' . esc_attr( $m['header_offset'] ) . '" class="small-text">' );
						self::field_row( __( 'Light/dark key', 'stasimo-static-site-mount' ), __( 'localStorage key where your theme stores the light/dark choice (for example wenisch-theme). The mounted site then follows it.', 'stasimo-static-site-mount' ), '<input type="text" class="regular-text" name="' . esc_attr( $n ) . '[host_theme_key]" value="' . esc_attr( $m['host_theme_key'] ) . '" placeholder="my-theme-mode">' );
						self::field_row( __( 'Hide elements', 'stasimo-static-site-mount' ), __( 'CSS selectors hidden in addition to the type\'s defaults. Safe for JavaScript apps.', 'stasimo-static-site-mount' ), '<input type="text" class="large-text" name="' . esc_attr( $n ) . '[hide]" value="' . esc_attr( $m['hide'] ) . '" placeholder=".site-header, .cookie-note">' );
						self::field_row( __( 'Remove elements', 'stasimo-static-site-mount' ), __( 'Taken out of the HTML on the server. Supports tag, #id, .class and spaces. For sites without a JavaScript app.', 'stasimo-static-site-mount' ), '<input type="text" class="large-text" name="' . esc_attr( $n ) . '[remove]" value="' . esc_attr( $m['remove'] ) . '" placeholder="header.site-header, #footer">' );
						?>
					</table>
				</div>

				<div class="stasimo-card">
					<h2><?php esc_html_e( 'Search engines', 'stasimo-static-site-mount' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_row( __( 'Title suffix', 'stasimo-static-site-mount' ), __( 'Appended to every page title, for example " | Docs".', 'stasimo-static-site-mount' ), '<input type="text" class="regular-text" name="' . esc_attr( $n ) . '[title_suffix]" value="' . esc_attr( $m['title_suffix'] ) . '">' );
						self::field_row(
							__( 'Indexing', 'stasimo-static-site-mount' ),
							'',
							'<label><input type="checkbox" name="' . esc_attr( $n ) . '[sitemap]" value="1" ' . checked( $m['sitemap'], true, false ) . '> ' . esc_html__( 'List the pages in the sitemap', 'stasimo-static-site-mount' ) . '</label><br>'
							. '<label><input type="checkbox" name="' . esc_attr( $n ) . '[noindex]" value="1" ' . checked( $m['noindex'], true, false ) . '> ' . esc_html__( 'Ask search engines not to index this mount', 'stasimo-static-site-mount' ) . '</label>'
						);
						?>
					</table>
				</div>
				<?php submit_button( $is_new ? __( 'Create mount', 'stasimo-static-site-mount' ) : __( 'Save changes', 'stasimo-static-site-mount' ), 'primary', 'submit', false ); ?>
			</form>

			<?php
			if ( ! $is_new ) {
				self::side_cards( $m );
			} else {
				?>
				<div class="stasimo-card">
					<h2><?php esc_html_e( 'Next steps', 'stasimo-static-site-mount' ); ?></h2>
					<ol class="stasimo-steps">
						<li><?php esc_html_e( 'Choose a name and the URL prefix.', 'stasimo-static-site-mount' ); ?></li>
						<li><?php esc_html_e( 'Create the mount. Its page shows ready-made deploy commands.', 'stasimo-static-site-mount' ); ?></li>
						<li><?php esc_html_e( 'Upload your build and open the URL.', 'stasimo-static-site-mount' ); ?></li>
					</ol>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Print the status, deploy, pages and remove cards of the detail view.
	 *
	 * @param array $m Mount.
	 */
	private static function side_cards( array $m ) {
		$slug = $m['slug'];
		$idx  = Stasimo_Indexer::get( $slug );
		$dir  = Stasimo_Mounts::dir( $m );
		$curl = "cd build && zip -qr ../build.zip . && cd ..\n"
			. "curl -fsS -u \"\$WP_USER:\$WP_APP_PASSWORD\" \\\n  -F \"file=@build.zip\" \\\n  \"" . rest_url( 'stasimo/v1/mounts/' . $slug . '/deploy' ) . '"';
		?>
		<div>
			<div class="stasimo-card">
				<h2><?php esc_html_e( 'Status', 'stasimo-static-site-mount' ); ?></h2>
				<dl class="stasimo-kv">
					<dt><?php esc_html_e( 'Pages', 'stasimo-static-site-mount' ); ?></dt><dd><?php echo esc_html( (string) count( $idx['pages'] ) ); ?></dd>
					<dt><?php esc_html_e( 'Last indexed', 'stasimo-static-site-mount' ); ?></dt><dd><?php echo $idx['time'] ? esc_html( human_time_diff( $idx['time'] ) . ' ' . __( 'ago', 'stasimo-static-site-mount' ) ) : '&ndash;'; ?></dd>
					<dt><?php esc_html_e( 'Folder', 'stasimo-static-site-mount' ); ?></dt><dd><code><?php echo esc_html( $dir ); ?></code><?php echo is_dir( $dir ) ? '' : ' <span class="stasimo-badge stasimo-badge--warn">' . esc_html__( 'missing', 'stasimo-static-site-mount' ) . '</span>'; ?></dd>
					<dt><?php esc_html_e( 'Page content', 'stasimo-static-site-mount' ); ?></dt><dd><?php echo $m['trusted'] ? esc_html__( 'Trusted', 'stasimo-static-site-mount' ) : esc_html__( 'Filtered', 'stasimo-static-site-mount' ); ?></dd>
					<dt><?php esc_html_e( 'Sitemap', 'stasimo-static-site-mount' ); ?></dt><dd><?php echo $m['sitemap'] && ! $m['noindex'] ? esc_html__( 'included', 'stasimo-static-site-mount' ) : esc_html__( 'not included', 'stasimo-static-site-mount' ); ?></dd>
				</dl>
				<div class="stasimo-buttons">
					<a class="button" href="<?php echo esc_url( Stasimo_Mounts::url( $m ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View site', 'stasimo-static-site-mount' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::action_url( 'stasimo_reindex', $slug ) ); ?>"><?php esc_html_e( 'Re-index', 'stasimo-static-site-mount' ); ?></a>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'to', 'detail', self::action_url( 'stasimo_toggle', $slug ) ) ); ?>"><?php echo $m['enabled'] ? esc_html__( 'Disable', 'stasimo-static-site-mount' ) : esc_html__( 'Enable', 'stasimo-static-site-mount' ); ?></a>
				</div>
			</div>

			<div class="stasimo-card">
				<h2><?php esc_html_e( 'Deploy', 'stasimo-static-site-mount' ); ?></h2>
				<p class="description"><?php esc_html_e( 'From CI, with an Application Password (Users > Profile). The folder is replaced atomically and re-indexed.', 'stasimo-static-site-mount' ); ?></p>
				<pre class="stasimo-code"><?php echo esc_html( $curl ); ?></pre>
				<p class="description"><?php echo esc_html( 'wp stasimo deploy ' . $slug . ' /path/to/build' ); ?></p>
			</div>

			<div class="stasimo-card">
				<h2><?php esc_html_e( 'Pages', 'stasimo-static-site-mount' ); ?></h2>
				<?php if ( ! $idx['pages'] ) : ?>
					<p class="description"><?php esc_html_e( 'Nothing indexed yet. Deploy a build or press Re-index.', 'stasimo-static-site-mount' ); ?></p>
				<?php else : ?>
					<table class="stasimo-pages">
						<thead><tr><th><?php esc_html_e( 'Path', 'stasimo-static-site-mount' ); ?></th><th><?php esc_html_e( 'Title', 'stasimo-static-site-mount' ); ?></th></tr></thead>
						<tbody>
						<?php
						$shown = 0;
						foreach ( $idx['pages'] as $path => $p ) :
							if ( ++$shown > 25 ) {
								break;
							}
							?>
							<tr><td><a href="<?php echo esc_url( Stasimo_Mounts::url( $m, $path ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '/' . $path ); ?></a><?php echo ! empty( $p['n'] ) ? ' <span class="stasimo-badge stasimo-badge--off">noindex</span>' : ''; ?></td><td><?php echo esc_html( $p['t'] ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( count( $idx['pages'] ) > 25 ) : ?>
						<p class="description"><?php echo esc_html( sprintf( /* translators: %d number */ __( '… and %d more.', 'stasimo-static-site-mount' ), count( $idx['pages'] ) - 25 ) ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="stasimo-card stasimo-danger">
				<h2><?php esc_html_e( 'Remove', 'stasimo-static-site-mount' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Removes the mount from this list and stops serving it. The files stay on disk.', 'stasimo-static-site-mount' ); ?></p>
				<a class="button stasimo-delete" href="<?php echo esc_url( self::action_url( 'stasimo_delete', $slug ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this mount? Its files stay on disk.', 'stasimo-static-site-mount' ) ); ?>')"><?php esc_html_e( 'Remove mount', 'stasimo-static-site-mount' ); ?></a>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------ about view */

	/**
	 * Print one row of the environment check.
	 *
	 * @param string    $label What was checked.
	 * @param bool|null $ok    True if fine, false if it needs attention, null for information only.
	 * @param string    $note  Explanation.
	 */
	private static function check_row( $label, $ok, $note ) {
		$badge = true === $ok ? '<span class="stasimo-badge stasimo-badge--on">OK</span>' : ( false === $ok ? '<span class="stasimo-badge stasimo-badge--warn">Check</span>' : '<span class="stasimo-badge stasimo-badge--info">Info</span>' );
		echo '<tr><td>' . esc_html( $label ) . '</td><td>' . wp_kses( $badge, self::allowed_html() ) . '</td><td>' . esc_html( $note ) . '</td></tr>';
	}

	/**
	 * About screen: how it works, environment check and API reference.
	 */
	public static function page_about() {
		self::guard();
		$base     = Stasimo_Mounts::base_dir();
		$block    = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$pretty   = '' !== (string) get_option( 'permalink_structure' );
		$yoast    = defined( 'WPSEO_VERSION' );
		$writable = is_dir( $base ) ? wp_is_writable( $base ) : wp_is_writable( dirname( $base ) );
		?>
		<div class="wrap stasimo-wrap stasimo-about">
			<div class="stasimo-head">
				<h1><?php esc_html_e( 'About Stasimo', 'stasimo-static-site-mount' ); ?></h1>
				<span class="stasimo-badge stasimo-badge--info"><?php echo esc_html( 'v' . STASIMO_VERSION ); ?></span>
			</div>
			<p class="stasimo-sub"><?php esc_html_e( 'Publish a built static site (Docusaurus, MkDocs, Hugo, plain HTML) at a URL of this WordPress site, inside your theme\'s header and footer, with SEO tags and sitemap entries, and deploy it from CI with one request.', 'stasimo-static-site-mount' ); ?></p>

			<h2><?php esc_html_e( 'How it works', 'stasimo-static-site-mount' ); ?></h2>
			<ol class="stasimo-steps">
				<li><strong><?php esc_html_e( 'Build with the right base URL.', 'stasimo-static-site-mount' ); ?></strong> <?php esc_html_e( 'JavaScript apps embed their base path, so build the site for the URL you will mount it at.', 'stasimo-static-site-mount' ); ?></li>
				<li><strong><?php esc_html_e( 'Add a mount.', 'stasimo-static-site-mount' ); ?></strong> <?php esc_html_e( 'Choose a name, the URL prefix and the generator type.', 'stasimo-static-site-mount' ); ?></li>
				<li><strong><?php esc_html_e( 'Deploy.', 'stasimo-static-site-mount' ); ?></strong> <?php esc_html_e( 'Upload a zip through the REST API or WP-CLI. The folder is replaced atomically and every page is indexed.', 'stasimo-static-site-mount' ); ?></li>
				<li><strong><?php esc_html_e( 'Visitors get one site.', 'stasimo-static-site-mount' ); ?></strong> <?php esc_html_e( 'Pages are served through WordPress, wrapped in your theme\'s header and footer, with titles, descriptions, canonical URLs and a sitemap written by the plugin.', 'stasimo-static-site-mount' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'Environment check', 'stasimo-static-site-mount' ); ?></h2>
			<table class="widefat striped">
				<tbody>
				<?php
				self::check_row( 'PHP ' . PHP_VERSION, version_compare( PHP_VERSION, '7.4', '>=' ), __( 'Requires PHP 7.4 or newer.', 'stasimo-static-site-mount' ) );
				self::check_row( __( 'Zip support', 'stasimo-static-site-mount' ), class_exists( 'ZipArchive' ), __( 'Needed to deploy zip files. Without it you can still copy files and press Re-index.', 'stasimo-static-site-mount' ) );
				self::check_row( __( 'Block theme', 'stasimo-static-site-mount' ), $block, $block ? __( 'The theme\'s header and footer wrap mounted pages.', 'stasimo-static-site-mount' ) : __( 'Classic theme: pages are served without the header/footer wrapper. Use the stasimo_header_html / stasimo_footer_html filters.', 'stasimo-static-site-mount' ) );
				self::check_row( __( 'Pretty permalinks', 'stasimo-static-site-mount' ), $pretty, $pretty ? __( 'Enabled.', 'stasimo-static-site-mount' ) : __( 'Enable them under Settings > Permalinks, mounts need them.', 'stasimo-static-site-mount' ) );
				self::check_row( __( 'Storage folder', 'stasimo-static-site-mount' ), $writable, $base );
				self::check_row( __( 'Upload limit', 'stasimo-static-site-mount' ), null, sprintf( /* translators: 1: upload_max_filesize 2: post_max_size */ __( 'upload_max_filesize %1$s, post_max_size %2$s. Zips larger than this cannot be deployed over REST.', 'stasimo-static-site-mount' ), ini_get( 'upload_max_filesize' ), ini_get( 'post_max_size' ) ) );
				self::check_row( __( 'SEO plugin', 'stasimo-static-site-mount' ), null, $yoast ? __( 'Yoast SEO detected: its tags are replaced on mounted pages and the sitemap is added to its index.', 'stasimo-static-site-mount' ) : __( 'None detected. The plugin writes its own tags and sitemap.', 'stasimo-static-site-mount' ) );
				?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'REST API (for CI)', 'stasimo-static-site-mount' ); ?></h2>
			<p><?php esc_html_e( 'Authenticate with an Application Password of an administrator (Users > Profile).', 'stasimo-static-site-mount' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Request', 'stasimo-static-site-mount' ); ?></th><th><?php esc_html_e( 'Purpose', 'stasimo-static-site-mount' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>GET /wp-json/stasimo/v1/mounts</code></td><td><?php esc_html_e( 'List mounts, page counts and last index time.', 'stasimo-static-site-mount' ); ?></td></tr>
					<tr><td><code>PUT /wp-json/stasimo/v1/mounts/&lt;name&gt;</code></td><td><?php esc_html_e( 'Create or update a mount (JSON: prefix, preset, header_offset, host_theme_key, enabled).', 'stasimo-static-site-mount' ); ?></td></tr>
					<tr><td><code>POST /wp-json/stasimo/v1/mounts/&lt;name&gt;/deploy</code></td><td><?php esc_html_e( 'Replace the folder with an uploaded zip and re-index. Send multipart field "file".', 'stasimo-static-site-mount' ); ?></td></tr>
					<tr><td><code>POST /wp-json/stasimo/v1/mounts/&lt;name&gt;/reindex</code></td><td><?php esc_html_e( 'Re-index after files changed by other means.', 'stasimo-static-site-mount' ); ?></td></tr>
				</tbody>
			</table>

			<h2>WP-CLI</h2>
			<pre class="stasimo-code">wp stasimo list
wp stasimo add &lt;name&gt; --prefix=docs/my-project --preset=docusaurus
wp stasimo deploy &lt;name&gt; build.zip   # or a folder
wp stasimo reindex [&lt;name&gt;]</pre>

			<h2><?php esc_html_e( 'Good to know', 'stasimo-static-site-mount' ); ?></h2>
			<ul style="list-style:disc;padding-left:22px">
				<li><?php esc_html_e( 'Only allow-listed file types are served (no PHP, no dotfiles), and archives are checked for unsafe paths. A refused deploy leaves the live site untouched.', 'stasimo-static-site-mount' ); ?></li>
				<li><?php esc_html_e( 'The generator\'s CSS and your theme\'s CSS share one page, so check headings and buttons after the first deploy.', 'stasimo-static-site-mount' ); ?></li>
				<li><?php esc_html_e( 'Disabling a mount keeps its files and settings; its URLs show a normal 404.', 'stasimo-static-site-mount' ); ?></li>
				<li><?php esc_html_e( 'Sitemap of all mounted pages:', 'stasimo-static-site-mount' ); ?> <a href="<?php echo esc_url( Stasimo_Sitemap::url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Stasimo_Sitemap::url() ); ?></a></li>
			</ul>

			<h2><?php esc_html_e( 'Developers', 'stasimo-static-site-mount' ); ?></h2>
			<p><?php esc_html_e( 'Filters:', 'stasimo-static-site-mount' ); ?> <code>stasimo_presets</code>, <code>stasimo_title</code>, <code>stasimo_description</code>, <code>stasimo_header_html</code>, <code>stasimo_footer_html</code>, <code>stasimo_host_css</code>, <code>stasimo_mime_types</code>, <code>stasimo_base_dir</code>. <?php esc_html_e( 'Action:', 'stasimo-static-site-mount' ); ?> <code>stasimo_deployed</code>.</p>

			<h2><?php esc_html_e( 'Project', 'stasimo-static-site-mount' ); ?></h2>
			<p>
				<a href="https://github.com/wenisch-tech/wordpress-static-site-mount" target="_blank" rel="noopener"><?php esc_html_e( 'Source code and documentation on GitHub', 'stasimo-static-site-mount' ); ?></a><br>
				<?php esc_html_e( 'Licensed under GPL-2.0-or-later. Made by Jean-Fabian Wenisch.', 'stasimo-static-site-mount' ); ?>
			</p>
		</div>
		<?php
	}
}
