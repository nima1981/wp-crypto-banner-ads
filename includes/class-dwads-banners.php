<?php
/**
 * Banner records, image handling and slot selection.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Banner storage.
 */
final class DWADS_Banners {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dwads_banners';
	}

	/**
	 * Allowed statuses.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'pending', 'active', 'paused', 'rejected', 'archived' );
	}

	/**
	 * Fetch one banner.
	 *
	 * @param int $id Banner id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Fetch a banner by its secret management token.
	 *
	 * @param string $token Plain token.
	 * @return array|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;
		$token = (string) $token;
		if ( '' === $token ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s', self::token_hash( $token ) ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Per-install secret.
	 *
	 * @return string
	 */
	public static function secret() {
		$secret = get_option( 'dwads_secret' );
		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( 'dwads_secret', $secret, 'no' );
		}
		return $secret;
	}

	/**
	 * Hash a management token for storage. Only the hash is ever written to the
	 * database, so a database leak does not hand out control of banners.
	 *
	 * @param string $token Plain token.
	 * @return string
	 */
	public static function token_hash( $token ) {
		return hash_hmac( 'sha256', 'dwads-banner-token|' . $token, self::secret() );
	}

	/**
	 * New random management token.
	 *
	 * @return string
	 */
	public static function generate_token() {
		return bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Encrypt the management token for storage.
	 *
	 * The hash is what lookups use; this copy exists only so the site owner can
	 * hand a lost link back to an advertiser. It is encrypted with the install
	 * secret, so a database dump on its own does not reveal working links.
	 *
	 * @param string $token Plain token.
	 * @return string
	 */
	public static function encrypt_token( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return '';
		}
		$key = hash( 'sha256', 'dwads-token-enc|' . self::secret(), true );
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$ct  = openssl_encrypt( $token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $ct ) {
				return 'g1:' . base64_encode( $iv . $tag . $ct );
			}
		}
		// No usable OpenSSL: fall back to an obfuscated copy rather than losing
		// the ability to help an advertiser entirely.
		return 'p1:' . base64_encode( $token );
	}

	/**
	 * Decrypt a stored management token.
	 *
	 * @param array $banner Banner row.
	 * @return string Empty when unavailable.
	 */
	public static function decrypt_token( $banner ) {
		$stored = isset( $banner['token_enc'] ) ? (string) $banner['token_enc'] : '';
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'p1:' ) ) {
			$raw = base64_decode( substr( $stored, 3 ), true );
			return false === $raw ? '' : $raw;
		}
		if ( 0 !== strpos( $stored, 'g1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$bin = base64_decode( substr( $stored, 3 ), true );
		if ( false === $bin || strlen( $bin ) < 29 ) {
			return '';
		}
		$key = hash( 'sha256', 'dwads-token-enc|' . self::secret(), true );
		$iv  = substr( $bin, 0, 12 );
		$tag = substr( $bin, 12, 16 );
		$ct  = substr( $bin, 28 );
		$out = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $out ? '' : $out;
	}

	/**
	 * Recoverable management URL for the admin, or an empty string.
	 *
	 * @param array $banner Banner row.
	 * @return string
	 */
	public static function manage_url_for( $banner ) {
		$token = self::decrypt_token( $banner );
		if ( '' === $token ) {
			return '';
		}
		return DWADS_Public_Pages::manage_url( (int) $banner['id'], $token );
	}

	/**
	 * Balance in satoshis.
	 *
	 * @param array $banner Banner row.
	 * @return int
	 */
	public static function balance_sats( $banner ) {
		return (int) $banner['sats_received'] - (int) $banner['sats_spent'];
	}

	/**
	 * How many clicks remain at the banner's price.
	 *
	 * @param array $banner Banner row.
	 * @return int
	 */
	public static function remaining_clicks( $banner ) {
		$balance = self::balance_sats( $banner );
		if ( $balance <= 0 ) {
			return 0;
		}
		$sats = DWADS_Price::usd_to_sats( DWADS_Settings::click_price_for( $banner ) );
		return (int) floor( $balance / max( 1, $sats ) );
	}

	/**
	 * Upload directory, created on demand.
	 *
	 * @return array|WP_Error
	 */
	public static function upload_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'dwads_uploads', $uploads['error'] );
		}
		$path = trailingslashit( $uploads['basedir'] ) . 'dwads';
		if ( ! wp_mkdir_p( $path ) ) {
			return new WP_Error( 'dwads_uploads', __( 'The uploads folder could not be created.', 'dwads' ) );
		}
		return array(
			'path' => $path,
			'url'  => trailingslashit( $uploads['baseurl'] ) . 'dwads',
		);
	}

	/**
	 * Public URL for a banner image.
	 *
	 * @param array $banner Banner row.
	 * @return string
	 */
	public static function image_url( $banner ) {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . 'dwads/' . rawurlencode( (string) $banner['image_file'] );
	}

	/**
	 * Absolute path for a stored file name.
	 *
	 * @param string $file File name.
	 * @return string
	 */
	public static function file_path( $file ) {
		$uploads = wp_upload_dir();
		return trailingslashit( trailingslashit( $uploads['basedir'] ) . 'dwads' ) . basename( (string) $file );
	}

	/**
	 * Remove a stored image.
	 *
	 * @param string $file File name.
	 * @return void
	 */
	public static function delete_file( $file ) {
		$path = self::file_path( $file );
		if ( $file && is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Validate an advertiser landing page URL: https only, no javascript/data.
	 *
	 * @param string $url Raw URL.
	 * @return string|WP_Error
	 */
	public static function sanitize_target_url( $url ) {
		if ( ! is_scalar( $url ) ) {
			return new WP_Error( 'dwads_target', __( 'Use a full https:// link for the banner destination.', 'dwads' ) );
		}
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return new WP_Error( 'dwads_target', __( 'A destination link is required for every banner.', 'dwads' ) );
		}
		$clean = esc_url_raw( $url, array( 'https' ) );
		if ( ! $clean ) {
			return new WP_Error( 'dwads_target', __( 'Use a full https:// link for the banner destination.', 'dwads' ) );
		}
		$scheme = strtolower( (string) wp_parse_url( $clean, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $clean, PHP_URL_HOST );
		if ( 'https' !== $scheme || '' === $host ) {
			return new WP_Error( 'dwads_target', __( 'Use a full https:// link for the banner destination.', 'dwads' ) );
		}
		return $clean;
	}

	/**
	 * Pick the closest allowed size for an image that is not an exact match.
	 *
	 * Matching is by aspect ratio, not pixel count, so a retina export of the
	 * right banner still fits. A target is accepted when the centre-crop needed
	 * to reach it removes no more than 8% of the image (filterable).
	 *
	 * @param int   $w     Image width.
	 * @param int   $h     Image height.
	 * @param array $sizes Allowed sizes.
	 * @return array|WP_Error
	 */
	public static function nearest_size( $w, $h, $sizes ) {
		$w = (int) $w;
		$h = (int) $h;
		if ( $w <= 0 || $h <= 0 ) {
			return new WP_Error( 'dwads_upload_dims', __( 'The image has no usable dimensions.', 'dwads' ) );
		}

		$ratio      = $w / $h;
		$best       = null;
		$best_diff  = PHP_FLOAT_MAX;

		// Shape matters, not pixel count: a 2x retina export of a 728x90 banner
		// has different dimensions but the same aspect ratio, and fits perfectly.
		foreach ( (array) $sizes as $size ) {
			$tw = (int) $size['w'];
			$th = (int) $size['h'];
			if ( $tw <= 0 || $th <= 0 ) {
				continue;
			}
			$diff = abs( log( ( $tw / $th ) / $ratio ) );
			if ( $diff < $best_diff ) {
				$best_diff = $diff;
				$best      = $size;
			}
		}

		if ( ! $best ) {
			return new WP_Error( 'dwads_upload_dims', __( 'No banner sizes are configured.', 'dwads' ) );
		}

		$target_ratio = (int) $best['w'] / (int) $best['h'];
		$cropped      = $ratio > $target_ratio
			? 1 - ( $target_ratio / $ratio )
			: 1 - ( $ratio / $target_ratio );

		$limit = (float) apply_filters( 'dwads_autofit_crop_limit', 0.08, $w, $h, $best );

		if ( $cropped > $limit ) {
			return new WP_Error(
				'dwads_upload_dims',
				sprintf(
					/* translators: 1: width, 2: height, 3: closest width, 4: closest height, 5: crop percentage, 6: allowed sizes */
					__( 'This image is %1$d×%2$d. The closest allowed size is %3$d×%4$d, but fitting it would crop about %5$d%% away. Please export the banner at one of: %6$s.', 'dwads' ),
					$w,
					$h,
					(int) $best['w'],
					(int) $best['h'],
					(int) round( $cropped * 100 ),
					implode( ', ', array_keys( (array) $sizes ) )
				)
			);
		}

		return array(
			'w'      => (int) $best['w'],
			'h'      => (int) $best['h'],
			'fitted' => true,
			'crop'   => (int) round( $cropped * 100 ),
		);
	}

	/**
	 * Mild unsharp pass after a fitted downscale.
	 *
	 * Downscaling removes high-frequency detail, which reads as softness. A
	 * light sharpen restores edge contrast without adding halos. Strength is
	 * filterable and defaults to 0.5; set it to 0 to disable it entirely.
	 *
	 * @param WP_Image_Editor $editor The editor holding the resized image.
	 * @return void
	 */
	private static function sharpen( $editor ) {
		$amount = (float) apply_filters( 'dwads_autofit_sharpen', 0.5 );
		if ( $amount <= 0 ) {
			return;
		}
		$amount = min( 1.0, $amount );

		if ( method_exists( $editor, 'get_imagick' ) ) {
			$imagick = $editor->get_imagick();
			if ( $imagick instanceof Imagick ) {
				try {
					$imagick->unsharpMaskImage( 0.5, 0.5, $amount, 0.05 );
				} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Sharpen is cosmetic; never fail an upload over it.
				}
				return;
			}
		}

		if ( method_exists( $editor, 'get_image' ) && function_exists( 'imageconvolution' ) ) {
			$img = $editor->get_image();
			if ( is_resource( $img ) || $img instanceof GdImage ) {
				$s = $amount;
				// Kernel sums to 1, so brightness is unchanged:
				//   0  -s  0
				//  -s 1+4s -s
				//   0  -s  0
				imageconvolution(
					$img,
					array(
						array( 0, -$s, 0 ),
						array( -$s, 1 + 4 * $s, -$s ),
						array( 0, -$s, 0 ),
					),
					1,
					0
				);
			}
		}
	}

	/**
	 * Validate, re-encode and store an uploaded banner image.
	 *
	 * @param array $file        Entry from $_FILES.
	 * @param array $allow_sizes Allowed sizes keyed "WxH".
	 * @return array|WP_Error
	 */
	public static function handle_uploaded_file( $file, $allow_sizes ) {
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! isset( $file['error'] ) ) {
			return new WP_Error( 'dwads_upload', __( 'The file was not uploaded correctly.', 'dwads' ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'dwads_upload', __( 'The upload failed. Large images are rejected; try again with a smaller file.', 'dwads' ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'dwads_upload', __( 'The file was not uploaded correctly.', 'dwads' ) );
		}

		$max_kb = max( 16, (int) DWADS_Settings::get( 'max_upload_kb', 512 ) );
		if ( (int) filesize( $file['tmp_name'] ) > $max_kb * 1024 ) {
			/* translators: %d: maximum kilobytes */
			return new WP_Error( 'dwads_upload_size', sprintf( __( 'Images must be smaller than %d KB.', 'dwads' ), $max_kb ) );
		}

		$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || empty( $info['mime'] ) ) {
			return new WP_Error( 'dwads_upload_type', __( 'That file is not a readable image.', 'dwads' ) );
		}
		$w = (int) $info[0];
		$h = (int) $info[1];

		$target = array(
			'w'      => $w,
			'h'      => $h,
			'fitted' => false,
		);

		if ( ! isset( $allow_sizes[ $w . 'x' . $h ] ) ) {
			if ( ! DWADS_Settings::get( 'auto_fit_uploads', 1 ) ) {
				/* translators: %s: list of allowed sizes */
				return new WP_Error( 'dwads_upload_dims', sprintf( __( 'Images must be one of these exact sizes: %s.', 'dwads' ), implode( ', ', array_keys( $allow_sizes ) ) ) );
			}
			$target = self::nearest_size( $w, $h, $allow_sizes );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
		}

		$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
		if ( ! in_array( $info['mime'], $allowed_mimes, true ) ) {
			return new WP_Error( 'dwads_upload_mime', __( 'Only JPEG, PNG, WebP or GIF images are accepted. Animated GIFs are flattened.', 'dwads' ) );
		}

		$target_mime = ( 'image/gif' === $info['mime'] ) ? 'image/png' : $info['mime'];
		$ext_map     = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		);

		$editor = wp_get_image_editor( $file['tmp_name'] );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error( 'dwads_upload_editor', __( 'This server could not process the image (GD or Imagick is required).', 'dwads' ) );
		}
		$quality = (int) apply_filters( 'dwads_editor_quality', 90 );
		$editor->set_quality( $quality );

		if ( ! empty( $target['fitted'] ) ) {
			// Cover-fit: scale so the image covers the target, then centre-crop
			// whatever sticks out. No distortion, at most a sliver lost.
			$resized = $editor->resize( (int) $target['w'], (int) $target['h'], true );
			if ( is_wp_error( $resized ) ) {
				return new WP_Error( 'dwads_upload_resize', __( 'The image could not be resized to the nearest banner size.', 'dwads' ) );
			}
			// Downscaling softens edges; a light sharpen pass puts some of that
			// back without looking crunchy.
			self::sharpen( $editor );
		}

		$dir = self::upload_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$filename = 'banner-' . gmdate( 'Ymd' ) . '-' . bin2hex( random_bytes( 6 ) ) . '.' . $ext_map[ $target_mime ];
		$path     = trailingslashit( $dir['path'] ) . $filename;

		// Re-encoding from pixel data drops EXIF, embedded payloads and anything
		// appended to the original file.
		$saved = $editor->save( $path, $target_mime );
		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'dwads_upload_save', __( 'The image could not be stored. WebP may be unsupported on this server; try PNG.', 'dwads' ) );
		}

		$saved_path = isset( $saved['path'] ) ? $saved['path'] : $path;
		$check      = @getimagesize( $saved_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $check || (int) $check[0] !== (int) $target['w'] || (int) $check[1] !== (int) $target['h'] ) {
			@unlink( $saved_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'dwads_upload_dims', __( 'The image could not be kept at its exact size.', 'dwads' ) );
		}
		@chmod( $saved_path, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return array(
			'file'     => basename( $saved_path ),
			'w'        => (int) $target['w'],
			'h'        => (int) $target['h'],
			'source_w' => (int) $w,
			'source_h' => (int) $h,
			'fitted'   => ! empty( $target['fitted'] ),
			'mime'     => isset( $saved['mime'] ) ? $saved['mime'] : $target_mime,
			'bytes'    => (int) filesize( $saved_path ),
		);
	}

	/**
	 * Create a banner with a freshly derived address.
	 *
	 * @param array $args Banner data including a processed image array.
	 * @return array|WP_Error Array with id, token, manage_url.
	 */
	public static function create( $args ) {
		global $wpdb;

		$image = isset( $args['image'] ) && is_array( $args['image'] ) ? $args['image'] : null;
		if ( ! $image || empty( $image['file'] ) ) {
			return new WP_Error( 'dwads_no_image', __( 'No image was processed.', 'dwads' ) );
		}

		$target = self::sanitize_target_url( isset( $args['target_url'] ) ? $args['target_url'] : '' );
		if ( is_wp_error( $target ) ) {
			self::delete_file( $image['file'] );
			return $target;
		}

		$status = ! empty( DWADS_Settings::get( 'approval_required', 1 ) ) ? 'pending' : 'active';
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::statuses(), true ) ) {
			$status = $args['status'];
		}

		$price = null;
		if ( isset( $args['click_price_usd'] ) && '' !== $args['click_price_usd'] && null !== $args['click_price_usd'] ) {
			$price = number_format( (float) $args['click_price_usd'], 6, '.', '' );
		}

		$token = self::generate_token();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$data = array(
			'token_hash'       => self::token_hash( $token ),
			'site_id'          => isset( $args['site_id'] ) ? (int) $args['site_id'] : 0,
			'status'           => $status,
			'advertiser_label' => sanitize_text_field( isset( $args['advertiser_label'] ) ? $args['advertiser_label'] : '' ),
			'email'            => sanitize_email( isset( $args['email'] ) ? $args['email'] : '' ),
			'image_file'       => $image['file'],
			'image_w'          => (int) $image['w'],
			'image_h'          => (int) $image['h'],
			'image_mime'       => (string) $image['mime'],
			'image_bytes'      => (int) $image['bytes'],
			'target_url'       => $target,
			'alt_text'         => sanitize_text_field( isset( $args['alt_text'] ) ? $args['alt_text'] : '' ),
			'keywords'         => sanitize_text_field( isset( $args['keywords'] ) ? $args['keywords'] : '' ),
			'click_price_usd'  => $price,
			'created_at'       => $now,
			'updated_at'       => $now,
			'token_enc'        => self::encrypt_token( $token ),
			'source_w'         => isset( $image['source_w'] ) ? (int) $image['source_w'] : (int) $image['w'],
			'source_h'         => isset( $image['source_h'] ) ? (int) $image['source_h'] : (int) $image['h'],
		);
		$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' );

		$derived = null;
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$derived = DWADS_Xpub::next_address();
			if ( is_wp_error( $derived ) ) {
				self::delete_file( $image['file'] );
				return $derived;
			}
			$data['address']          = $derived['address'];
			$data['derivation_index'] = (int) $derived['index'];

			$inserted = $wpdb->insert( self::table(), $data, array_merge( $formats, array( '%s', '%d' ) ) );
			if ( $inserted ) {
				$id = (int) $wpdb->insert_id;
				DWADS_Log::add( sprintf( 'Banner #%d created (%s, %dx%d, address %s).', $id, $status, (int) $image['w'], (int) $image['h'], $derived['address'] ) );
				return array(
					'id'         => $id,
					'token'      => $token,
					'status'     => $status,
					'address'    => $derived['address'],
					'manage_url' => DWADS_Public_Pages::manage_url( $id, $token ),
				);
			}
			if ( false === stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				break;
			}
		}

		self::delete_file( $image['file'] );
		DWADS_Log::add( 'Banner insert failed: ' . (string) $wpdb->last_error );
		return new WP_Error( 'dwads_db', __( 'The banner could not be saved.', 'dwads' ) );
	}

	/**
	 * Update a banner's advertiser editable fields.
	 *
	 * @param int   $id   Banner id.
	 * @param array $data Field values.
	 * @return true|WP_Error
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$fields  = array();
		$formats = array();

		if ( isset( $data['target_url'] ) ) {
			$target = self::sanitize_target_url( $data['target_url'] );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			$fields['target_url'] = $target;
			$formats[]            = '%s';
		}
		if ( isset( $data['alt_text'] ) ) {
			$fields['alt_text'] = sanitize_text_field( $data['alt_text'] );
			$formats[]          = '%s';
		}
		if ( isset( $data['keywords'] ) ) {
			$fields['keywords'] = sanitize_text_field( $data['keywords'] );
			$formats[]          = '%s';
		}
		if ( array_key_exists( 'click_price_usd', $data ) ) {
			$price = ( null === $data['click_price_usd'] || '' === $data['click_price_usd'] ) ? null : number_format( (float) $data['click_price_usd'], 6, '.', '' );
			$fields['click_price_usd'] = $price;
			$formats[]                 = '%s';
		}
		if ( isset( $data['image'] ) && is_array( $data['image'] ) && ! empty( $data['image']['file'] ) ) {
			$old = $wpdb->get_var( $wpdb->prepare( 'SELECT image_file FROM ' . self::table() . ' WHERE id = %d', (int) $id ) );
			$fields['image_file']  = $data['image']['file'];
			$fields['image_w']     = (int) $data['image']['w'];
			$fields['image_h']     = (int) $data['image']['h'];
			$fields['image_mime']  = (string) $data['image']['mime'];
			$fields['image_bytes'] = (int) $data['image']['bytes'];
			$formats               = array_merge( $formats, array( '%s', '%d', '%d', '%s', '%d' ) );
			if ( $old && $old !== $data['image']['file'] ) {
				self::delete_file( $old );
			}
		}

		if ( ! $fields ) {
			return true;
		}

		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$formats[]            = '%s';

		$result = $wpdb->update( self::table(), $fields, array( 'id' => (int) $id ), $formats, array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'dwads_db', __( 'The banner could not be updated.', 'dwads' ) );
		}
		return true;
	}

	/**
	 * Change status.
	 *
	 * @param int    $id     Banner id.
	 * @param string $status New status.
	 * @return true|WP_Error
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, self::statuses(), true ) ) {
			return new WP_Error( 'dwads_status', __( 'Unknown status.', 'dwads' ) );
		}
		$fields = array(
			'status'     => $status,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$formats = array( '%s', '%s' );
		if ( 'active' === $status ) {
			$fields['approved_at'] = gmdate( 'Y-m-d H:i:s' );
			$formats[]             = '%s';
		}
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $id ), $formats, array( '%d' ) );
		return true;
	}

	/**
	 * Delete a banner and its dependent rows.
	 *
	 * @param int $id Banner id.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		$banner = self::get( $id );
		if ( ! $banner ) {
			return;
		}
		if ( ! empty( $banner['image_file'] ) ) {
			self::delete_file( $banner['image_file'] );
		}
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'dwads_payments', array( 'banner_id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'dwads_clicks', array( 'banner_id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'dwads_impressions', array( 'banner_id' => $id ), array( '%d' ) );
		DWADS_Log::add( sprintf( 'Banner #%d deleted.', $id ) );
	}

	/**
	 * Choose a banner for a slot, weighted by remaining balance.
	 *
	 * @param int $w       Slot width.
	 * @param int $h       Slot height.
	 * @param int $site_id Publisher site id (0 = this site).
	 * @return array|null
	 */
	public static function pick_for_slot( $w, $h, $site_id = 0 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE status = %s AND image_w = %d AND image_h = %d AND site_id = %d AND ( sats_received - sats_spent ) > 0 LIMIT 200',
				'active',
				(int) $w,
				(int) $h,
				(int) $site_id
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return null;
		}

		// A single very large balance should not starve everyone else, so the
		// weight is capped at roughly a thousand clicks' worth.
		$click_sats = max( 1, DWADS_Price::usd_to_sats( (float) DWADS_Settings::get( 'click_price_usd', 0.02 ) ) );
		$cap        = apply_filters( 'dwads_weight_cap_sats', $click_sats * 1000, $w, $h );

		$weights = array();
		$total   = 0;
		foreach ( $rows as $index => $row ) {
			$balance = (int) $row['sats_received'] - (int) $row['sats_spent'];

			// A banner that cannot cover one click would be shown for free: it
			// fills the space, the advertiser owes nothing, and the click is never
			// billable. Leave it out until it is topped up.
			$one_click = DWADS_Price::usd_to_sats( DWADS_Settings::click_price_for( $row ) );
			if ( $balance < max( 1, $one_click ) ) {
				continue;
			}

			$weight = (int) apply_filters( 'dwads_banner_weight', min( $balance, $cap ), $row, $w, $h );
			if ( $weight <= 0 ) {
				continue;
			}
			$weights[ $index ] = $weight;
			$total            += $weight;
		}
		if ( $total <= 0 ) {
			return null;
		}

		$roll = random_int( 1, $total );
		$acc  = 0;
		foreach ( $weights as $index => $weight ) {
			$acc += $weight;
			if ( $roll <= $acc ) {
				return $rows[ $index ];
			}
		}
		return $rows[0];
	}

	/**
	 * Record that a banner was served.
	 *
	 * @param int $id Banner id.
	 * @return void
	 */
	/**
	 * Zero a banner's impression counter and forget its recorded impressions.
	 *
	 * Deliberately narrow. Clicks charged, the balance, the money already spent
	 * and the click log are all left alone: those are the billing record, and a
	 * reset must never look like a refund or rewrite history. Clicking "reset"
	 * and seeing the click log still there is the point.
	 *
	 * The impression rows go because they are what the de-duplication window and
	 * the click-rate brake read. Keeping them would let an inflated history go
	 * on deciding what happens next.
	 *
	 * @param int $id Banner id.
	 * @return bool
	 */
	public static function reset_impressions( $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 || ! self::get( $id ) ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET impressions = 0, last_served_at = NULL, reset_at = %s WHERE id = %d',
				gmdate( 'Y-m-d H:i:s' ),
				$id
			)
		);

		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . DWADS_Clicks::impressions_table() . ' WHERE banner_id = %d', $id )
		);

		if ( class_exists( 'DWADS_Log' ) ) {
			DWADS_Log::add( sprintf( 'Impression counter reset for banner #%d by an administrator.', $id ) );
		}

		return true;
	}

	public static function note_served( $id ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET impressions = impressions + 1, last_served_at = %s WHERE id = %d',
				$now,
				(int) $id
			)
		);
	}

	/**
	 * Record that the advertiser opened their management page.
	 *
	 * @param int $id Banner id.
	 * @return void
	 */
	public static function note_manage_view( $id ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET last_manage_view_at = %s WHERE id = %d',
				$now,
				(int) $id
			)
		);
	}

	/**
	 * Email the advertiser, when they gave an address.
	 *
	 * @param array  $banner  Banner row.
	 * @param string $subject Subject.
	 * @param string $intro   First paragraph.
	 * @return bool
	 */
	public static function mail_advertiser( $banner, $subject, $intro ) {
		$email = isset( $banner['email'] ) ? sanitize_email( $banner['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}
		$url   = self::manage_url_for( $banner );
		$lines = array(
			$intro,
			'',
			'' !== $url ? $url : __( '(Management link unavailable. Contact the site owner with your banner ID.)', 'dwads' ),
			'',
			sprintf( /* translators: 1: banner id, 2: site name */ __( 'Banner #%1$d on %2$s', 'dwads' ), (int) $banner['id'], wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			home_url( '/' ),
		);
		return wp_mail( $email, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Banners that deserve a payment check.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public static function poll_candidates( $limit = 25 ) {
		global $wpdb;
		$banners = self::table();
		$payments = $wpdb->prefix . 'dwads_payments';
		$now      = time();
		$recent_manage = gmdate( 'Y-m-d H:i:s', $now - 3 * HOUR_IN_SECONDS );
		$recent_activity = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
		$recent_created = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );

		$sql = $wpdb->prepare(
			"SELECT * FROM {$banners} b
			WHERE b.status IN ('pending','active','paused')
			AND b.address <> ''
			AND (
				b.last_manage_view_at > %s
				OR b.last_activity_at > %s
				OR b.created_at > %s
				OR EXISTS ( SELECT 1 FROM {$payments} p WHERE p.banner_id = b.id AND p.credited = 0 AND p.status = 'mempool' )
			)
			ORDER BY b.last_poll_at IS NOT NULL, b.last_poll_at ASC
			LIMIT %d",
			$recent_manage,
			$recent_activity,
			$recent_created,
			(int) $limit
		);

		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Banners waiting for moderation.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', 'pending' ) );
	}
}
