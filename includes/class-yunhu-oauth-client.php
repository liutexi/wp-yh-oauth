<?php
/**
 * OAuth 2.0 HTTP client for the 云湖 open platform.
 *
 * @package WP_Yunhu_OAuth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Yunhu_OAuth_Client
 */
class Yunhu_OAuth_Client {

	/**
	 * Settings store.
	 *
	 * @var Yunhu_OAuth_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Yunhu_OAuth_Settings|null $settings Optional settings store.
	 */
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof Yunhu_OAuth_Settings ? $settings : Yunhu_OAuth_Settings::instance();
	}

	/**
	 * Build the browser authorization URL.
	 *
	 * @param string $state         Opaque anti-CSRF value.
	 * @param string $code_verifier PKCE verifier (empty to skip).
	 * @param string $redirect_uri  Registered callback URL.
	 * @return string
	 */
	public function authorize_url( $state, $code_verifier, $redirect_uri ) {
		$args = array(
			'response_type' => 'code',
			'client_id'     => $this->settings->client_id(),
			'redirect_uri'  => $redirect_uri,
			'state'         => $state,
		);

		$scope = trim( (string) $this->settings->get( 'scope' ) );
		if ( '' !== $scope ) {
			$args['scope'] = $scope;
		}

		// 云湖 (oauth2.jwzhd.com) requires PKCE S256 and does not support "plain".
		if ( '' !== $code_verifier ) {
			$args['code_challenge']        = self::challenge( $code_verifier );
			$args['code_challenge_method'] = 'S256';
		}

		/**
		 * Filter the query arguments sent to the authorization endpoint.
		 *
		 * @param array $args Query arguments.
		 */
		$args = apply_filters( 'yunhu_oauth_authorize_args', $args );

		$url = add_query_arg( $args, $this->settings->get( 'authorize_endpoint' ) );

		return (string) apply_filters( 'yunhu_oauth_authorize_url', $url, $args );
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param string $code          Authorization code.
	 * @param string $code_verifier PKCE verifier (empty when unused).
	 * @param string $redirect_uri  Same URI used in the authorization request.
	 * @return array|WP_Error Token payload on success.
	 */
	public function exchange_code( $code, $code_verifier, $redirect_uri ) {
		$body    = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $redirect_uri,
			'client_id'    => $this->settings->client_id(),
		);
		$headers = array();

		if ( 'client_secret_basic' === $this->settings->get( 'token_auth_method' ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $this->settings->client_id() . ':' . $this->settings->client_secret() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} else {
			$body['client_secret'] = $this->settings->client_secret();
		}

		if ( '' !== $code_verifier ) {
			$body['code_verifier'] = $code_verifier;
		}

		/**
		 * Filter the token request body.
		 *
		 * @param array $body Form body.
		 */
		$body = apply_filters( 'yunhu_oauth_token_request_body', $body );

		$response = $this->http(
			'POST',
			$this->settings->get( 'token_endpoint' ),
			array(
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->parse_body( $response['body'], 'token' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			return new WP_Error(
				'yunhu_token_error',
				$this->error_message( $data, __( '换取访问令牌失败。', 'wp-yunhu-oauth' ) ),
				array( 'status' => $response['status'] )
			);
		}

		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'yunhu_token_missing', __( 'Token 接口未返回 access_token。', 'wp-yunhu-oauth' ) );
		}

		return $data;
	}

	/**
	 * Fetch the authorized user's profile.
	 *
	 * @param string $access_token Access token.
	 * @return array|WP_Error Normalized profile: id, username, nickname, email, avatar.
	 */
	public function fetch_user( $access_token ) {
		$url     = $this->settings->get( 'userinfo_endpoint' );
		$headers = array();

		if ( 'query' === $this->settings->get( 'userinfo_auth' ) ) {
			$url = add_query_arg( 'access_token', $access_token, $url );
		} else {
			$headers['Authorization'] = 'Bearer ' . $access_token;
		}

		$response = $this->http(
			'GET',
			$url,
			array(
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 401 === (int) $response['status'] ) {
			return new WP_Error( 'yunhu_userinfo_unauthorized', __( '访问令牌无效、已过期或授权已被撤销（HTTP 401），请重新发起登录。', 'wp-yunhu-oauth' ) );
		}

		$data = $this->parse_body( $response['body'], 'userinfo' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			return new WP_Error(
				'yunhu_userinfo_error',
				$this->error_message( $data, __( '获取用户信息失败。', 'wp-yunhu-oauth' ) ),
				array( 'status' => $response['status'] )
			);
		}

		/**
		 * Filter the raw user payload before field mapping.
		 *
		 * @param array $data Decoded response.
		 */
		$data = apply_filters( 'yunhu_oauth_raw_user', $data );

		return $this->map_user( $data );
	}

	/**
	 * Map a provider payload onto a predictable profile array.
	 *
	 * @param array $raw Decoded userinfo response.
	 * @return array|WP_Error
	 */
	public function map_user( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'yunhu_user_invalid', __( '用户信息格式无法识别。', 'wp-yunhu-oauth' ) );
		}

		// Many Chinese platforms wrap the payload in { code, message, data: {...} }.
		if ( isset( $raw['data'] ) && is_array( $raw['data'] ) ) {
			$raw = array_merge( $raw, $raw['data'] );
		}

		$id = $this->pick(
			$raw,
			$this->field_candidates( 'id_field', array( 'user_id', 'userId', 'id', 'uid', 'userid', 'openid', 'open_id', 'sub', 'yunhu_id', 'yunhuId', 'number' ) )
		);

		if ( '' === $id ) {
			$this->log( 'userinfo 中找不到唯一 ID，返回字段：' . implode( ', ', array_keys( $raw ) ) );

			return new WP_Error( 'yunhu_user_no_id', __( '用户信息中缺少 user_id。请确认授权 scope 包含 openid（建议 openid profile），或在上方「唯一 ID 字段名」中指定正确字段。', 'wp-yunhu-oauth' ) );
		}

		$username = $this->pick(
			$raw,
			$this->field_candidates( 'username_field', array( 'username', 'user_name', 'userName', 'nickname', 'nickName', 'name', 'display_name' ) )
		);

		$nickname = $this->pick(
			$raw,
			$this->field_candidates( 'username_field', array( 'nickname', 'nickName', 'name', 'display_name', 'username', 'user_name', 'userName' ) )
		);

		$email = $this->pick(
			$raw,
			$this->field_candidates( 'email_field', array( 'email', 'mail', 'email_address' ) )
		);

		$avatar = $this->pick(
			$raw,
			$this->field_candidates( 'avatar_field', array( 'avatar', 'avatar_url', 'avatarUrl', 'headimg', 'head_img', 'head_url', 'figureurl', 'picture', 'icon' ) )
		);

		$profile = array(
			'id'       => (string) $id,
			'username' => (string) $username,
			'nickname' => (string) ( '' !== $nickname ? $nickname : $username ),
			'email'    => is_email( $email ) ? $email : '',
			'avatar'   => esc_url_raw( (string) $avatar ),
		);

		/**
		 * Filter the normalized profile.
		 *
		 * @param array $profile Normalized profile.
		 * @param array $raw     Raw payload.
		 */
		return apply_filters( 'yunhu_oauth_user_profile', $profile, $raw );
	}

	/**
	 * Candidate field names: an explicit setting wins over the detected defaults.
	 *
	 * @param string $setting   Setting key holding a manual field name.
	 * @param array  $fallbacks Default candidates.
	 * @return array
	 */
	private function field_candidates( $setting, $fallbacks ) {
		$configured = trim( (string) $this->settings->get( $setting ) );

		if ( '' !== $configured ) {
			return array( $configured );
		}

		return $fallbacks;
	}

	/**
	 * Return the first non-empty scalar value from a set of keys.
	 *
	 * @param array $data Raw payload.
	 * @param array $keys Candidate keys.
	 * @return string
	 */
	private function pick( $data, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$value = trim( (string) $data[ $key ] );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * Perform an HTTP request and return status plus raw body.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Target URL.
	 * @param array  $args   Request arguments.
	 * @return array|WP_Error
	 */
	private function http( $method, $url, $args ) {
		$url = trim( (string) $url );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'yunhu_bad_endpoint', __( '端点地址无效，请检查插件设置。', 'wp-yunhu-oauth' ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'sslverify'   => true,
				'headers'     => array(),
			)
		);

		$args['headers'] = array_merge(
			array(
				'Accept'     => 'application/json',
				'User-Agent' => 'WP-Yunhu-OAuth/' . YUNHU_OAUTH_VERSION . '; ' . home_url( '/' ),
			),
			(array) $args['headers']
		);

		/**
		 * Filter the outgoing HTTP arguments.
		 *
		 * @param array  $args   Request arguments.
		 * @param string $url    Target URL.
		 * @param string $method HTTP method.
		 */
		$args = apply_filters( 'yunhu_oauth_http_args', $args, $url, $method );

		$this->log( sprintf( '%s %s | args: %s', $method, $url, wp_json_encode( $this->redact( $args ) ) ) );

		$response = 'POST' === $method
			? wp_remote_post( $url, $args )
			: wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'HTTP 错误：' . $response->get_error_message() );

			return new WP_Error(
				'yunhu_http_error',
				sprintf(
					/* translators: %s: transport error message */
					__( '无法连接云湖接口：%s', 'wp-yunhu-oauth' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		$this->log( sprintf( '响应 %d：%s', $status, $this->truncate( $body ) ) );

		return array(
			'status' => $status,
			'body'   => $body,
		);
	}

	/**
	 * Decode a response body as JSON, falling back to form encoding.
	 *
	 * @param string $body    Raw body.
	 * @param string $context Log context.
	 * @return array|WP_Error
	 */
	private function parse_body( $body, $context ) {
		$body = trim( (string) $body );

		if ( '' === $body ) {
			return new WP_Error( 'yunhu_empty_response', __( '云湖接口返回了空响应。', 'wp-yunhu-oauth' ) );
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			$parsed = array();
			parse_str( $body, $parsed );
			$data = $parsed;
		}

		if ( ! is_array( $data ) || ! $data ) {
			$this->log( sprintf( '%s 响应无法解析：%s', $context, $this->truncate( $body ) ) );

			return new WP_Error( 'yunhu_bad_response', __( '无法解析云湖接口返回的数据。', 'wp-yunhu-oauth' ) );
		}

		return $data;
	}

	/**
	 * Extract a human readable error out of a provider error payload.
	 *
	 * @param array  $data     Decoded payload.
	 * @param string $fallback Fallback message.
	 * @return string
	 */
	private function error_message( $data, $fallback ) {
		foreach ( array( 'error_description', 'message', 'msg', 'error', 'errmsg' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				return (string) $data[ $key ];
			}
		}

		return $fallback;
	}

	/**
	 * Strip secrets before logging.
	 *
	 * @param array $args Request arguments.
	 * @return array
	 */
	private function redact( $args ) {
		$copy = $args;

		if ( isset( $copy['body'] ) && is_array( $copy['body'] ) ) {
			foreach ( array( 'client_secret', 'code', 'code_verifier' ) as $key ) {
				if ( isset( $copy['body'][ $key ] ) ) {
					$copy['body'][ $key ] = '***';
				}
			}
		}

		if ( isset( $copy['headers']['Authorization'] ) ) {
			$copy['headers']['Authorization'] = '***';
		}

		return $copy;
	}

	/**
	 * Truncate long log lines.
	 *
	 * @param string $value Raw value.
	 * @param int    $limit Max length.
	 * @return string
	 */
	private function truncate( $value, $limit = 800 ) {
		$value = (string) $value;

		return strlen( $value ) > $limit ? substr( $value, 0, $limit ) . '…' : $value;
	}

	/**
	 * Write a debug line when enabled.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function log( $message ) {
		if ( $this->settings instanceof Yunhu_OAuth_Settings && $this->settings->get( 'debug' ) ) {
			error_log( '[云湖OAuth] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Generate a PKCE code verifier.
	 *
	 * @return string
	 */
	public static function create_verifier() {
		return self::base64url( random_bytes( 48 ) );
	}

	/**
	 * Derive the S256 challenge for a verifier.
	 *
	 * @param string $verifier Code verifier.
	 * @return string
	 */
	public static function challenge( $verifier ) {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Base64url encode without padding.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	public static function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
