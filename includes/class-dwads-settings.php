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
 * Plugin settings.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings accessor with sane defaults.
 */
final class DWADS_Settings {

	const OPTION = 'dwads_settings';

	/** @var array|null */
	private static $cache = null;

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Money.
			'click_price_usd'          => '0.02',
			'approval_required'        => 1,
			'sizes'                    => "728x90 | Leaderboard | top of every page\n320x100 | Large mobile banner | phones\n300x250 | Medium rectangle | in-content, best performing | recommended\n336x280 | Large rectangle | in-content\n250x250 | Square | sidebar\n160x600 | Wide skyscraper | tall sidebar\n300x600 | Half page | end of article\n970x250 | Billboard | wide header | recommended",
			'upload_intro'             => "Pick an image, add the link it should open, and upload. No account is needed: right after uploading you receive a management link with a payment address. Load the balance there with your cryptocurrency or regular money of choice — the swap widget converts it for you — and each click on your banner uses a small amount of it.",
			'max_upload_kb'            => 512,
			'max_banners_per_upload'   => 5,
			'auto_fit_uploads'         => 1,
			// Display.
			'target_new_tab'           => 1,
			'rel_attributes'           => 'nofollow sponsored noopener',
			'ad_label_enabled'         => 1,
			'ad_label_text'            => 'Sponsored',
			'credit_enabled'           => 0,
			'footer_link_enabled'      => 0,
			'footer_link_text'         => '',
			'footer_link_url'          => '',
			'footer_link_align'        => 'center',
			'footer_link_new_tab'      => 0,
			'fund_link_enabled'        => 1,
			'fund_link_text'           => 'Fund this banner',
			'fund_link_align'          => 'right',
			'fund_link_new_tab'        => 0,
			'manage_disclaimer'        => '',
			'notify_advertiser'        => 1,
			// Email copy. Every email the plugin sends is editable here; the
			// {TOKENS} are filled in at send time.
			'email_upload_subject'       => 'Your banner on {SITE_NAME}',
			'email_upload_body'          => "Thanks for your upload. Here is the link for each banner.\n\n{LINKS}\n\nTop up the balance on that page with your cryptocurrency or regular money of choice, using the swap widget there, or by sending Bitcoin straight to the address. If your banner is waiting for approval, payments still count while it waits.",
			'email_approved_subject'     => 'Your banner is live on {SITE_NAME}',
			'email_approved_body'        => "Your banner has been approved and is now being shown. Open your management link to see the balance and top up.\n\n{LINK}",
			'email_rejected_subject'     => 'Your banner was not approved on {SITE_NAME}',
			'email_rejected_body'        => "This banner was rejected and is not being shown. Contact the site owner if you would like to know why.\n\n{LINK}",
			'email_resend_subject'       => 'Your banner link on {SITE_NAME}',
			'email_resend_body'          => "Here is your management link again, as requested by the site owner.\n\n{LINK}",
			'email_admin_upload_subject' => '[{SITE_NAME}] New banner upload',
			'email_admin_upload_body'    => "New banners were uploaded and are waiting in Banner Ads:\n\n{BANNERS}\n\n{ADMIN_URL}",
			// Bitcoin.
			'xpub'                     => '',
			'derivation_path'          => '0/{i}',
			'address_type'             => 'auto',
			'credit_mempool'           => 1,
			'price_api'                => 'https://mempool.space/api/v1/prices',
			'fallback_btc_price_usd'   => '',
			'price_cache_minutes'      => 15,
			// Click accounting.
			'dedupe_hours'             => 24,
			'bot_uas'                  => 'bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headlesschrome|phantomjs|python-requests|python-urllib|curl/|wget|go-http-client|okhttp|java/|libwww|scrapy|ahrefs|semrush|mj12|dotbot|petalbot|yandex|baidu',
			'max_clicks_per_ip_hour'   => 10,
			'max_uploads_per_ip_hour'  => 5,
			'impression_ttl_minutes'   => 30,
			'impression_dedupe_hours'  => 24,
			'record_impressions'       => 1,
			// Human-visitor checks. All of these are on by default: the plugin
			// would rather lose a handful of legitimate clicks than bill for a
			// machine.
			'impression_viewable'      => 1,
			'click_min_dwell_ms'       => 400,
			'require_gesture'          => 1,
			'require_browser_headers'  => 1,
			'honeypot_enabled'         => 1,
			'max_clicks_per_visitor_day' => 3,
			'max_clicks_per_ip_day'    => 15,
			'max_clicks_per_banner_day' => 0,
			'click_rate_ceiling_percent' => 20,
			'click_rate_min_impressions' => 20,
			// URLs.
			'click_base'               => 'ads-go',
			'manage_base'              => 'ads/manage',
			// Top up.
			// One field holds the swap provider's complete embed code, pasted as
			// given. Nothing is detected or wrapped: whatever is here is what
			// prints, with {ADDRESS} swapped for the banner's deposit address.
			// Empty falls back to the shipped default; "none" hides the widget.
			'exchange_widget'          => '<iframe id="iframe-widget" src="https://changenow.io/embeds/exchange-widget/v2/widget.html?FAQ=true&amount=0.01&amountFiat=6.50&backgroundColor=FFFFFF&darkMode=false&from=xmr&fromFiat=usd&horizontal=false&isFiat&lang=en-US&link_id=bc1c9b656c7061&locales=true&logo=false&primaryColor=00C26F&to=btc&toFiat=eth&toTheMoon=true&topUpAddress={ADDRESS}&topUpCurrency=btc&topUpExtraId&topUpMode=true&topUpNetwork=btc" style="height:356px;width:100%;border:none" title="ChangeNOW widget"></iframe>'
				. "\n" . '<script defer src="https://changenow.io/embeds/exchange-widget/v2/stepper-connector.js"></script>', // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- optional swap-widget embed the site owner configures (documented service)
			'qr_service'               => 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=8&data=bitcoin:{ADDRESS}',
			// Housekeeping.
			'notify_admin_uploads'     => 1,
			'delete_data_on_uninstall' => 0,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			self::$cache = wp_parse_args( $stored, self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when empty.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( isset( $all[ $key ] ) && '' !== $all[ $key ] && null !== $all[ $key ] ) {
			return $all[ $key ];
		}
		if ( null !== $default ) {
			return $default;
		}
		$defaults = self::defaults();
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : null;
	}

