<?php
/**
 * Admin screens: top-level "Static Sites" menu with Mounts (list, detail, add) and About.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

class SSM_Admin {

	const PAGE       = 'static-site-mount';
	const PAGE_ABOUT = 'static-site-mount-about';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ssm_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_ssm_reindex', array( __CLASS__, 'reindex' ) );
		add_action( 'admin_post_ssm_toggle', array( __CLASS__, 'toggle' ) );
		add_action( 'admin_post_ssm_delete', array( __CLASS__, 'delete' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SSM_FILE ), array( __CLASS__, 'links' ) );
	}

	/* ------------------------------------------------------------------ menu */

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Mounts', 'static-site-mount' ) . '</a>' );
		return $links;
	}

	public static function menu() {
		add_menu_page( __( 'Static Sites', 'static-site-mount' ), __( 'Static Sites', 'static-site-mount' ), 'manage_options', self::PAGE, array( __CLASS__, 'page_mounts' ), 'dashicons-networking', 58 );
		add_submenu_page( self::PAGE, __( 'Mounts', 'static-site-mount' ), __( 'Mounts', 'static-site-mount' ), 'manage_options', self::PAGE, array( __CLASS__, 'page_mounts' ) );
		add_submenu_page( self::PAGE, __( 'About Static Site Mount', 'static-site-mount' ), __( 'About', 'static-site-mount' ), 'manage_options', self::PAGE_ABOUT, array( __CLASS__, 'page_about' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'static-site-mount' ) ) {
			return;
		}
		wp_enqueue_style( 'ssm-admin', plugins_url( 'assets/admin.css', SSM_FILE ), array(), SSM_VERSION );
	}

	/* --------------------------------------------------------------- helpers */

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	private static function action_url( $action, $slug ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . '&slug=' . rawurlencode( $slug ) ), $action . '_' . $slug );
	}

	private static function back( $notice, $type = 'success', $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( $args, array( 'ssm_notice' => $notice, 'ssm_type' => $type ) ), self::url( $args ) ) );
		exit;
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'static-site-mount' ) );
		}
	}

	private static function notice() {
		// Display-only message after a redirect; escaped on output, no state change.
		// phpcs:disable WordPress.Security.NonceVerification
		$notice = isset( $_GET['ssm_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ssm_notice'] ) ) : '';
		$type   = isset( $_GET['ssm_type'] ) && 'error' === sanitize_key( wp_unslash( (string) $_GET['ssm_type'] ) ) ? 'error' : 'success';
		// phpcs:enable WordPress.Security.NonceVerification
		if ( $notice ) {
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
	}

	private static function status( array $m ) {
		if ( ! $m['enabled'] ) {
			return array( 'off', __( 'Disabled', 'static-site-mount' ) );
		}
		if ( ! is_dir( SSM_Mounts::dir( $m ) ) ) {
			return array( 'warn', __( 'No files yet', 'static-site-mount' ) );
		}
		return array( 'on', __( 'Active', 'static-site-mount' ) );
	}

	private static function badge( array $m ) {
		$s = self::status( $m );
		return '<span class="ssm-badge ssm-badge--' . esc_attr( $s[0] ) . '">' . esc_html( $s[1] ) . '</span>';
	}

	/* -------------------------------------------------------------- handlers */

	public static function save() {
		self::guard();
		check_admin_referer( 'ssm_save' );
		$raw      = isset( $_POST['ssm'] ) && is_array( $_POST['ssm'] ) ? wp_unslash( $_POST['ssm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$original = isset( $_POST['ssm_original'] ) ? sanitize_title( wp_unslash( $_POST['ssm_original'] ) ) : '';
		$all      = SSM_Mounts::all();
		if ( '' !== $original && isset( $all[ $original ] ) ) {
			$raw['slug']    = $original; // the name of an existing mount does not change.
			$raw['enabled'] = isset( $all[ $original ]['enabled'] ) ? $all[ $original ]['enabled'] : true;
		}
		$m = SSM_Mounts::sanitize( $raw );
		if ( is_wp_error( $m ) ) {
			self::back( $m->get_error_message(), 'error', '' !== $original ? array( 'mount' => $original ) : array( 'action' => 'new' ) );
		}
		if ( '' === $original && isset( $all[ $m['slug'] ] ) ) {
			self::back( __( 'A mount with this name already exists.', 'static-site-mount' ), 'error', array( 'action' => 'new' ) );
		}
		foreach ( $all as $slug => $other ) {
			if ( $slug !== $m['slug'] && isset( $other['prefix'] ) && $other['prefix'] === $m['prefix'] ) {
				self::back( __( 'Another mount already uses this URL prefix.', 'static-site-mount' ), 'error', '' !== $original ? array( 'mount' => $original ) : array( 'action' => 'new' ) );
			}
		}
		if ( get_page_by_path( $m['prefix'] ) ) {
			self::back( sprintf( /* translators: %s prefix */ __( 'A WordPress page already uses /%s/. Choose another prefix.', 'static-site-mount' ), $m['prefix'] ), 'error', '' !== $original ? array( 'mount' => $original ) : array( 'action' => 'new' ) );
		}
		$all[ $m['slug'] ] = $m;
		SSM_Mounts::save_all( $all );
		SSM_Mounts::prepare_base_dir();
		self::back( '' === $original ? __( 'Mount created. Upload the build next (see "Deploy" below).', 'static-site-mount' ) : __( 'Saved.', 'static-site-mount' ), 'success', array( 'mount' => $m['slug'] ) );
	}

	public static function reindex() {
		self::guard();
		$slug = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : '';
		check_admin_referer( 'ssm_reindex_' . $slug );
		$m = SSM_Mounts::get( $slug );
		if ( ! $m ) {
			self::back( __( 'Unknown mount.', 'static-site-mount' ), 'error' );
		}
		$r = SSM_Indexer::reindex( $m );
		if ( is_wp_error( $r ) ) {
			self::back( $r->get_error_message(), 'error', array( 'mount' => $slug ) );
		}
		/* translators: %d pages */
		self::back( sprintf( __( 'Indexed %d pages.', 'static-site-mount' ), $r['count'] ), 'success', array( 'mount' => $slug ) );
	}

	public static function toggle() {
		self::guard();
		$slug = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : '';
		check_admin_referer( 'ssm_toggle_' . $slug );
		$all = SSM_Mounts::all();
		if ( ! isset( $all[ $slug ] ) ) {
			self::back( __( 'Unknown mount.', 'static-site-mount' ), 'error' );
		}
		$now                    = ! ( isset( $all[ $slug ]['enabled'] ) ? $all[ $slug ]['enabled'] : true );
		$all[ $slug ]['enabled'] = $now;
		SSM_Mounts::save_all( $all );
		$back = isset( $_GET['to'] ) && 'detail' === $_GET['to'] ? array( 'mount' => $slug ) : array();
		self::back( $now ? __( 'Mount enabled. Its pages are live again.', 'static-site-mount' ) : __( 'Mount disabled. Its URLs now show a normal 404; the files stay on disk.', 'static-site-mount' ), 'success', $back );
	}

	public static function delete() {
		self::guard();
		$slug = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : '';
		check_admin_referer( 'ssm_delete_' . $slug );
		$all = SSM_Mounts::all();
		unset( $all[ $slug ] );
		SSM_Mounts::save_all( $all );
		delete_option( SSM_Indexer::option_name( $slug ) );
		self::back( __( 'Mount removed. Its files were left on disk.', 'static-site-mount' ) );
	}

	/* ------------------------------------------------------------ list view */

	public static function page_mounts() {
		self::guard();
		$view = isset( $_GET['action'] ) && 'new' === $_GET['action'] ? 'new' : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$slug = isset( $_GET['mount'] ) ? sanitize_title( wp_unslash( $_GET['mount'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap ssm-wrap">';
		self::notice();
		if ( 'new' === $view ) {
			self::view_form( null );
		} elseif ( '' !== $slug && SSM_Mounts::get( $slug ) ) {
			self::view_form( SSM_Mounts::get( $slug ) );
		} else {
			self::view_list();
		}
		echo '</div>';
	}

	private static function view_list() {
		$mounts = SSM_Mounts::all();
		?>
		<div class="ssm-head">
			<h1><?php esc_html_e( 'Static Sites', 'static-site-mount' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( self::url( array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add mount', 'static-site-mount' ); ?></a>
		</div>
		<p class="ssm-sub"><?php esc_html_e( 'A mount publishes a folder of static HTML (a documentation site, a blog, a landing page) at a URL of this site, inside your theme.', 'static-site-mount' ); ?></p>

		<?php if ( ! $mounts ) : ?>
			<div class="ssm-empty">
				<h2><?php esc_html_e( 'No mounts yet', 'static-site-mount' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Build your site with the mount\'s URL as its base path.', 'static-site-mount' ); ?></li>
					<li><?php esc_html_e( 'Add a mount here and choose where it appears.', 'static-site-mount' ); ?></li>
					<li><?php esc_html_e( 'Upload the build (CI, WP-CLI or files), and it is live.', 'static-site-mount' ); ?></li>
				</ol><br>
				<a class="button button-primary button-hero" href="<?php echo esc_url( self::url( array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add your first mount', 'static-site-mount' ); ?></a>
				<a class="button button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_ABOUT ) ); ?>"><?php esc_html_e( 'How it works', 'static-site-mount' ); ?></a>
			</div>
			<?php
			return;
		endif;
		$presets = SSM_Mounts::presets();
		?>
		<table class="widefat striped ssm-table">
			<thead><tr>
				<th><?php esc_html_e( 'Mount', 'static-site-mount' ); ?></th>
				<th><?php esc_html_e( 'Type', 'static-site-mount' ); ?></th>
				<th><?php esc_html_e( 'Pages', 'static-site-mount' ); ?></th>
				<th><?php esc_html_e( 'Last indexed', 'static-site-mount' ); ?></th>
				<th><?php esc_html_e( 'Status', 'static-site-mount' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $mounts as $slug => $m ) :
				$m   = wp_parse_args( $m, SSM_Mounts::defaults() );
				$idx = SSM_Indexer::get( $slug );
				?>
				<tr class="<?php echo $m['enabled'] ? '' : 'ssm-off'; ?>">
					<td>
						<div class="ssm-name"><a href="<?php echo esc_url( self::url( array( 'mount' => $slug ) ) ); ?>"><?php echo esc_html( $slug ); ?></a></div>
						<div class="ssm-url"><?php echo esc_html( '/' . $m['prefix'] . '/' ); ?></div>
					</td>
					<td><?php echo esc_html( isset( $presets[ $m['preset'] ] ) ? $presets[ $m['preset'] ]['label'] : $m['preset'] ); ?></td>
					<td><?php echo esc_html( (string) count( $idx['pages'] ) ); ?></td>
					<td><?php echo $idx['time'] ? esc_html( human_time_diff( $idx['time'] ) . ' ' . __( 'ago', 'static-site-mount' ) ) : '&ndash;'; ?></td>
					<td><?php echo self::badge( $m ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td>
						<div class="ssm-actions">
							<a class="button" href="<?php echo esc_url( self::url( array( 'mount' => $slug ) ) ); ?>"><?php esc_html_e( 'Details', 'static-site-mount' ); ?></a>
							<a class="button" href="<?php echo esc_url( self::action_url( 'ssm_toggle', $slug ) ); ?>"><?php echo $m['enabled'] ? esc_html__( 'Disable', 'static-site-mount' ) : esc_html__( 'Enable', 'static-site-mount' ); ?></a>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ----------------------------------------------- detail / new (one form) */

	private static function field_row( $label, $help, $input ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $input; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function view_form( $m ) {
		$is_new  = null === $m;
		$m       = wp_parse_args( $is_new ? array() : $m, SSM_Mounts::defaults() );
		$presets = SSM_Mounts::presets();
		$slug    = $m['slug'];
		$n       = 'ssm';
		?>
		<p class="ssm-crumb"><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All mounts', 'static-site-mount' ); ?></a></p>
		<div class="ssm-head">
			<h1><?php echo $is_new ? esc_html__( 'Add mount', 'static-site-mount' ) : esc_html( $slug ); ?></h1>
			<?php
			if ( ! $is_new ) {
				echo self::badge( $m ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
		</div>
		<?php if ( ! $is_new ) : ?>
			<p class="ssm-sub"><a href="<?php echo esc_url( SSM_Mounts::url( $m ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( SSM_Mounts::url( $m ) ); ?></a></p>
		<?php endif; ?>

		<div class="ssm-grid">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ssm_save">
				<input type="hidden" name="ssm_original" value="<?php echo esc_attr( $is_new ? '' : $slug ); ?>">
				<?php wp_nonce_field( 'ssm_save' ); ?>

				<div class="ssm-card">
					<h2><?php esc_html_e( 'Where it lives', 'static-site-mount' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_row(
							__( 'Name', 'static-site-mount' ),
							__( 'A short name, also the default folder name. Cannot be changed later.', 'static-site-mount' ),
							'<input type="text" class="regular-text" name="' . esc_attr( $n ) . '[slug]" value="' . esc_attr( $slug ) . '" placeholder="my-project" ' . ( $is_new ? '' : 'readonly' ) . '>'
						);
						self::field_row(
							__( 'URL prefix', 'static-site-mount' ),
							__( 'Build your site with this as its base URL, for example Docusaurus baseUrl "/docs/my-project/" or MkDocs site_url ending in /docs/my-project/.', 'static-site-mount' ),
							'<div class="ssm-prefix"><code>' . esc_html( home_url( '/' ) ) . '</code><input type="text" class="regular-text" name="' . esc_attr( $n ) . '[prefix]" value="' . esc_attr( $m['prefix'] ) . '" placeholder="docs/my-project"></div>'
						);
						$opts = '';
						foreach ( $presets as $key => $p ) {
							$opts .= '<option value="' . esc_attr( $key ) . '" ' . selected( $m['preset'], $key, false ) . '>' . esc_html( $p['label'] ) . '</option>';
						}
						self::field_row( __( 'Type', 'static-site-mount' ), __( 'Which generator built the site. It decides which parts (navbar, footer) are replaced by your theme.', 'static-site-mount' ), '<select name="' . esc_attr( $n ) . '[preset]">' . $opts . '</select>' );
						self::field_row(
							__( 'Folder', 'static-site-mount' ),
							sprintf( /* translators: %s default folder */ __( 'Optional. Empty means %s', 'static-site-mount' ), SSM_Mounts::base_dir() . '/' . ( $slug ? $slug : '<name>' ) ),
							'<input type="text" class="large-text" name="' . esc_attr( $n ) . '[dir]" value="' . esc_attr( $m['dir'] ) . '">'
						);
						?>
					</table>
				</div>

				<div class="ssm-card">
					<h2><?php esc_html_e( 'Look and feel', 'static-site-mount' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_row( __( 'Space for a fixed header (px)', 'static-site-mount' ), __( 'Only needed when your theme\'s header stays fixed at the top. Pushes the content down by this much.', 'static-site-mount' ), '<input type="number" min="0" max="400" name="' . esc_attr( $n ) . '[header_offset]" value="' . esc_attr( $m['header_offset'] ) . '" class="small-text">' );
						self::field_row( __( 'Light/dark key', 'static-site-mount' ), __( 'localStorage key where your theme stores the light/dark choice (for example wenisch-theme). The mounted site then follows it.', 'static-site-mount' ), '<input type="text" class="regular-text" name="' . esc_attr( $n ) . '[host_theme_key]" value="' . esc_attr( $m['host_theme_key'] ) . '" placeholder="my-theme-mode">' );
						self::field_row( __( 'Hide elements', 'static-site-mount' ), __( 'CSS selectors hidden in addition to the type\'s defaults. Safe for JavaScript apps.', 'static-site-mount' ), '<input type="text" class="large-text" name="' . esc_attr( $n ) . '[hide]" value="' . esc_attr( $m['hide'] ) . '" placeholder=".site-header, .cookie-note">' );
						self::field_row( __( 'Remove elements', 'static-site-mount' ), __( 'Taken out of the HTML on the server. Supports tag, #id, .class and spaces. For sites without a JavaScript app.', 'static-site-mount' ), '<input type="text" class="large-text" name="' . esc_attr( $n ) . '[remove]" value="' . esc_attr( $m['remove'] ) . '" placeholder="header.site-header, #footer">' );
						?>
					</table>
				</div>

				<div class="ssm-card">
					<h2><?php esc_html_e( 'Search engines', 'static-site-mount' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::field_row( __( 'Title suffix', 'static-site-mount' ), __( 'Appended to every page title, for example " | Docs".', 'static-site-mount' ), '<input type="text" class="regular-text" name="' . esc_attr( $n ) . '[title_suffix]" value="' . esc_attr( $m['title_suffix'] ) . '">' );
						self::field_row(
							__( 'Indexing', 'static-site-mount' ),
							'',
							'<label><input type="checkbox" name="' . esc_attr( $n ) . '[sitemap]" value="1" ' . checked( $m['sitemap'], true, false ) . '> ' . esc_html__( 'List the pages in the sitemap', 'static-site-mount' ) . '</label><br>'
							. '<label><input type="checkbox" name="' . esc_attr( $n ) . '[noindex]" value="1" ' . checked( $m['noindex'], true, false ) . '> ' . esc_html__( 'Ask search engines not to index this mount', 'static-site-mount' ) . '</label>'
						);
						?>
					</table>
				</div>
				<?php submit_button( $is_new ? __( 'Create mount', 'static-site-mount' ) : __( 'Save changes', 'static-site-mount' ), 'primary', 'submit', false ); ?>
			</form>

			<?php
			if ( ! $is_new ) {
				self::side_cards( $m );
			} else {
				?>
				<div class="ssm-card">
					<h2><?php esc_html_e( 'Next steps', 'static-site-mount' ); ?></h2>
					<ol class="ssm-steps">
						<li><?php esc_html_e( 'Choose a name and the URL prefix.', 'static-site-mount' ); ?></li>
						<li><?php esc_html_e( 'Create the mount. Its page shows ready-made deploy commands.', 'static-site-mount' ); ?></li>
						<li><?php esc_html_e( 'Upload your build and open the URL.', 'static-site-mount' ); ?></li>
					</ol>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}

	private static function side_cards( array $m ) {
		$slug = $m['slug'];
		$idx  = SSM_Indexer::get( $slug );
		$dir  = SSM_Mounts::dir( $m );
		$curl = "cd build && zip -qr ../build.zip . && cd ..\n"
			. "curl -fsS -u \"\$WP_USER:\$WP_APP_PASSWORD\" \\\n  -F \"file=@build.zip\" \\\n  \"" . rest_url( 'static-site-mount/v1/mounts/' . $slug . '/deploy' ) . '"';
		?>
		<div>
			<div class="ssm-card">
				<h2><?php esc_html_e( 'Status', 'static-site-mount' ); ?></h2>
				<dl class="ssm-kv">
					<dt><?php esc_html_e( 'Pages', 'static-site-mount' ); ?></dt><dd><?php echo esc_html( (string) count( $idx['pages'] ) ); ?></dd>
					<dt><?php esc_html_e( 'Last indexed', 'static-site-mount' ); ?></dt><dd><?php echo $idx['time'] ? esc_html( human_time_diff( $idx['time'] ) . ' ' . __( 'ago', 'static-site-mount' ) ) : '&ndash;'; ?></dd>
					<dt><?php esc_html_e( 'Folder', 'static-site-mount' ); ?></dt><dd><code><?php echo esc_html( $dir ); ?></code><?php echo is_dir( $dir ) ? '' : ' <span class="ssm-badge ssm-badge--warn">' . esc_html__( 'missing', 'static-site-mount' ) . '</span>'; ?></dd>
					<dt><?php esc_html_e( 'Sitemap', 'static-site-mount' ); ?></dt><dd><?php echo $m['sitemap'] && ! $m['noindex'] ? esc_html__( 'included', 'static-site-mount' ) : esc_html__( 'not included', 'static-site-mount' ); ?></dd>
				</dl>
				<div class="ssm-buttons">
					<a class="button" href="<?php echo esc_url( SSM_Mounts::url( $m ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View site', 'static-site-mount' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::action_url( 'ssm_reindex', $slug ) ); ?>"><?php esc_html_e( 'Re-index', 'static-site-mount' ); ?></a>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'to', 'detail', self::action_url( 'ssm_toggle', $slug ) ) ); ?>"><?php echo $m['enabled'] ? esc_html__( 'Disable', 'static-site-mount' ) : esc_html__( 'Enable', 'static-site-mount' ); ?></a>
				</div>
			</div>

			<div class="ssm-card">
				<h2><?php esc_html_e( 'Deploy', 'static-site-mount' ); ?></h2>
				<p class="description"><?php esc_html_e( 'From CI, with an Application Password (Users > Profile). The folder is replaced atomically and re-indexed.', 'static-site-mount' ); ?></p>
				<pre class="ssm-code"><?php echo esc_html( $curl ); ?></pre>
				<p class="description"><?php echo esc_html( 'wp static-site-mount deploy ' . $slug . ' /path/to/build' ); ?></p>
			</div>

			<div class="ssm-card">
				<h2><?php esc_html_e( 'Pages', 'static-site-mount' ); ?></h2>
				<?php if ( ! $idx['pages'] ) : ?>
					<p class="description"><?php esc_html_e( 'Nothing indexed yet. Deploy a build or press Re-index.', 'static-site-mount' ); ?></p>
				<?php else : ?>
					<table class="ssm-pages">
						<thead><tr><th><?php esc_html_e( 'Path', 'static-site-mount' ); ?></th><th><?php esc_html_e( 'Title', 'static-site-mount' ); ?></th></tr></thead>
						<tbody>
						<?php
						$shown = 0;
						foreach ( $idx['pages'] as $path => $p ) :
							if ( ++$shown > 25 ) {
								break;
							}
							?>
							<tr><td><a href="<?php echo esc_url( SSM_Mounts::url( $m, $path ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '/' . $path ); ?></a><?php echo ! empty( $p['n'] ) ? ' <span class="ssm-badge ssm-badge--off">noindex</span>' : ''; ?></td><td><?php echo esc_html( $p['t'] ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( count( $idx['pages'] ) > 25 ) : ?>
						<p class="description"><?php echo esc_html( sprintf( /* translators: %d number */ __( '… and %d more.', 'static-site-mount' ), count( $idx['pages'] ) - 25 ) ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="ssm-card ssm-danger">
				<h2><?php esc_html_e( 'Remove', 'static-site-mount' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Removes the mount from this list and stops serving it. The files stay on disk.', 'static-site-mount' ); ?></p>
				<a class="button ssm-delete" href="<?php echo esc_url( self::action_url( 'ssm_delete', $slug ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this mount? Its files stay on disk.', 'static-site-mount' ) ); ?>')"><?php esc_html_e( 'Remove mount', 'static-site-mount' ); ?></a>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------ about view */

	private static function check_row( $label, $ok, $note ) {
		$badge = true === $ok ? '<span class="ssm-badge ssm-badge--on">OK</span>' : ( false === $ok ? '<span class="ssm-badge ssm-badge--warn">Check</span>' : '<span class="ssm-badge ssm-badge--info">Info</span>' );
		echo '<tr><td>' . esc_html( $label ) . '</td><td>' . $badge . '</td><td>' . esc_html( $note ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function page_about() {
		self::guard();
		$base     = SSM_Mounts::base_dir();
		$block    = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$pretty   = '' !== (string) get_option( 'permalink_structure' );
		$yoast    = defined( 'WPSEO_VERSION' );
		$writable = is_dir( $base ) ? is_writable( $base ) : is_writable( dirname( $base ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		?>
		<div class="wrap ssm-wrap ssm-about">
			<div class="ssm-head">
				<h1><?php esc_html_e( 'About Static Site Mount', 'static-site-mount' ); ?></h1>
				<span class="ssm-badge ssm-badge--info"><?php echo esc_html( 'v' . SSM_VERSION ); ?></span>
			</div>
			<p class="ssm-sub"><?php esc_html_e( 'Publish a built static site (Docusaurus, MkDocs, Hugo, plain HTML) at a URL of this WordPress site, inside your theme\'s header and footer, with SEO tags and sitemap entries, and deploy it from CI with one request.', 'static-site-mount' ); ?></p>

			<h2><?php esc_html_e( 'How it works', 'static-site-mount' ); ?></h2>
			<ol class="ssm-steps">
				<li><strong><?php esc_html_e( 'Build with the right base URL.', 'static-site-mount' ); ?></strong> <?php esc_html_e( 'JavaScript apps embed their base path, so build the site for the URL you will mount it at.', 'static-site-mount' ); ?></li>
				<li><strong><?php esc_html_e( 'Add a mount.', 'static-site-mount' ); ?></strong> <?php esc_html_e( 'Choose a name, the URL prefix and the generator type.', 'static-site-mount' ); ?></li>
				<li><strong><?php esc_html_e( 'Deploy.', 'static-site-mount' ); ?></strong> <?php esc_html_e( 'Upload a zip through the REST API or WP-CLI. The folder is replaced atomically and every page is indexed.', 'static-site-mount' ); ?></li>
				<li><strong><?php esc_html_e( 'Visitors get one site.', 'static-site-mount' ); ?></strong> <?php esc_html_e( 'Pages are served through WordPress, wrapped in your theme\'s header and footer, with titles, descriptions, canonical URLs and a sitemap written by the plugin.', 'static-site-mount' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'Environment check', 'static-site-mount' ); ?></h2>
			<table class="widefat striped">
				<tbody>
				<?php
				self::check_row( 'PHP ' . PHP_VERSION, version_compare( PHP_VERSION, '7.4', '>=' ), __( 'Requires PHP 7.4 or newer.', 'static-site-mount' ) );
				self::check_row( __( 'Zip support', 'static-site-mount' ), class_exists( 'ZipArchive' ), __( 'Needed to deploy zip files. Without it you can still copy files and press Re-index.', 'static-site-mount' ) );
				self::check_row( __( 'Block theme', 'static-site-mount' ), $block, $block ? __( 'The theme\'s header and footer wrap mounted pages.', 'static-site-mount' ) : __( 'Classic theme: pages are served without the header/footer wrapper. Use the ssm_header_html / ssm_footer_html filters.', 'static-site-mount' ) );
				self::check_row( __( 'Pretty permalinks', 'static-site-mount' ), $pretty, $pretty ? __( 'Enabled.', 'static-site-mount' ) : __( 'Enable them under Settings > Permalinks, mounts need them.', 'static-site-mount' ) );
				self::check_row( __( 'Storage folder', 'static-site-mount' ), $writable, $base );
				self::check_row( __( 'Upload limit', 'static-site-mount' ), null, sprintf( /* translators: 1: upload_max_filesize 2: post_max_size */ __( 'upload_max_filesize %1$s, post_max_size %2$s. Zips larger than this cannot be deployed over REST.', 'static-site-mount' ), ini_get( 'upload_max_filesize' ), ini_get( 'post_max_size' ) ) );
				self::check_row( __( 'SEO plugin', 'static-site-mount' ), null, $yoast ? __( 'Yoast SEO detected: its tags are replaced on mounted pages and the sitemap is added to its index.', 'static-site-mount' ) : __( 'None detected. The plugin writes its own tags and sitemap.', 'static-site-mount' ) );
				?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'REST API (for CI)', 'static-site-mount' ); ?></h2>
			<p><?php esc_html_e( 'Authenticate with an Application Password of an administrator (Users > Profile).', 'static-site-mount' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Request', 'static-site-mount' ); ?></th><th><?php esc_html_e( 'Purpose', 'static-site-mount' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>GET /wp-json/static-site-mount/v1/mounts</code></td><td><?php esc_html_e( 'List mounts, page counts and last index time.', 'static-site-mount' ); ?></td></tr>
					<tr><td><code>PUT /wp-json/static-site-mount/v1/mounts/&lt;name&gt;</code></td><td><?php esc_html_e( 'Create or update a mount (JSON: prefix, preset, header_offset, host_theme_key, enabled).', 'static-site-mount' ); ?></td></tr>
					<tr><td><code>POST /wp-json/static-site-mount/v1/mounts/&lt;name&gt;/deploy</code></td><td><?php esc_html_e( 'Replace the folder with an uploaded zip and re-index. Send multipart field "file".', 'static-site-mount' ); ?></td></tr>
					<tr><td><code>POST /wp-json/static-site-mount/v1/mounts/&lt;name&gt;/reindex</code></td><td><?php esc_html_e( 'Re-index after files changed by other means.', 'static-site-mount' ); ?></td></tr>
				</tbody>
			</table>

			<h2>WP-CLI</h2>
			<pre class="ssm-code">wp static-site-mount list
wp static-site-mount add &lt;name&gt; --prefix=docs/my-project --preset=docusaurus
wp static-site-mount deploy &lt;name&gt; build.zip   # or a folder
wp static-site-mount reindex [&lt;name&gt;]</pre>

			<h2><?php esc_html_e( 'Good to know', 'static-site-mount' ); ?></h2>
			<ul style="list-style:disc;padding-left:22px">
				<li><?php esc_html_e( 'Only allow-listed file types are served (no PHP, no dotfiles), and archives are checked for unsafe paths. A refused deploy leaves the live site untouched.', 'static-site-mount' ); ?></li>
				<li><?php esc_html_e( 'The generator\'s CSS and your theme\'s CSS share one page, so check headings and buttons after the first deploy.', 'static-site-mount' ); ?></li>
				<li><?php esc_html_e( 'Disabling a mount keeps its files and settings; its URLs show a normal 404.', 'static-site-mount' ); ?></li>
				<li><?php esc_html_e( 'Sitemap of all mounted pages:', 'static-site-mount' ); ?> <a href="<?php echo esc_url( SSM_Sitemap::url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( SSM_Sitemap::url() ); ?></a></li>
			</ul>

			<h2><?php esc_html_e( 'Developers', 'static-site-mount' ); ?></h2>
			<p><?php esc_html_e( 'Filters:', 'static-site-mount' ); ?> <code>ssm_presets</code>, <code>ssm_title</code>, <code>ssm_description</code>, <code>ssm_header_html</code>, <code>ssm_footer_html</code>, <code>ssm_host_css</code>, <code>ssm_mime_types</code>, <code>ssm_base_dir</code>. <?php esc_html_e( 'Action:', 'static-site-mount' ); ?> <code>ssm_deployed</code>.</p>

			<h2><?php esc_html_e( 'Project', 'static-site-mount' ); ?></h2>
			<p>
				<a href="https://github.com/wenisch-tech/wordpress-static-site-mount" target="_blank" rel="noopener"><?php esc_html_e( 'Source code and documentation on GitHub', 'static-site-mount' ); ?></a><br>
				<?php esc_html_e( 'Licensed under GPL-2.0-or-later. Made by Jean-Fabian Wenisch.', 'static-site-mount' ); ?>
			</p>
		</div>
		<?php
	}
}
