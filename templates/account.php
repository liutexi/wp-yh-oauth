<?php
/**
 * Template for the front-end account route (/my/ by default).
 *
 * Uses get_header()/get_footer() so the page inherits the active theme. On a
 * block theme without a header.php WordPress falls back to its bundled
 * theme-compat header, which still emits wp_head()/wp_body_open(). Sites that
 * need tighter theme integration can place [yunhu_me] in a real page instead.
 *
 * @package WP_Yunhu_OAuth
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="yunhu-me" class="yunhu-me-main">
	<div class="yunhu-me-wrap">
		<?php Yunhu_OAuth_Account::instance()->render(); ?>
	</div>
</main>
<?php
get_footer();