	/**
	 * Persist a full settings array.
	 *
	 * @param array $values Values.
	 * @return void
	 */
	public static function update( $values ) {
		$all         = self::all();
		self::$cache = wp_parse_args( $values, $all );
		update_option( self::OPTION, self::$cache );
	}

	/**
	 * Drop the in-memory cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Read the option straight from the database, bypassing every cache.
	 *
	 * Used to tell "the write failed" apart from "the read is stale".
	 *
	 * @return array|null
	 */
	public static function raw_stored() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		if ( null === $raw ) {
			return null;
		}
		$value = maybe_unserialize( $raw );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Parse the size list.
	 *
	 * One size per line: `WxH`, optionally followed by `| label | note` and the
	 * word `recommended`. Plain `WxH` lines keep working, so older saved values
	 * stay valid.
	 *
	 * @param string $raw Raw setting value.
	 * @return array<string,array<string,mixed>>
	 */
	public static function parse_sizes( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			$dims  = array_shift( $parts );
			if ( ! preg_match( '/^(\d{2,4})\s*[xX×]\s*(\d{2,4})$/', $dims, $m ) ) {
				continue;
			}
			$w = (int) $m[1];
			$h = (int) $m[2];
			if ( $w < 20 || $h < 20 || $w > 4000 || $h > 4000 ) {
				continue;
			}
			$label       = '';
			$note        = '';
			$recommended = false;
			foreach ( $parts as $part ) {
				$part = wp_strip_all_tags( $part );
				if ( '' === $part ) {
					continue;
				}
				if ( 0 === strcasecmp( $part, 'recommended' ) ) {
					$recommended = true;
					continue;
				}
				if ( '' === $label ) {
					$label = $part;
				} elseif ( '' === $note ) {
					$note = $part;
				}
			}
			$out[ $w . 'x' . $h ] = array(
				'w'           => $w,
				'h'           => $h,
				'label'       => mb_substr( $label, 0, 60 ),
				'note'        => mb_substr( $note, 0, 120 ),
				'recommended' => $recommended,
			);
		}
		return $out;
	}

	/**
	 * Turn the structured list back into the stored one-per-line form.
	 *
	 * @param array $sizes Parsed sizes.
	 * @return string
	 */
	public static function serialize_sizes( $sizes ) {
		$lines = array();
		foreach ( (array) $sizes as $key => $size ) {
			$parts = array( $key );
			if ( ! empty( $size['label'] ) ) {
				$parts[] = $size['label'];
			}
			if ( ! empty( $size['note'] ) ) {
				$parts[] = $size['note'];
			}
			if ( ! empty( $size['recommended'] ) ) {
				$parts[] = 'recommended';
			}
			$lines[] = implode( ' | ', $parts );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Allowed banner sizes keyed "WxH".
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function sizes() {
		return self::parse_sizes( self::get( 'sizes' ) );
	}

	/**
	 * Sizes marked as recommended, in list order.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function recommended_sizes() {
		$out = array();
		foreach ( self::sizes() as $key => $size ) {
			if ( ! empty( $size['recommended'] ) ) {
				$out[ $key ] = $size;
			}
		}
		return $out;
	}

	/**
	 * Resolve a slot name such as "300x250".
	 *
	 * @param string $slot Slot.
	 * @return array|null Array with w and h.
	 */
	public static function size_for_slot( $slot ) {
		$slot  = strtolower( trim( (string) $slot ) );
		$slot  = preg_replace( '/\s+/', '', $slot ); // tolerate "300 x 250".
		$sizes = self::sizes();
		return isset( $sizes[ $slot ] ) ? $sizes[ $slot ] : null;
	}

	/**
	 * Click price for a banner row, honouring the per-banner override.
	 *
	 * @param array|null $banner Banner row.
	 * @return float
	 */
	public static function click_price_for( $banner ) {
		if ( is_array( $banner ) && isset( $banner['click_price_usd'] ) && '' !== (string) $banner['click_price_usd'] && null !== $banner['click_price_usd'] ) {
			return (float) $banner['click_price_usd'];
		}
		return (float) self::get( 'click_price_usd', 0.02 );
	}

	/**
	 * Sanitised URL bases for the public routes.
	 *
	 * @return array{0:string,1:string}
	 */
	/**
	 * The swap provider embed, or an empty string when it should not render.
	 *
	 * @return string
	 */
	public static function exchange_template() {
		$value = trim( (string) self::get( 'exchange_widget' ) );
		if ( 0 === strcasecmp( $value, 'none' ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * QR image template, or an empty string when disabled.
	 *
	 * @return string
	 */
	public static function qr_template() {
		$value = trim( (string) self::get( 'qr_service' ) );
		if ( 0 === strcasecmp( $value, 'none' ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Resolve the small link that sits under a banner.
	 *
	 * Overrides come from a single placement (widget instance or shortcode) and
	 * only win where they are non-empty, so a blank widget field inherits the
	 * site setting instead of blanking it.
	 *
	 * @param array $overrides mode, text, url, align.
	 * @return array Empty when the link should not be rendered.
	 */
	public static function footer_link( $overrides = array() ) {
		$mode = isset( $overrides['mode'] ) ? strtolower( trim( (string) $overrides['mode'] ) ) : '';
		if ( 'off' === $mode ) {
			return array();
		}
		$forced_on = ( 'on' === $mode );

		if ( ! $forced_on && ! (bool) self::get( 'footer_link_enabled', 0 ) ) {
			return array();
		}

		$text = isset( $overrides['text'] ) ? trim( (string) $overrides['text'] ) : '';
		if ( '' === $text ) {
			$text = trim( (string) self::get( 'footer_link_text', '' ) );
		}

		$url = isset( $overrides['url'] ) ? trim( (string) $overrides['url'] ) : '';
		if ( '' === $url ) {
			$url = trim( (string) self::get( 'footer_link_url', '' ) );
		}
		if ( '' === $url ) {
			// The natural destination is the page holding the upload form.
			$url = self::upload_page_url();
		}
		if ( 0 === strcasecmp( $url, 'none' ) ) {
			return array();
		}
		$url = esc_url_raw( $url );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return array();
		}

		$align = isset( $overrides['align'] ) ? strtolower( trim( (string) $overrides['align'] ) ) : '';
		if ( ! in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			$align = strtolower( (string) self::get( 'footer_link_align', 'center' ) );
		}
		if ( ! in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			$align = 'center';
		}

		if ( '' === $text || 0 === strcasecmp( $text, 'none' ) ) {
			$text = __( 'Advertise here', 'crypto-banner-ads' );
		}
		$text = mb_substr( sanitize_text_field( $text ), 0, 60 );

		return array(
			'text'    => $text,
			'url'     => $url,
			'align'   => $align,
			'new_tab' => (bool) self::get( 'footer_link_new_tab', 0 ),
		);
	}

	/**
	 * The "Fund this banner" link under a creative.
	 *
	 * Same shape as footer_link(), except the address is never a setting: it is
	 * the banner's own management page, which only exists once a banner has
	 * been served.
	 *
	 * @param array $overrides Per-placement overrides (mode, text, align).
	 * @return array Empty when the link should not be shown.
	 */
	public static function fund_link( $overrides = array() ) {
		$mode = isset( $overrides['mode'] ) ? strtolower( trim( (string) $overrides['mode'] ) ) : '';
		if ( 'off' === $mode ) {
			return array();
		}
		if ( 'on' !== $mode && ! (bool) self::get( 'fund_link_enabled', 1 ) ) {
			return array();
		}

		$text = isset( $overrides['text'] ) ? trim( (string) $overrides['text'] ) : '';
		if ( '' === $text ) {
			$text = trim( (string) self::get( 'fund_link_text', 'Fund this banner' ) );
		}
		if ( '' === $text || 0 === strcasecmp( $text, 'none' ) || 0 === strcasecmp( $text, 'off' ) ) {
			return array();
		}
		$text = mb_substr( sanitize_text_field( $text ), 0, 60 );

		$align = isset( $overrides['align'] ) ? strtolower( trim( (string) $overrides['align'] ) ) : '';
		if ( ! in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			$align = strtolower( (string) self::get( 'fund_link_align', 'right' ) );
		}
		if ( ! in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			$align = 'right';
		}

		return array(
			'text'    => $text,
			'align'   => $align,
			'new_tab' => (bool) self::get( 'fund_link_new_tab', 0 ),
		);
	}

	/**
	 * URL of the page that contains the upload shortcode, when there is one.
	 *
	 * Cached because it is a LIKE query and the answer changes about never.
	 *
	 * @return string Empty when no page holds the shortcode.
	 */
	public static function upload_page_url() {
		$cached = get_transient( 'dwads_upload_page_url' );
		if ( false !== $cached ) {
			return (string) $cached;
		}
		global $wpdb;
		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( 'page', 'post' ) AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
				'%[dwads_upload%'
			)
		);
		$url = $id ? (string) get_permalink( (int) $id ) : '';
		set_transient( 'dwads_upload_page_url', $url, HOUR_IN_SECONDS );
		return $url;
	}

	/**
	 * Sanitised URL bases for the public routes.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function route_bases() {
		$click  = preg_replace( '#[^a-z0-9\-/]#', '', strtolower( (string) self::get( 'click_base', 'ads-go' ) ) );
		$manage = preg_replace( '#[^a-z0-9\-/]#', '', strtolower( (string) self::get( 'manage_base', 'ads/manage' ) ) );
		$click  = trim( (string) $click, '/' );
		$manage = trim( (string) $manage, '/' );
		return array(
			$click ? $click : 'ads-go',
			$manage ? $manage : 'ads/manage',
		);
	}
}
