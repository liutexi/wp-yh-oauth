<?php
/**
 * Front-end "我的" account page.
 *
 * Registers a theme-agnostic route (default /my/) that renders the signed-in
 * user's profile, the 云湖 binding state and a few account actions. The same
 * markup is available through the [yunhu_me] shortcode for sites that prefer
 * to embed it in a real page (block themes, page builders).
 *
 * @package WP_Yunhu_OAuth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Yunhu_OAuth_Account
 */
class Yunhu_OAuth_Account {

	const QUERY_VAR   = 'yunhu_me';
	const DEFAULT_SLUG = 'my';
	const FLUSH_FLAG   = 'yunhu_oauth_flush_rewrite';
	const RULES_MARKER = 'yunhu_oauth_rules_marker';

	const NICKNAME_META = 'yunhu_oauth_nickname';
	const LAST_LOGIN_META = 'yunhu_oauth_last_login';

	/**
	 * Singleton instance.
	 *
	 * @var Yunhu_OAuth_Account|null
	 */
	private static $instance = null;

	/**
	 * Settings store.
	 *
	 * @var Yunhu_OAuth_Settings
	 */
	private $settings;

	/**
	 * Get instance.
	 *
	 * @return Yunhu_OAuth_Account
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = Yunhu_OAuth_Settings::instance();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register_rewrite' ), 5 );
		add_action( 'init', array( $this, 'maybe_flush' ), 20 );

		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'detect_route' ), 5 );
		add_filter( 'pre_handle_404', array( $this, 'prevent_404' ) );
		add_action( 'template_redirect', array( $this, 'handle_post_actions' ), 5 );
		add_action( 'template_redirect', array( $this, 'guard' ) );
		add_filter( 'template_include', array( $this, 'template' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		add_action( 'admin_post_yunhu_oauth_flush_rules', array( $this, 'handle_flush_rules' ) );

		add_filter( 'login_redirect', array( $this, 'filter_login_redirect' ), 10, 3 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_node' ), 80 );

		add_shortcode( 'yunhu_me', array( $this, 'shortcode' ) );

		if ( $this->settings->get( 'hide_admin_bar' ) ) {
			add_action( 'after_setup_theme', array( $this, 'maybe_hide_admin_bar' ) );
		}
	}

	/**
	 * Route slug, sanitized and guaranteed non-empty.
	 *
	 * @return string
	 */
	public function slug() {
		$slug = sanitize_title( (string) $this->settings->get( 'account_slug' ) );
		$slug = trim( $slug, '/' );

		return '' === $slug ? self::DEFAULT_SLUG : $slug;
	}

	/**
	 * Public URL of the account page, or an empty string when disabled.
	 *
	 * Plain permalinks cannot serve a rewrite rule, so the query-string form is
	 * used as a fallback instead of silently 404-ing.
	 *
	 * @return string
	 */
	public function page_url() {
		if ( ! $this->settings->get( 'account_page' ) ) {
			return '';
		}

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
		}

