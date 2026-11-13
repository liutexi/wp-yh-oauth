<?php
/**
 * Profile screen: show binding status, allow linking and unlinking.
 *
 * @package WP_Yunhu_OAuth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Yunhu_OAuth_Profile
 */
class Yunhu_OAuth_Profile {

	/**
	 * Singleton instance.
	 *
	 * @var Yunhu_OAuth_Profile|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Yunhu_OAuth_Profile
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'show_user_profile', array( $this, 'render_section' ) );
		add_action( 'edit_user_profile', array( $this, 'render_section' ) );
		add_action( 'admin_post_yunhu_oauth_unlink', array( $this, 'handle_unlink' ) );
		add_filter( 'pre_get_avatar_data', array( $this, 'filter_avatar' ), 10, 2 );
		add_filter( 'get_avatar', array( $this, 'filter_avatar_html' ), 10, 5 );
	}

	/**
	 * Render the binding block on the profile / user-edit screen.
	 *
	 * @param WP_User $user User being edited.
	 * @return void
	 */
	public function render_section( $user ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$settings = Yunhu_OAuth_Settings::instance();
		$can_edit = ( get_current_user_id() === (int) $user->ID ) || current_user_can( 'edit_user', $user->ID );

		if ( ! $can_edit ) {
			return;
		}

		$uid       = (string) get_user_meta( $user->ID, Yunhu_OAuth_Login::UID_META, true );
		$bound_at  = (int) get_user_meta( $user->ID, 'yunhu_oauth_bound_at', true );
		$can_link  = $settings->get( 'enabled' ) && $settings->is_configured() && get_current_user_id() === (int) $user->ID;
		?>
		<?php if ( isset( $_GET['unlinked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag. ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( '已解除云湖账号绑定。', 'wp-yunhu-oauth' ); ?></p></div>
		<?php endif; ?>
		<h2><?php esc_html_e( '云湖账号', 'wp-yunhu-oauth' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( '绑定状态', 'wp-yunhu-oauth' ); ?></th>
				<td>
					<?php if ( '' !== $uid ) : ?>
						<p>
							<span class="dashicons dashicons-yes-alt" style="color:#46b450"></span>
							<?php
							printf(
								/* translators: %s: 云湖 user id */
								esc_html__( '已绑定，云湖用户 ID：%s', 'wp-yunhu-oauth' ),
								'<code>' . esc_html( $uid ) . '</code>'
							);
							?>
						</p>
						<?php if ( $bound_at ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: formatted date */
									esc_html__( '绑定时间：%s', 'wp-yunhu-oauth' ),
									esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $bound_at ) )
								);
								?>
							</p>
						<?php endif; ?>

						<?php if ( $can_edit ) : ?>
							<p>
								<a
									class="button button-secondary"
									href="<?php echo esc_url( $this->unlink_url( $user->ID ) ); ?>"
									onclick="return confirm('<?php echo esc_js( __( '解除绑定后，你将无法再使用云湖账号登录本站，确定继续？', 'wp-yunhu-oauth' ) ); ?>');"
								>
									<?php esc_html_e( '解除绑定', 'wp-yunhu-oauth' ); ?>
								</a>
							</p>
						<?php endif; ?>
					<?php else : ?>
						<p><?php esc_html_e( '尚未绑定云湖账号。', 'wp-yunhu-oauth' ); ?></p>
						<?php if ( $can_link ) : ?>
							<p>
								<a class="button button-primary" href="<?php echo esc_url( Yunhu_OAuth_Login::instance()->start_url( get_edit_profile_url( $user->ID ) ) ); ?>" rel="nofollow">
									<?php esc_html_e( '绑定我的云湖账号', 'wp-yunhu-oauth' ); ?>
								</a>
							</p>
							<p class="description"><?php esc_html_e( '点击后跳转到云湖完成授权，授权成功即自动绑定当前账号。', 'wp-yunhu-oauth' ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Nonced unlink URL.
	 *
	 * @param int $user_id Target user.
	 * @return string
	 */
	private function unlink_url( $user_id ) {
		$url = add_query_arg(
			array(
				'action'  => 'yunhu_oauth_unlink',
				'user_id' => (int) $user_id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'yunhu_oauth_unlink_' . (int) $user_id );
	}

	/**
	 * Handle the unlink request.
	 *
	 * @return void
	 */
	public function handle_unlink() {
		$user_id = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;

		if ( ! $user_id ) {
			wp_die( esc_html__( '缺少用户参数。', 'wp-yunhu-oauth' ), 400 );
		}

		check_admin_referer( 'yunhu_oauth_unlink_' . $user_id );

		$is_self = get_current_user_id() === $user_id;

		if ( ! $is_self && ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( '你没有权限执行此操作。', 'wp-yunhu-oauth' ), 403 );
		}

		delete_user_meta( $user_id, Yunhu_OAuth_Login::UID_META );
		delete_user_meta( $user_id, 'yunhu_oauth_bound_at' );
		delete_user_meta( $user_id, Yunhu_OAuth_Login::AVATAR_META );
		Yunhu_OAuth_Avatar::instance()->forget( $user_id );

		wp_safe_redirect(
			add_query_arg(
				'unlinked',
				'1',
				get_edit_profile_url( $user_id )
			)
		);
		exit;
	}

	/**
	 * Use the stored 云湖 avatar when WordPress has no other source.
	 *
	 * @param array        $args        Avatar arguments.
	 * @param mixed        $id_or_email User identifier.
	 * @return array
	 */
	public function filter_avatar( $args, $id_or_email ) {
		if ( ! empty( $args['url'] ) ) {
			return $args;
		}

		$user_id = $this->resolve_user_id( $id_or_email );

		if ( ! $user_id ) {
			return $args;
		}

		// Prefer the locally cached copy: the provider blocks hotlinking.
		$url = Yunhu_OAuth_Avatar::instance()->local_url( $user_id );

		if ( '' === $url ) {
			$url = (string) get_user_meta( $user_id, Yunhu_OAuth_Login::AVATAR_META, true );
		}

		if ( '' === $url ) {
			return $args;
		}

		$args['url']          = esc_url_raw( $url );
		$args['found_avatar'] = true;

		return $args;
	}

	/**
	 * When only the remote URL is available, ask the browser to drop the
	 * Referer header so hotlink protection is less likely to reject it.
	 *
	 * @param string $avatar      Image markup.
	 * @param mixed  $id_or_email User identifier.
	 * @param int    $size        Requested size.
	 * @param string $default     Default avatar.
	 * @param string $alt         Alt text.
	 * @return string
	 */
	public function filter_avatar_html( $avatar, $id_or_email, $size, $default, $alt ) {
		if ( false !== strpos( $avatar, 'referrerpolicy' ) ) {
			return $avatar;
		}

		$user_id = $this->resolve_user_id( $id_or_email );

		if ( ! $user_id ) {
			return $avatar;
		}

		// Nothing to do when the image is served from our own uploads.
		if ( '' !== Yunhu_OAuth_Avatar::instance()->local_url( $user_id ) ) {
			return $avatar;
		}

		$remote = (string) get_user_meta( $user_id, Yunhu_OAuth_Login::AVATAR_META, true );

		if ( '' === $remote ) {
			return $avatar;
		}

		if ( false === strpos( $avatar, $remote ) && false === strpos( $avatar, esc_url( $remote ) ) ) {
			return $avatar;
		}

		return str_replace( '<img ', '<img referrerpolicy="no-referrer" ', $avatar );
	}

	/**
	 * Normalize the many shapes $id_or_email can take.
	 *
	 * @param mixed $id_or_email User identifier.
	 * @return int
	 */
	private function resolve_user_id( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}

		if ( $id_or_email instanceof WP_User ) {
			return (int) $id_or_email->ID;
		}

		if ( $id_or_email instanceof WP_Post ) {
			return (int) $id_or_email->post_author;
		}

		if ( $id_or_email instanceof WP_Comment ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				return (int) $id_or_email->user_id;
			}

			$id_or_email = $id_or_email->comment_author_email;
		}

		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );

			return $user ? (int) $user->ID : 0;
		}

		return 0;
	}
}
