<?php
/**
 * Plugin Name:       云湖 OAuth2 登录
 * Plugin URI:        https://oauth2.jwzhd.com
 * Description:       通过云湖账号（云湖 OAuth2 授权码 + 强制 PKCE S256）登录 WordPress，支持自动注册、账号绑定、昵称与头像同步。
 * Version:           0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Yunhu OAuth Contributors
 * License:           AGPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:       wp-yunhu-oauth
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'YUNHU_OAUTH_VERSION', '0.1' );
define( 'YUNHU_OAUTH_FILE', __FILE__ );
define( 'YUNHU_OAUTH_DIR', plugin_dir_path( __FILE__ ) );
define( 'YUNHU_OAUTH_URL', plugin_dir_url( __FILE__ ) );

require_once YUNHU_OAUTH_DIR . 'includes/class-yunhu-oauth-settings.php';
require_once YUNHU_OAUTH_DIR . 'includes/class-yunhu-oauth-client.php';
require_once YUNHU_OAUTH_DIR . 'includes/class-yunhu-oauth-avatar.php';
require_once YUNHU_OAUTH_DIR . 'includes/class-yunhu-oauth-login.php';
require_once YUNHU_OAUTH_DIR . 'includes/class-yunhu-oauth-profile.php';

/**
 * Boot the plugin once all plugins are loaded so other plugins can filter our settings.
 */
function yunhu_oauth_bootstrap() {
	load_plugin_textdomain( 'wp-yunhu-oauth', false, dirname( plugin_basename( YUNHU_OAUTH_FILE ) ) . '/languages' );

	Yunhu_OAuth_Settings::instance()->hooks();
	Yunhu_OAuth_Login::instance()->hooks();
	Yunhu_OAuth_Profile::instance()->hooks();
}
add_action( 'plugins_loaded', 'yunhu_oauth_bootstrap' );

/**
 * Activation: seed default options.
 */
function yunhu_oauth_activate() {
	if ( false === get_option( Yunhu_OAuth_Settings::OPTION ) ) {
		add_option( Yunhu_OAuth_Settings::OPTION, Yunhu_OAuth_Settings::defaults() );
	}
}
register_activation_hook( __FILE__, 'yunhu_oauth_activate' );

/**
 * Convenience links on the plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function yunhu_oauth_action_links( $links ) {
	$settings_url = admin_url( 'options-general.php?page=' . Yunhu_OAuth_Settings::PAGE );
	array_unshift( $links, '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( '设置', 'wp-yunhu-oauth' ) . '</a>' );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'yunhu_oauth_action_links' );
