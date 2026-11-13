<?php
/**
 * Settings storage and admin screen.
 *
 * @package WP_Yunhu_OAuth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Yunhu_OAuth_Settings
 */
class Yunhu_OAuth_Settings {

	const OPTION = 'yunhu_oauth_settings';
	const PAGE   = 'yunhu-oauth';
	const GROUP  = 'yunhu_oauth_group';

	/**
	 * Singleton instance.
	 *
	 * @var Yunhu_OAuth_Settings|null
	 */
	private static $instance = null;

	/**
	 * Cached settings array.
	 *
	 * @var array|null
	 */
	private $data = null;

	/**
	 * Get instance.
	 *
	 * @return Yunhu_OAuth_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'            => 1,
			'client_id'          => '',
			'client_secret'      => '',
			'authorize_endpoint' => 'https://oauth2.jwzhd.com/oauth/authorize',
			'token_endpoint'     => 'https://oauth2.jwzhd.com/oauth/token',
			'userinfo_endpoint'  => 'https://oauth2.jwzhd.com/api/userinfo',
			'scope'              => 'openid profile',
			'callback_style'     => 'rest',
			'use_pkce'           => 1,
			'token_auth_method'  => 'client_secret_post',
			'userinfo_auth'      => 'bearer',
			'button_label'       => '使用云湖账号登录',
			'show_on_login_form' => 1,
			'auto_register'      => 1,
			'default_role'       => 'subscriber',
			'link_by_email'      => 0,
			'sync_profile'       => 1,
			'cache_avatar'       => 1,
			'avatar_referer'     => 'https://chat-go.jwzhd.com/',
			'id_field'           => '',
			'username_field'     => '',
			'email_field'        => '',
			'avatar_field'       => '',
			'debug'              => 0,
		);
	}

	/**
	 * All settings, merged with defaults and filterable.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->data ) {
			$stored     = get_option( self::OPTION, array() );
			$stored     = is_array( $stored ) ? $stored : array();
			$this->data = wp_parse_args( $stored, self::defaults() );

			/**
			 * Filter the resolved plugin settings.
			 *
			 * @param array $data Settings array.
			 */
			$this->data = apply_filters( 'yunhu_oauth_settings', $this->data );
		}

		return $this->data;
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public function get( $key, $default = '' ) {
		$data = $this->all();

		if ( array_key_exists( $key, $data ) ) {
			return $data[ $key ];
		}

		return $default;
	}

	/**
	 * Client ID, allowing a wp-config.php constant override.
	 *
	 * @return string
	 */
	public function client_id() {
		if ( defined( 'YUNHU_OAUTH_CLIENT_ID' ) && YUNHU_OAUTH_CLIENT_ID ) {
			return (string) YUNHU_OAUTH_CLIENT_ID;
		}

		return (string) $this->get( 'client_id' );
	}

	/**
	 * Client secret, allowing a wp-config.php constant override.
	 *
	 * Keeping the secret out of the database is the safer deployment.
	 *
	 * @return string
	 */
	public function client_secret() {
		if ( defined( 'YUNHU_OAUTH_CLIENT_SECRET' ) && YUNHU_OAUTH_CLIENT_SECRET ) {
			return (string) YUNHU_OAUTH_CLIENT_SECRET;
		}

		return (string) $this->get( 'client_secret' );
	}

	/**
	 * Whether the plugin has everything it needs to talk to the provider.
	 *
	 * @return bool
	 */
	public function is_configured() {
		$required = array(
			$this->client_id(),
			$this->client_secret(),
			$this->get( 'authorize_endpoint' ),
			$this->get( 'token_endpoint' ),
			$this->get( 'userinfo_endpoint' ),
		);

		foreach ( $required as $value ) {
			if ( '' === trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The redirect URI that must be registered in the 云湖开放平台 console.
	 *
	 * The platform matches the full callback URL byte-for-byte, so this exact
	 * string is what the user has to register. The REST route is the default
	 * because it carries no query string, which keeps naive providers (those
	 * that append "?code=..." without checking for an existing "?") working.
	 *
	 * @return string
	 */
	public function callback_url() {
		if ( 'admin_post' === $this->get( 'callback_style' ) ) {
			return add_query_arg( 'action', 'yunhu_oauth_callback', admin_url( 'admin-post.php' ) );
		}

		return rest_url( 'yunhu-oauth/v1/callback' );
	}

	/**
	 * The same callback in the other supported style, shown as a fallback.
	 *
	 * @return string
	 */
	public function alt_callback_url() {
		if ( 'admin_post' === $this->get( 'callback_style' ) ) {
			return rest_url( 'yunhu-oauth/v1/callback' );
		}

		return add_query_arg( 'action', 'yunhu_oauth_callback', admin_url( 'admin-post.php' ) );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the settings page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( '云湖 OAuth2 登录', 'wp-yunhu-oauth' ),
			__( '云湖登录', 'wp-yunhu-oauth' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$old      = get_option( self::OPTION, array() );
		$old      = is_array( $old ) ? $old : array();
		$defaults = self::defaults();
		$out      = array();

		$out['enabled']            = empty( $input['enabled'] ) ? 0 : 1;
		$out['show_on_login_form'] = empty( $input['show_on_login_form'] ) ? 0 : 1;
		$out['auto_register']      = empty( $input['auto_register'] ) ? 0 : 1;
		$out['link_by_email']      = empty( $input['link_by_email'] ) ? 0 : 1;
		$out['sync_profile']       = empty( $input['sync_profile'] ) ? 0 : 1;
		$out['cache_avatar']       = empty( $input['cache_avatar'] ) ? 0 : 1;
		$out['use_pkce']           = empty( $input['use_pkce'] ) ? 0 : 1;
		$out['debug']              = empty( $input['debug'] ) ? 0 : 1;

		$out['client_id'] = isset( $input['client_id'] ) ? sanitize_text_field( wp_unslash( $input['client_id'] ) ) : '';

		$secret = isset( $input['client_secret'] ) ? trim( (string) wp_unslash( $input['client_secret'] ) ) : '';
		if ( '' === $secret ) {
			// An empty field means "keep the stored secret".
			$secret = isset( $old['client_secret'] ) ? (string) $old['client_secret'] : '';
		}
		$out['client_secret'] = $secret;

		foreach ( array( 'authorize_endpoint', 'token_endpoint', 'userinfo_endpoint' ) as $key ) {
			$value     = isset( $input[ $key ] ) ? trim( (string) wp_unslash( $input[ $key ] ) ) : '';
			$out[ $key ] = '' === $value ? $defaults[ $key ] : esc_url_raw( $value, array( 'https', 'http' ) );
		}

		$out['scope'] = isset( $input['scope'] ) ? sanitize_text_field( wp_unslash( $input['scope'] ) ) : '';

		$out['button_label'] = isset( $input['button_label'] ) ? sanitize_text_field( wp_unslash( $input['button_label'] ) ) : $defaults['button_label'];
		if ( '' === $out['button_label'] ) {
			$out['button_label'] = $defaults['button_label'];
		}

		$role                 = isset( $input['default_role'] ) ? sanitize_key( wp_unslash( $input['default_role'] ) ) : $defaults['default_role'];
		$roles                = wp_roles()->roles;
		$out['default_role']  = isset( $roles[ $role ] ) ? $role : $defaults['default_role'];

		$out['callback_style']    = 'admin_post' === ( $input['callback_style'] ?? '' ) ? 'admin_post' : 'rest';
		$out['token_auth_method'] = 'client_secret_basic' === ( $input['token_auth_method'] ?? '' ) ? 'client_secret_basic' : 'client_secret_post';
		$out['userinfo_auth']     = 'query' === ( $input['userinfo_auth'] ?? '' ) ? 'query' : 'bearer';

		foreach ( array( 'id_field', 'username_field', 'email_field', 'avatar_field' ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
		}

		$referer              = isset( $input['avatar_referer'] ) ? trim( (string) wp_unslash( $input['avatar_referer'] ) ) : '';
		$out['avatar_referer'] = '' === $referer ? '' : esc_url_raw( $referer, array( 'https', 'http' ) );

		if ( $out['enabled'] ) {
			$check = wp_parse_args( $out, $defaults );
			$missing = array();
			foreach ( array( 'client_id' => 'Client ID', 'client_secret' => 'Client Secret', 'authorize_endpoint' => '授权端点', 'token_endpoint' => 'Token 端点', 'userinfo_endpoint' => '用户信息端点' ) as $key => $label ) {
				if ( '' === trim( (string) $check[ $key ] ) && ! ( 'client_id' === $key && defined( 'YUNHU_OAUTH_CLIENT_ID' ) ) && ! ( 'client_secret' === $key && defined( 'YUNHU_OAUTH_CLIENT_SECRET' ) ) ) {
					$missing[] = $label;
				}
			}
			if ( $missing ) {
				add_settings_error(
					self::OPTION,
					'yunhu_oauth_incomplete',
					sprintf(
						/* translators: %s: comma separated field names */
						__( '已启用云湖登录，但以下配置为空：%s', 'wp-yunhu-oauth' ),
						implode( '、', $missing )
					),
					'warning'
				);
			}
		}

		// Force a fresh read next time.
		$this->data = null;

		return $out;
	}

	/**
	 * Render a text input row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @param string $type        Input type.
	 * @param string $placeholder Placeholder.
	 * @return void
	 */
	private function text_row( $key, $label, $description = '', $type = 'text', $placeholder = '' ) {
		$value    = (string) $this->get( $key );
		$readonly = '';
		$note     = '';

		// Never echo a stored secret back into the page source.
		if ( 'client_secret' === $key ) {
			$has_secret = '' !== $this->client_secret();
			$value      = '';
			$placeholder = $has_secret
				? __( '已保存，留空表示不修改', 'wp-yunhu-oauth' )
				: __( '粘贴 Client Secret', 'wp-yunhu-oauth' );
		}

		if ( 'client_secret' === $key && defined( 'YUNHU_OAUTH_CLIENT_SECRET' ) && YUNHU_OAUTH_CLIENT_SECRET ) {
			$readonly = ' readonly="readonly"';
			$note     = __( '已在 wp-config.php 中通过 YUNHU_OAUTH_CLIENT_SECRET 常量定义，此处不生效。', 'wp-yunhu-oauth' );
		}
		if ( 'client_id' === $key && defined( 'YUNHU_OAUTH_CLIENT_ID' ) && YUNHU_OAUTH_CLIENT_ID ) {
			$readonly = ' readonly="readonly"';
			$note     = __( '已在 wp-config.php 中通过 YUNHU_OAUTH_CLIENT_ID 常量定义，此处不生效。', 'wp-yunhu-oauth' );
		}
		?>
		<tr>
			<th scope="row"><label for="yunhu-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					id="yunhu-<?php echo esc_attr( $key ); ?>"
					name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					class="regular-text code"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					autocomplete="<?php echo 'password' === $type ? 'new-password' : 'off'; ?>"
					<?php echo $readonly; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				/>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $note ) : ?>
					<p class="description"><strong><?php echo esc_html( $note ); ?></strong></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a checkbox row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function checkbox_row( $key, $label, $description = '' ) {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label for="yunhu-<?php echo esc_attr( $key ); ?>">
					<input
						type="checkbox"
						id="yunhu-<?php echo esc_attr( $key ); ?>"
						name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>"
						value="1"
						<?php checked( (int) $this->get( $key ), 1 ); ?>
					/>
					<?php echo wp_kses_post( $description ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$roles        = wp_roles()->roles;
		$is_ready     = $this->is_configured();
		$callback     = $this->callback_url();
		$alt_callback = $this->alt_callback_url();
		$home         = home_url();
		$is_loopback  = (bool) preg_match( '#^http://(127\.0\.0\.1|localhost|\[::1\])(?::\d+)?(/|$)#i', $home );
		$insecure     = 0 === strpos( $home, 'http://' ) && ! $is_loopback;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '云湖 OAuth2 登录', 'wp-yunhu-oauth' ); ?></h1>

			<?php settings_errors( self::OPTION ); ?>

			<?php if ( $insecure ) : ?>
				<div class="notice notice-error inline">
					<p>
						<strong><?php esc_html_e( '本站未启用 HTTPS。', 'wp-yunhu-oauth' ); ?></strong>
						<?php esc_html_e( '云湖要求生产环境的回调地址必须使用 HTTPS，HTTP 只允许 127.0.0.1、localhost、::1 等本机回环地址。当前配置下授权和换取令牌都会被拒绝。', 'wp-yunhu-oauth' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="notice notice-<?php echo $is_ready ? 'success' : 'warning'; ?> inline">
				<p>
					<?php if ( $is_ready ) : ?>
						<?php esc_html_e( '配置已完成，登录入口已启用。', 'wp-yunhu-oauth' ); ?>
					<?php else : ?>
						<?php esc_html_e( '尚未配置完整：请填写 Client ID / Client Secret 与三个端点后再启用。', 'wp-yunhu-oauth' ); ?>
					<?php endif; ?>
				</p>
				<p>
					<strong><?php esc_html_e( '回调地址（需原样登记到云湖应用配置）', 'wp-yunhu-oauth' ); ?></strong><br />
					<code id="yunhu-callback-url"><?php echo esc_html( $callback ); ?></code>
				</p>
				<p class="description">
					<?php esc_html_e( '备选地址（当前未使用）：', 'wp-yunhu-oauth' ); ?>
					<code><?php echo esc_html( $alt_callback ); ?></code>
				</p>
				<p class="description">
					<?php esc_html_e( '云湖按完整 URL 精确匹配：协议、主机、端口、路径、查询参数、末尾斜杠任一不同都会导致授权失败。授权请求与 token 请求必须使用同一个原始地址。', 'wp-yunhu-oauth' ); ?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( '基础开关', 'wp-yunhu-oauth' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox_row( 'enabled', __( '启用', 'wp-yunhu-oauth' ), __( '关闭后前端不再显示云湖登录入口，已发起的授权请求也无法完成回调。', 'wp-yunhu-oauth' ) );
					$this->checkbox_row( 'show_on_login_form', __( '登录页显示', 'wp-yunhu-oauth' ), __( '在 wp-login.php 登录表单下方自动插入登录按钮。', 'wp-yunhu-oauth' ) );
					?>
					<tr>
						<th scope="row"><label for="yunhu-button_label"><?php esc_html_e( '按钮文案', 'wp-yunhu-oauth' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="yunhu-button_label" name="<?php echo esc_attr( self::OPTION . '[button_label]' ); ?>" value="<?php echo esc_attr( (string) $this->get( 'button_label' ) ); ?>" />
							<p class="description"><?php esc_html_e( '也可用短代码自定义：[yunhu_login label="用云湖账号登录"]', 'wp-yunhu-oauth' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '应用凭据', 'wp-yunhu-oauth' ); ?></h2>
				<p class="description">
					<?php esc_html_e( '在云湖开放平台创建应用后获取。建议把 Client Secret 写进 wp-config.php 的 YUNHU_OAUTH_CLIENT_SECRET 常量，避免入库。', 'wp-yunhu-oauth' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<?php
					$this->text_row( 'client_id', __( 'Client ID', 'wp-yunhu-oauth' ) );
					$this->text_row( 'client_secret', __( 'Client Secret', 'wp-yunhu-oauth' ), __( '留空表示保持原有密钥不变。', 'wp-yunhu-oauth' ), 'password' );
					?>
				</table>

				<h2><?php esc_html_e( '端点与授权参数', 'wp-yunhu-oauth' ); ?></h2>
				<p class="description">
					<?php esc_html_e( '默认值对应云湖官方 OAuth2 服务（oauth2.jwzhd.com），一般无需修改；若官方调整地址，改这里即可，无需改代码。', 'wp-yunhu-oauth' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<?php
					$this->text_row( 'authorize_endpoint', __( '授权端点', 'wp-yunhu-oauth' ), __( '浏览器跳转到该地址完成云湖登录与授权确认。', 'wp-yunhu-oauth' ) );
					$this->text_row( 'token_endpoint', __( 'Token 端点', 'wp-yunhu-oauth' ), __( '服务端用 code 兑换 access_token，请求体为 application/x-www-form-urlencoded。', 'wp-yunhu-oauth' ) );
					$this->text_row( 'userinfo_endpoint', __( '用户信息端点', 'wp-yunhu-oauth' ), __( '携带 Bearer 令牌获取 user_id、nickname、avatar_url。', 'wp-yunhu-oauth' ) );
					$this->text_row( 'scope', __( 'Scope', 'wp-yunhu-oauth' ), __( 'openid 返回 user_id，profile 返回 nickname 与 avatar_url，建议保持「openid profile」；留空时服务端默认也是它。', 'wp-yunhu-oauth' ), 'text', 'openid profile' );
					?>
					<tr>
						<th scope="row"><label for="yunhu-token_auth_method"><?php esc_html_e( '客户端认证方式', 'wp-yunhu-oauth' ); ?></label></th>
						<td>
							<select id="yunhu-token_auth_method" name="<?php echo esc_attr( self::OPTION . '[token_auth_method]' ); ?>">
								<option value="client_secret_post" <?php selected( 'client_secret_post', $this->get( 'token_auth_method' ) ); ?>><?php esc_html_e( 'client_secret_post（放在表单体里）', 'wp-yunhu-oauth' ); ?></option>
								<option value="client_secret_basic" <?php selected( 'client_secret_basic', $this->get( 'token_auth_method' ) ); ?>><?php esc_html_e( 'client_secret_basic（HTTP Basic 头）', 'wp-yunhu-oauth' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( '换 Token 时密钥的传递方式。多数平台用第一种；若返回 invalid_client，试第二种。', 'wp-yunhu-oauth' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="yunhu-userinfo_auth"><?php esc_html_e( '用户信息凭据', 'wp-yunhu-oauth' ); ?></label></th>
						<td>
							<select id="yunhu-userinfo_auth" name="<?php echo esc_attr( self::OPTION . '[userinfo_auth]' ); ?>">
								<option value="bearer" <?php selected( 'bearer', $this->get( 'userinfo_auth' ) ); ?>><?php esc_html_e( 'Authorization: Bearer（推荐）', 'wp-yunhu-oauth' ); ?></option>
								<option value="query" <?php selected( 'query', $this->get( 'userinfo_auth' ) ); ?>><?php esc_html_e( 'access_token 作为查询参数', 'wp-yunhu-oauth' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '回调与安全', 'wp-yunhu-oauth' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="yunhu-callback_style"><?php esc_html_e( '回调实现方式', 'wp-yunhu-oauth' ); ?></label></th>
						<td>
							<select id="yunhu-callback_style" name="<?php echo esc_attr( self::OPTION . '[callback_style]' ); ?>">
								<option value="rest" <?php selected( 'rest', $this->get( 'callback_style' ) ); ?>><?php esc_html_e( 'REST 路由（推荐，地址不含查询参数）', 'wp-yunhu-oauth' ); ?></option>
								<option value="admin_post" <?php selected( 'admin_post', $this->get( 'callback_style' ) ); ?>><?php esc_html_e( 'admin-post.php（站点禁用 REST API 时使用）', 'wp-yunhu-oauth' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( '切换后顶部显示的回调地址会随之改变，记得同步更新云湖应用里登记的地址。', 'wp-yunhu-oauth' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'PKCE', 'wp-yunhu-oauth' ); ?></th>
						<td>
							<p><?php esc_html_e( '已强制启用 S256 PKCE（云湖要求，且不支持 plain）。每次授权生成独立 verifier，只保存在服务端瞬态中，回调处理后立即销毁。', 'wp-yunhu-oauth' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '账号处理', 'wp-yunhu-oauth' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox_row( 'auto_register', __( '自动注册', 'wp-yunhu-oauth' ), __( '云湖账号首次登录时自动创建本地账号。关闭后仅允许已绑定用户登录。', 'wp-yunhu-oauth' ) );
					$this->checkbox_row( 'sync_profile', __( '同步资料', 'wp-yunhu-oauth' ), __( '每次登录同步昵称与头像。', 'wp-yunhu-oauth' ) );
					$this->checkbox_row( 'cache_avatar', __( '本地化头像', 'wp-yunhu-oauth' ), __( '云湖图片有防盗链，开启后登录时把头像下载到 uploads 目录并本地输出，站内显示才不会 403。关闭则直接引用远程地址（可能显示不出来）。', 'wp-yunhu-oauth' ) );
					?>
					<tr>
						<th scope="row"><?php esc_html_e( '关于邮箱', 'wp-yunhu-oauth' ); ?></th>
						<td>
							<p class="description">
								<?php esc_html_e( '云湖 userinfo 只返回 user_id、nickname、avatar_url，不返回邮箱，因此本插件不支持「按邮箱合并账号」；账号一律以 user_id 作为关联标识，自动注册的账号邮箱为空。', 'wp-yunhu-oauth' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="yunhu-default_role"><?php esc_html_e( '新用户角色', 'wp-yunhu-oauth' ); ?></label></th>
						<td>
							<select id="yunhu-default_role" name="<?php echo esc_attr( self::OPTION . '[default_role]' ); ?>">
								<?php foreach ( $roles as $role_key => $role_obj ) : ?>
									<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $role_key, $this->get( 'default_role' ) ); ?>>
										<?php echo esc_html( translate_user_role( $role_obj['name'] ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( '自动注册账号的默认角色，建议保持「订阅者」。', 'wp-yunhu-oauth' ); ?></p>
						</td>
					</tr>
					<?php
					$this->text_row( 'id_field', __( '唯一 ID 字段名', 'wp-yunhu-oauth' ), __( '留空自动识别（id / uid / user_id / openid / sub 等）。若用户信息接口返回的字段名不同，在此填写。', 'wp-yunhu-oauth' ) );
					$this->text_row( 'username_field', __( '用户名字段名', 'wp-yunhu-oauth' ), __( '留空自动识别（username / name / nickname 等）。', 'wp-yunhu-oauth' ) );
					$this->text_row( 'email_field', __( '邮箱字段名', 'wp-yunhu-oauth' ), __( '留空自动识别（email / mail）。', 'wp-yunhu-oauth' ) );
					$this->text_row( 'avatar_field', __( '头像字段名', 'wp-yunhu-oauth' ), __( '留空自动识别（avatar / avatar_url / headimg 等）。', 'wp-yunhu-oauth' ) );
					$this->text_row( 'avatar_referer', __( '头像请求 Referer', 'wp-yunhu-oauth' ), __( '云湖防盗链只放行自家域名，默认使用 https://chat-go.jwzhd.com/（云湖客户端域名）。下载被 403 时会自动再尝试图片自身域名与不带 Referer，一般无需改动。', 'wp-yunhu-oauth' ), 'text', 'https://chat-go.jwzhd.com/' );
					$this->checkbox_row( 'debug', __( '调试日志', 'wp-yunhu-oauth' ), __( '把接口请求与响应写入 PHP error_log，便于排查。上线后请关闭。', 'wp-yunhu-oauth' ) );
					?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