		return home_url( user_trailingslashit( $this->slug(), 'page' ) );
	}

	/**
	 * Add the rewrite rule for the account route.
	 *
	 * @return void
	 */
	public function register_rewrite() {
		if ( ! $this->settings->get( 'account_page' ) ) {
			return;
		}

		add_rewrite_rule(
			'^' . $this->slug() . '/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Flush rewrite rules when the route changed — including the first request
	 * after upgrading from a version that had no account route at all.
	 *
	 * @return void
	 */
	public function maybe_flush() {
		$signature = $this->route_signature();

		if ( ! get_option( self::FLUSH_FLAG ) && (string) get_option( self::RULES_MARKER ) === $signature ) {
			return;
		}

		flush_rewrite_rules( false );

		delete_option( self::FLUSH_FLAG );
		update_option( self::RULES_MARKER, $signature );
	}

	/**
	 * Identity of the currently registered route, used to detect staleness.
	 *
	 * @return string
	 */
	private function route_signature() {
		return ( $this->settings->get( 'account_page' ) ? 'on' : 'off' ) . '|' . $this->slug();
	}

	/**
	 * Whether the saved rewrite rules actually contain the account route.
	 *
	 * Rewrite rules live in the `rewrite_rules` option, so this is the only way
	 * to tell "the rule was written" from "the rule is missing".
	 *
	 * @return bool
	 */
	public function rules_ready() {
		$rules = get_option( 'rewrite_rules' );

		if ( ! is_array( $rules ) ) {
			return false;
		}

		foreach ( $rules as $query ) {
			if ( is_string( $query ) && false !== strpos( $query, self::QUERY_VAR . '=1' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve /my/ even when no rewrite rule matched.
	 *
	 * Permission plugins, hosts that rewrite `rewrite_rules` themselves, or a
	 * rule flush that never happened all produce a 404 on an otherwise valid
	 * route. Matching the request path here makes the page independent of the
	 * rewrite layer entirely.
	 *
	 * @param WP $wp Current request object.
	 * @return void
	 */
	public function detect_route( $wp ) {
		if ( ! $this->settings->get( 'account_page' ) ) {
			return;
		}

		// Note: an unmatched request has an empty (but set) query_vars array,
		// which is exactly the case that needs rescuing — so test isset(), not empty().
		if ( ! is_object( $wp ) || ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) ) {
			return;
		}

		if ( ! empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$path = $this->request_path();

		if ( '' === $path ) {
			return;
		}

		$slug = $this->slug();

		if ( $path !== $slug && rawurldecode( $path ) !== rawurldecode( $slug ) ) {
			return;
		}

		$wp->query_vars[ self::QUERY_VAR ] = 1;

		// The request matched nothing, so WordPress may have queued a 404.
		unset( $wp->query_vars['error'], $wp->query_vars['pagename'], $wp->query_vars['name'] );
	}

	/**
	 * Request path relative to the site's home path, without slashes.
	 *
	 * Handles subdirectory installs and `index.php/` style permalinks.
	 *
	 * @return string
	 */
	private function request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $uri ) {
			return '';
		}

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( '' !== $home && '/' !== $home ) {
			// Subdirectory install: a path outside the home path is not ours.
			if ( 0 !== strpos( $path, $home ) ) {
				return '';
			}

			$path = substr( $path, strlen( $home ) );
		}

		$path = trim( $path, '/' );
		$path = (string) preg_replace( '#^index\.php/#', '', $path );

		return $path;
	}

	/**
	 * Force a rewrite flush from the settings screen.
	 *
	 * @return void
	 */
	public function handle_flush_rules() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '你没有权限执行此操作。', 'wp-yunhu-oauth' ), 403 );
		}

		check_admin_referer( 'yunhu_oauth_flush_rules' );

		flush_rewrite_rules( false );

		update_option( self::RULES_MARKER, $this->route_signature() );
		delete_option( self::FLUSH_FLAG );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => Yunhu_OAuth_Settings::PAGE,
					'yunhu_rules_flushed' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Make the route query var publicly readable.
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * The virtual route has no posts, so stop WordPress from turning it into a
	 * 404 before the template loader runs.
	 *
	 * @param bool $preempt Short-circuit flag.
	 * @return bool
	 */
	public function prevent_404( $preempt ) {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return $preempt;
		}

		global $wp_query;

		if ( $wp_query instanceof WP_Query ) {
			$wp_query->is_404 = false;
		}

		status_header( 200 );

		return true;
	}

	/**
	 * Require a signed-in user before rendering the account page.
	 *
	 * @return void
	 */
	public function guard() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		nocache_headers();

		if ( is_user_logged_in() ) {
			return;
		}

		wp_safe_redirect( wp_login_url( $this->current_url() ) );
		exit;
	}

	/**
	 * Load the bundled template for the account route.
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 */
	public function template( $template ) {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return $template;
		}

		$file = YUNHU_OAUTH_DIR . 'templates/account.php';

		return file_exists( $file ) ? $file : $template;
	}

	/**
	 * Mark the body so themes can style the page if they want to.
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public function body_class( $classes ) {
		if ( get_query_var( self::QUERY_VAR ) ) {
			$classes[] = 'yunhu-me-page';
		}

		return $classes;
	}

	/**
	 * Keep the personal page out of search indexes.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public function robots( $robots ) {
		if ( get_query_var( self::QUERY_VAR ) ) {
			$robots['noindex']  = true;
			$robots['noarchive'] = true;
		}

		return $robots;
	}

	/**
	 * Enqueue the account stylesheet.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$this->enqueue_assets();
	}

	/**
	 * Register the account styles, plus the login button styles when needed.
	 *
	 * @param bool $with_login_css Whether the shared button styles are required.
	 * @return void
	 */
	private function enqueue_assets( $with_login_css = false ) {
		if ( $with_login_css ) {
			wp_enqueue_style(
				'yunhu-oauth',
				YUNHU_OAUTH_URL . 'assets/css/yunhu-oauth.css',
				array(),
				YUNHU_OAUTH_VERSION
			);
		}

		wp_enqueue_style(
			'yunhu-oauth-account',
			YUNHU_OAUTH_URL . 'assets/css/yunhu-oauth-account.css',
			array(),
			YUNHU_OAUTH_VERSION
		);
	}

	/**
	 * URL of the current account page request, used for post-login redirects.
	 *
	 * @return string
	 */
	private function current_url() {
		$url = $this->page_url();

		return '' === $url ? home_url( '/' ) : $url;
	}

	/**
	 * Shortcode wrapper: [yunhu_me]
	 *
	 * @return string
	 */
	public function shortcode() {
		if ( ! is_user_logged_in() ) {
			$this->enqueue_assets( true );

			$login = Yunhu_OAuth_Login::instance();

			return sprintf(
				'<div class="yunhu-me"><div class="yunhu-me__card yunhu-me__guest"><p>%s</p><p><a class="button yunhu-oauth-button" href="%s" rel="nofollow">%s</a></p></div></div>',
				esc_html__( '请先登录后查看你的账号信息。', 'wp-yunhu-oauth' ),
				esc_url( $login->start_url( $this->current_url() ) ),
				esc_html__( '使用云湖账号登录', 'wp-yunhu-oauth' )
			);
		}

		$this->enqueue_assets();

		ob_start();
		$this->render();

		return (string) ob_get_clean();
	}

	/**
	 * Render the whole account block.
	 *
	 * @return void
	 */
	public function render() {
		$user = wp_get_current_user();

		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return;
		}

		$uid      = (string) get_user_meta( $user->ID, Yunhu_OAuth_Login::UID_META, true );
		$nickname = (string) get_user_meta( $user->ID, self::NICKNAME_META, true );
		$bound_at = (int) get_user_meta( $user->ID, 'yunhu_oauth_bound_at', true );
		$last     = (int) get_user_meta( $user->ID, self::LAST_LOGIN_META, true );
		$managed  = (bool) get_user_meta( $user->ID, Yunhu_OAuth_Login::MANAGED_META, true );
		$synced   = $managed && $this->settings->get( 'sync_profile' );
		$bound    = '' !== $uid;
		$can_bind = $this->settings->get( 'enabled' ) && $this->settings->is_configured();

		/**
		 * Filter the data shown on the account page.
		 *
		 * @param array   $data Resolved values.
		 * @param WP_User $user Current user.
		 */
		$data = apply_filters(
			'yunhu_oauth_account_data',
			array(
				'uid'      => $uid,
				'nickname' => $nickname,
				'bound_at' => $bound_at,
				'last'     => $last,
				'managed'  => $managed,
				'bound'    => $bound,
			),
			$user
		);

		echo '<div class="yunhu-me">';

		$this->render_notices();

		/**
		 * Fires before the account page content.
		 *
		 * @param WP_User $user Current user.
		 */
		do_action( 'yunhu_oauth_account_before', $user );

		$this->render_hero( $user, $data );

		echo '<div class="yunhu-me__grid">';
		$this->render_account_card( $user, $data );
		$this->render_provider_card( $user, $data, $can_bind );
		echo '</div>';

		$this->render_name_form( $user, $synced );

		/**
		 * Add extra sections to the account page.
		 *
		 * @param WP_User $user Current user.
		 */
		do_action( 'yunhu_oauth_account_sections', $user );

		$this->render_actions( $user );

		echo '</div>';
	}

	/**
	 * Avatar, display name, role and binding badge.
	 *
	 * @param WP_User $user Current user.
	 * @param array   $data Resolved values.
	 * @return void
	 */
	private function render_hero( $user, $data ) {
		$name = '' !== (string) $user->display_name ? $user->display_name : $user->user_login;
		$role = $this->role_name( $user );
		?>
		<header class="yunhu-me__hero">
			<div class="yunhu-me__avatar">
				<?php
				echo get_avatar( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
					$user->ID,
					96,
					'',
					$name,
					array( 'class' => 'yunhu-me__avatar-img' )
				);
				?>
			</div>
			<div class="yunhu-me__identity">
				<h2 class="yunhu-me__name"><?php echo esc_html( $name ); ?></h2>
				<p class="yunhu-me__handle">@<?php echo esc_html( $user->user_login ); ?></p>
				<p class="yunhu-me__badges">
					<span class="yunhu-me__badge"><?php echo esc_html( $role ); ?></span>
					<?php if ( $data['bound'] ) : ?>
						<span class="yunhu-me__badge is-ok"><?php esc_html_e( '已绑定云湖', 'wp-yunhu-oauth' ); ?></span>
					<?php else : ?>
						<span class="yunhu-me__badge is-muted"><?php esc_html_e( '未绑定云湖', 'wp-yunhu-oauth' ); ?></span>
					<?php endif; ?>
				</p>
			</div>
		</header>
		<?php
	}

	/**
	 * Local account details.
	 *
	 * @param WP_User $user Current user.
	 * @param array   $data Resolved values.
	 * @return void
	 */
	private function render_account_card( $user, $data ) {
		$rows = array(
			__( '用户名', 'wp-yunhu-oauth' ) => '<code>' . esc_html( $user->user_login ) . '</code>',
			__( '显示名称', 'wp-yunhu-oauth' ) => esc_html( (string) $user->display_name ),
			__( '邮箱', 'wp-yunhu-oauth' )   => '' !== (string) $user->user_email
				? esc_html( $user->user_email )
				: '<span class="yunhu-me__muted">' . esc_html__( '未设置', 'wp-yunhu-oauth' ) . '</span>',
			__( '角色', 'wp-yunhu-oauth' )   => esc_html( $this->role_name( $user ) ),
			__( '注册时间', 'wp-yunhu-oauth' ) => esc_html( $this->format_date( (string) $user->user_registered ) ),
			__( '上次云湖登录', 'wp-yunhu-oauth' ) => $data['last']
				? esc_html( $this->format_date( (int) $data['last'] ) )
				: '<span class="yunhu-me__muted">' . esc_html__( '暂无记录', 'wp-yunhu-oauth' ) . '</span>',
		);
		?>
		<section class="yunhu-me__card">
			<h3 class="yunhu-me__card-title"><?php esc_html_e( '账号信息', 'wp-yunhu-oauth' ); ?></h3>
			<dl class="yunhu-me__list">
				<?php foreach ( $rows as $label => $value ) : ?>
					<div class="yunhu-me__row">
						<dt><?php echo esc_html( $label ); ?></dt>
						<dd><?php echo wp_kses( $value, array( 'code' => array(), 'span' => array( 'class' => array() ) ) ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
			<?php if ( '' === (string) $user->user_email ) : ?>
				<p class="yunhu-me__hint">
					<?php esc_html_e( '云湖不提供邮箱，本账号的邮箱为空。补填邮箱后才能使用「忘记密码」找回账号。', 'wp-yunhu-oauth' ); ?>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * 云湖 binding state and link/unlink actions.
	 *
	 * @param WP_User $user     Current user.
	 * @param array   $data     Resolved values.
	 * @param bool    $can_bind Whether linking is possible right now.
	 * @return void
	 */
	private function render_provider_card( $user, $data, $can_bind ) {
		$nickname = (string) $data['nickname'];
		?>
		<section class="yunhu-me__card">
			<h3 class="yunhu-me__card-title"><?php esc_html_e( '云湖账号', 'wp-yunhu-oauth' ); ?></h3>

			<?php if ( $data['bound'] ) : ?>
				<dl class="yunhu-me__list">
					<div class="yunhu-me__row">
						<dt><?php esc_html_e( '云湖用户 ID', 'wp-yunhu-oauth' ); ?></dt>
						<dd><code><?php echo esc_html( $data['uid'] ); ?></code></dd>
					</div>
					<?php if ( '' !== $nickname ) : ?>
						<div class="yunhu-me__row">
							<dt><?php esc_html_e( '云湖昵称', 'wp-yunhu-oauth' ); ?></dt>
							<dd><?php echo esc_html( $nickname ); ?></dd>
						</div>
					<?php endif; ?>
					<?php if ( $data['bound_at'] ) : ?>
						<div class="yunhu-me__row">
							<dt><?php esc_html_e( '绑定时间', 'wp-yunhu-oauth' ); ?></dt>
							<dd><?php echo esc_html( $this->format_date( (int) $data['bound_at'] ) ); ?></dd>
						</div>
					<?php endif; ?>
				</dl>
				<form class="yunhu-me__actions-row" method="post" action="<?php echo esc_url( $this->current_url() ); ?>">
					<input type="hidden" name="yunhu_action" value="unlink" />
					<input type="hidden" name="_redirect" value="<?php echo esc_url( $this->current_url() ); ?>" />
					<?php wp_nonce_field( 'yunhu_oauth_unlink_' . $user->ID, '_yunhu_nonce' ); ?>
					<button
						type="submit"
						class="yunhu-me__btn is-danger"
						onclick="return confirm('<?php echo esc_js( __( '解除绑定后，你将无法再使用云湖账号登录本站，确定继续？', 'wp-yunhu-oauth' ) ); ?>');"
					>
						<?php esc_html_e( '解除绑定', 'wp-yunhu-oauth' ); ?>
					</button>
				</form>
				<p class="yunhu-me__hint">
					<?php esc_html_e( '解除绑定不会删除本站账号，你仍可用用户名和密码登录。', 'wp-yunhu-oauth' ); ?>
				</p>
			<?php else : ?>
				<p class="yunhu-me__empty"><?php esc_html_e( '当前账号还没有绑定云湖，绑定后可以直接用云湖账号一键登录。', 'wp-yunhu-oauth' ); ?></p>
				<?php if ( $can_bind ) : ?>
					<p class="yunhu-me__actions-row">
						<a class="yunhu-me__btn is-primary" href="<?php echo esc_url( Yunhu_OAuth_Login::instance()->start_url( $this->current_url() ) ); ?>" rel="nofollow">
							<?php esc_html_e( '绑定我的云湖账号', 'wp-yunhu-oauth' ); ?>
						</a>
					</p>
				<?php else : ?>
					<p class="yunhu-me__hint"><?php esc_html_e( '云湖登录尚未启用或配置不完整，暂时无法绑定。', 'wp-yunhu-oauth' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Display-name editor.
	 *
	 * @param WP_User $user   Current user.
	 * @param bool    $synced Whether the display name is overwritten on next login.
	 * @return void
	 */
	private function render_name_form( $user, $synced ) {
		?>
		<section class="yunhu-me__card">
			<h3 class="yunhu-me__card-title"><?php esc_html_e( '显示名称', 'wp-yunhu-oauth' ); ?></h3>
			<form class="yunhu-me__form" method="post" action="<?php echo esc_url( $this->current_url() ); ?>">
				<input type="hidden" name="yunhu_action" value="update_name" />
				<input type="hidden" name="_redirect" value="<?php echo esc_url( $this->current_url() ); ?>" />
				<?php wp_nonce_field( 'yunhu_oauth_update_name', '_yunhu_nonce' ); ?>
				<label class="screen-reader-text" for="yunhu-me-display-name"><?php esc_html_e( '显示名称', 'wp-yunhu-oauth' ); ?></label>
				<input
					type="text"
					id="yunhu-me-display-name"
					name="display_name"
					class="yunhu-me__input"
					value="<?php echo esc_attr( (string) $user->display_name ); ?>"
					maxlength="100"
					required
				/>
				<button type="submit" class="yunhu-me__btn is-primary"><?php esc_html_e( '保存', 'wp-yunhu-oauth' ); ?></button>
			</form>
			<?php if ( $synced ) : ?>
				<p class="yunhu-me__hint">
					<?php esc_html_e( '当前账号的显示名称会跟随云湖昵称同步，这里修改后，下次用云湖登录时会被覆盖。', 'wp-yunhu-oauth' ); ?>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Footer actions: profile editor, home, logout.
	 *
	 * @param WP_User $user Current user.
	 * @return void
	 */
	private function render_actions( $user ) {
		?>
		<nav class="yunhu-me__footer">
			<a class="yunhu-me__link" href="<?php echo esc_url( get_edit_profile_url( $user->ID ) ); ?>">
				<?php esc_html_e( '编辑完整资料', 'wp-yunhu-oauth' ); ?>
			</a>
			<a class="yunhu-me__link" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php esc_html_e( '返回首页', 'wp-yunhu-oauth' ); ?>
			</a>
			<a class="yunhu-me__link is-danger" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
				<?php esc_html_e( '退出登录', 'wp-yunhu-oauth' ); ?>
			</a>
		</nav>
		<?php
	}

	/**
	 * Inline feedback for the flows that bounce back to this page.
	 *
	 * @return void
	 */
	private function render_notices() {
		$notices = array();

		if ( isset( $_GET['yunhu_oauth_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$code   = sanitize_key( wp_unslash( $_GET['yunhu_oauth_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$detail = '';

			if ( isset( $_GET['yunhu_oauth_detail'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$detail = sanitize_text_field( rawurldecode( wp_unslash( $_GET['yunhu_oauth_detail'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			if ( 'linked' === $code ) {
				$notices[] = array( 'success', __( '已成功绑定云湖账号。', 'wp-yunhu-oauth' ) );
			} elseif ( 'unlinked' === $code ) {
				$notices[] = array( 'success', __( '已解除云湖账号绑定。', 'wp-yunhu-oauth' ) );
			} else {
				$notices[] = array( 'error', Yunhu_OAuth_Login::message( $code ), $detail );
			}
		}

		if ( isset( $_GET['yunhu_me_updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notices[] = array( 'success', __( '显示名称已更新。', 'wp-yunhu-oauth' ) );
		}

		if ( isset( $_GET['yunhu_me_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$code = sanitize_key( wp_unslash( $_GET['yunhu_me_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$notices[] = array(
				'error',
				'name_empty' === $code
					? __( '显示名称不能为空。', 'wp-yunhu-oauth' )
					: __( '保存失败，请稍后重试。', 'wp-yunhu-oauth' ),
			);
		}

		foreach ( $notices as $notice ) {
			$class = 'error' === $notice[0] ? 'is-error' : 'is-success';

			printf(
				'<div class="yunhu-me__notice %s" role="status">%s%s</div>',
				esc_attr( $class ),
				esc_html( $notice[1] ),
				! empty( $notice[2] ) ? '<code class="yunhu-me__notice-detail">' . esc_html( $notice[2] ) . '</code>' : ''
			);
		}
	}

	/**
	 * Handle the account page's own POST submissions.
	 *
	 * Forms post back to the account URL instead of wp-admin, so the page keeps
	 * working on sites that block subscribers from the dashboard.
	 *
	 * @return void
	 */
	public function handle_post_actions() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'POST' !== $method || ! is_user_logged_in() ) {
			return;
		}

		$action = isset( $_POST['yunhu_action'] ) ? sanitize_key( wp_unslash( $_POST['yunhu_action'] ) ) : '';

		if ( '' === $action ) {
			return;
		}

		nocache_headers();

		$user_id = get_current_user_id();
		$back    = isset( $_POST['_redirect'] ) ? wp_validate_redirect( wp_unslash( $_POST['_redirect'] ), '' ) : '';

		if ( '' === $back ) {
			$back = $this->current_url();
		}

		if ( 'update_name' === $action ) {
			$this->handle_update_name( $user_id, $back );
		}

		if ( 'unlink' === $action ) {
			$this->handle_account_unlink( $user_id, $back );
		}
	}

	/**
	 * Persist a new display name.
	 *
	 * @param int    $user_id Current user.
	 * @param string $back    Redirect target.
	 * @return void
	 */
	private function handle_update_name( $user_id, $back ) {
		$nonce = isset( $_POST['_yunhu_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_yunhu_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'yunhu_oauth_update_name' ) ) {
			wp_die( esc_html__( '安全校验失败，请刷新页面后重试。', 'wp-yunhu-oauth' ), 403 );
		}

		$name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';

		if ( '' === trim( $name ) ) {
			wp_safe_redirect( add_query_arg( 'yunhu_me_error', 'name_empty', $back ) );
			exit;
		}

		$result = wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $name,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'yunhu_me_error', 'name_failed', $back ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'yunhu_me_updated', '1', $back ) );
		exit;
	}

	/**
	 * Remove the 云湖 binding from the account page.
	 *
	 * @param int    $user_id Current user.
	 * @param string $back    Redirect target.
	 * @return void
	 */
	private function handle_account_unlink( $user_id, $back ) {
		$nonce = isset( $_POST['_yunhu_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_yunhu_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'yunhu_oauth_unlink_' . $user_id ) ) {
			wp_die( esc_html__( '安全校验失败，请刷新页面后重试。', 'wp-yunhu-oauth' ), 403 );
		}

		Yunhu_OAuth_Profile::instance()->unlink( $user_id );

		wp_safe_redirect( add_query_arg( 'yunhu_oauth_notice', 'unlinked', $back ) );
		exit;
	}

	/**
	 * Send subscribers to the account page after a normal login.
	 *
	 * Users who can write posts keep the default wp-admin destination, and an
	 * explicit redirect_to from WordPress always wins.
	 *
	 * @param string           $redirect_to           Resolved destination.
	 * @param string           $requested_redirect_to Raw request value.
	 * @param WP_User|WP_Error $user                  Logged-in user.
	 * @return string
	 */
	public function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! $user instanceof WP_User || ! $this->settings->get( 'login_redirect' ) ) {
			return $redirect_to;
		}

		if ( '' !== (string) $requested_redirect_to ) {
			return $redirect_to;
		}

		if ( user_can( $user, 'edit_posts' ) ) {
			return $redirect_to;
		}

		$url = $this->page_url();

		return '' === $url ? $redirect_to : $url;
	}

	/**
	 * Hide the admin toolbar for non-editors when the option is on.
	 *
	 * @return void
	 */
	public function maybe_hide_admin_bar() {
		if ( ! is_user_logged_in() || user_can( get_current_user_id(), 'edit_posts' ) ) {
			return;
		}

		add_filter( 'show_admin_bar', '__return_false' );
	}

	/**
	 * Add a "我的" node to the admin toolbar.
	 *
	 * @param WP_Admin_Bar $bar Toolbar instance.
	 * @return void
	 */
	public function admin_bar_node( $bar ) {
		if ( ! is_user_logged_in() || ! $bar instanceof WP_Admin_Bar ) {
			return;
		}

		$url = $this->page_url();

		if ( '' === $url ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'yunhu-me',
				'title' => esc_html__( '我的', 'wp-yunhu-oauth' ),
				'href'  => $url,
			)
		);
	}

	/**
	 * Human readable role name.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private function role_name( $user ) {
		$names = wp_roles()->get_names();

		foreach ( (array) $user->roles as $role ) {
			if ( isset( $names[ $role ] ) ) {
				return translate_user_role( $names[ $role ] );
			}
		}

		return __( '无角色', 'wp-yunhu-oauth' );
	}

	/**
	 * Localized date from a timestamp or a MySQL date string.
	 *
	 * @param int|string $value Timestamp or date string.
	 * @return string
	 */
	private function format_date( $value ) {
		$timestamp = is_numeric( $value ) ? (int) $value : (int) strtotime( (string) $value );

		if ( $timestamp <= 0 ) {
			return '—';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
