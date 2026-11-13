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
 * Shortcodes, widget and the placeholder markup.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end integration points.
 */
final class DWADS_Shortcodes {

	/** @var bool */
	private static $localized = false;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );
	}

	/**
	 * Register shortcodes.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( 'dwads', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'dwads_upload', array( __CLASS__, 'upload_shortcode' ) );
	}

	/**
	 * Register assets (enqueued only when a slot is printed).
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_script(
			'dwads',
			DWADS_URL . 'assets/dwads.js',
			array(),
			DWADS_VERSION,
			true
		);
		wp_register_style(
			'dwads',
			DWADS_URL . 'assets/dwads.css',
			array(),
			DWADS_VERSION
		);
		wp_register_style(
			'dwads-form',
			DWADS_URL . 'assets/form.css',
			array(),
			DWADS_VERSION
		);
		wp_register_script(
			'dwads-upload',
			DWADS_URL . 'assets/upload.js',
			array(),
			DWADS_VERSION,
			true
		);
	}

	/**
	 * [dwads slot="300x250"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'slot'        => '300x250',
				'slot_mobile' => '',
				'fallback'    => '',
				'foot'        => '',
				'foot_text'   => '',
				'foot_url'    => '',
				'foot_align'  => '',
				'fund'        => '',
				'fund_text'   => '',
				'fund_align'  => '',
			),
			$atts,
			'dwads'
		);
		return self::placeholder(
			$atts['slot'],
			array(
				'mobile'    => $atts['slot_mobile'],
				'fallback'  => $atts['fallback'],
				'mode'      => $atts['foot'],
				'text'      => $atts['foot_text'],
				'url'       => $atts['foot_url'],
				'align'     => $atts['foot_align'],
				'fund_mode' => $atts['fund'],
				'fund_text' => $atts['fund_text'],
				'fund_align' => $atts['fund_align'],
			)
		);
	}

	/**
	 * [dwads_upload]
	 *
	 * @return string
	 */
	public static function upload_shortcode() {
		if ( ! wp_style_is( 'dwads-form', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'dwads-form' );
		wp_enqueue_script( 'dwads-upload' );
		return DWADS_Public_Pages::upload_form_html();
	}

	/**
	 * The empty slot markup. It has no intrinsic size, so a page with no
	 * eligible banners simply shows nothing.
	 *
	 * @param string $slot  Slot such as "300x250".
	 * @param array  $extra Optional per-placement overrides (footer link).
	 * @return string
	 */
	public static function placeholder( $slot, $extra = array() ) {
		$size = DWADS_Settings::size_for_slot( $slot );
		if ( ! $size ) {
			return '';
		}
		self::enqueue();

		// Emit the canonical WxH form so the attribute always matches a
		// configured size, even if the author typed "300 X 250".
		$canonical = $size['w'] . 'x' . $size['h'];
		$attrs     = '';

		// Responsive slot: a second size used below the mobile breakpoint. It
		// has to be one of the configured sizes too, otherwise no banner could
		// ever match it, so an unknown value is dropped rather than shipped.
		$mobile = isset( $extra['mobile'] ) ? DWADS_Settings::size_for_slot( (string) $extra['mobile'] ) : null;
		if ( $mobile ) {
			$mobile_key = $mobile['w'] . 'x' . $mobile['h'];
			if ( $mobile_key !== $canonical ) {
				$attrs .= ' data-dwads-slot-mobile="' . esc_attr( $mobile_key ) . '"';
			}
		}

		$fallback = isset( $extra['fallback'] ) ? strtolower( trim( (string) $extra['fallback'] ) ) : '';
		if ( in_array( $fallback, array( 'off', 'no', '0', 'false' ), true ) ) {
			$attrs .= ' data-dwads-fallback="0"';
		}

		foreach ( array(
			'mode'       => 'data-dwads-foot',
			'text'       => 'data-dwads-foot-text',
			'url'        => 'data-dwads-foot-url',
			'align'      => 'data-dwads-foot-align',
			'fund_mode'  => 'data-dwads-fund',
			'fund_text'  => 'data-dwads-fund-text',
			'fund_align' => 'data-dwads-fund-align',
		) as $key => $attribute ) {
			$value = isset( $extra[ $key ] ) ? trim( (string) $extra[ $key ] ) : '';
			if ( '' !== $value ) {
				$attrs .= ' ' . $attribute . '="' . esc_attr( $value ) . '"';
			}
		}

		return '<div class="dwads-slot" data-dwads-slot="' . esc_attr( $canonical ) . '"' . $attrs . '></div>';
	}

	/**
	 * Enqueue the loader with its API endpoint.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! wp_script_is( 'dwads', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_script( 'dwads' );
		wp_enqueue_style( 'dwads' );

		if ( ! self::$localized ) {
			self::$localized = true;
			wp_localize_script(
				'dwads',
				'dwadsConfig',
				array(
					'api'              => rest_url( 'dwads/v1/serve' ),
					'pageId'           => self::current_page_id(),
					'mobileBreakpoint' => (int) apply_filters( 'dwads_mobile_breakpoint', 600 ),
				)
			);
		}
	}

	/**
	 * Page id used later for keyword matching. Safe with cached pages because the
	 * value is per page, not per visitor.
	 *
	 * @return int
	 */
	private static function current_page_id() {
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return (int) get_queried_object_id();
		}
		return 0;
	}

	/**
	 * Register the sidebar widget.
	 *
	 * @return void
	 */
	public static function register_widget() {
		register_widget( 'DWADS_Widget' );
	}
}

