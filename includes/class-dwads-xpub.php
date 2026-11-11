<?php
/**
 * Watch-only HD wallet derivation.
 *
 * The plugin stores an extended PUBLIC key only. Addresses are derived on demand
 * and no private key material ever touches the web server.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extended public key parsing and derivation.
 */
final class DWADS_Xpub {

	/**
	 * Supported versions: type of addresses produced, and network.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function versions() {
		return array(
			'0488B21E' => array(
				'type'    => 'p2pkh',
				'testnet' => false,
				'label'   => 'xpub',
			),
			'049D7CB2' => array(
				'type'    => 'p2sh-p2wpkh',
				'testnet' => false,
				'label'   => 'ypub',
			),
			'04B24746' => array(
				'type'    => 'p2wpkh',
				'testnet' => false,
				'label'   => 'zpub',
			),
			'043587CF' => array(
				'type'    => 'p2pkh',
				'testnet' => true,
				'label'   => 'tpub',
			),
			'044A5262' => array(
				'type'    => 'p2sh-p2wpkh',
				'testnet' => true,
				'label'   => 'upub',
			),
			'045F1CF6' => array(
				'type'    => 'p2wpkh',
				'testnet' => true,
				'label'   => 'vpub',
			),
		);
	}

	/**
	 * Parse an extended public key into a derivation node.
	 *
	 * @param string $xpub Encoded key.
	 * @return array|WP_Error
	 */
	public static function parse( $xpub ) {
		if ( ! DWADS_BigInt::available() ) {
			return new WP_Error( 'dwads_backend', __( 'The PHP GMP or BCMath extension is required to derive addresses.', 'dwads' ) );
		}
		$raw = DWADS_Base58::checkDecode( trim( (string) $xpub ) );
		if ( null === $raw || 78 !== strlen( $raw ) ) {
			return new WP_Error( 'dwads_xpub_format', __( 'That is not a valid extended public key. Paste the key starting with xpub, ypub, zpub, tpub, upub or vpub.', 'dwads' ) );
		}
		$version = strtoupper( bin2hex( substr( $raw, 0, 4 ) ) );
		$versions = self::versions();
		if ( ! isset( $versions[ $version ] ) ) {
			return new WP_Error( 'dwads_xpub_version', __( 'That extended key version is not supported.', 'dwads' ) );
		}
		$key = substr( $raw, 45, 33 );
		if ( "\x02" !== $key[0] && "\x03" !== $key[0] ) {
			return new WP_Error( 'dwads_xpub_private', __( 'That looks like a private extended key (xprv/zprv). Only a watch-only public key may be stored.', 'dwads' ) );
		}
		return array(
			'version'    => $version,
			'label'      => $versions[ $version ]['label'],
			'type'       => $versions[ $version ]['type'],
			'testnet'    => $versions[ $version ]['testnet'],
			'depth'      => ord( $raw[4] ),
			'chain_code' => substr( $raw, 13, 32 ),
			'pubkey'     => $key,
		);
	}

	/**
	 * Derive one non-hardened child.
	 *
	 * @param array $node  Node.
	 * @param int   $index Child index (below 2^31).
	 * @return array|WP_Error
	 */
	public static function derive_child( $node, $index ) {
		$index = (int) $index;
		if ( $index < 0 || $index >= 2147483648 ) {
			return new WP_Error( 'dwads_index', __( 'Address index out of range.', 'dwads' ) );
		}
		$hmac = hash_hmac( 'sha512', $node['pubkey'] . pack( 'N', $index ), $node['chain_code'], true );
		$il   = substr( $hmac, 0, 32 );
		$ir   = substr( $hmac, 32, 32 );

		$tweak = DWADS_BigInt::fromHex( bin2hex( $il ) );
		if ( DWADS_BigInt::cmp( $tweak, DWADS_Secp256k1::order() ) >= 0 ) {
			return new WP_Error( 'dwads_derive', __( 'Derived key material was out of range.', 'dwads' ) );
		}
		$point = DWADS_Secp256k1::tweakAdd( bin2hex( $node['pubkey'] ), $tweak );
		if ( is_wp_error( $point ) ) {
			return $point;
		}
		$pub_hex = DWADS_Secp256k1::compress( $point );
		if ( ! $pub_hex ) {
			return new WP_Error( 'dwads_derive', __( 'Derived public key was invalid.', 'dwads' ) );
		}
		return array(
			'version'    => $node['version'],
			'label'      => $node['label'],
			'type'       => $node['type'],
			'testnet'    => $node['testnet'],
			'depth'      => $node['depth'] + 1,
			'chain_code' => $ir,
			'pubkey'     => hex2bin( $pub_hex ),
			'index'      => $index,
		);
	}

