<?php
/**
 * Local avatar cache.
 *
 * 云湖的图片启用了防盗链，浏览器直接引用会因 Referer 不符被拒绝，因此登录时
 * 把头像下载到 uploads 目录并本地化输出。下载失败时退回远程地址，并在输出
 * <img> 时补上 referrerpolicy="no-referrer" 作为兜底。
 *
 * @package WP_Yunhu_OAuth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Yunhu_OAuth_Avatar
 */
class Yunhu_OAuth_Avatar {

	const META_FILE   = 'yunhu_oauth_avatar_file';
	const META_SOURCE = 'yunhu_oauth_avatar_source';
	const DIR         = 'yunhu-avatars';
	const MAX_BYTES   = 2097152; // 2 MB

	/**
	 * Singleton instance.
	 *
	 * @var Yunhu_OAuth_Avatar|null
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
	 * @return Yunhu_OAuth_Avatar
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
	 * Download the provider avatar and store it under uploads.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $remote_url Provider avatar URL.
	 * @return string Local URL on success, empty string otherwise.
	 */
	public function sync( $user_id, $remote_url ) {
		$user_id    = (int) $user_id;
		$remote_url = esc_url_raw( trim( (string) $remote_url ) );

		if ( ! $user_id || '' === $remote_url || ! preg_match( '#^https?://#i', $remote_url ) ) {
			return '';
		}

		if ( ! $this->settings->get( 'cache_avatar' ) ) {
			return '';
		}

		$current_file = (string) get_user_meta( $user_id, self::META_FILE, true );
		$known_source = (string) get_user_meta( $user_id, self::META_SOURCE, true );

		if ( '' !== $current_file && $known_source === $remote_url ) {
			$existing = $this->path( $current_file );

			if ( '' !== $existing && file_exists( $existing ) ) {
				return $this->url( $current_file );
			}
		}

		$body = $this->download( $remote_url );

		if ( '' === $body ) {
			$this->log( '头像下载失败，回退到远程地址：' . $remote_url );

			return '';
		}

		// Trust the bytes, not the Content-Type header.
		$info = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mime = ( is_array( $info ) && ! empty( $info['mime'] ) ) ? (string) $info['mime'] : '';
		$ext  = $this->extension_for( $mime );

		if ( '' === $ext ) {
			$this->log( '头像不是可识别的图片类型：' . $mime );

			return '';
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$target_dir = trailingslashit( $uploads['basedir'] ) . self::DIR;

		if ( ! wp_mkdir_p( $target_dir ) ) {
			return '';
		}

		$filename = md5( 'yunhu-avatar|' . $user_id . '|' . $remote_url ) . '.' . $ext;
		$absolute = $target_dir . '/' . $filename;
		$relative = self::DIR . '/' . $filename;

		if ( false === @file_put_contents( $absolute, $body ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			$this->log( '头像写入失败：' . $absolute );

			return '';
		}

		@chmod( $absolute, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( '' !== $current_file && $current_file !== $relative ) {
			$this->delete_file( $current_file );
		}

		update_user_meta( $user_id, self::META_FILE, $relative );
		update_user_meta( $user_id, self::META_SOURCE, $remote_url );

		return trailingslashit( $uploads['baseurl'] ) . $relative;
	}

	/**
	 * Cached local URL for a user, if any.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function local_url( $user_id ) {
		$file = (string) get_user_meta( (int) $user_id, self::META_FILE, true );

		if ( '' === $file ) {
			return '';
		}

		if ( '' === $this->path( $file ) || ! file_exists( $this->path( $file ) ) ) {
			return '';
		}

		return $this->url( $file );
	}

	/**
	 * Remove the cached file and its bookkeeping.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function forget( $user_id ) {
		$user_id = (int) $user_id;
		$file    = (string) get_user_meta( $user_id, self::META_FILE, true );

		if ( '' !== $file ) {
			$this->delete_file( $file );
		}

		delete_user_meta( $user_id, self::META_FILE );
		delete_user_meta( $user_id, self::META_SOURCE );
	}

	/**
	 * Fetch image bytes, cycling through plausible Referers until one is accepted.
	 *
	 * @param string $url Remote URL.
	 * @return string Raw body, empty on failure.
	 */
	private function download( $url ) {
		foreach ( $this->referer_candidates( $url ) as $referer ) {
			$args = $this->request_args( $referer );

			/**
			 * Filter the avatar download request.
			 *
			 * @param array  $args    Request arguments.
			 * @param string $url     Remote avatar URL.
			 * @param string $referer Referer used for this attempt.
			 */
			$args = apply_filters( 'yunhu_oauth_avatar_request_args', $args, $url, $referer );

			// wp_safe_remote_get() also blocks requests to private/loopback addresses.
			$response = wp_safe_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) {
				$this->log( '头像请求错误：' . $response->get_error_message() );

				return '';
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );

			if ( 200 === $code && '' !== $body && strlen( $body ) <= self::MAX_BYTES ) {
				return $body;
			}

			$this->log( sprintf( '头像请求返回 HTTP %d（Referer: %s）', $code, '' !== $referer ? $referer : '无' ) );

			// Only a hotlink rejection is worth retrying with another Referer.
			if ( ! in_array( $code, array( 401, 403 ), true ) ) {
				return '';
			}
		}

		return '';
	}

	/**
	 * Referer values to try, in order.
	 *
	 * 云湖的防盗链只放行自家域名，因此先试配置值，再试云湖客户端/网页端域名，
	 * 最后才试图片自身域名和不带 Referer。
	 *
	 * @param string $url Remote avatar URL.
	 * @return array
	 */
	private function referer_candidates( $url ) {
		$candidates = array();
		$configured = trim( (string) $this->settings->get( 'avatar_referer' ) );

		if ( '' !== $configured ) {
			$candidates[] = $configured;
		}

		$candidates[] = 'https://chat-go.jwzhd.com/';
		$candidates[] = 'https://www.yhchat.com/';

		$parts = wp_parse_url( $url );

		if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
			$candidates[] = $parts['scheme'] . '://' . $parts['host'] . '/';
		}

		// Some CDNs only reject *foreign* referers, so no Referer may work.
		$candidates[] = '';

		/**
		 * Filter the Referer chain used when downloading avatars.
		 *
		 * @param array  $candidates Referer values, in order.
		 * @param string $url        Remote avatar URL.
		 */
		$candidates = apply_filters( 'yunhu_oauth_avatar_referers', $candidates, $url );

		return array_values( array_unique( array_map( 'strval', (array) $candidates ) ) );
	}

	/**
	 * Build request arguments for one download attempt.
	 *
	 * @param string $referer Referer to send, empty to omit the header.
	 * @return array
	 */
	private function request_args( $referer ) {
		$args = array(
			'timeout'             => 8,
			'redirection'         => 3,
			'limit_response_size' => self::MAX_BYTES + 1,
			'headers'             => array(
				'Accept'     => 'image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8',
				'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			),
		);

		if ( '' !== $referer ) {
			$args['headers']['Referer'] = $referer;
		}

		return $args;
	}

	/**
	 * Map an image mime type to a file extension.
	 *
	 * @param string $mime Mime type.
	 * @return string
	 */
	private function extension_for( $mime ) {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);

		return isset( $map[ $mime ] ) ? $map[ $mime ] : '';
	}

	/**
	 * Absolute path for a stored relative file name.
	 *
	 * @param string $relative Relative path under uploads.
	 * @return string
	 */
	private function path( $relative ) {
		$relative = (string) $relative;

		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return '';
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . $relative;
	}

	/**
	 * Public URL for a stored relative file name.
	 *
	 * @param string $relative Relative path under uploads.
	 * @return string
	 */
	private function url( $relative ) {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		return trailingslashit( $uploads['baseurl'] ) . $relative;
	}

	/**
	 * Delete a stored file.
	 *
	 * @param string $relative Relative path under uploads.
	 * @return void
	 */
	private function delete_file( $relative ) {
		$path = $this->path( $relative );

		if ( '' !== $path && file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
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
