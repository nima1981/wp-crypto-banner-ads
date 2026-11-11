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

		$token = DWADS_Clicks::begin_impression( $banner, $site_id, $page_id );
		DWADS_Banners::note_served( (int) $banner['id'] );

		$alt = trim( (string) $banner['alt_text'] );
		if ( '' === $alt ) {
			$alt = get_bloginfo( 'name' );
		}

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

		$response = new WP_REST_Response( $payload, 200 );
		self::cors( $response );
		return $response;
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
