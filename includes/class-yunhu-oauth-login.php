<?php
/**
 * Front-end and callback login flow.
 *
 * @package WP_Yunhu_OAuth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Yunhu_OAuth_Login
 */
class Yunhu_OAuth_Login {

	const STATE_PREFIX = 'yunhu_oauth_st_';
	const UID_META     = 'yunhu_oauth_uid';
	const AVATAR_META  = 'yunhu_oauth_avatar';
	const MANAGED_META = 'yunhu_oauth_managed';

	/**
	 * Singleton instance.
	 *
	 * @var Yunhu_OAuth_Login|null
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
	 * @return Yunhu_OAuth_Login
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
		add_action( 'login_init', array( $this, 'maybe_start' ) );
		add_action( 'login_form', array( $this, 'render_login_button' ) );
		add_action( 'login_message', array( $this, 'render_login_message' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );

		add_action( 'admin_post_nopriv_yunhu_oauth_callback', array( $this, 'handle_callback' ) );
		add_action( 'admin_post_yunhu_oauth_callback', array( $this, 'handle_callback' ) );

		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );

		add_shortcode( 'yunhu_login', array( $this, 'shortcode' ) );
	}

	/**
	 * REST callback: /wp-json/yunhu-oauth/v1/callback
	 *
	 * The path-only URL is the default because 云湖 matches the full callback
	 * string and this form survives providers that append "?code=" naively.
	 *
	 * @return void
	 */
	public function register_rest_route() {
		register_rest_route(
			'yunhu-oauth/v1',
			'/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Read a callback parameter, tolerating providers that append the query
	 * string incorrectly (e.g. a second "?" instead of "&").
	 *
	 * @param string $name Parameter name.
	 * @return string
	 */
	private function callback_param( $name ) {
		if ( isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_text_field( wp_unslash( (string) $_GET[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' !== $uri && preg_match( '/(?:^|[?&])' . preg_quote( $name, '/' ) . '=([^&#]*)/', $uri, $matches ) ) {
			return sanitize_text_field( urldecode( $matches[1] ) );
		}

		return '';
	}

	/**
	 * Public URL that kicks off the authorization redirect.
	 *
	 * @param string $redirect_to Where to send the user after login.
	 * @return string
	 */
	public function start_url( $redirect_to = '' ) {
		$args = array( 'action' => 'yunhu_oauth' );

		if ( '' === $redirect_to ) {
			$redirect_to = $this->current_request_uri();
		}

		if ( '' !== $redirect_to ) {
			$args['redirect_to'] = $redirect_to;
		}

		return add_query_arg( $args, wp_login_url() );
	}

	/**
	 * Where a login lands when the caller did not ask for a specific page.
	 *
	 * Prefers the bundled "我的" page so subscribers never get dropped into
	 * wp-admin; falls back to the dashboard when that page is disabled.
	 *
	 * @return string
	 */
	private function default_redirect() {
		$url = Yunhu_OAuth_Account::instance()->page_url();

		return '' === $url ? admin_url() : $url;
	}

	/**
	 * Best-effort "where the user was" for post-login redirects.
	 *
	 * @return string
	 */
	private function current_request_uri() {
		if ( is_admin() ) {
			return '';
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $uri ) {
			return '';
		}

		return wp_validate_redirect( home_url( $uri ), '' );
	}

	/**
	 * Load the stylesheet where a button might appear.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'yunhu-oauth',
			YUNHU_OAUTH_URL . 'assets/css/yunhu-oauth.css',
			array(),
			YUNHU_OAUTH_VERSION
		);
	}

	/**
	 * Step 1: build state, then bounce the browser to 云湖.
	 *
	 * @return void
	 */
	public function maybe_start() {
		if ( ! isset( $_GET['action'] ) || 'yunhu_oauth' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! $this->settings->get( 'enabled' ) ) {
			$this->fail( 'disabled' );
		}

		if ( ! $this->settings->is_configured() ) {
			$this->fail( 'not_configured' );
		}

		$redirect_to = '';

		if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redirect_to = wp_validate_redirect( wp_unslash( $_REQUEST['redirect_to'] ), '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( '' === $redirect_to ) {
			$redirect_to = $this->default_redirect();
		}

		$link_user = is_user_logged_in() ? get_current_user_id() : 0;
		// 云湖 requires state to be 32-128 chars; 64 hex chars is comfortably inside.
		$state    = bin2hex( random_bytes( 32 ) );
		$verifier = Yunhu_OAuth_Client::create_verifier();
		$callback = $this->settings->callback_url();

		set_transient(
			self::STATE_PREFIX . hash( 'sha256', $state ),
			array(
				'state'       => $state,
				'verifier'    => $verifier,
				'redirect_to' => $redirect_to,
				'link_user'   => (int) $link_user,
				'expires'     => time() + 10 * MINUTE_IN_SECONDS,
			),
			10 * MINUTE_IN_SECONDS
		);

		$client = new Yunhu_OAuth_Client( $this->settings );
		$url    = $client->authorize_url( $state, $verifier, $callback );

		if ( ! wp_http_validate_url( $url ) ) {
			$this->fail( 'not_configured' );
		}

		// External redirect: wp_redirect() is intentional here.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Step 2: handle the provider callback, provision the user, log them in.
	 *
	 * @return void
	 */
	public function handle_callback() {
		if ( ! $this->settings->get( 'enabled' ) ) {
			$this->fail( 'disabled' );
		}

		$error = $this->callback_param( 'error' );
		$state = $this->callback_param( 'state' );
		$code  = $this->callback_param( 'code' );

		if ( '' !== $error ) {
			$this->fail( 'access_denied' === $error ? 'cancelled' : 'provider_error' );
		}

		if ( '' === $state || '' === $code ) {
			$this->fail( 'invalid_request' );
		}

		$key    = self::STATE_PREFIX . hash( 'sha256', $state );
		$stored = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $stored ) || empty( $stored['state'] ) || ! hash_equals( (string) $stored['state'], $state ) ) {
			$this->fail( 'state_mismatch' );
		}

		if ( empty( $stored['expires'] ) || time() > (int) $stored['expires'] ) {
			$this->fail( 'state_expired' );
		}

		$fallback    = $this->default_redirect();
		$redirect_to = ! empty( $stored['redirect_to'] ) ? wp_validate_redirect( $stored['redirect_to'], $fallback ) : $fallback;
		$link_user   = isset( $stored['link_user'] ) ? (int) $stored['link_user'] : 0;

		if ( $link_user > 0 && get_current_user_id() !== $link_user ) {
			$this->fail( 'session_expired' );
		}

		$client = new Yunhu_OAuth_Client( $this->settings );
		$token  = $client->exchange_code( $code, (string) ( $stored['verifier'] ?? '' ), $this->settings->callback_url() );

		if ( is_wp_error( $token ) ) {
			$this->fail( 'token_error', $link_user, $token->get_error_message() );
		}

		$profile = $client->fetch_user( (string) $token['access_token'] );

		if ( is_wp_error( $profile ) ) {
			$map = array(
				'yunhu_user_no_id'              => 'no_id',
				'yunhu_userinfo_unauthorized'   => 'token_expired',
			);
			$map_code = isset( $map[ $profile->get_error_code() ] ) ? $map[ $profile->get_error_code() ] : 'userinfo_error';

			$this->fail( $map_code, $link_user, $profile->get_error_message() );
		}

		$result = $this->resolve_user( $profile, $link_user );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_code(), $link_user, $result->get_error_message() );
		}

		$user_id = (int) $result;
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			$this->fail( 'unknown_error' );
		}

		if ( $link_user > 0 ) {
			$back = Yunhu_OAuth_Account::instance()->page_url();

			if ( '' === $back ) {
				$back = get_edit_profile_url( $user_id );
			}

			wp_safe_redirect( add_query_arg( 'yunhu_oauth_notice', 'linked', $back ) );
			exit;
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		update_user_meta( $user_id, Yunhu_OAuth_Account::LAST_LOGIN_META, time() );
		do_action( 'wp_login', $user->user_login, $user );

		/**
		 * Fires after a successful 云湖 login, before the redirect.
		 *
		 * @param int   $user_id WordPress user ID.
		 * @param array $profile Normalized provider profile.
		 */
		do_action( 'yunhu_oauth_login', $user_id, $profile );

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Map the provider profile onto a WordPress user, creating or linking as configured.
	 *
	 * @param array $profile   Normalized profile.
	 * @param int   $link_user Existing user to bind to (0 for a normal login).
	 * @return int|WP_Error User ID on success.
	 */
	private function resolve_user( $profile, $link_user ) {
		$uid = (string) $profile['id'];

		if ( $link_user > 0 ) {
			$owner = $this->find_user_by_uid( $uid );

			if ( $owner && $owner !== $link_user ) {
				return new WP_Error( 'link_conflict', __( '该云湖账号已绑定到其他站点账号。', 'wp-yunhu-oauth' ) );
			}

			$this->bind( $link_user, $profile );

			return $link_user;
		}

		$user_id = $this->find_user_by_uid( $uid );

		if ( ! $user_id && $this->settings->get( 'link_by_email' ) && '' !== $profile['email'] ) {
			$by_email = get_user_by( 'email', $profile['email'] );

			if ( $by_email instanceof WP_User ) {
				$user_id = (int) $by_email->ID;
				$this->bind( $user_id, $profile );
			}
		}

		if ( $user_id ) {
			if ( $this->settings->get( 'sync_profile' ) ) {
				$this->bind( $user_id, $profile );
			}

			return $user_id;
		}

		if ( ! $this->settings->get( 'auto_register' ) ) {
			return new WP_Error( 'not_linked', __( '该云湖账号尚未绑定本站账号，请先登录后在个人资料页完成绑定。', 'wp-yunhu-oauth' ) );
		}

		return $this->create_user( $profile );
	}

	/**
	 * Find a user by the stored 云湖 id.
	 *
	 * @param string $uid 云湖 user id.
	 * @return int
	 */
	private function find_user_by_uid( $uid ) {
		$users = get_users(
			array(
				'meta_key'     => self::UID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => $uid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'       => 1,
				'fields'       => 'ID',
				'meta_compare' => '=',
			)
		);

		return $users ? (int) $users[0] : 0;
	}

	/**
	 * Store the binding and optionally sync profile fields.
	 *
	 * @param int   $user_id WordPress user ID.
	 * @param array $profile Normalized profile.
	 * @return void
	 */
	private function bind( $user_id, $profile ) {
		update_user_meta( $user_id, self::UID_META, (string) $profile['id'] );

		if ( '' !== (string) $profile['nickname'] ) {
			update_user_meta( $user_id, Yunhu_OAuth_Account::NICKNAME_META, (string) $profile['nickname'] );
		}

		if ( ! get_user_meta( $user_id, 'yunhu_oauth_bound_at', true ) ) {
			update_user_meta( $user_id, 'yunhu_oauth_bound_at', time() );
		}

		if ( '' !== $profile['avatar'] ) {
			update_user_meta( $user_id, self::AVATAR_META, $profile['avatar'] );

			// 云湖图片有防盗链：先下载到本地再输出，失败时才退回远程地址。
			Yunhu_OAuth_Avatar::instance()->sync( $user_id, $profile['avatar'] );
		}

		if ( ! $this->settings->get( 'sync_profile' ) || '' === $profile['nickname'] ) {
			return;
		}

		// Only overwrite the display name of accounts this plugin created.
		if ( get_user_meta( $user_id, self::MANAGED_META, true ) ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $profile['nickname'],
				)
			);
		}
	}

	/**
	 * Create a local account for a first-time 云湖 user.
	 *
	 * @param array $profile Normalized profile.
	 * @return int|WP_Error
	 */
	private function create_user( $profile ) {
		$username = $this->unique_username( $profile['username'], $profile['id'] );
		$email    = $profile['email'];

		if ( '' !== $email && email_exists( $email ) ) {
			// Handled by link_by_email; without it we simply skip the conflicting address.
			$email = '';
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => $email,
				'display_name' => '' !== $profile['nickname'] ? $profile['nickname'] : $username,
				'nickname'     => '' !== $profile['nickname'] ? $profile['nickname'] : $username,
				'role'         => $this->valid_role(),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			$this->log( '创建用户失败：' . $user_id->get_error_message() );

			return new WP_Error( 'create_failed', __( '创建本地账号失败，请联系站点管理员。', 'wp-yunhu-oauth' ) );
		}

		update_user_meta( $user_id, self::MANAGED_META, 1 );
		$this->bind( $user_id, $profile );

		/**
		 * Fires after a local account was provisioned from a 云湖 profile.
		 *
		 * @param int   $user_id New user ID.
		 * @param array $profile Normalized profile.
		 */
		do_action( 'yunhu_oauth_user_created', $user_id, $profile );

		return (int) $user_id;
	}

	/**
	 * Build a unique, valid user_login.
	 *
	 * @param string $preferred Preferred name from the provider.
	 * @param string $uid       Provider user id.
	 * @return string
	 */
	private function unique_username( $preferred, $uid ) {
		$base = sanitize_user( (string) $preferred, true );
		$base = preg_replace( '/\s+/', '', $base );
		$base = (string) $base;

		if ( '' === $base ) {
			$base = 'yunhu' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $uid );
		}

		$base = substr( $base, 0, 50 );

		if ( '' === $base ) {
			$base = 'yunhu';
		}

		$candidate = $base;
		$i         = 1;

		while ( username_exists( $candidate ) || ! validate_username( $candidate ) ) {
			$candidate = substr( $base, 0, 44 ) . '-' . $i;
			++$i;

			if ( $i > 100 ) {
				$candidate = 'yunhu-' . wp_generate_password( 8, false, false );
				break;
			}
		}

		return $candidate;
	}

	/**
	 * Sanitize the configured default role against the live role list.
	 *
	 * @return string
	 */
	private function valid_role() {
		$role  = (string) $this->settings->get( 'default_role' );
		$roles = wp_roles()->roles;

		return isset( $roles[ $role ] ) ? $role : 'subscriber';
	}

	/**
	 * Abort the flow with a translated message.
	 *
	 * @param string $code      Error code.
	 * @param int    $link_user Link-mode user id (0 for anonymous logins).
	 * @param string $detail    Technical detail for logged-in users.
	 * @return void
	 */
	private function fail( $code, $link_user = 0, $detail = '' ) {
		$this->log( sprintf( '登录中止：%s %s', $code, $detail ) );

		if ( $link_user > 0 ) {
			$args = array( 'yunhu_oauth_notice' => $code );

			if ( '' !== $detail ) {
				$args['yunhu_oauth_detail'] = rawurlencode( wp_strip_all_tags( $detail ) );
			}

			$url = add_query_arg( $args, get_edit_profile_url( $link_user ) );
		} else {
			$url = add_query_arg( 'yunhu_oauth_error', $code, wp_login_url() );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Human readable text for an error code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function message( $code ) {
		$messages = array(
			'disabled'        => __( '云湖登录当前未启用。', 'wp-yunhu-oauth' ),
			'not_configured'  => __( '云湖登录尚未完成配置，请联系站点管理员。', 'wp-yunhu-oauth' ),
			'cancelled'       => __( '你取消了云湖授权。', 'wp-yunhu-oauth' ),
			'provider_error'  => __( '云湖返回了授权错误，请稍后重试。', 'wp-yunhu-oauth' ),
			'invalid_request' => __( '授权回调参数不完整，请重新发起登录。', 'wp-yunhu-oauth' ),
			'state_mismatch'  => __( '登录状态校验失败，请重新发起登录。', 'wp-yunhu-oauth' ),
			'state_expired'   => __( '授权会话已过期，请重新发起登录。', 'wp-yunhu-oauth' ),
			'session_expired' => __( '当前登录状态已变化，请重新绑定。', 'wp-yunhu-oauth' ),
			'access_denied'   => __( '你拒绝了本次授权。', 'wp-yunhu-oauth' ),
			'token_error'     => __( '换取访问令牌失败，请稍后重试或联系管理员。', 'wp-yunhu-oauth' ),
			'token_expired'   => __( '云湖授权已失效，请重新发起登录。', 'wp-yunhu-oauth' ),
			'userinfo_error'  => __( '获取云湖用户信息失败。', 'wp-yunhu-oauth' ),
			'no_id'           => __( '未拿到云湖用户 ID，请确认应用已申请 openid 权限（建议 scope 为 openid profile）。', 'wp-yunhu-oauth' ),
			'not_linked'      => __( '该云湖账号尚未绑定本站账号，请先登录后在个人资料页完成绑定。', 'wp-yunhu-oauth' ),
			'link_conflict'   => __( '该云湖账号已绑定到其他站点账号。', 'wp-yunhu-oauth' ),
			'create_failed'   => __( '创建本地账号失败，请联系站点管理员。', 'wp-yunhu-oauth' ),
			'unknown_error'   => __( '登录过程中发生未知错误，请重试。', 'wp-yunhu-oauth' ),
		);

		if ( isset( $messages[ $code ] ) ) {
			return $messages[ $code ];
		}

		return __( '登录失败，请稍后重试。', 'wp-yunhu-oauth' );
	}

	/**
	 * Show the error on the login screen.
	 *
	 * @param string $message Existing markup.
	 * @return string
	 */
	public function render_login_message( $message ) {
		if ( empty( $_GET['yunhu_oauth_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $message;
		}

		$code = sanitize_key( wp_unslash( $_GET['yunhu_oauth_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return $message . '<div id="login_error" class="yunhu-oauth-error">' . esc_html( self::message( $code ) ) . '</div>';
	}

	/**
	 * Admin-side feedback for the account linking flow.
	 *
	 * @return void
	 */
	public function render_admin_notice() {
		if ( empty( $_GET['yunhu_oauth_notice'] ) || ! current_user_can( 'read' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code   = sanitize_key( wp_unslash( $_GET['yunhu_oauth_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$detail = isset( $_GET['yunhu_oauth_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['yunhu_oauth_detail'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'linked' === $code ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( '已成功绑定云湖账号。', 'wp-yunhu-oauth' )
			);

			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s%s</p></div>',
			esc_html( self::message( $code ) ),
			'' !== $detail ? '<br /><code>' . esc_html( $detail ) . '</code>' : ''
		);
	}

	/**
	 * "使用云湖账号登录" button under the login form.
	 *
	 * @return void
	 */
	public function render_login_button() {
		if ( ! $this->settings->get( 'enabled' ) || ! $this->settings->get( 'show_on_login_form' ) ) {
			return;
		}

		if ( ! $this->settings->is_configured() ) {
			return;
		}

		$label = (string) $this->settings->get( 'button_label' );

		if ( '' === $label ) {
			$label = __( '使用云湖账号登录', 'wp-yunhu-oauth' );
		}

		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		printf(
			'<div class="yunhu-oauth-divider"><span>%s</span></div><p class="yunhu-oauth-actions"><a class="button button-large yunhu-oauth-button" href="%s" rel="nofollow">%s</a></p>',
			esc_html__( '或', 'wp-yunhu-oauth' ),
			esc_url( $this->start_url( is_string( $redirect_to ) ? $redirect_to : '' ) ),
			esc_html( $label )
		);
	}

	/**
	 * Shortcode: [yunhu_login label="用云湖登录" redirect="/account" class="my-btn"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'label'    => (string) $this->settings->get( 'button_label' ),
				'redirect' => '',
				'class'    => '',
			),
			$atts,
			'yunhu_login'
		);

		if ( ! $this->settings->get( 'enabled' ) || ! $this->settings->is_configured() ) {
			return '';
		}

		if ( is_user_logged_in() ) {
			return '';
		}

		$this->enqueue_assets();

		$label = '' !== trim( (string) $atts['label'] ) ? $atts['label'] : __( '使用云湖账号登录', 'wp-yunhu-oauth' );
		$class = 'button yunhu-oauth-button';

		if ( '' !== trim( (string) $atts['class'] ) ) {
			$class .= ' ' . sanitize_html_class( $atts['class'] );
		}

		$redirect = '' !== trim( (string) $atts['redirect'] ) ? wp_validate_redirect( $atts['redirect'], '' ) : '';

		return sprintf(
			'<a class="%s" href="%s" rel="nofollow">%s</a>',
			esc_attr( $class ),
			esc_url( $this->start_url( $redirect ) ),
			esc_html( $label )
		);
	}

	/**
	 * Debug helper.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function log( $message ) {
		if ( $this->settings->get( 'debug' ) ) {
			error_log( '[云湖OAuth] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
