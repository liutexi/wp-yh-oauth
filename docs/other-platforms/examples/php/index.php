<?php
/**
 * 云湖 OAuth2 登录示例（纯 PHP，无 Composer 依赖）
 *
 * 用法：
 *   YUNHU_CLIENT_ID=xxx YUNHU_CLIENT_SECRET=xxx \
 *   YUNHU_REDIRECT_URI=http://127.0.0.1:9000/ php -S 127.0.0.1:9000 index.php
 *
 * 回调地址需在云湖应用配置中登记为 YUNHU_REDIRECT_URI 的完整值。
 */

declare(strict_types=1);

session_start();

const AUTHORIZE = 'https://oauth2.jwzhd.com/oauth/authorize';
const TOKEN     = 'https://oauth2.jwzhd.com/oauth/token';
const USERINFO  = 'https://oauth2.jwzhd.com/api/userinfo';

$clientId     = (string) ( getenv( 'YUNHU_CLIENT_ID' ) ?: '' );
$clientSecret = (string) ( getenv( 'YUNHU_CLIENT_SECRET' ) ?: '' );
$redirectUri  = (string) ( getenv( 'YUNHU_REDIRECT_URI' ) ?: 'http://127.0.0.1:9000/' );

/**
 * Base64URL（无填充），用于 verifier 与 challenge。
 */
function b64url( string $raw ): string {
	return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
}

/**
 * application/x-www-form-urlencoded POST。
 */
function post_form( string $url, array $fields ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query( $fields ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HTTPHEADER     => array( 'Accept: application/json' ),
		)
	);
	$body = (string) curl_exec( $ch );
	curl_close( $ch );

	return json_decode( $body, true ) ?: array();
}

/**
 * 带 Bearer 令牌的 GET。
 */
function get_json( string $url, string $token ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HTTPHEADER     => array( 'Authorization: Bearer ' . $token, 'Accept: application/json' ),
		)
	);
	$body = (string) curl_exec( $ch );
	curl_close( $ch );

	return json_decode( $body, true ) ?: array();
}

// ---- 1. 发起授权 ----------------------------------------------------------
if ( isset( $_GET['login'] ) ) {
	$state    = bin2hex( random_bytes( 32 ) );   // 64 字符，满足 32–128
	$verifier = b64url( random_bytes( 48 ) );

	$_SESSION['yh_state']    = $state;
	$_SESSION['yh_verifier'] = $verifier;
	$_SESSION['yh_expires']  = time() + 600;

	$url = AUTHORIZE . '?' . http_build_query(
		array(
			'response_type'         => 'code',
			'client_id'             => $clientId,
			'redirect_uri'          => $redirectUri,
			'scope'                 => 'openid profile',
			'state'                 => $state,
			'code_challenge'        => b64url( hash( 'sha256', $verifier, true ) ),
			'code_challenge_method' => 'S256',
		)
	);

	header( 'Location: ' . $url, true, 302 );
	exit;
}

// ---- 2. 处理回调 ----------------------------------------------------------
if ( isset( $_GET['code'] ) || isset( $_GET['error'] ) ) {
	$state    = (string) ( $_GET['state'] ?? '' );
	$saved    = (string) ( $_SESSION['yh_state'] ?? '' );
	$verifier = (string) ( $_SESSION['yh_verifier'] ?? '' );
	$expires  = (int) ( $_SESSION['yh_expires'] ?? 0 );

	unset( $_SESSION['yh_state'], $_SESSION['yh_verifier'], $_SESSION['yh_expires'] );

	if ( '' === $state || '' === $saved || ! hash_equals( $saved, $state ) || time() > $expires ) {
		http_response_code( 400 );
		exit( 'state 校验失败或已过期，请重新登录' );
	}

	if ( isset( $_GET['error'] ) ) {
		http_response_code( 400 );
		exit( 'access_denied' === $_GET['error'] ? '你取消了授权' : '授权失败' );
	}

	$token = post_form(
		TOKEN,
		array(
			'grant_type'    => 'authorization_code',
			'code'          => (string) $_GET['code'],
			'redirect_uri'  => $redirectUri,
			'client_id'     => $clientId,
			'client_secret' => $clientSecret,
			'code_verifier' => $verifier,
		)
	);

	if ( empty( $token['access_token'] ) ) {
		http_response_code( 502 );
		exit( '换取令牌失败：' . ( $token['error_description'] ?? $token['error'] ?? 'unknown' ) );
	}

	$user = get_json( USERINFO, (string) $token['access_token'] );

	if ( empty( $user['user_id'] ) ) {
		http_response_code( 502 );
		exit( '获取用户失败' );
	}

	// ---- 3. 建立你自己的会话（示例直接放 session，生产请映射到你的用户体系）----
	session_regenerate_id( true );
	$_SESSION['uid']      = (string) $user['user_id'];
	$_SESSION['nickname'] = (string) ( $user['nickname'] ?? '' );
	$_SESSION['avatar']   = (string) ( $user['avatar_url'] ?? '' );

	header( 'Location: ' . strtok( $_SERVER['REQUEST_URI'], '?' ), true, 302 );
	exit;
}

// ---- 4. 首页 --------------------------------------------------------------
header( 'Content-Type: text/html; charset=utf-8' );

if ( ! empty( $_SESSION['uid'] ) ) {
	printf(
		'<p>已登录：%s（%s）</p><p><a href="?logout=1">退出</a></p>',
		htmlspecialchars( (string) $_SESSION['nickname'], ENT_QUOTES, 'UTF-8' ),
		htmlspecialchars( (string) $_SESSION['uid'], ENT_QUOTES, 'UTF-8' )
	);
	if ( ! empty( $_SESSION['avatar'] ) ) {
		printf( '<p><small>头像原始地址：%s</small></p>', htmlspecialchars( (string) $_SESSION['avatar'], ENT_QUOTES, 'UTF-8' ) );
	}
} else {
	echo '<p><a href="?login=1">使用云湖账号登录</a></p>';
}