	/**
	 * Normalise a derivation path template such as "0/{i}".
	 *
	 * @param string $template Raw template.
	 * @return string
	 */
	public static function clean_template( $template ) {
		$template = trim( (string) $template );
		$template = preg_replace( '#^m/?#i', '', $template );
		$template = preg_replace( '/[^0-9\{\}\/i]/', '', $template );
		$template = trim( preg_replace( '#/+#', '/', $template ), '/' );
		if ( '' === $template ) {
			$template = '0/{i}';
		}
		if ( false === strpos( $template, '{i}' ) ) {
			$template .= '/{i}';
		}
		return $template;
	}

	/**
	 * Whether a path template asks for hardened derivation, which is impossible
	 * from a public key alone.
	 *
	 * @param string $template Raw template.
	 * @return bool
	 */
	public static function has_hardened( $template ) {
		return (bool) preg_match( "/[0-9]\\s*['hH]/", (string) $template );
	}

	/**
	 * Derive along a template path.
	 *
	 * @param array  $node     Starting node.
	 * @param string $template Path template containing {i}.
	 * @param int    $index    Index substituted for {i}.
	 * @return array|WP_Error
	 */
	public static function derive_path( $node, $template, $index ) {
		$segments = explode( '/', self::clean_template( $template ) );
		foreach ( $segments as $segment ) {
			$segment = trim( $segment );
			if ( '' === $segment ) {
				continue;
			}
			$value = ( '{i}' === $segment ) ? (int) $index : (int) preg_replace( '/[^0-9]/', '', str_replace( '{i}', (string) $index, $segment ) );
			if ( $value < 0 ) {
				return new WP_Error( 'dwads_path', __( 'Invalid derivation path.', 'dwads' ) );
			}
			$node = self::derive_child( $node, $value );
			if ( is_wp_error( $node ) ) {
				return $node;
			}
		}
		return $node;
	}

	/**
	 * Turn a derived node into an address.
	 *
	 * @param array $node Node.
	 * @return string|WP_Error
	 */
	public static function address( $node ) {
		return DWADS_Address::encode( $node['pubkey'], $node['type'], ! empty( $node['testnet'] ) );
	}

	/**
	 * Load the configured key plus address type override.
	 *
	 * @return array|WP_Error
	 */
	public static function settings_config() {
		$xpub = trim( (string) DWADS_Settings::get( 'xpub', '' ) );
		if ( '' === $xpub ) {
			return new WP_Error( 'dwads_no_xpub', __( 'No extended public key has been saved yet. Add your wallet xpub in Banner Ads settings to accept paid banners.', 'dwads' ) );
		}
		$node = self::parse( $xpub );
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		$type = (string) DWADS_Settings::get( 'address_type', 'auto' );
		if ( in_array( $type, array( 'p2pkh', 'p2sh-p2wpkh', 'p2wpkh' ), true ) ) {
			$node['type'] = $type;
		}
		return $node;
	}

	/**
	 * Reserve the next derivation index. Uses a single atomic UPDATE so two
	 * simultaneous uploads cannot share an index.
	 *
	 * @return int
	 */
	public static function reserve_index() {
		global $wpdb;

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", 'dwads_next_index' ) );
		if ( null === $exists ) {
			add_option( 'dwads_next_index', 0, '', 'no' );
		}
		$before = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'dwads_next_index' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", 'dwads_next_index' ) );
		wp_cache_delete( 'dwads_next_index', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		return (int) $before;
	}

	/**
	 * Current index without reserving it.
	 *
	 * @return int
	 */
	public static function peek_index() {
		return (int) get_option( 'dwads_next_index', 0 );
	}

	/**
	 * Derive a fresh address for a new banner.
	 *
	 * @return array|WP_Error Array with address and index.
	 */
	public static function next_address() {
		$node = self::settings_config();
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		$template = self::clean_template( DWADS_Settings::get( 'derivation_path', '0/{i}' ) );
		$last     = null;
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			$index = self::reserve_index();
			$address = self::derive_path( $node, $template, $index );
			if ( ! is_wp_error( $address ) ) {
				$encoded = self::address( $address );
				if ( ! is_wp_error( $encoded ) ) {
					return array(
						'address' => $encoded,
						'index'   => $index,
					);
				}
				return $encoded;
			}
			$last = $address;
		}
		return $last ? $last : new WP_Error( 'dwads_xpub_derive', __( 'Could not derive an address.', 'dwads' ) );
	}

	/**
	 * Preview several addresses for the settings screen.
	 *
	 * @param int $count How many.
	 * @param int $start Starting index.
	 * @return array|WP_Error List of array( index, address ).
	 */
	public static function preview( $count = 5, $start = 0 ) {
		$node = self::settings_config();
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		$template = self::clean_template( DWADS_Settings::get( 'derivation_path', '0/{i}' ) );
		$out      = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$index = (int) $start + $i;
			$child = self::derive_path( $node, $template, $index );
			if ( is_wp_error( $child ) ) {
				return $child;
			}
			$address = self::address( $child );
			if ( is_wp_error( $address ) ) {
				return $address;
			}
			$out[] = array(
				'index'   => $index,
				'address' => $address,
			);
		}
		return $out;
	}
}
