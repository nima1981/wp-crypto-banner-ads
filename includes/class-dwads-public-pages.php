<?php
/**
 * Crypto Banner Ads
 *
 * Cookie-free, account-free paid banner ads. One watch-only payment address
 * per banner, pay-per-click balances, and a rotating placement snippet.
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation; either version 2 of the License, or (at your
 * option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
 * Public License for more details.
 *
 * @package Crypto_Banner_Ads
 * @license GPL-2.0-or-later
 */

/**
 * Public upload and self-service management pages.
 *
 * No accounts, no cookies. A banner's management URL is its only credential.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public pages.
 */
final class DWADS_Public_Pages {

	/** @var bool Whether the upload form printed during this request. */
	private static $upload_rendered = false;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_handle_post' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_no_cache' ), 6 );
		add_action( 'wp_footer', array( __CLASS__, 'footer_credit' ), 99 );
	}

	/**
	 * The credit line as markup, or an empty string when it is switched off.
	 *
	 * Returned rather than echoed because the management page and the upload
	 * page are standalone documents that never run wp_footer; they place the
	 * same markup at the bottom of their own body.
	 *
	 * The styles travel with the markup: the plugin stylesheet is only enqueued
	 * on pages that actually render a slot.
	 *
	 * @return string
	 */
	public static function credit_html() {
		if ( ! DWADS_Settings::get( 'credit_enabled', 1 ) ) {
			return '';
		}

		$line = sprintf(
			/* translators: 1: plugin name, linked to its repository. 2: company name, linked to its site. */
			esc_html__( 'Powered by %1$s from %2$s', 'dwads' ),
			'<a href="' . esc_url( 'https://github.com/nima1981/wp-crypto-banner-ads' ) . '" rel="noopener">' . esc_html__( 'Crypto Banner Ads', 'dwads' ) . '</a>',
			'<a href="' . esc_url( 'https://reza.consulting' ) . '" rel="noopener">' . esc_html__( 'Reza Consulting', 'dwads' ) . '</a>'
		);

		return '<style>.dwads-credit{clear:both;margin:1.5em 0 1em;padding:0 .75em;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:11px;line-height:1.5;text-align:center;color:inherit;opacity:.55}.dwads-credit a{color:inherit;text-decoration:none;border-bottom:1px solid rgba(128,128,128,.45)}.dwads-credit a:hover,.dwads-credit a:focus{border-bottom-color:currentColor}.dwads-credit a:focus-visible{outline:1px solid currentColor;outline-offset:2px}</style>'
			. '<p class="dwads-credit">' . $line . '</p>';
	}

	/**
	 * Print the credit in the theme's footer, on plugin pages only.
	 *
	 * A page counts as the plugin's when the upload form actually printed on it.
	 * Ordinary articles that happen to carry a banner are left alone: the credit
	 * belongs to the plugin's own screens, not to the whole site.
	 *
	 * @return void
	 */
	public static function footer_credit() {
		if ( ! self::$upload_rendered ) {
			return;
		}
		echo self::credit_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside credit_html().
	}

	/**
	 * Pages that display one-time results must not be cached.
	 *
	 * @return void
	 */
	public static function maybe_no_cache() {
		if ( isset( $_GET['dwads_result'] ) || isset( $_GET['dwads_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			nocache_headers();
		}
	}

	/**
	 * Public URL for a banner's management page.
	 *
	 * @param int    $id    Banner id.
	 * @param string $token Plain token.
	 * @return string
	 */
	public static function manage_url( $id, $token ) {
		list( , $manage_base ) = DWADS_Settings::route_bases();
		return home_url( '/' . $manage_base . '/' . (int) $id . '/' . rawurlencode( (string) $token ) . '/' );
	}

	/**
	 * Public click URL for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function click_url( $token ) {
		list( $click_base ) = DWADS_Settings::route_bases();
		return home_url( '/' . $click_base . '/' . rawurlencode( (string) $token ) . '/' );
	}

	/**
	 * Dispatch public form posts.
	 *
	 * @return void
	 */
	public static function maybe_handle_post() {
		if ( empty( $_POST['dwads_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! is_scalar( $_POST['dwads_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$action = sanitize_key( wp_unslash( (string) $_POST['dwads_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'upload' === $action ) {
			self::handle_upload_post();
			return;
		}
		if ( 'manage_update' === $action ) {
			self::handle_manage_update();
			return;
		}
	}

	/**
	 * Current URL without the result arguments.
	 *
	 * @return string
	 */
	private static function return_url() {
		$url = '';
		if ( isset( $_POST['dwads_return'] ) && is_scalar( $_POST['dwads_return'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$url = esc_url_raw( wp_unslash( (string) $_POST['dwads_return'] ) );
		}
		if ( ! $url ) {
			$url = home_url( '/' );
		}
		return remove_query_arg( array( 'dwads_result', 'dwads_msg' ), $url );
	}

	/**
	 * Redirect helper.
	 *
	 * @param string $url  Target.
	 * @param array  $args Query args.
	 * @return void
	 */
	private static function redirect( $url, $args = array() ) {
		$url = add_query_arg( $args, $url );
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * Upload handler.
	 *
	 * @return void
	 */
	private static function handle_upload_post() {
		$back = self::return_url();

		if ( ! empty( $_POST['dwads_hp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::redirect( $back, array( 'dwads_msg' => 'spam' ) );
		}
		if ( ! DWADS_BigInt::available() ) {
			self::redirect( $back, array( 'dwads_msg' => 'misconfigured' ) );
		}

		$ip_key = 'dwads_up_' . substr( DWADS_Clicks::ip_hash(), 0, 32 );
		$count  = (int) get_transient( $ip_key );
		if ( $count >= max( 1, (int) DWADS_Settings::get( 'max_uploads_per_ip_hour', 5 ) ) ) {
			self::redirect( $back, array( 'dwads_msg' => 'rate_limited' ) );
		}
		set_transient( $ip_key, $count + 1, HOUR_IN_SECONDS );

		$allow_sizes = DWADS_Settings::sizes();
		$max_rows    = max( 1, (int) DWADS_Settings::get( 'max_banners_per_upload', 5 ) );
		$rows        = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$files       = isset( $_FILES['rows'] ) && is_array( $_FILES['rows'] ) ? $_FILES['rows'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$label   = isset( $_POST['advertiser_label'] ) && is_scalar( $_POST['advertiser_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['advertiser_label'] ) ) : '';
		$email   = isset( $_POST['email'] ) && is_scalar( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
		$created = array();
		$errors  = array();

		$processed = 0;
		foreach ( (array) $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( $processed >= $max_rows ) {
				break;
			}
			$index = (int) $index;
			$file  = array(
				'name'     => isset( $files['name'][ $index ]['file'] ) ? $files['name'][ $index ]['file'] : '',
				'type'     => isset( $files['type'][ $index ]['file'] ) ? $files['type'][ $index ]['file'] : '',
				'tmp_name' => isset( $files['tmp_name'][ $index ]['file'] ) ? $files['tmp_name'][ $index ]['file'] : '',
				'error'    => isset( $files['error'][ $index ]['file'] ) ? (int) $files['error'][ $index ]['file'] : UPLOAD_ERR_NO_FILE,
				'size'     => isset( $files['size'][ $index ]['file'] ) ? (int) $files['size'][ $index ]['file'] : 0,
			);

			if ( UPLOAD_ERR_NO_FILE === $file['error'] || '' === $file['tmp_name'] ) {
				continue;
			}
			$processed++;

			$link = isset( $row['link'] ) && is_scalar( $row['link'] ) ? trim( (string) $row['link'] ) : '';
			$alt  = isset( $row['alt'] ) && is_scalar( $row['alt'] ) ? sanitize_text_field( (string) $row['alt'] ) : '';
			$kw   = isset( $row['keywords'] ) && is_scalar( $row['keywords'] ) ? sanitize_text_field( (string) $row['keywords'] ) : '';

			$image = DWADS_Banners::handle_uploaded_file( $file, $allow_sizes );
			if ( is_wp_error( $image ) ) {
				$errors[] = $image->get_error_message();
				continue;
			}

			$result = DWADS_Banners::create(
				array(
					'image'            => $image,
					'target_url'       => $link,
					'alt_text'         => $alt,
					'keywords'         => $kw,
					'advertiser_label' => $label,
					'email'            => $email,
				)
			);
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}
			$created[] = array(
				'id'         => $result['id'],
				'manage_url' => $result['manage_url'],
				'status'     => $result['status'],
				'address'    => $result['address'],
				'fit_from'   => ! empty( $image['fitted'] ) ? (int) $image['source_w'] . '×' . (int) $image['source_h'] : '',
				'fit_to'     => ! empty( $image['fitted'] ) ? (int) $image['w'] . '×' . (int) $image['h'] : '',
			);
		}

		if ( ! $processed ) {
			$errors[] = __( 'Choose at least one banner image.', 'dwads' );
		}

		// PHP drops files beyond max_file_uploads without saying so. When the
		// request arrives right at that ceiling the shortfall is almost
		// certainly truncation, so say it instead of leaving banners missing.
		$php_cap  = (int) ini_get( 'max_file_uploads' );
		$received = isset( $files['name'] ) && is_array( $files['name'] ) ? count( $files['name'] ) : 0;
		if ( $php_cap > 0 && $received >= $php_cap && $max_rows > $php_cap ) {
			$errors[] = sprintf(
				/* translators: %d: PHP max_file_uploads value */
				__( 'Your server accepts at most %d files per request (PHP max_file_uploads); anything beyond that did not arrive. Upload the rest in another run.', 'dwads' ),
				$php_cap
			);
		}

		$key = bin2hex( random_bytes( 8 ) );
		set_transient(
			'dwads_result_' . $key,
			array(
				'created' => $created,
				'errors'  => $errors,
			),
			15 * MINUTE_IN_SECONDS
		);

		if ( $created && '' !== $email && DWADS_Settings::get( 'notify_advertiser', 1 ) ) {
			$links = array();
			foreach ( $created as $item ) {
				$links[] = $item['manage_url'];
			}
			$tokens = array(
				'{SITE_NAME}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{SITE_URL}'  => home_url( '/' ),
				'{LINKS}'     => implode( "\n", $links ),
			);
			wp_mail(
				$email,
				strtr( (string) DWADS_Settings::get( 'email_upload_subject', '' ), $tokens ),
				strtr( (string) DWADS_Settings::get( 'email_upload_body', '' ), $tokens )
			);
		}

		if ( $created && DWADS_Settings::get( 'notify_admin_uploads', 1 ) ) {
			$lines = array();
			foreach ( $created as $item ) {
				$lines[] = sprintf( '#%d (%s)', $item['id'], $item['status'] );
			}
			$tokens = array(
				'{SITE_NAME}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{BANNERS}'   => implode( "\n", $lines ),
				'{ADMIN_URL}' => admin_url( 'admin.php?page=dwads-banners' ),
			);
			wp_mail(
				get_option( 'admin_email' ),
				strtr( (string) DWADS_Settings::get( 'email_admin_upload_subject', '' ), $tokens ),
				strtr( (string) DWADS_Settings::get( 'email_admin_upload_body', '' ), $tokens )
			);
		}

		self::redirect( $back, array( 'dwads_result' => $key ) );
	}

	/**
	 * Management page actions.
	 *
	 * @return void
	 */
	private static function handle_manage_update() {
		$id    = isset( $_POST['banner_id'] ) && is_scalar( $_POST['banner_id'] ) ? (int) $_POST['banner_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token = isset( $_POST['banner_token'] ) && is_scalar( $_POST['banner_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['banner_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$banner = DWADS_Banners::get_by_token( $token );

		if ( ! $banner || (int) $banner['id'] !== $id ) {
			wp_safe_redirect( home_url( '/' ), 302 );
			exit;
		}

		$manage_url = self::manage_url( $id, $token );
		$action     = isset( $_POST['do'] ) && is_scalar( $_POST['do'] ) ? sanitize_key( wp_unslash( (string) $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'refresh' === $action ) {
			$nudge = 'dwads_nudge_' . (int) $banner['id'];
			if ( get_transient( $nudge ) ) {
				self::redirect( $manage_url, array( 'dwads_msg' => 'just_checked' ) );
			}
			set_transient( $nudge, 1, MINUTE_IN_SECONDS );

			if ( DWADS_Payments::paused_for() > 0 ) {
				self::redirect( $manage_url, array( 'dwads_msg' => 'busy' ) );
			}

			$result = DWADS_Payments::poll_banner( $banner );
			self::redirect( $manage_url, array( 'dwads_msg' => is_wp_error( $result ) ? 'check_failed' : 'checked' ) );
		}

		self::redirect( $manage_url, array( 'dwads_msg' => 'unknown' ) );
	}

	/**
	 * The upload form markup (used by the [dwads_upload] shortcode).
	 *
	 * @return string
	 */
	public static function upload_form_html() {
		$sizes = DWADS_Settings::sizes();
		if ( ! $sizes ) {
			return '';
		}
		self::$upload_rendered = true;
		$max_rows   = max( 1, (int) DWADS_Settings::get( 'max_banners_per_upload', 5 ) );
		$max_kb     = (int) DWADS_Settings::get( 'max_upload_kb', 512 );
		$result_key = isset( $_GET['dwads_result'] ) ? sanitize_key( wp_unslash( $_GET['dwads_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg        = isset( $_GET['dwads_msg'] ) ? sanitize_key( wp_unslash( $_GET['dwads_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$html = '<div class="dwads-upload">';

		if ( $msg ) {
			$html .= self::notice( self::message_text( $msg ), 'error' );
		}

		if ( $result_key ) {
			$result = get_transient( 'dwads_result_' . $result_key );
			if ( is_array( $result ) ) {
				if ( ! empty( $result['created'] ) ) {
					$list = '<p>' . esc_html__( 'Save these management links. They are the only way to top up or edit a banner, and they are not shown again.', 'dwads' ) . '</p><ul class="dwads-links">';
					foreach ( $result['created'] as $item ) {
						$list .= '<li><strong>#' . (int) $item['id'] . '</strong> <a href="' . esc_url( $item['manage_url'] ) . '" target="_blank" rel="nofollow noopener noreferrer">' . esc_html( $item['manage_url'] ) . '</a> <span class="dwads-muted">(' . esc_html( $item['status'] ) . ')</span>';
						if ( ! empty( $item['fit_from'] ) ) {
							$list .= '<br><span class="dwads-muted">' . esc_html(
								sprintf(
									/* translators: 1: original size, 2: final size */
									__( 'Your image was %1$s; it has been fitted to %2$s by centre-cropping.', 'dwads' ),
									$item['fit_from'],
									$item['fit_to']
								)
							) . '</span>';
						}
						$list .= '</li>';
					}
					$list .= '</ul>';
					$html .= self::notice( $list, 'success', true );
				}
				if ( ! empty( $result['errors'] ) ) {
					$html .= self::notice( implode( '<br>', array_map( 'esc_html', $result['errors'] ) ), 'error', true );
				}
			}
		}

		$current = remove_query_arg( array( 'dwads_result', 'dwads_msg' ) );

		$row_template = self::upload_row( '__i__', $sizes, $max_kb );

		$html .= '<form class="dwads-form" method="post" enctype="multipart/form-data" action="' . esc_url( $current ) . '">';
		$html .= '<input type="hidden" name="dwads_action" value="upload">';
		$html .= '<input type="hidden" name="dwads_return" value="' . esc_url( $current ) . '">';
		$html .= '<input type="text" name="dwads_hp" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;height:1px;width:1px;opacity:0">';
		$intro = (string) DWADS_Settings::get( 'upload_intro' );
		if ( '' !== trim( $intro ) ) {
			$html .= '<div class="dwads-intro">' . wp_kses_post( wpautop( $intro ) ) . '</div>';
		}

		$html .= '<div class="dwads-sizes">';
		$html .= '<p class="dwads-sizes-title">' . esc_html__( 'Accepted sizes', 'dwads' ) . '</p><ul>';
		foreach ( $sizes as $size_key => $size_item ) {
			$html .= '<li><strong>' . esc_html( $size_key ) . '</strong>';
			if ( ! empty( $size_item['label'] ) ) {
				$html .= ' <span class="dwads-size-label">' . esc_html( $size_item['label'] ) . '</span>';
			}
			if ( ! empty( $size_item['recommended'] ) ) {
				$html .= ' <span class="dwads-size-rec">' . esc_html__( 'recommended', 'dwads' ) . '</span>';
			}
			if ( ! empty( $size_item['note'] ) ) {
				$html .= '<span class="dwads-size-note">' . esc_html( $size_item['note'] ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		$html .= '<p class="dwads-hint">' . esc_html(
			sprintf(
				/* translators: %d: maximum kilobytes */
				__( 'Files up to %d KB, PNG, JPEG or WebP. The link must be https:// and the image must match one of the sizes exactly.', 'dwads' ),
				$max_kb
			)
		) . '</p>';
		$html .= '</div>';

		// Drop several files at once: each one becomes a row of its own, grouped
		// by size. The files are placed into the existing per-row file inputs,
		// so the POST shape and every server-side check stay untouched.
		$html .= '<div class="dwads-drop" id="dwads-dropzone" tabindex="0" role="button" hidden>'
			. '<p class="dwads-drop-title">' . esc_html__( 'Drop banner images here', 'dwads' ) . '</p>'
			. '<p class="dwads-drop-hint">' . esc_html( self::drop_limit_text( $max_rows ) ) . '</p>'
			. '<input type="file" id="dwads-drop-input" accept="image/png,image/jpeg,image/webp" multiple hidden>'
			. '</div>'
			. '<div class="dwads-drop-status" id="dwads-drop-status" role="status" aria-live="polite"></div>';

		$html .= '<div id="dwads-rows">' . self::upload_row( 0, $sizes, $max_kb ) . '</div>';
		$html .= '<p><button type="button" class="dwads-btn dwads-btn-secondary" id="dwads-add-row" data-max="' . (int) $max_rows . '">' . esc_html__( 'Add another banner', 'dwads' ) . '</button></p>';
		$html .= '<h3>' . esc_html__( 'Optional', 'dwads' ) . '</h3>';
		$html .= '<p><label>' . esc_html__( 'Advertiser or business name', 'dwads' ) . '<input type="text" name="advertiser_label" maxlength="120" value=""></label></p>';
		$html .= '<p><label>' . esc_html__( 'Email for recovery (not shown publicly)', 'dwads' ) . '<input type="email" name="email" maxlength="190" value=""></label></p>';
		$html .= '<p><button type="submit" class="dwads-btn">' . esc_html__( 'Upload and get my top-up link', 'dwads' ) . '</button></p>';
		$html .= '</form>';
		$html .= '<template id="dwads-row-template">' . $row_template . '</template>';

		$js_sizes = array();
		foreach ( $sizes as $size_key => $size_item ) {
			$js_sizes[] = array(
				'key'   => (string) $size_key,
				'w'     => isset( $size_item['w'] ) ? (int) $size_item['w'] : 0,
				'h'     => isset( $size_item['h'] ) ? (int) $size_item['h'] : 0,
				'label' => isset( $size_item['label'] ) ? (string) $size_item['label'] : '',
			);
		}
		$js_cfg = array(
			'max'     => (int) $max_rows,
			'phpMax'  => (int) ini_get( 'max_file_uploads' ),
			'maxKb'   => (int) $max_kb,
			'autofit' => (bool) DWADS_Settings::get( 'auto_fit_uploads', 1 ),
			'sizes'   => $js_sizes,
		);
		$html .= '<script>window.DWADS_UPLOAD=' . wp_json_encode( $js_cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';</script>';

		if ( ! wp_script_is( 'dwads-upload', 'registered' ) ) {
			DWADS_Shortcodes::register_assets();
		}
		wp_enqueue_script( 'dwads-upload' );

		return $html . '</div>';
	}

	/**
	 * What the drop zone can honestly promise.
	 *
	 * The admin's setting is the intent; PHP's max_file_uploads is the ceiling
	 * on how many files can arrive in one request. Quoting the smaller of the
	 * two avoids promising a batch the request cannot carry.
	 *
	 * @param int $max_rows Configured maximum.
	 * @return string
	 */
	private static function drop_limit_text( $max_rows ) {
		$php_cap = (int) ini_get( 'max_file_uploads' );
		if ( $php_cap > 0 && $php_cap < $max_rows ) {
			return sprintf(
				/* translators: 1: PHP max_file_uploads value */
				__( 'Or click to choose files. Drop as many as you like — they are grouped by size. Your server accepts %1$d files per request, so that is the practical limit for one upload.', 'dwads' ),
				$php_cap
			);
		}
		return sprintf(
			/* translators: %d: maximum banners per upload */
			__( 'Or click to choose files. Drop as many as you like — they are grouped by size. Up to %d per upload.', 'dwads' ),
			$max_rows
		);
	}

	/**
	 * One upload row.
	 *
	 * @param int|string $index    Row index or __i__ placeholder.
	 * @param array      $sizes    Sizes.
	 * @param int        $max_kb   Max size.
	 * @return string
	 */
	private static function upload_row( $index, $sizes, $max_kb ) {
		$html  = '<div class="dwads-row">';
		$html .= '<p><label>' . esc_html__( 'Banner image', 'dwads' ) . '<input type="file" name="rows[' . esc_attr( $index ) . '][file]" accept="image/png,image/jpeg,image/webp"></label></p>';
		$html .= '<p><label>' . esc_html__( 'Destination link (https://…)', 'dwads' ) . '<input type="url" name="rows[' . esc_attr( $index ) . '][link]" placeholder="https://example.com/" required></label></p>';
		$html .= '<p><label>' . esc_html__( 'Alt text (optional)', 'dwads' ) . '<input type="text" name="rows[' . esc_attr( $index ) . '][alt]" maxlength="255" value=""></label></p>';
		$html .= '<p><label>' . esc_html__( 'Keywords (optional)', 'dwads' ) . '<input type="text" name="rows[' . esc_attr( $index ) . '][keywords]" maxlength="255" placeholder="' . esc_attr__( 'e.g. privacy, surveillance, crypto', 'dwads' ) . '"></label>';
		$html .= '<span class="dwads-hint">' . esc_html__( 'Comma separated. Used later to favour banners that match the page.', 'dwads' ) . '</span></p>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * Build a notice block.
	 *
	 * @param string $text    Already-escaped or trusted HTML.
	 * @param string $type    success or error.
	 * @param bool   $is_html Whether the text is HTML.
	 * @return string
	 */
	private static function notice( $text, $type = 'success', $is_html = false ) {
		$class = 'error' === $type ? 'dwads-notice dwads-notice-error' : 'dwads-notice dwads-notice-success';
		return '<div class="' . esc_attr( $class ) . '">' . ( $is_html ? $text : esc_html( $text ) ) . '</div>';
	}

	/**
	 * Human text for short message codes.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private static function message_text( $code ) {
		switch ( $code ) {
			case 'saved':
				return __( 'Saved.', 'dwads' );
			case 'paused':
				return __( 'Your banner is paused and will not be shown until you resume it.', 'dwads' );
			case 'resumed':
				return __( 'Your banner is live again.', 'dwads' );
			case 'not_paused':
				return __( 'This banner is not paused, so there is nothing to resume.', 'dwads' );
			case 'invalid_link':
				return __( 'That destination link is not valid. Use a full https:// address.', 'dwads' );
			case 'image_updated':
				return __( 'The image was replaced.', 'dwads' );
			case 'bad_image':
				return __( 'The replacement image was rejected. It must be an image of exactly the same size.', 'dwads' );
			case 'no_image':
				return __( 'Choose an image file first.', 'dwads' );
			case 'rate_limited':
				return __( 'Too many uploads from this connection. Try again in an hour.', 'dwads' );
			case 'spam':
				return __( 'The upload was rejected.', 'dwads' );
			case 'misconfigured':
				return __( 'Uploads are temporarily unavailable. Please try later.', 'dwads' );
			case 'unknown':
				return __( 'Nothing was changed.', 'dwads' );
			case 'checked':
				return __( 'Payment check complete. If a payment just arrived it is now included; otherwise give the network a minute and check again.', 'dwads' );
			case 'just_checked':
				return __( 'These amounts were checked less than a minute ago. Please try again shortly.', 'dwads' );
			case 'busy':
				return __( 'Payment checks are paused for a few minutes after the API asked us to slow down. Your payment is not lost — it will be credited on the next automatic check.', 'dwads' );
			case 'check_failed':
				return __( 'The payment API could not be reached just now. Nothing was lost; the next automatic check will pick the payment up.', 'dwads' );
		}
		return __( 'Request completed.', 'dwads' );
	}

	/**
	 * Build the exchange widget markup for a banner.
	 *
	 * The ChangeNOW embed is a plain URL whose topUpAddress parameter carries the
	 * banner address, and whose link_id identifies the affiliate account. Storing
	 * the URL instead of raw HTML means the plugin can render the frame itself
	 * and no unfiltered HTML is required.
	 *
	 * @param array $banner Banner row.
	 * @return string
	 */
	/**
	 * Print the swap provider's embed, with the banner address filled in.
	 *
	 * The setting holds the provider's complete embed code, pasted as given. It is
	 * printed unchanged apart from {ADDRESS}, which becomes the banner's deposit
	 * address: one address per banner means it cannot be typed in by hand. No URL
	 * detection, no wrapping, no height handling — whatever is pasted is what
	 * renders, so switching providers is a copy-paste.
	 *
	 * @param array $banner Banner row.
	 * @return string Markup, or an empty string when nothing should render.
	 */
	public static function exchange_widget_html( $banner ) {
		$address = isset( $banner['address'] ) ? (string) $banner['address'] : '';
		if ( '' === $address ) {
			return '';
		}

		$template = (string) DWADS_Settings::exchange_template();
		if ( '' === trim( $template ) ) {
			return '';
		}

		$snippet = str_replace(
			array( '{{ADDRESS}}', '{ADDRESS}' ),
			rawurlencode( $address ),
			$template
		);

		// A brace token nobody replaced must never reach the browser.
		$snippet = preg_replace( '/\{\{?[A-Z_]{2,}\}?\}/', '', $snippet );

		return '<div class="dwads-widget">' . $snippet . '</div>';
	}

	/**
	 * Render the self-service management page.
	 *
	 * @param int    $id    Banner id.
	 * @param string $token Plain token.
	 * @return void
	 */
	public static function render_manage( $id, $token ) {
		global $wpdb;

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$banner = DWADS_Banners::get_by_token( $token );
		if ( ! $banner || (int) $banner['id'] !== (int) $id ) {
			status_header( 404 );
			echo self::page_shell( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				__( 'Link not found', 'dwads' ),
				'<p>' . esc_html__( 'This management link is not valid. If you lost your link, contact the site owner with your banner ID and payment transaction.', 'dwads' ) . '</p>'
			);
			return;
		}

		DWADS_Banners::note_manage_view( (int) $banner['id'] );

		$balance   = DWADS_Banners::balance_sats( $banner );
		$price     = DWADS_Settings::click_price_for( $banner );
		$price_sats = DWADS_Price::usd_to_sats( $price );
		$remaining = DWADS_Banners::remaining_clicks( $banner );
		$msg       = isset( $_GET['dwads_msg'] ) ? sanitize_key( wp_unslash( $_GET['dwads_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$manage    = self::manage_url( $banner['id'], $token );

		$body  = '';
		if ( $msg ) {
			$body .= self::notice( self::message_text( $msg ), in_array( $msg, array( 'saved', 'paused', 'resumed', 'image_updated' ), true ) ? 'success' : 'error' );
		}

		if ( 'pending' === $banner['status'] ) {
			$body .= self::notice( __( 'This banner is waiting for the site owner to approve it. Payments you send now are still counted.', 'dwads' ), 'success' );
		}
		if ( 'rejected' === $banner['status'] ) {
			$body .= self::notice( __( 'This banner was rejected and is not being shown.', 'dwads' ), 'error' );
		}
		if ( 'paused' === $banner['status'] ) {
			$body .= self::notice( __( 'This banner is paused.', 'dwads' ), 'error' );
		}
		if ( 'active' === $banner['status'] && $balance <= 0 ) {
			$body .= self::notice( __( 'The balance is empty, so the banner is not being shown. Top up to bring it back.', 'dwads' ), 'error' );
		}

		$body .= '<div class="dwads-summary">';
		$body .= '<div><span class="dwads-label">' . esc_html__( 'Balance', 'dwads' ) . '</span><strong>' . esc_html( DWADS_Price::format_btc( $balance ) ) . '</strong><span class="dwads-muted">' . esc_html( sprintf( '≈ $%s', number_format( DWADS_Price::sats_to_usd( $balance ), 2 ) ) ) . '</span></div>';
		$body .= '<div><span class="dwads-label">' . esc_html__( 'Clicks left', 'dwads' ) . '</span><strong>' . esc_html( number_format_i18n( $remaining ) ) . '</strong><span class="dwads-muted">' . esc_html( sprintf( __( '$%s per click', 'dwads' ), number_format( $price, 2 ) ) ) . '</span></div>';
		$body .= '<div><span class="dwads-label">' . esc_html__( 'Clicks charged', 'dwads' ) . '</span><strong>' . esc_html( number_format_i18n( (int) $banner['clicks_charged'] ) ) . '</strong><span class="dwads-muted">' . esc_html__( 'billable', 'dwads' ) . '</span></div>';

		$impressions = (int) $banner['impressions'];
		$charged     = (int) $banner['clicks_charged'];
		$body .= '<div><span class="dwads-label">' . esc_html__( 'Impressions', 'dwads' ) . '</span><strong>' . esc_html( number_format_i18n( $impressions ) ) . '</strong><span class="dwads-muted">' . esc_html__( 'times shown', 'dwads' ) . '</span></div>';

		// Only meaningful once something has been shown; a bare 0.00% reads like
		// a failure rather than an absence of data.
		$rate = $impressions > 0 ? ( $charged / $impressions ) * 100 : null;
		$body .= '<div><span class="dwads-label">' . esc_html__( 'Click rate', 'dwads' ) . '</span><strong>' . esc_html( null === $rate ? '—' : number_format_i18n( $rate, 2 ) . '%' ) . '</strong><span class="dwads-muted">' . esc_html__( 'clicks ÷ impressions', 'dwads' ) . '</span></div>';
		$body .= '</div>';

		$dedupe_hours = (int) DWADS_Settings::get( 'impression_dedupe_hours', 24 );
		if ( $dedupe_hours > 0 ) {
			$body .= '<p class="dwads-muted">' . esc_html(
				sprintf(
					/* translators: %d: number of hours */
					__( 'An impression counts once per visitor every %d hours, only once the banner has actually been on screen, and never for a crawler — so the figure tracks people rather than page loads. A click is charged once per visitor in the same period, only after the banner was visible for a moment, which is why it is lower than the raw click count.', 'dwads' ),
					$dedupe_hours
				)
			) . '</p>';
		}
		$body .= '<p class="dwads-muted">' . esc_html(
			sprintf(
				/* translators: 1: received BTC, 2: spent BTC */
				__( 'Received %1$s · spent %2$s on clicks. Balances are never refunded.', 'dwads' ),
				DWADS_Price::format_btc( (int) $banner['sats_received'] ),
				DWADS_Price::format_btc( (int) $banner['sats_spent'] )
			)
		) . '</p>';

		$body .= '<form class="dwads-form dwads-refresh" method="post" action="' . esc_url( $manage ) . '">';
		$body .= '<input type="hidden" name="dwads_action" value="manage_update">';
		$body .= '<input type="hidden" name="do" value="refresh">';
		$body .= '<input type="hidden" name="banner_id" value="' . (int) $banner['id'] . '">';
		$body .= '<input type="hidden" name="banner_token" value="' . esc_attr( $token ) . '">';
		$body .= '<p><button type="submit" class="dwads-btn">' . esc_html__( 'Check for payment now', 'dwads' ) . '</button></p>';
		$body .= '<p class="dwads-muted">' . esc_html__( 'Payments are usually credited within a minute of appearing on the network, and are checked automatically every 5 minutes. Amounts above may lag slightly behind.', 'dwads' ) . '</p>';
		$body .= '</form>';

		$body .= '<h2>' . esc_html__( 'Top up', 'dwads' ) . '</h2>';

		// Two ways to pay, in the order most people want them: convert something
		// else with the provider's widget, or send Bitcoin straight to the address.
		$widget = self::exchange_widget_html( $banner );
		if ( '' !== $widget ) {
			$body .= '<p>' . esc_html__( 'Pay with your cryptocurrency or regular money of choice: the widget converts it and sends it to this address, so there is nothing else to do.', 'dwads' ) . '</p>';
			$body .= $widget;
			$body .= '<p>' . esc_html__( 'Or send Bitcoin straight to the address below.', 'dwads' ) . '</p>';
		} else {
			$body .= '<p>' . esc_html__( 'Send Bitcoin to the address below.', 'dwads' ) . '</p>';
		}

		$body .= '<p class="dwads-address"><code id="dwads-address">' . esc_html( $banner['address'] ) . '</code></p>';
		$body .= '<p class="dwads-muted">' . esc_html__( 'Anything that arrives is credited within a few minutes of appearing on the network, before confirmation.', 'dwads' ) . '</p>';
		$body .= '<p><button type="button" class="dwads-btn dwads-btn-secondary" id="dwads-copy" data-address="' . esc_attr( $banner['address'] ) . '">' . esc_html__( 'Copy address', 'dwads' ) . '</button></p>';
		$body .= '<p><a class="dwads-btn dwads-btn-secondary" href="' . esc_attr( 'bitcoin:' . rawurlencode( $banner['address'] ) ) . '">' . esc_html__( 'Open in wallet', 'dwads' ) . '</a></p>';

		$qr_template = DWADS_Settings::qr_template();
		if ( '' !== $qr_template ) {
			$qr_url = str_replace( '{ADDRESS}', rawurlencode( $banner['address'] ), $qr_template );
			$body  .= '<p><img class="dwads-qr" src="' . esc_url( $qr_url ) . '" alt="' . esc_attr__( 'Payment address QR code', 'dwads' ) . '" loading="lazy"></p>';
		}


		$body .= '<h2>' . esc_html__( 'Your banner', 'dwads' ) . '</h2>';
		$body .= '<p><img class="dwads-preview" src="' . esc_url( DWADS_Banners::image_url( $banner ) ) . '" alt="" width="' . (int) $banner['image_w'] . '" height="' . (int) $banner['image_h'] . '"></p>';
		$body .= '<p class="dwads-muted">' . esc_html( sprintf( '%d × %d px · %s', (int) $banner['image_w'], (int) $banner['image_h'], $banner['status'] ) ) . '</p>';
		if ( (int) $banner['source_w'] > 0 && ( (int) $banner['source_w'] !== (int) $banner['image_w'] || (int) $banner['source_h'] !== (int) $banner['image_h'] ) ) {
			$body .= '<p class="dwads-muted">' . esc_html(
				sprintf(
					/* translators: 1: width, 2: height, 3: final width, 4: final height */
					__( 'Uploaded at %1$d×%2$d and fitted to %3$d×%4$d by centre-cropping.', 'dwads' ),
					(int) $banner['source_w'],
					(int) $banner['source_h'],
					(int) $banner['image_w'],
					(int) $banner['image_h']
				)
			) . '</p>';
		}

		$body .= '<table class="dwads-table"><tbody>';
		$body .= '<tr><td>' . esc_html__( 'Destination', 'dwads' ) . '</td><td><a href="' . esc_url( $banner['target_url'] ) . '" rel="nofollow noopener" target="_blank">' . esc_html( wp_trim_words( (string) $banner['target_url'], 12 ) ) . '</a></td></tr>';
		if ( '' !== trim( (string) $banner['alt_text'] ) ) {
			$body .= '<tr><td>' . esc_html__( 'Alt text', 'dwads' ) . '</td><td>' . esc_html( $banner['alt_text'] ) . '</td></tr>';
		}
		if ( '' !== trim( (string) $banner['keywords'] ) ) {
			$body .= '<tr><td>' . esc_html__( 'Keywords', 'dwads' ) . '</td><td>' . esc_html( $banner['keywords'] ) . '</td></tr>';
		}
		$body .= '</tbody></table>';

		$recent = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT created_at, sats_charged FROM ' . DWADS_Clicks::table() . ' WHERE banner_id = %d AND charged = 1 ORDER BY id DESC LIMIT 10',
				(int) $banner['id']
			),
			ARRAY_A
		);
		if ( $recent ) {
			$body .= '<h2>' . esc_html__( 'Recent charged clicks', 'dwads' ) . '</h2><table class="dwads-table"><tbody>';
			foreach ( $recent as $click ) {
				$body .= '<tr><td>' . esc_html( $click['created_at'] ) . ' UTC</td><td>' . esc_html( DWADS_Price::format_btc( (int) $click['sats_charged'] ) ) . '</td></tr>';
			}
			$body .= '</tbody></table>';
		}

		$disclaimer = trim( (string) DWADS_Settings::get( 'manage_disclaimer', '' ) );
		if ( '' !== $disclaimer ) {
			$body .= '<p class="dwads-muted">' . wp_kses_post( $disclaimer ) . '</p>';
		}
		$body .= '<script>(function(){var b=document.getElementById("dwads-copy");if(!b)return;b.addEventListener("click",function(){var a=b.getAttribute("data-address")||"";function ok(){b.textContent="Copied";setTimeout(function(){b.textContent="Copy address";},1500);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(a).then(ok,ok);}else{var t=document.createElement("textarea");t.value=a;document.body.appendChild(t);t.select();try{document.execCommand("copy");}catch(e){}document.body.removeChild(t);ok();}});})();</script>';

		echo self::page_shell(  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			sprintf(
				/* translators: %d: banner id */
				__( 'Banner #%d', 'dwads' ),
				(int) $banner['id']
			),
			$body
		);
	}

	/**
	 * Standalone HTML document. Deliberately theme independent so a caching or
	 * security plugin cannot break the page.
	 *
	 * @param string $title Title.
	 * @param string $body  Body HTML.
	 * @return string
	 */
	private static function page_shell( $title, $body ) {
		$site = get_bloginfo( 'name' );
		$home = home_url( '/' );
		$css  = '
			:root { color-scheme: light; }
			* { box-sizing: border-box; }
			body { margin: 0; font: 16px/1.55 system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #f6f7f9; color: #16181d; }
			.dwads-wrap { max-width: 720px; margin: 0 auto; padding: 32px 18px 64px; }
			.dwads-brand { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 22px; }
			.dwads-brand a { color: inherit; text-decoration: none; font-weight: 600; }
			.dwads-card { background: #fff; border: 1px solid #e3e6eb; border-radius: 12px; padding: 22px 20px; }
			h1 { font-size: 1.5rem; margin: 0 0 6px; }
			h2 { font-size: 1.1rem; margin: 26px 0 10px; }
			h3 { font-size: 1rem; margin: 20px 0 8px; }
			label { display: block; font-weight: 600; font-size: .9rem; margin: 0 0 12px; }
			input[type=text], input[type=url], input[type=email], input[type=file] { display: block; width: 100%; margin-top: 6px; padding: 9px 10px; border: 1px solid #ccd2db; border-radius: 8px; font: inherit; background: #fff; }
			.dwads-btn { display: inline-block; border: 0; border-radius: 8px; padding: 10px 16px; background: #1d4ed8; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
			.dwads-btn-secondary { background: #e6e9ef; color: #1a1d23; }
			.dwads-summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin: 16px 0 4px; }
			@media (min-width: 560px) { .dwads-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
			.dwads-summary > div { border: 1px solid #e3e6eb; border-radius: 10px; padding: 12px; }
			.dwads-label { display: block; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; opacity: .7; }
			.dwads-summary strong { display: block; font-size: 1.15rem; margin: 2px 0; overflow-wrap: anywhere; }
			.dwads-muted { opacity: .68; font-size: .87rem; }
			.dwads-address code { display: inline-block; padding: 10px 12px; border-radius: 8px; border: 1px solid #ccd2db; word-break: break-all; font-size: .9rem; }
			.dwads-qr, .dwads-preview { max-width: 100%; height: auto; border-radius: 8px; }
			.dwads-notice { border-radius: 10px; padding: 12px 14px; margin: 0 0 16px; background: #eef2f7; border: 1px solid #d6dde8; }
			.dwads-notice-error { border-color: #e3b4b4; background: #fdf1f1; }
			.dwads-notice-success { border-color: #b9dcc4; background: #f0faf3; }
			.dwads-links { margin: 8px 0 0; padding-left: 18px; }
			.dwads-links a { word-break: break-all; }
			.dwads-row { border-top: 1px dashed #d3d9e2; padding-top: 14px; margin-top: 10px; }
			.dwads-row:first-child { border-top: 0; padding-top: 0; margin-top: 0; }
			.dwads-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
			.dwads-table td { border-bottom: 1px solid #e3e6eb; padding: 6px 4px; }

			/* Dark mode last, on purpose: these rules must beat the light ones
			   above at equal specificity, so anything earlier would lose. */
			@media (prefers-color-scheme: dark) {
				:root { color-scheme: dark; }
				body { background: #101318; color: #e8eaf0; }
				.dwads-card { background: #171b22; border-color: #2a3140; }
				input, code, .dwads-address code { background: #0f1318; color: inherit; border-color: #2f3745; }
				.dwads-notice { background: #1d2430; border-color: #2f3745; }
				.dwads-notice-error { background: #2a1c1c; border-color: #5a3535; }
				.dwads-notice-success { background: #16261c; border-color: #2f4a38; }
				.dwads-table td { border-bottom-color: #2a3140; }
				.dwads-summary > div { border-color: #2a3140; }
			}
		';
		return '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
			. '<title>' . esc_html( $title ) . ' · ' . esc_html( $site ) . '</title><style>' . $css . '</style></head><body><div class="dwads-wrap">'
			. '<div class="dwads-brand"><span>' . esc_html( $site ) . '</span><a href="' . esc_url( $home ) . '">← ' . esc_html__( 'Back to the site', 'dwads' ) . '</a></div>'
			. '<div class="dwads-card"><h1>' . esc_html( $title ) . '</h1>' . $body . '</div>'
			. self::credit_html()
			. '</div></body></html>';
	}
}
