<?php
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
			'upload_intro'             => "Pick an image, add the link it should open, and upload. No account is needed: right after uploading you receive a private management link with a Bitcoin address. Send bitcoin there to load the balance, and each click on your banner uses a small amount of it.",
			'max_upload_kb'            => 512,
			'max_banners_per_upload'   => 5,
			'auto_fit_uploads'         => 1,
			// Display.
			'target_new_tab'           => 1,
			'rel_attributes'           => 'nofollow sponsored noopener',
			'ad_label_enabled'         => 1,
			'ad_label_text'            => 'Sponsored',
			'notify_advertiser'        => 1,
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
			'max_clicks_per_ip_hour'   => 40,
			'max_uploads_per_ip_hour'  => 5,
			'impression_ttl_minutes'   => 30,
			'record_impressions'       => 1,
			// URLs.
			'click_base'               => 'ads-go',
			'manage_base'              => 'ads/manage',
			// Top up.
			'exchange_widget_url'      => 'https://changenow.io/embeds/exchange-widget/v2/widget.html?FAQ=true&amount=0.01&amountFiat=6.50&backgroundColor=FFFFFF&darkMode=false&from=xmr&fromFiat=usd&horizontal=false&isFiat&lang=en-US&link_id={LINK_ID}&locales=true&logo=false&primaryColor=00C26F&to=btc&toFiat=eth&toTheMoon=true&topUpAddress={ADDRESS}&topUpCurrency=btc&topUpExtraId&topUpMode=true&topUpNetwork=btc',
			// Ships with the maintainer's affiliate id so a fresh install has a
			// working widget; replace it with your own, or clear the field to
			// drop the parameter entirely.
			'exchange_widget_link_id'  => 'bc1c9b656c7061',
			'exchange_widget_height'   => 356,
			'exchange_widget_html'     => '',
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
	 * Affiliate id used by the exchange widget.
	 *
	 * Empty means "not configured", which falls back to the shipped default id,
	 * so an install that never touched the field still gets a working widget.
	 * The literal value "none" is the explicit opt-out and returns an empty
	 * string, which makes the widget drop the parameter entirely.
	 *
	 * @return string Empty when no affiliate id should be sent.
	 */
	public static function exchange_link_id() {
		// No second argument: an explicit fallback would win over the settings
		// default and turn an unset field into "no affiliate id" instead of
		// "use the shipped default".
		$value = trim( (string) self::get( 'exchange_widget_link_id' ) );
		if ( 0 === strcasecmp( $value, 'none' ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Exchange widget URL template, or an empty string when disabled.
	 *
	 * @return string
	 */
	public static function exchange_widget_url() {
		$value = trim( (string) self::get( 'exchange_widget_url' ) );
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
