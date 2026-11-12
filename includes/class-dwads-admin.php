<?php
/**
 * Admin screens.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
final class DWADS_Admin {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_dwads_banner_action', array( __CLASS__, 'handle_banner_action' ) );
		add_action( 'admin_post_dwads_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_dwads_diag', array( __CLASS__, 'handle_diag' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Load admin styles on our screens only.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'dwads' ) ) {
			return;
		}
		wp_enqueue_style( 'dwads-admin', DWADS_URL . 'assets/admin.css', array(), DWADS_VERSION );
		wp_enqueue_script( 'dwads-admin', DWADS_URL . 'assets/admin.js', array(), DWADS_VERSION, true );
	}

	/**
	 * Register menus.
	 *
	 * @return void
	 */
	public static function menu() {
		$pending = DWADS_Banners::pending_count();
		$label   = $pending > 0
			/* translators: %d: number of banners */
			? sprintf( __( 'Banner Ads %s', 'dwads' ), '<span class="update-plugins count-' . (int) $pending . '"><span class="update-count">' . (int) $pending . '</span></span>' )
			: __( 'Crypto Ads', 'dwads' );

		add_menu_page( __( 'Crypto Ads', 'dwads' ), $label, 'manage_options', 'dwads-banners', array( __CLASS__, 'render_banners' ), 'dashicons-megaphone', 58 );
		add_submenu_page( 'dwads-banners', __( 'Banners', 'dwads' ), __( 'Banners', 'dwads' ), 'manage_options', 'dwads-banners', array( __CLASS__, 'render_banners' ) );
		add_submenu_page( 'dwads-banners', __( 'Settings', 'dwads' ), __( 'Settings', 'dwads' ), 'manage_options', 'dwads-settings', array( __CLASS__, 'render_settings' ) );
		add_submenu_page( 'dwads-banners', __( 'Diagnostics', 'dwads' ), __( 'Diagnostics', 'dwads' ), 'manage_options', 'dwads-diagnostics', array( __CLASS__, 'render_diagnostics' ) );
	}

	/**
	 * Store a one-shot admin notice.
	 *
	 * @param string $message Message.
	 * @param string $type    success or error.
	 * @return void
	 */
	private static function flash( $message, $type = 'success' ) {
		set_transient( 'dwads_flash_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 90 );
	}

	/**
	 * Print and clear the notice.
	 *
	 * @return void
	 */
	private static function print_flash() {
		$key   = 'dwads_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) || empty( $flash['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$class = 'error' === $flash['type'] ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $flash['message'] ) . '</p></div>';
	}

	/**
	 * Redirect back to an admin screen.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra args.
	 * @return void
	 */
	private static function back( $page, $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Banners
	 * ------------------------------------------------------------------ */

	/**
	 * Banner list screen.
	 *
	 * @return void
	 */
	public static function render_banners() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$table    = DWADS_Banners::table();
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;

		$where = '1=1';
		$args  = array();
		if ( 'all' !== $status && in_array( $status, DWADS_Banners::statuses(), true ) ) {
			$where .= ' AND status = %s';
			$args[] = $status;
		}

		$total_sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where;
		$total     = $args
			? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $args ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $total_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$list_args = array_merge( $args, array( $per_page, $offset ) );
		$rows      = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE ' . $where . ' ORDER BY created_at DESC LIMIT %d OFFSET %d', $list_args ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$counts = array();
		foreach ( DWADS_Banners::statuses() as $s ) {
			$counts[ $s ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE status = %s', $s ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		echo '<div class="wrap dwads-admin">';
		echo '<h1>' . esc_html__( 'Crypto Banner Ads', 'dwads' ) . '</h1>';
		self::print_flash();

		if ( '' === trim( (string) DWADS_Settings::get( 'xpub', '' ) ) ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: settings link */
					__( 'No wallet key yet. Add an extended public key on the %s before advertisers can upload.', 'dwads' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=dwads-settings' ) ) . '">' . esc_html__( 'Settings page', 'dwads' ) . '</a>'
				)
			) . '</p></div>';
		}

		$filters = array( 'all' => __( 'All', 'dwads' ) );
		foreach ( DWADS_Banners::statuses() as $s ) {
			$filters[ $s ] = ucfirst( $s ) . ' (' . (int) $counts[ $s ] . ')';
		}
		echo '<ul class="subsubsub">';
		$parts = array();
		foreach ( $filters as $key => $label ) {
			$url     = add_query_arg( array( 'page' => 'dwads-banners', 'status' => $key ), admin_url( 'admin.php' ) );
			$class   = ( $status === $key ) ? ' class="current"' : '';
			$parts[] = '<li><a href="' . esc_url( $url ) . '"' . $class . '>' . esc_html( $label ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo implode( ' | ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</ul>';

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No banners yet. Create a page with the [dwads_upload] shortcode to open the public upload form.', 'dwads' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped dwads-banners-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Banner', 'dwads' ) . '</th>';
		echo '<th>' . esc_html__( 'Advertiser', 'dwads' ) . '</th>';
		echo '<th>' . esc_html__( 'Balance', 'dwads' ) . '</th>';
		echo '<th>' . esc_html__( 'Clicks', 'dwads' ) . '</th>';
		echo '<th>' . esc_html__( 'Price / click', 'dwads' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'dwads' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'dwads' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $banner ) {
			$balance = DWADS_Banners::balance_sats( $banner );
			$price   = DWADS_Settings::click_price_for( $banner );
			echo '<tr>';
			echo '<td><div class="dwads-thumb"><img src="' . esc_url( DWADS_Banners::image_url( $banner ) ) . '" alt="" width="' . (int) $banner['image_w'] . '" height="' . (int) $banner['image_h'] . '"></div>';
			echo '<div class="dwads-meta">#' . (int) $banner['id'] . ' · ' . (int) $banner['image_w'] . '×' . (int) $banner['image_h'];
			if ( (int) $banner['source_w'] > 0 && ( (int) $banner['source_w'] !== (int) $banner['image_w'] || (int) $banner['source_h'] !== (int) $banner['image_h'] ) ) {
				echo ' <span title="' . esc_attr__( 'Original upload', 'dwads' ) . '">(fitted from ' . (int) $banner['source_w'] . '×' . (int) $banner['source_h'] . ')</span>';
			}
			echo ' · ' . esc_html( (string) $banner['created_at'] ) . ' UTC</div>';
			echo '<div class="dwads-meta"><a href="' . esc_url( $banner['target_url'] ) . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html( wp_trim_words( (string) $banner['target_url'], 8 ) ) . '</a></div>';
			echo '<div class="dwads-meta dwads-mono" title="' . esc_attr( $banner['address'] ) . '">' . esc_html( $banner['address'] ) . '</div>';

			$manage_url = DWADS_Banners::manage_url_for( $banner );
			if ( '' !== $manage_url ) {
				echo '<div class="dwads-meta">' . esc_html__( 'Management link', 'dwads' ) . ' <a href="' . esc_url( $manage_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'open', 'dwads' ) . '</a></div>';
				echo '<input type="text" readonly class="dwads-manage-link dwads-mono" value="' . esc_attr( $manage_url ) . '" onclick="this.select()" title="' . esc_attr__( 'Click to select the whole link', 'dwads' ) . '">';
				if ( ! empty( $banner['email'] ) ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dwads-inline">';
					wp_nonce_field( 'dwads_banner_action' );
					echo '<input type="hidden" name="action" value="dwads_banner_action"><input type="hidden" name="do" value="email_link"><input type="hidden" name="banner_id" value="' . (int) $banner['id'] . '">';
					echo '<button type="submit" class="button button-small">' . esc_html__( 'Email this link to the advertiser', 'dwads' ) . '</button>';
					echo '</form>';
				}
			} else {
				echo '<div class="dwads-meta">' . esc_html__( 'Management link not recoverable (banner created before 1.1).', 'dwads' ) . '</div>';
			}
			echo '</td>';

			echo '<td>' . esc_html( '' !== $banner['advertiser_label'] ? $banner['advertiser_label'] : '—' );
			if ( ! empty( $banner['email'] ) ) {
				echo '<div class="dwads-meta">' . esc_html( $banner['email'] ) . '</div>';
			}
			echo '</td>';

			echo '<td><strong>' . esc_html( DWADS_Price::format_btc( $balance ) ) . '</strong><div class="dwads-meta">$' . esc_html( number_format( DWADS_Price::sats_to_usd( $balance ), 2 ) ) . ' · ' . esc_html( number_format_i18n( DWADS_Banners::remaining_clicks( $banner ) ) ) . ' clicks left</div>';
			echo '<div class="dwads-meta">in ' . esc_html( DWADS_Price::format_btc( (int) $banner['sats_received'] ) ) . ' / out ' . esc_html( DWADS_Price::format_btc( (int) $banner['sats_spent'] ) ) . '</div></td>';

			echo '<td>' . esc_html( number_format_i18n( (int) $banner['clicks_charged'] ) ) . '<div class="dwads-meta">' . esc_html( sprintf( __( '%s impressions', 'dwads' ), number_format_i18n( (int) $banner['impressions'] ) ) ) . '</div></td>';

			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dwads-inline">';
			wp_nonce_field( 'dwads_banner_action' );
			echo '<input type="hidden" name="action" value="dwads_banner_action"><input type="hidden" name="do" value="price"><input type="hidden" name="banner_id" value="' . (int) $banner['id'] . '">';
			echo '<input type="number" step="0.001" min="0" name="click_price_usd" value="' . esc_attr( number_format( $price, 3, '.', '' ) ) . '" class="small-text"> ';
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Set', 'dwads' ) . '</button>';
			echo '</form><div class="dwads-meta">' . ( null === $banner['click_price_usd'] || '' === (string) $banner['click_price_usd'] ? esc_html__( 'global default', 'dwads' ) : esc_html__( 'custom', 'dwads' ) ) . '</div></td>';

			echo '<td><span class="dwads-badge dwads-badge-' . esc_attr( $banner['status'] ) . '">' . esc_html( $banner['status'] ) . '</span></td>';

			echo '<td class="dwads-actions">';
			$buttons = array();
			if ( in_array( $banner['status'], array( 'pending', 'rejected', 'paused' ), true ) ) {
				$buttons['approve'] = __( 'Approve', 'dwads' );
			}
			if ( 'active' === $banner['status'] ) {
				$buttons['pause'] = __( 'Pause', 'dwads' );
			}
			if ( in_array( $banner['status'], array( 'active', 'paused' ), true ) ) {
				$buttons['reject'] = __( 'Reject', 'dwads' );
			}
			$buttons['delete'] = __( 'Delete', 'dwads' );

			// Kept separate from the status buttons: it changes no state a reader
			// can see, it only clears the two figures shown on the next column.
			$buttons['reset_impressions'] = __( 'Reset impressions', 'dwads' );

			foreach ( $buttons as $do => $text ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dwads-inline">';
				wp_nonce_field( 'dwads_banner_action' );
				echo '<input type="hidden" name="action" value="dwads_banner_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="banner_id" value="' . (int) $banner['id'] . '">';
				$class = 'delete' === $do ? 'button button-small button-link-delete' : 'button button-small';
				$confirm = '';
				if ( 'delete' === $do ) {
					$confirm = ' onclick="return confirm(\'' . esc_js( __( 'Delete this banner and its history?', 'dwads' ) ) . '\')"';
				} elseif ( 'reset_impressions' === $do ) {
					$confirm = ' onclick="return confirm(\'' . esc_js( __( 'Reset the impression counter for this banner to zero? Clicks charged, the balance and the click log are kept.', 'dwads' ) ) . '\')"';
				}
				echo '<button type="submit" class="' . esc_attr( $class ) . '"' . $confirm . '>' . esc_html( $text ) . '</button>';
				echo '</form>';
			}

			$one_click = max( 1, DWADS_Price::usd_to_sats( DWADS_Settings::click_price_for( $banner ) ) );
			if ( 'active' === $banner['status'] && $balance < $one_click ) {
				echo '<div class="dwads-meta">' . esc_html(
					sprintf(
						/* translators: 1: balance in sats, 2: sats needed for one click */
						__( 'Below one click (%1$s of %2$s sats): not served', 'dwads' ),
						number_format_i18n( $balance ),
						number_format_i18n( $one_click )
					)
				) . '</div>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( array( 'page' => 'dwads-banners', 'status' => $status, 'paged' => '%#%' ), admin_url( 'admin.php' ) ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}

		echo '<p class="dwads-meta">' . esc_html__( 'Banner management links are secrets: the plugin stores only a hash, so they cannot be recovered here. Advertisers can replace a lost link by uploading again.', 'dwads' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Handle a banner row action.
	 *
	 * @return void
	 */
	public static function handle_banner_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'dwads' ) );
		}
		check_admin_referer( 'dwads_banner_action' );

		$id     = isset( $_POST['banner_id'] ) && is_scalar( $_POST['banner_id'] ) ? (int) $_POST['banner_id'] : 0;
		$do     = isset( $_POST['do'] ) && is_scalar( $_POST['do'] ) ? sanitize_key( wp_unslash( (string) $_POST['do'] ) ) : '';
		$notice = __( 'Nothing changed.', 'dwads' );

		if ( $id > 0 ) {
			switch ( $do ) {
				case 'approve':
					DWADS_Banners::set_status( $id, 'active' );
					$banner = DWADS_Banners::get( $id );
					if ( $banner && DWADS_Settings::get( 'notify_advertiser', 1 ) ) {
						DWADS_Banners::mail_advertiser(
							$banner,
							'email_approved_subject',
							'email_approved_body'
						);
					}
					$notice = __( 'Banner approved and live.', 'dwads' );
					break;
				case 'reject':
					DWADS_Banners::set_status( $id, 'rejected' );
					$banner = DWADS_Banners::get( $id );
					if ( $banner && DWADS_Settings::get( 'notify_advertiser', 1 ) ) {
						DWADS_Banners::mail_advertiser(
							$banner,
							'email_rejected_subject',
							'email_rejected_body'
						);
					}
					$notice = __( 'Banner rejected.', 'dwads' );
					break;
				case 'email_link':
					$banner = DWADS_Banners::get( $id );
					$sent   = $banner ? DWADS_Banners::mail_advertiser(
						$banner,
						'email_resend_subject',
						'email_resend_body'
					) : false;
					$notice = $sent ? __( 'Management link emailed.', 'dwads' ) : __( 'No email address on file for this banner.', 'dwads' );
					break;
				case 'pause':
					DWADS_Banners::set_status( $id, 'paused' );
					$notice = __( 'Banner paused.', 'dwads' );
					break;
				case 'delete':
					DWADS_Banners::delete( $id );
					$notice = __( 'Banner deleted.', 'dwads' );
					break;
				case 'reset_impressions':
					DWADS_Banners::reset_impressions( $id );
					$notice = __( 'Impression counter reset to zero.', 'dwads' );
					break;
				case 'price':
					$price = isset( $_POST['click_price_usd'] ) && is_scalar( $_POST['click_price_usd'] ) ? (float) $_POST['click_price_usd'] : 0;
					DWADS_Banners::update( $id, array( 'click_price_usd' => $price > 0 ? $price : null ) );
					$notice = __( 'Click price updated.', 'dwads' );
					break;
			}
		}

		self::flash( $notice );
		self::back( 'dwads-banners' );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * Settings screen.
	 *
	 * @return void
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap dwads-admin"><h1>' . esc_html__( 'Crypto Banner Ads settings', 'dwads' ) . '</h1>';
		self::print_flash();

		// Compare the raw database row with what the page is displaying. A
		// mismatch means a cache (or a second install) is serving stale values.
		$raw_row = DWADS_Settings::raw_stored();
		if ( is_array( $raw_row ) ) {
			$effective = DWADS_Settings::all();
			foreach ( array( 'xpub', 'delete_data_on_uninstall' ) as $check_key ) {
				$raw_value     = (string) ( $raw_row[ $check_key ] ?? '' );
				$display_value = (string) ( $effective[ $check_key ] ?? '' );
				if ( $raw_value !== $display_value ) {
					echo '<div class="notice notice-error"><p>' . esc_html(
						sprintf(
							/* translators: 1: setting key, 2: database value, 3: displayed value */
							__( 'Stored value differs from the displayed value for “%1$s”: the database has “%2$s” but this page is showing “%3$s”. A stale object cache or a second copy of the site is involved.', 'dwads' ),
							$check_key,
							'' === $raw_value ? '(empty)' : $raw_value,
							'' === $display_value ? '(empty)' : $display_value
						)
					) . '</p></div>';
				}
			}
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dwads_save_settings' );
		echo '<input type="hidden" name="action" value="dwads_save_settings">';

		echo '<h2 class="title">' . esc_html__( 'Pricing and moderation', 'dwads' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		self::field_number( 'click_price_usd', __( 'Default price per click (USD)', 'dwads' ), __( 'Charged from the banner balance at the current exchange rate. Each banner can override this.', 'dwads' ), '0.0001' );
		self::field_checkbox( 'approval_required', __( 'Require admin approval', 'dwads' ), __( 'When off, a paid banner goes live as soon as it is uploaded.', 'dwads' ) );
		self::field_checkbox( 'target_new_tab', __( 'Open advertiser links in a new tab', 'dwads' ) );
		self::field_text( 'rel_attributes', __( 'rel attributes on banner links', 'dwads' ), __( 'Recommended: nofollow sponsored noopener', 'dwads' ) );
		self::field_checkbox( 'ad_label_enabled', __( 'Show an advertisement label on banners', 'dwads' ), __( 'A small corner tag, the way the large ad networks disclose paid placements.', 'dwads' ) );
		self::field_checkbox( 'credit_enabled', __( 'Show a small credit line on the plugin’s own pages', 'dwads' ), __( 'A single quiet line — “Powered by Crypto Banner Ads from Reza Consulting”, with both names linked — at the bottom of the upload form page and the banner management page. Ordinary posts and pages never show it, even when they display banners.', 'dwads' ) );
		self::field_text( 'ad_label_text', __( 'Label text', 'dwads' ), __( 'Keep it short and honest. “Sponsored” is the usual choice; “Ad” is the shortest.', 'dwads' ) );
		self::field_checkbox( 'notify_advertiser', __( 'Email advertisers their link and approval status', 'dwads' ), __( 'Only applies when an email address was supplied at upload. This is the recovery path when a link is lost.', 'dwads' ) );
		echo '</table>';

		echo '<h2 class="title">' . esc_html__( 'Link under the banner', 'dwads' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		self::field_checkbox( 'footer_link_enabled', __( 'Show a small link underneath each banner', 'dwads' ), __( 'It sits below the creative, in the page flow rather than over the image, and it never appears when a slot is empty. Useful for pointing readers at your own “advertise here” page.', 'dwads' ) );
		self::field_text( 'footer_link_text', __( 'Link text', 'dwads' ), __( 'Short and plain. Leave empty to use “Advertise here”.', 'dwads' ) );
		self::field_text( 'footer_link_url', __( 'Link address', 'dwads' ), __( 'Leave empty to use the page that contains the [dwads_upload] shortcode, when one can be found. Type none to switch the link off without clearing the other fields.', 'dwads' ) );
		self::field_select(
			'footer_link_align',
			__( 'Alignment', 'dwads' ),
			__( 'Alignment is relative to the banner image itself, so “left” lines up with the creative’s left edge even when the column is wider.', 'dwads' ),
			array(
				'left'   => __( 'Left', 'dwads' ),
				'center' => __( 'Center', 'dwads' ),
				'right'  => __( 'Right', 'dwads' ),
			)
		);
		self::field_checkbox( 'footer_link_new_tab', __( 'Open the link in a new tab', 'dwads' ), __( 'The “Advertise here” link opens its destination in a new tab instead of navigating the reader away. Off by default.', 'dwads' ) );
		echo '</table>';

		echo '<h2 class="title">' . esc_html__( '“Fund this banner” link', 'dwads' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		self::field_checkbox( 'fund_link_enabled', __( 'Show a second link underneath each banner', 'dwads' ), __( 'Same style as the link above, pointing at that banner’s own management page, where a reader can add funds to it. On by default.', 'dwads' ) );
		self::field_text( 'fund_link_text', __( 'Link text', 'dwads' ), __( 'Leave empty to use “Fund this banner”. Type none to switch the link off without clearing the other fields.', 'dwads' ) );
		self::field_select(
			'fund_link_align',
			__( 'Alignment', 'dwads' ),
			__( 'If both links are shown and end up on the same side, this one moves to the opposite side automatically, so the two never overlap.', 'dwads' ),
			array(
				'left'   => __( 'Left', 'dwads' ),
				'center' => __( 'Center', 'dwads' ),
				'right'  => __( 'Right', 'dwads' ),
			)
		);
		self::field_checkbox( 'fund_link_new_tab', __( 'Open the link in a new tab', 'dwads' ), __( 'The “Fund this banner” link opens the funding page in a new tab instead of navigating the reader away. Off by default.', 'dwads' ) );
		echo '</table>';

		echo '<h2 class="title">' . esc_html__( 'Uploads', 'dwads' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		self::field_textarea( 'sizes', __( 'Allowed sizes (one per line)', 'dwads' ), __( 'Format: WxH, then optional | label | short note, then the word recommended for the ones you want to push. Example: 300x250 | Medium rectangle | in-content | recommended', 'dwads' ) );

		$preview_sizes = DWADS_Settings::sizes();
		if ( $preview_sizes ) {
			echo '<tr><th scope="row">' . esc_html__( 'What advertisers will see', 'dwads' ) . '</th><td><ul class="dwads-size-list">';
			foreach ( $preview_sizes as $size_key => $size_item ) {
				echo '<li><code>' . esc_html( $size_key ) . '</code>';
				if ( ! empty( $size_item['label'] ) ) {
					echo ' <strong>' . esc_html( $size_item['label'] ) . '</strong>';
				}
				if ( ! empty( $size_item['note'] ) ) {
					echo ' <span class="dwads-meta">' . esc_html( $size_item['note'] ) . '</span>';
				}
				if ( ! empty( $size_item['recommended'] ) ) {
					echo ' <span class="dwads-badge dwads-badge-active">' . esc_html__( 'recommended', 'dwads' ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul></td></tr>';
		}

		self::field_textarea( 'upload_intro', __( 'Upload page introduction', 'dwads' ), __( 'Shown above the form, so advertisers know what happens next. Plain text or simple HTML.', 'dwads' ) );
		self::field_textarea( 'manage_disclaimer', __( 'Management page disclaimer', 'dwads' ), __( 'Shown at the very bottom of the page each advertiser uses to top up a banner. Empty by default, which prints nothing. Plain text or simple HTML.', 'dwads' ) );
		self::field_number( 'max_upload_kb', __( 'Maximum file size (KB)', 'dwads' ), '', '1' );
		$php_uploads = (int) ini_get( 'max_file_uploads' );
		self::field_number(
			'max_banners_per_upload',
			__( 'Maximum banners per upload', 'dwads' ),
			$php_uploads > 0
				? sprintf(
					/* translators: %d: PHP max_file_uploads value */
					__( 'How many images one submission may contain. Your server accepts at most %d files in a single request (PHP max_file_uploads), so a larger number here cannot all arrive at once.', 'dwads' ),
					$php_uploads
				)
				: __( 'How many images one submission may contain.', 'dwads' ),
			'1'
		);
		self::field_checkbox( 'auto_fit_uploads', __( 'Fit near-miss images automatically', 'dwads' ), __( 'If an image has almost the right shape for an allowed size, scale it and centre-crop the small overflow instead of rejecting it. Crops larger than 8% are refused, and the advertiser is told exactly what happened.', 'dwads' ) );
		self::field_checkbox( 'notify_admin_uploads', __( 'Email me when a new banner is uploaded', 'dwads' ) );
		echo '</table>';
		echo '<p class="description">' . wp_kses_post( __( 'Publish a page containing the <code>[dwads_upload]</code> shortcode to give advertisers the upload form.', 'dwads' ) ) . '</p>';

		echo '<h2 class="title">' . esc_html__( 'Payment wallet', 'dwads' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		self::field_text( 'xpub', __( 'Extended public key (watch-only)', 'dwads' ), __( 'Paste a zpub (native segwit) or ypub (wrapped segwit) account key from a modern descriptor wallet. The plugin derives one address per banner and never stores a private key. Legacy xpub and testnet keys are refused: those wallets put change back on the same chain the banners are numbered from. Use an account-level key from a wallet that is not used for anything else. Note: address numbering restarts at 0 when the plugin data is deleted, so re-using the same key after a wipe hands old, already-funded addresses to new banners. Use a different account or raise the address index in Diagnostics before uploading again. See “Use this wallet for nothing else” below for change addresses and sweeping.', 'dwads' ), 'dwads-mono' );
		self::field_text( 'derivation_path', __( 'Derivation path template', 'dwads' ), __( 'Default 0/{i} for the external chain. Use {i} where the banner index goes.', 'dwads' ) );
		self::field_select(
			'address_type',
			__( 'Address type', 'dwads' ),
			__( 'auto follows the key prefix (ypub → wrapped segwit, zpub → native segwit). Legacy keys are refused on save.', 'dwads' ),
			array(
				'auto'        => __( 'Automatic (recommended)', 'dwads' ),
				'p2sh-p2wpkh' => 'Segwit in P2SH (3…)',
				'p2wpkh'      => 'Native segwit (bc1…)',
			)
		);
		self::field_checkbox( 'credit_mempool', __( 'Credit payments as soon as they appear in the mempool', 'dwads' ), __( 'Unconfirmed payments that disappear from the network are removed again on a later check.', 'dwads' ) );
		self::field_text( 'price_api', __( 'Price API URL', 'dwads' ), __( 'Defaults to mempool.space. The response must contain a USD field.', 'dwads' ) );
		self::field_number( 'fallback_btc_price_usd', __( 'Fallback BTC price (USD)', 'dwads' ), __( 'Used only if the price API is unreachable. Leave empty to disable.', 'dwads' ), '0.01' );
		self::field_number( 'price_cache_minutes', __( 'Price cache (minutes)', 'dwads' ), '', '1' );
		echo '</table>';
		echo self::wallet_next_address_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::wallet_help_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<h2 class="title">' . esc_html__( 'Top-up page', 'dwads' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		self::field_textarea(
			'exchange_widget',
			__( 'Swap provider embed', 'dwads' ),
			__( 'Paste the complete embed code your swap provider gives you, exactly as they give it — iframe, script tag, whatever is in it. It is printed as pasted; the plugin does not detect a URL or wrap anything around it, so any provider works. The one thing it changes is {ADDRESS}, which becomes that banner\'s deposit address (each banner has its own, so it cannot be typed in by hand). Type none to show no widget. This is an administrator-only field and its contents are trusted as given.', 'dwads' ),
			DWADS_Settings::exchange_template(),
			10
		);
		self::field_text( 'qr_service', __( 'QR image template', 'dwads' ), __( 'Use {ADDRESS} as the placeholder. An empty field uses the default service; type none to hide the QR code.', 'dwads' ) );
		echo '</table>';

		echo '<h2 class="title">' . esc_html__( 'Public URLs', 'dwads' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		self::field_text( 'click_base', __( 'Click path', 'dwads' ), __( 'Default ads-go. Avoid a path that already exists.', 'dwads' ) );
		self::field_text( 'manage_base', __( 'Management path', 'dwads' ), __( 'Default ads/manage.', 'dwads' ) );
		echo '</table>';

		echo '<h2 class="title">' . esc_html__( 'Anti-fraud', 'dwads' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		self::field_number( 'dedupe_hours', __( 'Charge a visitor once per banner every N hours', 'dwads' ), '', '1' );
		self::field_number( 'max_clicks_per_ip_hour', __( 'Maximum charged clicks per address per hour', 'dwads' ), __( 'Backstop against one address rotating user-agents to dodge the per-visitor limit. A shared address — an office NAT, a phone carrier — can look like this, so on a high-traffic site raise it or set 0 to disable.', 'dwads' ), '1' );
		self::field_number( 'max_uploads_per_ip_hour', __( 'Maximum uploads per address per hour', 'dwads' ), '', '1' );
		self::field_number( 'impression_ttl_minutes', __( 'Click token lifetime (minutes)', 'dwads' ), '', '1' );
		self::field_checkbox( 'record_impressions', __( 'Record impressions (needed for replay protection)', 'dwads' ) );
		self::field_number( 'impression_dedupe_hours', __( 'Count an impression once per visitor every N hours', 'dwads' ), __( 'Crawlers and repeat views inside this window do not move the counter an advertiser sees. 24 is a sensible default; 0 counts every single request, which is what made the old numbers look inflated. Needs impressions to be recorded, and never more than 48, because rows are pruned after two days.', 'dwads' ), '1' );
		self::field_textarea( 'bot_uas', __( 'Bot user-agent pattern', 'dwads' ), __( 'Case-insensitive regular expression. Matching clicks are redirected but not charged.', 'dwads' ) );
		echo '</table>';

		echo '<h2 class="title">' . esc_html__( 'Human visitor checks', 'dwads' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'These settings decide what counts as a person rather than a machine. They are deliberately strict: a legitimate click that fails a check is not charged, so money is left on the table rather than handed to a crawler. Turning them off makes the numbers larger and less true.', 'dwads' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Every cap below stops billing, never serving: a banner that hits one keeps being shown, it just stops charging until the window rolls over. Set any cap to 0 to disable it. The per-visitor and per-address caps stop one person or script draining balances across the site; the per-banner cap and the click-rate brake protect each advertiser’s money.', 'dwads' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		self::field_checkbox( 'impression_viewable', __( 'Only count an impression when the banner is actually on screen', 'dwads' ), __( 'The ad script reports when at least half of the creative has been visible for a second. Off counts the moment the banner is requested, which includes slots nobody scrolled to.', 'dwads' ) );
		self::field_number( 'click_min_dwell_ms', __( 'Minimum time on screen before a click can be charged (milliseconds)', 'dwads' ), __( 'A person cannot see a banner and click it in a few milliseconds. 400 is conservative and unnoticeable.', 'dwads' ), '50' );
		self::field_checkbox( 'require_gesture', __( 'Require a real pointer or key press on the banner', 'dwads' ), __( 'A click is only billed when the browser first reported a pointer, keyboard or context-menu event on the creative. Catches anything that scrapes links out of the page without rendering it.', 'dwads' ) );
		self::field_checkbox( 'require_browser_headers', __( 'Require real browser fetch metadata', 'dwads' ), __( 'Chrome, Firefox and Safari all send Sec-Fetch-* headers on every request; scripts and scrapers do not. Very old browsers omit them and will not be billed.', 'dwads' ) );
		self::field_checkbox( 'honeypot_enabled', __( 'Add an invisible decoy link to every slot', 'dwads' ), __( 'Hidden from people and from the keyboard. Anything that follows it is a link-following machine, and is never billed again.', 'dwads' ) );
		self::field_number( 'max_clicks_per_visitor_day', __( 'Maximum charged clicks per visitor per day (all banners)', 'dwads' ), __( 'One visitor is billed at most this many times across all banners in a rolling day. A real person browsing clicks rarely; anything above this is automation or an extremely unusual visitor. 0 disables.', 'dwads' ), '1' );
		self::field_number( 'max_clicks_per_ip_day', __( 'Maximum charged clicks per address per day', 'dwads' ), __( 'The day-long version of the per-address limit, guarding against one address evading the per-visitor cap by rotating user-agents. On a high-traffic site behind shared or carrier addresses this is usually the first cap to raise or set to 0 — the per-banner cap and the click-rate brake are the real money guards.', 'dwads' ), '1' );
		self::field_number( 'max_clicks_per_banner_day', __( 'Maximum charged clicks per banner per day', 'dwads' ), __( 'Off by default. An opt-in guard if you suspect one specific banner is being drained: the ceiling on what that banner can be charged in a rolling day, whatever the traffic looks like. When it hits the cap, billing stops for that banner until the window rolls over (it keeps serving). The per-visitor and per-address caps already bound the damage, so most sites never need this. 0 disables.', 'dwads' ), '1' );
		self::field_number( 'click_rate_ceiling_percent', __( 'Pause billing above this click rate (%)', 'dwads' ), __( 'Real audiences click a banner a fraction of a percent of the time. Once a banner passes this rate it stops billing until the rolling day resets. 0 disables the brake.', 'dwads' ), '1' );
		self::field_number( 'click_rate_min_impressions', __( 'Impressions needed before the click-rate brake applies', 'dwads' ), __( 'Prevents a brand new banner from tripping the brake on its first few views.', 'dwads' ), '1' );
		echo '</table>';

		echo '<h2 class="title">' . esc_html__( 'Email copy', 'dwads' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Every email the plugin sends, editable. Placeholders are replaced at send time: {SITE_NAME}, {SITE_URL}, {LINK} (that banner’s management link), {LINKS} (one management link per banner), {BANNER_ID}, {BANNERS} (the admin’s list) and {ADMIN_URL}.', 'dwads' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		self::field_text( 'email_upload_subject', __( 'Upload confirmation — subject', 'dwads' ) );
		self::field_textarea( 'email_upload_body', __( 'Upload confirmation — body', 'dwads' ), __( 'Sent right after a banner is uploaded. {LINKS} is one management link per banner, one per line.', 'dwads' ) );
		self::field_text( 'email_approved_subject', __( 'Approved — subject', 'dwads' ) );
		self::field_textarea( 'email_approved_body', __( 'Approved — body', 'dwads' ), __( 'Sent when a banner is approved. {LINK} is that banner’s management link.', 'dwads' ) );
		self::field_text( 'email_rejected_subject', __( 'Rejected — subject', 'dwads' ) );
		self::field_textarea( 'email_rejected_body', __( 'Rejected — body', 'dwads' ), __( 'Sent when a banner is rejected.', 'dwads' ) );
		self::field_text( 'email_resend_subject', __( 'Management link re-sent — subject', 'dwads' ) );
		self::field_textarea( 'email_resend_body', __( 'Management link re-sent — body', 'dwads' ), __( 'Sent when an admin re-emails a banner’s management link.', 'dwads' ) );
		self::field_text( 'email_admin_upload_subject', __( 'Admin upload notice — subject', 'dwads' ) );
		self::field_textarea( 'email_admin_upload_body', __( 'Admin upload notice — body', 'dwads' ), __( 'Sent to the site admin when banners are uploaded. {BANNERS} lists each banner and its status; {ADMIN_URL} is the moderation screen.', 'dwads' ) );
		echo '</table>';

		echo '<h2 class="title">' . esc_html__( 'Housekeeping', 'dwads' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		self::field_checkbox( 'delete_data_on_uninstall', __( 'Delete all banner data when the plugin is uninstalled', 'dwads' ), __( 'Off by default so an accidental uninstall does not destroy paid balances.', 'dwads' ) );
		echo '</table>';

		submit_button( __( 'Save settings', 'dwads' ) );
		echo '</form>';

		echo '<h2 class="title">' . esc_html__( 'Placement snippet', 'dwads' ) . '</h2>';
		echo '<p>' . esc_html__( 'Paste the script once per page, then a placeholder div wherever a banner should rotate. The slot value is the exact pixel size of the banner you want there, written as WxH.', 'dwads' ) . '</p>';

		$slot_options = array_keys( DWADS_Settings::sizes() );
		$default_slot = in_array( '300x250', $slot_options, true ) ? '300x250' : ( $slot_options ? $slot_options[0] : '' );

		echo '<p><label for="dwads-slot-picker"><strong>' . esc_html__( 'Choose a slot size', 'dwads' ) . '</strong></label><br>';
		echo '<select id="dwads-slot-picker">';
		foreach ( $slot_options as $slot_option ) {
			echo '<option value="' . esc_attr( $slot_option ) . '"' . selected( $slot_option, $default_slot, false ) . '>' . esc_html( $slot_option ) . '</option>';
		}
		echo '</select> <span class="description">' . esc_html__( 'These are the sizes from the “Allowed sizes” field above. Add your own there and they show up here, in the upload form and in the widget.', 'dwads' ) . '</span></p>';

		echo '<p><label for="dwads-slot-mobile-picker"><strong>' . esc_html__( 'Size on phones (optional)', 'dwads' ) . '</strong></label><br>';
		echo '<select id="dwads-slot-mobile-picker">';
		echo '<option value="">' . esc_html__( 'Same size everywhere', 'dwads' ) . '</option>';
		foreach ( $slot_options as $slot_option ) {
			echo '<option value="' . esc_attr( $slot_option ) . '">' . esc_html( $slot_option ) . '</option>';
		}
		echo '</select> <span class="description">' . esc_html__( 'Adds a second size for narrow screens, so a phone gets a banner that fits instead of a wide one scaled down to unreadable. It must be one of the allowed sizes.', 'dwads' ) . '</span></p>';

		echo '<textarea id="dwads-snippet" readonly rows="5" class="large-text code dwads-mono">' . esc_textarea( self::snippet_code( $default_slot ) ) . '</textarea>';
		echo '<p><label for="dwads-shortcode"><strong>' . esc_html__( 'Shortcode equivalent', 'dwads' ) . '</strong></label><br>';
		echo '<input type="text" readonly id="dwads-shortcode" class="regular-text code dwads-mono" value="' . esc_attr( '[dwads slot="' . $default_slot . '"]' ) . '"></p>';
		echo '<div class="dwads-help">';
		echo '<h3>' . esc_html__( 'Slot values in detail', 'dwads' ) . '</h3>';
		echo '<ul>';
		echo '<li>' . wp_kses_post( __( 'A slot matches banners of <strong>exactly</strong> those dimensions. A <code>300x250</code> slot only ever shows 300×250 banners; a 728×90 banner will never appear in it.', 'dwads' ) ) . '</li>';
		echo '<li>' . wp_kses_post( __( 'Valid values are exactly the sizes listed in “Allowed sizes”: by default <code>728x90</code>, <code>320x100</code>, <code>300x250</code>, <code>336x280</code>, <code>250x250</code>, <code>160x600</code>, <code>300x600</code> and <code>970x250</code>. Any other value, including a blank one, is rejected and nothing renders.', 'dwads' ) ) . '</li>';
		echo '<li>' . wp_kses_post( __( 'The value is not a CSS size: it is a filter that picks which banners compete for the spot. The image is inserted at its natural size, so a slot never reserves space it cannot fill.', 'dwads' ) ) . '</li>';
		echo '<li>' . wp_kses_post( __( 'Spacing and capitalisation are forgiving (<code>300 X 250</code> works), but the numbers must match a configured size exactly.', 'dwads' ) ) . '</li>';
		echo '<li>' . wp_kses_post( __( 'Several slots on one page are fine and rotate independently. The script tag only needs to be on the page once.', 'dwads' ) ) . '</li>';
		echo '<li>' . wp_kses_post( __( 'A slot can name a second size for narrow screens: <code>[dwads slot="728x90" slot_mobile="320x100"]</code>. Below 600px wide the mobile size is served instead, so nothing is scaled down. If no banner exists at the mobile size, the wide creative is used so the space is not left empty; add <code>fallback="off"</code> to leave it blank instead.', 'dwads' ) ) . '</li>';
		echo '<li>' . wp_kses_post( __( 'Sizes that fit a 358px phone column natively: <code>320x100</code>, <code>300x250</code>, <code>336x280</code>, <code>250x250</code>, <code>300x600</code> and <code>160x600</code>. Only <code>728x90</code> and <code>970x250</code> need a mobile partner.', 'dwads' ) ) . '</li>';
		echo '</ul>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Copy-paste snippet for the site owner.
	 *
	 * @param string $slot Slot.
	 * @return string
	 */
	public static function snippet_code( $slot = '300x250', $mobile = '' ) {
		$script = DWADS_URL . 'assets/dwads.js';
		$api    = rest_url( 'dwads/v1/serve' );

		$mobile_attr = '';
		if ( '' !== (string) $mobile && (string) $mobile !== (string) $slot ) {
			$mobile_attr = ' data-dwads-slot-mobile="' . esc_attr( $mobile ) . '"';
		}

		return "<script async src=\"{$script}\"></script>\n"
			. "<!-- Repeat this div wherever a banner should appear -->\n"
			. '<div data-dwads-slot="' . $slot . '"' . $mobile_attr . ' data-dwads-api="' . $api . '"></div>';
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'dwads' ) );
		}
		check_admin_referer( 'dwads_save_settings' );

		$input   = isset( $_POST['dwads_settings'] ) && is_array( $_POST['dwads_settings'] ) ? wp_unslash( $_POST['dwads_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$current = DWADS_Settings::all();
		$new     = $current;
		$errors  = array();
		$scalar  = static function ( $key, $default = '' ) use ( $input ) {
			return ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) ? (string) $input[ $key ] : $default;
		};

		$new['click_price_usd'] = number_format( max( 0.000001, (float) $scalar( 'click_price_usd', '0.02' ) ), 6, '.', '' );

		foreach ( array( 'approval_required', 'target_new_tab', 'credit_mempool', 'record_impressions', 'notify_admin_uploads', 'notify_advertiser', 'ad_label_enabled', 'auto_fit_uploads', 'footer_link_enabled', 'footer_link_new_tab', 'fund_link_enabled', 'fund_link_new_tab', 'credit_enabled', 'delete_data_on_uninstall' ) as $flag ) {
			$new[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$new['rel_attributes'] = sanitize_text_field( $scalar( 'rel_attributes', 'nofollow sponsored noopener' ) );
		$label                 = sanitize_text_field( $scalar( 'ad_label_text', 'Sponsored' ) );
		$new['ad_label_text']  = ( '' === $label ) ? 'Sponsored' : mb_substr( $label, 0, 20 );

		$foot_text                  = sanitize_text_field( $scalar( 'footer_link_text', '' ) );
		$new['footer_link_text']    = mb_substr( $foot_text, 0, 60 );
		$foot_url                   = trim( $scalar( 'footer_link_url', '' ) );
		$new['footer_link_url']     = ( 0 === strcasecmp( $foot_url, 'none' ) ) ? 'none' : esc_url_raw( $foot_url );
		$foot_align                 = strtolower( $scalar( 'footer_link_align', 'center' ) );
		$new['footer_link_align']   = in_array( $foot_align, array( 'left', 'center', 'right' ), true ) ? $foot_align : 'center';
		if ( '' !== $foot_url && 0 !== strcasecmp( $foot_url, 'none' ) && '' === $new['footer_link_url'] ) {
			$errors[] = __( 'The footer link address was not a readable URL, so it was cleared. Use a full http or https address.', 'dwads' );
		}

		$sizes_raw    = $scalar( 'sizes', '' );
		$sizes_parsed = DWADS_Settings::parse_sizes( $sizes_raw );
		if ( $sizes_parsed ) {
			$new['sizes'] = DWADS_Settings::serialize_sizes( $sizes_parsed );
		} else {
			$errors[] = __( 'The size list was empty or unreadable; the previous sizes were kept.', 'dwads' );
		}

		$new['upload_intro']      = wp_kses_post( $scalar( 'upload_intro', '' ) );
		$new['manage_disclaimer'] = trim( wp_kses_post( $scalar( 'manage_disclaimer', '' ) ) );

		// Email copy: subjects are plain text, bodies may contain simple HTML.
		foreach ( array( 'email_upload_subject', 'email_approved_subject', 'email_rejected_subject', 'email_resend_subject', 'email_admin_upload_subject' ) as $email_subject_key ) {
			$new[ $email_subject_key ] = sanitize_text_field( $scalar( $email_subject_key, '' ) );
		}
		foreach ( array( 'email_upload_body', 'email_approved_body', 'email_rejected_body', 'email_resend_body', 'email_admin_upload_body' ) as $email_body_key ) {
			$new[ $email_body_key ] = wp_kses_post( $scalar( $email_body_key, '' ) );
		}

		$fund_text                = sanitize_text_field( $scalar( 'fund_link_text', 'Fund this banner' ) );
		$new['fund_link_text']    = mb_substr( $fund_text, 0, 60 );
		$fund_align               = strtolower( $scalar( 'fund_link_align', 'right' ) );
		$new['fund_link_align']   = in_array( $fund_align, array( 'left', 'center', 'right' ), true ) ? $fund_align : 'right';

		$new['max_upload_kb']            = max( 16, (int) $scalar( 'max_upload_kb', '512' ) );
		$new['max_banners_per_upload']   = max( 1, min( 200, (int) $scalar( 'max_banners_per_upload', '3' ) ) );
		$new['dedupe_hours']             = max( 1, (int) $scalar( 'dedupe_hours', '24' ) );
		$new['max_clicks_per_ip_hour']   = max( 0, (int) $scalar( 'max_clicks_per_ip_hour', '10' ) );
		$new['click_min_dwell_ms']       = max( 0, min( 10000, (int) $scalar( 'click_min_dwell_ms', '400' ) ) );
		$new['max_clicks_per_visitor_day']    = max( 0, (int) $scalar( 'max_clicks_per_visitor_day', '3' ) );
		$new['max_clicks_per_ip_day']         = max( 0, (int) $scalar( 'max_clicks_per_ip_day', '15' ) );
		$new['max_clicks_per_banner_day']     = max( 0, (int) $scalar( 'max_clicks_per_banner_day', '0' ) );
		$new['click_rate_ceiling_percent']    = max( 0, min( 100, (int) $scalar( 'click_rate_ceiling_percent', '20' ) ) );
		$new['click_rate_min_impressions']    = max( 1, (int) $scalar( 'click_rate_min_impressions', '20' ) );
		$new['impression_viewable']      = empty( $scalar( 'impression_viewable', '' ) ) ? 0 : 1;
		$new['require_gesture']          = empty( $scalar( 'require_gesture', '' ) ) ? 0 : 1;
		$new['require_browser_headers']  = empty( $scalar( 'require_browser_headers', '' ) ) ? 0 : 1;
		$new['honeypot_enabled']         = empty( $scalar( 'honeypot_enabled', '' ) ) ? 0 : 1;
		$new['max_uploads_per_ip_hour']  = max( 1, (int) $scalar( 'max_uploads_per_ip_hour', '5' ) );
		$new['impression_ttl_minutes']   = max( 5, (int) $scalar( 'impression_ttl_minutes', '30' ) );
		$new['impression_dedupe_hours']  = max( 0, min( 48, (int) $scalar( 'impression_dedupe_hours', '24' ) ) );
		$new['price_cache_minutes']      = max( 1, (int) $scalar( 'price_cache_minutes', '15' ) );
		$new['bot_uas']                  = sanitize_text_field( $scalar( 'bot_uas', '' ) );
		$new['price_api']                = esc_url_raw( $scalar( 'price_api', 'https://mempool.space/api/v1/prices' ) );
		$fallback                        = trim( $scalar( 'fallback_btc_price_usd', '' ) );
		$new['fallback_btc_price_usd']   = '' === $fallback ? '' : (string) max( 0, (float) $fallback );

		// The QR template contains a {ADDRESS} placeholder, so it cannot go through
		// esc_url_raw (which would strip the braces).
		$qr = trim( $scalar( 'qr_service', '' ) );
		if ( '' !== $qr && 0 !== strcasecmp( $qr, 'none' ) ) {
			if ( ! preg_match( '#^https?://#i', $qr ) ) {
				$errors[] = __( 'The QR image template must start with http:// or https://. The previous value was kept.', 'dwads' );
				$qr       = (string) $current['qr_service'];
			} else {
				$qr = preg_replace( '/[\x00-\x1f\x7f"\']/', '', $qr );
			}
		}
		$new['qr_service'] = $qr;

		// One field holds the provider's embed. It may be markup, so kses and
		// esc_url_raw would both destroy it: this is an administrator-only setting
		// and is stored as pasted. Control characters are still stripped.
		$widget = trim( (string) $scalar( 'exchange_widget', '' ) );
		if ( '' !== $widget && 0 !== strcasecmp( $widget, 'none' ) ) {
			$widget = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $widget );

			if ( false === strpos( $widget, '{ADDRESS}' ) ) {
				$errors[] = __( 'The swap provider embed must contain {ADDRESS}, or the widget cannot know where to send the funds. The previous value was kept.', 'dwads' );
				$widget   = isset( $current['exchange_widget'] ) ? (string) $current['exchange_widget'] : '';
			}
		}
		$new['exchange_widget'] = $widget;

		$address_type                    = $scalar( 'address_type', 'auto' );
		$new['address_type']             = in_array( $address_type, array( 'auto', 'p2sh-p2wpkh', 'p2wpkh' ), true ) ? $address_type : 'auto';
		$path_raw                        = $scalar( 'derivation_path', '0/{i}' );
		if ( DWADS_Xpub::has_hardened( $path_raw ) ) {
			$errors[] = __( 'Hardened path steps cannot be derived from a public key. Paste the account-level key and use a relative path such as 0/{i}.', 'dwads' );
		}
		$new['derivation_path']          = DWADS_Xpub::clean_template( $path_raw );
		$new['click_base']               = trim( preg_replace( '#[^a-z0-9\-/]#', '', strtolower( $scalar( 'click_base', 'ads-go' ) ) ), '/' );
		$new['manage_base']              = trim( preg_replace( '#[^a-z0-9\-/]#', '', strtolower( $scalar( 'manage_base', 'ads/manage' ) ) ), '/' );
		if ( '' === $new['click_base'] ) {
			$new['click_base'] = 'ads-go';
		}
		if ( '' === $new['manage_base'] ) {
			$new['manage_base'] = 'ads/manage';
		}

		$xpub = trim( $scalar( 'xpub', '' ) );
		if ( '' === $xpub ) {
			$new['xpub'] = '';
		} elseif ( DWADS_BigInt::available() ) {
			$parsed = DWADS_Xpub::parse( $xpub );
			if ( is_wp_error( $parsed ) ) {
				$errors[] = $parsed->get_error_message();
			} elseif ( ! empty( $parsed['testnet'] ) ) {
				$errors[] = __( 'Testnet keys (tpub, upub, vpub) are refused: this plugin runs on mainnet. Paste the mainnet account key.', 'dwads' );
			} elseif ( ! in_array( (string) $parsed['type'], array( 'p2wpkh', 'p2sh-p2wpkh' ), true ) ) {
				// Legacy accounts (xpub) send change back into the same chain the
				// banners are numbered from, so a change output can later become a
				// banner address and be credited to the wrong advertiser. Modern
				// descriptor wallets (zpub/ypub) keep change on a separate chain.
				// With no legacy installs to support, refuse the risky shape.
				$errors[] = __( 'Only zpub (native segwit) and ypub (wrapped segwit) account keys are accepted. A legacy xpub key comes from a wallet that reuses the banner chain for change addresses. Re-export from a descriptor wallet: in Electrum, Wallet → Information shows zpub for a native segwit wallet.', 'dwads' );
			} else {
				$new['xpub'] = $xpub;
			}
		} else {
			$errors[] = __( 'Address derivation needs PHP GMP or BCMath. The key was not saved.', 'dwads' );
		}

		DWADS_Settings::update( $new );
		DWADS_Settings::flush_cache();

		// Prove the write landed in the database itself, not just in a cache.
		$raw = DWADS_Settings::raw_stored();
		if ( ! is_array( $raw ) || (string) ( $raw['xpub'] ?? '' ) !== (string) $new['xpub'] ) {
			$errors[] = __( 'The database did not accept the new settings: the stored row still holds the old values. The database user probably cannot UPDATE the options table.', 'dwads' );
		}

		DWADS_Rewrite::add_rules();
		flush_rewrite_rules();

		if ( $errors ) {
			self::flash( implode( ' ', $errors ), 'error' );
		} else {
			self::flash( __( 'Settings saved.', 'dwads' ) );
		}
		self::back( 'dwads-settings' );
	}

	/* ---------------------------------------------------------------------
	 * Diagnostics
	 * ------------------------------------------------------------------ */

	/**
	 * One line showing the address that will be handed out next, so the key can
	 * be checked against the wallet immediately.
	 *
	 * @return string
	 */
	private static function wallet_next_address_html() {
		$key = trim( (string) DWADS_Settings::get( 'xpub', '' ) );
		if ( '' === $key ) {
			return '<p class="description">' . esc_html__( 'No key saved yet. Advertisers cannot upload until this is set.', 'dwads' ) . '</p>';
		}
		$next = DWADS_Xpub::preview( 1, DWADS_Xpub::peek_index() );
		if ( is_wp_error( $next ) ) {
			return '<div class="notice notice-error inline"><p>' . esc_html( $next->get_error_message() ) . '</p></div>';
		}
		return '<p class="dwads-next-address">'
			. esc_html__( 'Next address the plugin will hand out:', 'dwads' ) . ' <code>' . esc_html( $next[0]['address'] ) . '</code><br>'
			. '<span class="dwads-meta">' . esc_html__( 'Compare it with the receive addresses in your wallet. If they do not match, stop and fix the key or the derivation path before uploading banners.', 'dwads' ) . '</span></p>';
	}

	/**
	 * Long-form guidance about the watch-only key and keeping custody.
	 *
	 * @return string
	 */
	private static function wallet_help_html() {
		$diag = admin_url( 'admin.php?page=dwads-diagnostics' );
		ob_start();
		?>
		<div class="dwads-help">
			<h3><?php esc_html_e( 'What this key is, and what it can do', 'dwads' ); ?></h3>
			<p><?php esc_html_e( 'An extended public key (zpub or ypub) lets any software compute every receiving address in one wallet account and read its history. It cannot sign, so a copy on the web server cannot move your money. Extended private keys (xprv, yprv, zprv) are refused on purpose: the server never needs one, and a private key here would let anyone drain the wallet. Legacy account keys (xpub) and testnet keys are also refused, because legacy wallets put change back on the same chain the banners are numbered from.', 'dwads' ); ?></p>

			<h3><?php esc_html_e( 'Where to copy it from', 'dwads' ); ?></h3>
			<ul>
				<li><?php echo wp_kses_post( __( '<strong>Sparrow</strong> (also for Coldcard, Ledger and Trezor): open the wallet that should receive the money, then Settings → Keystore, and copy the zpub or ypub shown for the account.', 'dwads' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Electrum</strong>: Wallet → Information → “Master public key”. For a fresh native-segwit wallet that key is a <code>zpub</code>, which is what you want here with Address type set to Native segwit. Its receive chain is <code>m/0/i</code>, exactly what the default path <code>0/{i}</code> produces. The “type” line on that screen is Standard vs 2FA vs multisig, not descriptor vs legacy: every new standard wallet since Electrum 4.6 is a descriptor wallet, with change on <code>m/1/i</code>, which this plugin never derives. See “Use this wallet for nothing else” below.', 'dwads' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Bitcoin Core</strong> descriptor wallet: run <code>listdescriptors</code> and take the xpub part of the external descriptor (the one ending in <code>/0/*</code>) — Core exports plain xpub even for segwit accounts. The plugin refuses xpub, so import that wallet into Sparrow instead and copy the zpub it shows for the account.', 'dwads' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Ledger Live and Trezor Suite</strong> do not show account keys. Export through Sparrow or Electrum with the device connected.', 'dwads' ) ); ?></li>
			</ul>
			<p class="dwads-meta"><?php esc_html_e( 'Use an account-level key, meaning the node below the last hardened step (for example 84h/0h/0h). The path 0/{i} then matches your own receive chain. Electrum shows this as zpub for a native segwit wallet; paste that and leave Address type on Automatic.', 'dwads' ); ?></p>
			<h3><?php esc_html_e( 'Use this wallet for nothing else', 'dwads' ); ?></h3>
			<p><?php esc_html_e( 'The plugin reads every banner address by walking the wallet history, so it cannot tell a payment from a payment-shaped accident. Coins that already sit on an index are invisible until that index becomes a banner — and at that moment they are credited to that advertiser, who never paid them.', 'dwads' ); ?></p>
			<ul>
				<li><?php echo wp_kses_post( __( '<strong>One wallet, one job.</strong> Give the plugin its own wallet account and never use its receive addresses for anything else: no incoming payments, no manual addresses, no second plugin or store on the same key. A dedicated account (a new key from the same seed is enough) is the simplest thing that cannot go wrong.', 'dwads' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Check the wallet type if you use Electrum.</strong> Descriptor wallets, created by default from Electrum 4.6 on, send change to the internal chain <code>m/1/i</code>, which this plugin never derives, so change can never land on a banner address. A standard (legacy) wallet, which 4.5 could still create, sends change to the next unused address of the <em>same</em> receive chain — the very sequence banners are numbered from. Confirm the type at Wallet → Information. If it says standard, treat change as a hazard or move to a descriptor wallet.', 'dwads' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Sweep everything, never a partial amount.</strong> Sending the full balance spends every coin and produces no change output at all, so there is nothing to misplace. Do not send a round figure “and leave the rest”. Sweeping does not affect any balance here: the plugin counts what arrived and never reads what an address spent.', 'dwads' ) ); ?></li>
				<li><?php echo wp_kses_post( sprintf( __( 'After changing wallets, restoring a backup or wiping the plugin data, press <a href="%s">Check if the next address is unused</a> on the Diagnostics screen before uploading again.', 'dwads' ), esc_url( $diag ) ) ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Keeping control of everything that arrives', 'dwads' ); ?></h3>
			<ol>
				<li><?php echo wp_kses_post( sprintf( __( 'Save the key, then compare the next address above and the five listed on the <a href="%s">Diagnostics</a> page with the receive addresses in your wallet. They must be identical.', 'dwads' ), esc_url( $diag ) ) ); ?></li>
				<li><?php esc_html_e( 'Create one test banner, send a small payment, press “Check for payment now” on its management page, and confirm the coins appear in your wallet.', 'dwads' ); ?></li>
				<li><?php esc_html_e( 'Sweep that test amount once. The advertiser-facing balance must not change, because the plugin only counts money that arrived and never looks at what an address spent.', 'dwads' ); ?></li>
			</ol>
			<p><?php echo wp_kses_post( __( '<strong>Gap limit:</strong> wallets scan only about 20 unused addresses ahead of the last one they know. Each banner uses up an index even when it is never funded, so raise the limit (Sparrow → Settings → Gap limit, Electrum <code>wallet.gap_limit</code>) before you have many banners, or keep ads in a dedicated account with a modest banner count. Coins on an address your wallet has not scanned are not lost; a higher gap or a rescan will reveal them.', 'dwads' ) ); ?></p>
			<p><?php esc_html_e( 'Back up the database together with the wallet: the database holds the banner-to-address map and the click accounting. Losing it does not lose the coins, but it does lose the records.', 'dwads' ); ?></p>
			<p><?php echo wp_kses_post( __( '<strong>Wiping the data.</strong> Deleting the plugin data resets the address counter, so the next banner starts again at index 0 of the same key. Any money that already sits on those addresses is on-chain and will be credited to the new banner. To start clean either use a different account (a new key from the same wallet is enough, the seed can stay), or set a higher starting index on the Diagnostics screen before uploading.', 'dwads' ) ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Diagnostics screen.
	 *
	 * @return void
	 */
	public static function render_diagnostics() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap dwads-admin"><h1>' . esc_html__( 'Crypto Banner Ads diagnostics', 'dwads' ) . '</h1>';
		self::print_flash();

		echo '<h2 class="title">' . esc_html__( 'Environment', 'dwads' ) . '</h2><table class="widefat striped dwads-kv"><tbody>';
		self::kv( __( 'PHP', 'dwads' ), PHP_VERSION );
		self::kv( __( 'Big number backend', 'dwads' ), DWADS_BigInt::mode() );
		self::kv( __( 'RIPEMD160', 'dwads' ), in_array( 'ripemd160', hash_algos(), true ) ? __( 'available', 'dwads' ) : __( 'MISSING — address derivation will fail', 'dwads' ) );
		self::kv( __( 'Image editor', 'dwads' ), extension_loaded( 'imagick' ) ? 'Imagick' : ( extension_loaded( 'gd' ) ? 'GD' : __( 'none', 'dwads' ) ) );
		self::kv( __( 'WP-Cron', 'dwads' ), defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'disabled (use a real cron job)', 'dwads' ) : __( 'enabled', 'dwads' ) );
		$next_poll = wp_next_scheduled( 'dwads_cron_poll' );
		self::kv( __( 'Next payment check', 'dwads' ), $next_poll ? gmdate( 'Y-m-d H:i:s', $next_poll ) . ' UTC' : __( 'not scheduled', 'dwads' ) );
		$write_test = (int) get_option( 'dwads_write_test', 0 );
		self::kv( __( 'Last write test', 'dwads' ), $write_test > 0 ? gmdate( 'Y-m-d H:i:s', $write_test ) . ' UTC' : __( 'never run', 'dwads' ) );
		self::kv( __( 'Saving settings', 'dwads' ), __( 'If a save does not show a “Settings saved” banner, the request never reached the plugin: check the nonce, the admin URL scheme (https) and any security plugin.', 'dwads' ) );
		$paused = DWADS_Payments::paused_for();
		self::kv( __( 'Polling pause', 'dwads' ), $paused > 0 ? sprintf( '%d s remaining', $paused ) : __( 'none', 'dwads' ) );
		$last_poll = get_option( 'dwads_last_poll', array() );
		self::kv( __( 'Last poll', 'dwads' ), is_array( $last_poll ) && ! empty( $last_poll['at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $last_poll['at'] ) . sprintf( ' UTC — %d checked, %d credited', (int) $last_poll['checked'], (int) $last_poll['credited'] ) : '—' );
		$price = DWADS_Price::btc_usd();
		self::kv( __( 'BTC price', 'dwads' ), $price > 0 ? '$' . number_format( $price, 2 ) . ( DWADS_Price::cached_at() ? ' (' . gmdate( 'H:i', DWADS_Price::cached_at() ) . ' UTC)' : '' ) : __( 'unavailable', 'dwads' ) );
		echo '</tbody></table>';

		echo '<h2 class="title">' . esc_html__( 'Key and addresses', 'dwads' ) . '</h2>';
		echo '<table class="widefat striped dwads-kv"><tbody>';
		self::kv( __( 'Next address index', 'dwads' ), (string) DWADS_Xpub::peek_index() );
		echo '<tr><th scope="row">' . esc_html__( 'Address numbering', 'dwads' ) . '</th><td>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dwads-inline">';
		wp_nonce_field( 'dwads_diag' );
		echo '<input type="hidden" name="action" value="dwads_diag"><input type="hidden" name="do" value="setindex">';
		echo '<label>' . esc_html__( 'Start the next banner at index', 'dwads' ) . ' <input type="number" min="0" step="1" name="next_index" value="' . (int) DWADS_Xpub::peek_index() . '" class="small-text"></label> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Set', 'dwads' ) . '</button>';
		echo '</form> ';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dwads-inline">';
		wp_nonce_field( 'dwads_diag' );
		echo '<input type="hidden" name="action" value="dwads_diag"><input type="hidden" name="do" value="checkaddr">';
		echo '<button type="submit" class="button">' . esc_html__( 'Check if the next address is unused', 'dwads' ) . '</button>';
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Raising the index skips addresses. Do it after a data wipe when you keep the same key, so new banners do not inherit the addresses (and the money) of an earlier install. Never lower it below an index that has already been used. If this wallet is shared with anything else, check the next address is unused before uploading, and see “Use this wallet for nothing else” on the Settings screen.', 'dwads' ) . '</p>';
		echo '</td></tr>';
		$preview = DWADS_Xpub::preview( 5, DWADS_Xpub::peek_index() );
		if ( is_wp_error( $preview ) ) {
			self::kv( __( 'Derivation', 'dwads' ), $preview->get_error_message() );
		} else {
			$list = array();
			foreach ( $preview as $item ) {
				$list[] = '<code>' . esc_html( $item['address'] ) . '</code> <span class="dwads-meta">#' . (int) $item['index'] . '</span>';
			}
			self::kv( __( 'Next addresses', 'dwads' ), implode( '<br>', $list ), true );
		}
		echo '</tbody></table>';
		echo '<p class="dwads-meta">' . esc_html__( 'These must match the receive addresses shown by your wallet. Wallets only scan a limited number of unused addresses ahead (about 20 by default), so raise the gap limit before you have many banners, otherwise a funded address may not appear in the wallet even though the coins are there.', 'dwads' ) . '</p>';

		echo '<h2 class="title">' . esc_html__( 'Actions', 'dwads' ) . '</h2>';
		echo '<div class="dwads-diag-actions">';
		$actions = array(
			'poll'      => __( 'Check payments now', 'dwads' ),
			'reconcile' => __( 'Rebuild balances from history', 'dwads' ),
			'flush'     => __( 'Rebuild public URLs', 'dwads' ),
			'clearlog'  => __( 'Clear log', 'dwads' ),
			'writetest' => __( 'Test settings write', 'dwads' ),
		);
		foreach ( $actions as $do => $label ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dwads-inline">';
			wp_nonce_field( 'dwads_diag' );
			echo '<input type="hidden" name="action" value="dwads_diag"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
			echo '<button type="submit" class="button">' . esc_html( $label ) . '</button>';
			echo '</form>';
		}
		echo '</div>';

		echo '<h2 class="title">' . esc_html__( 'Log', 'dwads' ) . '</h2>';
		$log = DWADS_Log::read();
		if ( ! $log ) {
			echo '<p class="dwads-meta">' . esc_html__( 'Nothing logged yet.', 'dwads' ) . '</p>';
		} else {
			echo '<pre class="dwads-log">';
			foreach ( array_slice( $log, 0, 40 ) as $line ) {
				echo esc_html( $line ) . "\n";
			}
			echo '</pre>';
		}

		echo '<h2 class="title">' . esc_html__( 'Recent clicks', 'dwads' ) . '</h2>';
		$recent_clicks = $wpdb->get_results( 'SELECT created_at, banner_id, charged, reason, sats_charged FROM ' . DWADS_Clicks::table() . ' ORDER BY id DESC LIMIT 40', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $recent_clicks ) {
			echo '<p class="dwads-meta">' . esc_html__( 'No clicks recorded yet.', 'dwads' ) . '</p>';
		} else {
			echo '<table class="widefat striped dwads-kv"><thead><tr><th>' . esc_html__( 'UTC', 'dwads' ) . '</th><th>' . esc_html__( 'Banner', 'dwads' ) . '</th><th>' . esc_html__( 'Charged', 'dwads' ) . '</th><th>' . esc_html__( 'Reason', 'dwads' ) . '</th><th>' . esc_html__( 'Sats', 'dwads' ) . '</th></tr></thead><tbody>';
			foreach ( $recent_clicks as $row ) {
				echo '<tr><td>' . esc_html( $row['created_at'] ) . '</td><td>#' . (int) $row['banner_id'] . '</td><td>' . ( $row['charged'] ? esc_html__( 'yes', 'dwads' ) : '—' ) . '</td><td>' . esc_html( '' === $row['reason'] ? '-' : $row['reason'] ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['sats_charged'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p class="dwads-meta">' . esc_html__( '“duplicate” means that visitor was already charged for that banner inside the de-duplication window. Several charged clicks seconds apart means the visitor fingerprint changed between requests, which usually points at a proxy or CDN edge address.', 'dwads' ) . '</p>';
		}

		echo '<h2 class="title">' . esc_html__( 'Data', 'dwads' ) . '</h2><table class="widefat striped dwads-kv"><tbody>';
		self::kv( __( 'Banners', 'dwads' ), (string) (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DWADS_Banners::table() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::kv( __( 'Payments', 'dwads' ), (string) (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'dwads_payments' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::kv( __( 'Clicks', 'dwads' ), (string) (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DWADS_Clicks::table() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::kv( __( 'Impressions', 'dwads' ), (string) (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DWADS_Clicks::impressions_table() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		echo '</tbody></table></div>';
	}

	/**
	 * Key/value row.
	 *
	 * @param string $label   Label.
	 * @param string $value   Value (escaped unless $html).
	 * @param bool   $is_html Whether value is HTML.
	 * @return void
	 */
	private static function kv( $label, $value, $is_html = false ) {
		echo '<tr><th style="width:260px">' . esc_html( $label ) . '</th><td>' . ( $is_html ? $value : esc_html( $value ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Handle diagnostic buttons.
	 *
	 * @return void
	 */
	public static function handle_diag() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'dwads' ) );
		}
		check_admin_referer( 'dwads_diag' );

		$do = isset( $_POST['do'] ) && is_scalar( $_POST['do'] ) ? sanitize_key( wp_unslash( (string) $_POST['do'] ) ) : '';

		if ( 'poll' === $do ) {
			$summary = DWADS_Payments::poll_due( 25, true );
			$message = sprintf(
				/* translators: 1: checked, 2: credited */
				__( 'Checked %1$d banners, %2$d had balance changes.', 'dwads' ),
				(int) $summary['checked'],
				(int) $summary['credited']
			);
			if ( ! empty( $summary['errors'] ) ) {
				self::flash( $message . ' ' . implode( ' ', (array) $summary['errors'] ), 'error' );
			} else {
				self::flash( $message );
			}
		} elseif ( 'reconcile' === $do ) {
			$count = DWADS_Payments::reconcile();
			self::flash( sprintf( /* translators: %d: banners */ __( 'Rebuilt totals for %d banners.', 'dwads' ), $count ) );
		} elseif ( 'flush' === $do ) {
			DWADS_Rewrite::add_rules();
			flush_rewrite_rules();
			self::flash( __( 'Rewrite rules rebuilt.', 'dwads' ) );
		} elseif ( 'writetest' === $do ) {
			$stamp = time();
			$saved = update_option( 'dwads_write_test', $stamp, 'no' );
			$read  = (int) get_option( 'dwads_write_test', 0 );
			if ( $read === $stamp ) {
				self::flash( sprintf( /* translators: %s: timestamp */ __( 'Database writes work: option value %s was stored and read back. The problem is in the form submission, not the database.', 'dwads' ), (string) $stamp ) );
			} else {
				self::flash( sprintf( /* translators: 1: written, 2: read back */ __( 'Database writes FAILED: wrote %1$s but read back %2$s. The database user is probably not allowed to write, or an object cache is serving stale data.', 'dwads' ), (string) $stamp, (string) $read ), 'error' );
			}
			self::back( 'dwads-diagnostics' );
		} elseif ( 'setindex' === $do ) {
			global $wpdb;
			$requested = isset( $_POST['next_index'] ) && is_scalar( $_POST['next_index'] ) ? (int) $_POST['next_index'] : -1;
			$max_used  = (int) $wpdb->get_var( 'SELECT COALESCE( MAX(derivation_index), -1 ) FROM ' . DWADS_Banners::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( $requested < 0 || $requested > 2147483647 ) {
				self::flash( __( 'That index is out of range.', 'dwads' ), 'error' );
			} elseif ( $requested <= $max_used ) {
				self::flash( sprintf( /* translators: %d: highest index in use */ __( 'Index %d or lower is already used by an existing banner. Choose a higher number.', 'dwads' ), $max_used ), 'error' );
			} else {
				update_option( 'dwads_next_index', $requested, 'no' );
				wp_cache_delete( 'dwads_next_index', 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				DWADS_Log::add( sprintf( 'Next address index set to %d by the site owner.', $requested ) );
				self::flash( sprintf( /* translators: %d: index */ __( 'The next banner will start at index %d.', 'dwads' ), $requested ) );
			}
			self::back( 'dwads-diagnostics' );
		} elseif ( 'checkaddr' === $do ) {
			$preview = DWADS_Xpub::preview( 1, DWADS_Xpub::peek_index() );
			if ( is_wp_error( $preview ) ) {
				self::flash( $preview->get_error_message(), 'error' );
				self::back( 'dwads-diagnostics' );
			}
			$summary = DWADS_Payments::address_summary( $preview[0]['address'] );
			if ( is_wp_error( $summary ) ) {
				self::flash( $summary->get_error_message(), 'error' );
			} elseif ( $summary['tx_count'] > 0 ) {
				self::flash(
					sprintf(
						/* translators: 1: address, 2: transaction count, 3: satoshis */
						__( 'Heads up: %1$s already has %2$d transaction(s) and has received %3$s. A new banner using this address would inherit that history. Raise the index or use a different account.', 'dwads' ),
						$summary['address'],
						(int) $summary['tx_count'],
						DWADS_Price::format_btc( (int) $summary['funded_sats'] )
					),
					'error'
				);
			} else {
				self::flash( sprintf( /* translators: %s: address */ __( 'Good: %s has no on-chain history, so it is safe to use next.', 'dwads' ), $summary['address'] ) );
			}
			self::back( 'dwads-diagnostics' );
		} elseif ( 'clearlog' === $do ) {
			DWADS_Log::clear();
			self::flash( __( 'Log cleared.', 'dwads' ) );
		}

		self::back( 'dwads-diagnostics' );
	}

	/* ---------------------------------------------------------------------
	 * Field helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return 'dwads_settings[' . $key . ']';
	}

	/**
	 * Text input row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description.
	 * @param string $class Extra class.
	 * @return void
	 */
	private static function field_text( $key, $label, $desc = '', $class = 'regular-text' ) {
		$value = (string) DWADS_Settings::get( $key );
		echo '<tr><th scope="row"><label for="dwads-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" class="' . esc_attr( $class ) . '" id="dwads-' . esc_attr( $key ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="' . esc_attr( $value ) . '" autocomplete="off" spellcheck="false">';
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Numeric input row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description.
	 * @param string $step  Step.
	 * @return void
	 */
	private static function field_number( $key, $label, $desc = '', $step = '1' ) {
		$value = (string) DWADS_Settings::get( $key );
		echo '<tr><th scope="row"><label for="dwads-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" step="' . esc_attr( $step ) . '" class="small-text" id="dwads-' . esc_attr( $key ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="' . esc_attr( $value ) . '">';
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description.
	 * @return void
	 */
	private static function field_checkbox( $key, $label, $desc = '' ) {
		$checked = (bool) DWADS_Settings::get( $key );
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<label><input type="checkbox" id="dwads-' . esc_attr( $key ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="1" ' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label>';
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Textarea row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description.
	 * @return void
	 */
	private static function field_textarea( $key, $label, $desc = '', $value = null, $rows = 5 ) {
		if ( null === $value ) {
			$value = (string) DWADS_Settings::get( $key );
		}
		echo '<tr><th scope="row"><label for="dwads-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea rows="' . (int) $rows . '" class="large-text code" id="dwads-' . esc_attr( $key ) . '" name="' . esc_attr( self::name( $key ) ) . '">' . esc_textarea( $value ) . '</textarea>';
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Select row.
	 *
	 * @param string $key     Setting key.
	 * @param string $label   Label.
	 * @param string $desc    Description.
	 * @param array  $options Value => label.
	 * @return void
	 */
	private static function field_select( $key, $label, $desc, $options ) {
		$value = (string) DWADS_Settings::get( $key );
		echo '<tr><th scope="row"><label for="dwads-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select id="dwads-' . esc_attr( $key ) . '" name="' . esc_attr( self::name( $key ) ) . '">';
		foreach ( $options as $option => $text ) {
			echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select>';
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}
}