/**
 * Sidebar widget that prints an empty slot.
 */
class DWADS_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'dwads_slot',
			__( 'Banner Ad Slot', 'dwads' ),
			array( 'description' => __( 'Rotates paid banners that fit the selected size.', 'dwads' ) )
		);
	}

	/**
	 * Front-end output.
	 *
	 * @param array $args     Sidebar args.
	 * @param array $instance Settings.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$slot = isset( $instance['slot'] ) ? (string) $instance['slot'] : '300x250';
		$html = DWADS_Shortcodes::placeholder(
			$slot,
			array(
				'mobile'     => isset( $instance['slot_mobile'] ) ? (string) $instance['slot_mobile'] : '',
				'fund_mode'  => isset( $instance['fund_mode'] ) ? (string) $instance['fund_mode'] : '',
				'fund_text'  => isset( $instance['fund_text'] ) ? (string) $instance['fund_text'] : '',
				'fund_align' => isset( $instance['fund_align'] ) ? (string) $instance['fund_align'] : '',
				'mode'       => isset( $instance['foot_mode'] ) ? (string) $instance['foot_mode'] : '',
				'text'  => isset( $instance['foot_text'] ) ? (string) $instance['foot_text'] : '',
				'url'   => isset( $instance['foot_url'] ) ? (string) $instance['foot_url'] : '',
				'align' => isset( $instance['foot_align'] ) ? (string) $instance['foot_align'] : '',
			)
		);
		if ( '' === $html ) {
			return;
		}
		echo isset( $args['before_widget'] ) ? $args['before_widget'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! empty( $instance['title'] ) ) {
			echo ( isset( $args['before_title'] ) ? $args['before_title'] : '' ) . esc_html( $instance['title'] ) . ( isset( $args['after_title'] ) ? $args['after_title'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo isset( $args['after_widget'] ) ? $args['after_widget'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Admin form.
	 *
	 * @param array $instance Settings.
	 * @return void
	 */
	public function form( $instance ) {
		$slot  = isset( $instance['slot'] ) ? (string) $instance['slot'] : '300x250';
		$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		$sizes = DWADS_Settings::sizes();
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title (optional)', 'dwads' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'slot' ) ); ?>"><?php esc_html_e( 'Slot size', 'dwads' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'slot' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'slot' ) ); ?>">
				<?php foreach ( $sizes as $key => $size ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $slot, $key ); ?>><?php echo esc_html( $key ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
		$mobile = isset( $instance['slot_mobile'] ) ? (string) $instance['slot_mobile'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'slot_mobile' ) ); ?>"><?php esc_html_e( 'Size on phones (optional)', 'dwads' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'slot_mobile' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'slot_mobile' ) ); ?>">
				<option value="" <?php selected( $mobile, '' ); ?>><?php esc_html_e( 'Same size everywhere', 'dwads' ); ?></option>
				<?php foreach ( $sizes as $key => $size ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $mobile, $key ); ?>><?php echo esc_html( $key ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php esc_html_e( 'Banners of this size replace the one above on narrow screens. Never wider than the slot, or it will scale down.', 'dwads' ); ?></span>
		</p>
		<?php
		$mode  = isset( $instance['foot_mode'] ) ? (string) $instance['foot_mode'] : 'inherit';
		$ftext = isset( $instance['foot_text'] ) ? (string) $instance['foot_text'] : '';
		$furl  = isset( $instance['foot_url'] ) ? (string) $instance['foot_url'] : '';
		$fal   = isset( $instance['foot_align'] ) ? (string) $instance['foot_align'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'foot_mode' ) ); ?>"><?php esc_html_e( 'Link under the banner', 'dwads' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'foot_mode' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'foot_mode' ) ); ?>">
				<option value="inherit" <?php selected( $mode, 'inherit' ); ?>><?php esc_html_e( 'Use the site setting', 'dwads' ); ?></option>
				<option value="on" <?php selected( $mode, 'on' ); ?>><?php esc_html_e( 'Show a link here', 'dwads' ); ?></option>
				<option value="off" <?php selected( $mode, 'off' ); ?>><?php esc_html_e( 'Never show it here', 'dwads' ); ?></option>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'foot_text' ) ); ?>"><?php esc_html_e( 'Link text', 'dwads' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'foot_text' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'foot_text' ) ); ?>" type="text" value="<?php echo esc_attr( $ftext ); ?>" placeholder="<?php esc_attr_e( 'Site setting', 'dwads' ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'foot_url' ) ); ?>"><?php esc_html_e( 'Link address', 'dwads' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'foot_url' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'foot_url' ) ); ?>" type="text" value="<?php echo esc_attr( $furl ); ?>" placeholder="<?php esc_attr_e( 'Site setting', 'dwads' ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'foot_align' ) ); ?>"><?php esc_html_e( 'Alignment', 'dwads' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'foot_align' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'foot_align' ) ); ?>">
				<option value="" <?php selected( $fal, '' ); ?>><?php esc_html_e( 'Site setting', 'dwads' ); ?></option>
				<option value="left" <?php selected( $fal, 'left' ); ?>><?php esc_html_e( 'Left', 'dwads' ); ?></option>
				<option value="center" <?php selected( $fal, 'center' ); ?>><?php esc_html_e( 'Center', 'dwads' ); ?></option>
				<option value="right" <?php selected( $fal, 'right' ); ?>><?php esc_html_e( 'Right', 'dwads' ); ?></option>
			</select>
		</p>
		<?php
		$fmode = isset( $instance['fund_mode'] ) ? (string) $instance['fund_mode'] : 'inherit';
		$ftext2 = isset( $instance['fund_text'] ) ? (string) $instance['fund_text'] : '';
		$fal2  = isset( $instance['fund_align'] ) ? (string) $instance['fund_align'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'fund_mode' ) ); ?>"><?php esc_html_e( '“Fund this banner” link', 'dwads' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'fund_mode' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'fund_mode' ) ); ?>">
				<option value="inherit" <?php selected( $fmode, 'inherit' ); ?>><?php esc_html_e( 'Use the site setting', 'dwads' ); ?></option>
				<option value="on" <?php selected( $fmode, 'on' ); ?>><?php esc_html_e( 'Show it here', 'dwads' ); ?></option>
				<option value="off" <?php selected( $fmode, 'off' ); ?>><?php esc_html_e( 'Never show it here', 'dwads' ); ?></option>
			</select>
			<span class="description"><?php esc_html_e( 'Opens the banner’s own management page, where anyone can add funds to it.', 'dwads' ); ?></span>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'fund_text' ) ); ?>"><?php esc_html_e( 'Fund link text', 'dwads' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'fund_text' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'fund_text' ) ); ?>" type="text" value="<?php echo esc_attr( $ftext2 ); ?>" placeholder="<?php esc_attr_e( 'Site setting', 'dwads' ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'fund_align' ) ); ?>"><?php esc_html_e( 'Fund link alignment', 'dwads' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'fund_align' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'fund_align' ) ); ?>">
				<option value="" <?php selected( $fal2, '' ); ?>><?php esc_html_e( 'Site setting', 'dwads' ); ?></option>
				<option value="left" <?php selected( $fal2, 'left' ); ?>><?php esc_html_e( 'Left', 'dwads' ); ?></option>
				<option value="center" <?php selected( $fal2, 'center' ); ?>><?php esc_html_e( 'Center', 'dwads' ); ?></option>
				<option value="right" <?php selected( $fal2, 'right' ); ?>><?php esc_html_e( 'Right', 'dwads' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * Save.
	 *
	 * @param array $new_instance New values.
	 * @param array $old_instance Old values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$mode = isset( $new_instance['foot_mode'] ) ? sanitize_text_field( $new_instance['foot_mode'] ) : 'inherit';
		if ( ! in_array( $mode, array( 'inherit', 'on', 'off' ), true ) ) {
			$mode = 'inherit';
		}
		$align = isset( $new_instance['foot_align'] ) ? sanitize_text_field( $new_instance['foot_align'] ) : '';
		if ( ! in_array( $align, array( '', 'left', 'center', 'right' ), true ) ) {
			$align = '';
		}

		$fmode = isset( $new_instance['fund_mode'] ) ? sanitize_text_field( $new_instance['fund_mode'] ) : 'inherit';
		if ( ! in_array( $fmode, array( 'inherit', 'on', 'off' ), true ) ) {
			$fmode = 'inherit';
		}
		$fal2 = isset( $new_instance['fund_align'] ) ? sanitize_text_field( $new_instance['fund_align'] ) : '';
		if ( ! in_array( $fal2, array( '', 'left', 'center', 'right' ), true ) ) {
			$fal2 = '';
		}

		$mobile = sanitize_text_field( isset( $new_instance['slot_mobile'] ) ? $new_instance['slot_mobile'] : '' );
		if ( '' !== $mobile && ! DWADS_Settings::size_for_slot( $mobile ) ) {
			$mobile = '';
		}

		return array(
			'title'       => sanitize_text_field( isset( $new_instance['title'] ) ? $new_instance['title'] : '' ),
			'slot'        => sanitize_text_field( isset( $new_instance['slot'] ) ? $new_instance['slot'] : '300x250' ),
			'slot_mobile' => $mobile,
			'foot_mode'  => $mode,
			'foot_text'  => mb_substr( sanitize_text_field( isset( $new_instance['foot_text'] ) ? $new_instance['foot_text'] : '' ), 0, 60 ),
			'foot_url'   => esc_url_raw( isset( $new_instance['foot_url'] ) ? $new_instance['foot_url'] : '' ),
			'foot_align' => $align,
			'fund_mode'  => $fmode,
			'fund_text'  => mb_substr( sanitize_text_field( isset( $new_instance['fund_text'] ) ? $new_instance['fund_text'] : '' ), 0, 60 ),
			'fund_align' => $fal2,
		);
	}
}
