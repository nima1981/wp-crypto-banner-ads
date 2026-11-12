<?php
/**
 * The rotating slot endpoint used by the snippet.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint that hands out one banner per slot.
 */
final class DWADS_Serve {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			'dwads/v1',
			'/serve',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'serve' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'slot'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'page_id' => array(
						'required' => false,
						'type'     => 'integer',
					),
					'site'    => array(
						'required' => false,
						'type'     => 'string',
					),
					'foot'       => array(
						'required' => false,
						'type'     => 'string',
					),
					'foot_text'  => array(
						'required' => false,
						'type'     => 'string',
					),
					'foot_url'   => array(
						'required' => false,
						'type'     => 'string',
					),
					'foot_align' => array(
						'required' => false,
						'type'     => 'string',
					),
					'fund'       => array(
						'required' => false,
						'type'     => 'string',
					),
					'fund_text'  => array(
						'required' => false,
						'type'     => 'string',
					),
					'fund_align' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Return one banner for the requested slot, or 204 when nothing is eligible.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function serve( $request ) {
		$slot = (string) $request->get_param( 'slot' );
		$size = DWADS_Settings::size_for_slot( $slot );

		if ( ! $size ) {
			return new WP_REST_Response( array( 'error' => 'unknown_slot' ), 400 );
		}

		$page_id = (int) $request->get_param( 'page_id' );
		// Publisher identification is reserved for the multi-site phase.
		$site_id = 0;

		$banner = DWADS_Banners::pick_for_slot( $size['w'], $size['h'], $site_id );

		if ( ! $banner ) {
			$empty = new WP_REST_Response( null, 204 );
			self::cors( $empty );
			return $empty;
		}

		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$visitor = DWADS_Clicks::visitor_hash( (int) $banner['id'], DWADS_Clicks::client_ip(), $ua );

		$token = DWADS_Clicks::begin_impression( $banner, $site_id, $page_id, $visitor );

		// Handing out a banner is not the same as someone seeing it. The counter
		// ignores crawlers and repeat views inside the de-duplication window, so
		// the number an advertiser reads stays close to "people who saw this".
		if ( self::counts_as_impression( $banner, $visitor, $ua ) ) {
			DWADS_Banners::note_served( (int) $banner['id'] );
		}

		$alt = trim( (string) $banner['alt_text'] );
		if ( '' === $alt ) {
			$alt = get_bloginfo( 'name' );
		}

		$footer = DWADS_Settings::footer_link(
			array(
				'mode'  => (string) $request->get_param( 'foot' ),
				'text'  => (string) $request->get_param( 'foot_text' ),
				'url'   => (string) $request->get_param( 'foot_url' ),
				'align' => (string) $request->get_param( 'foot_align' ),
			)
		);

		$payload = array(
			'id'     => (int) $banner['id'],
			'img'    => DWADS_Banners::image_url( $banner ),
			'click'  => DWADS_Public_Pages::click_url( $token ),
			'alt'    => $alt,
			'width'  => (int) $banner['image_w'],
			'height' => (int) $banner['image_h'],
			'newTab' => (bool) DWADS_Settings::get( 'target_new_tab', 1 ),
			'rel'    => (string) DWADS_Settings::get( 'rel_attributes', 'nofollow sponsored noopener' ),
			'label'  => DWADS_Settings::get( 'ad_label_enabled', 1 ) ? (string) DWADS_Settings::get( 'ad_label_text', 'Sponsored' ) : '',
		);

		// The second link points at the banner's own management page, where the
		// balance and the top-up address live. That page only has a URL once the
		// banner exists, so it is resolved here rather than in a setting.
		$fund = DWADS_Settings::fund_link(
			array(
				'mode'  => (string) $request->get_param( 'fund' ),
				'text'  => (string) $request->get_param( 'fund_text' ),
				'align' => (string) $request->get_param( 'fund_align' ),
			)
		);
		if ( $fund ) {
			$manage = DWADS_Banners::manage_url_for( $banner );
			if ( '' !== $manage ) {
				$fund['url'] = $manage;
				// Two links cannot occupy the same position: when the alignments
				// collide, the fund link moves to the opposite side.
				if ( $footer ) {
					$fund['align'] = self::apart( $footer['align'], $fund['align'] );
				}
				$payload['fund'] = $fund;
			}
		}

		// Only sent when the link should be rendered, so an empty payload key
		// cannot accidentally produce a stray anchor.
		if ( $footer ) {
			$payload['foot'] = $footer;
		}

		$response = new WP_REST_Response( $payload, 200 );
		self::cors( $response );
		return $response;
	}

	/**
	 * Whether this serve should move the advertiser-visible impression counter.
	 *
	 * @param array  $banner  Banner row.
	 * @param string $visitor Visitor hash, empty when it could not be built.
	 * @param string $ua      User agent.
	 * @return bool
	 */
	private static function counts_as_impression( $banner, $visitor, $ua ) {
		$hours = (int) DWADS_Settings::get( 'impression_dedupe_hours', 24 );
		if ( $hours <= 0 ) {
			return true;
		}

		// No user agent at all means a script, not a browser.
		if ( '' === trim( (string) $ua ) ) {
			return false;
		}

		$pattern = (string) DWADS_Settings::get( 'bot_uas', '' );
		if ( '' !== $pattern && @preg_match( '#' . str_replace( '#', '\#', $pattern ) . '#i', (string) $ua ) ) {
			return false;
		}

		if ( '' === (string) $visitor ) {
			return true;
		}

		return ! DWADS_Clicks::recent_impression_exists( (int) $banner['id'], $visitor, $hours );
	}

	/**
	 * Keep two adjacent links from sharing the same alignment.
	 *
	 * @param string $taken Alignment already in use.
	 * @param string $align Requested alignment.
	 * @return string
	 */
	private static function apart( $taken, $align ) {
		if ( $align !== $taken ) {
			return $align;
		}
		$flip = array(
			'left'   => 'right',
			'right'  => 'left',
			'center' => 'right',
		);
		return isset( $flip[ $align ] ) ? $flip[ $align ] : 'right';
	}

	/**
	 * No-cache, cookie-free responses. CORS is open so the snippet can later be
	 * used by publisher sites without changing this endpoint.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return void
	 */
	private static function cors( $response ) {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Access-Control-Allow-Methods', 'GET' );
	}
}
