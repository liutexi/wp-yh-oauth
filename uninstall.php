<?php
/**
 * Uninstall routine.
 *
 * Removes plugin options only. User meta (绑定关系) is intentionally kept so that
 * re-installing the plugin does not lose the account mappings.
 *
 * @package WP_Yunhu_OAuth
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'yunhu_oauth_settings' );

// Drop leftover state transients.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_yunhu_oauth_st_%' OR option_name LIKE '_transient_timeout_yunhu_oauth_st_%'"
);
