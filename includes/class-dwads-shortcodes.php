<?php
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
	}

	/**
	 * [dwads slot="300x250"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array( 'slot' => '300x250' ),
			$atts,
			'dwads'
		);
		return self::placeholder( $atts['slot'] );
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
		return DWADS_Public_Pages::upload_form_html();
	}

	/**
	 * The empty slot markup. It has no intrinsic size, so a page with no
	 * eligible banners simply shows nothing.
	 *
	 * @param string $slot Slot such as "300x250".
	 * @return string
	 */
	public static function placeholder( $slot ) {
		$size = DWADS_Settings::size_for_slot( $slot );
		if ( ! $size ) {
			return '';
		}
		self::enqueue();
		// Emit the canonical WxH form so the attribute always matches a
		// configured size, even if the author typed "300 X 250".
		return '<div class="dwads-slot" data-dwads-slot="' . esc_attr( $size['w'] . 'x' . $size['h'] ) . '"></div>';
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
					'api'    => rest_url( 'dwads/v1/serve' ),
					'pageId' => self::current_page_id(),
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
		$html = DWADS_Shortcodes::placeholder( $slot );
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
	}

	/**
	 * Save.
	 *
	 * @param array $new_instance New values.
	 * @param array $old_instance Old values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title' => sanitize_text_field( isset( $new_instance['title'] ) ? $new_instance['title'] : '' ),
			'slot'  => sanitize_text_field( isset( $new_instance['slot'] ) ? $new_instance['slot'] : '300x250' ),
		);
	}
}
