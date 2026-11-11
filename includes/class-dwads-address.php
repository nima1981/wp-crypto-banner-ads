<?php
/**
 * Bitcoin address encoding for the supported script types.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Address helpers.
 */
final class DWADS_Address {

	/**
	 * RIPEMD160(SHA256(bytes)).
	 *
	 * @param string $bytes Public key bytes.
	 * @return string Raw 20 byte hash.
	 * @throws RuntimeException When RIPEMD160 is unavailable.
	 */
	public static function hash160( $bytes ) {
		static $available = null;
		if ( null === $available ) {
			$available = in_array( 'ripemd160', hash_algos(), true );
		}
		if ( ! $available ) {
			throw new RuntimeException( 'RIPEMD160 is missing from this PHP build.' );
		}
		return hash( 'ripemd160', hash( 'sha256', $bytes, true ), true );
	}

	/**
	 * Encode a public key as an address.
	 *
	 * @param string $pub_bytes Compressed public key bytes.
	 * @param string $type      p2pkh, p2sh-p2wpkh or p2wpkh.
	 * @param bool   $testnet   Testnet keys.
	 * @return string|WP_Error
	 */
	public static function encode( $pub_bytes, $type, $testnet = false ) {
		try {
			$hash = self::hash160( $pub_bytes );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'dwads_ripemd', __( 'This server cannot derive Bitcoin addresses because PHP is missing RIPEMD160 (OpenSSL legacy). Ask your host to enable the hash extension with ripemd160.', 'dwads' ) );
		}
		switch ( $type ) {
			case 'p2pkh':
				$version = $testnet ? "\x6f" : "\x00";
				return DWADS_Base58::checkEncode( $version . $hash );

			case 'p2sh-p2wpkh':
				$redeem = "\x00\x14" . $hash;
				$version = $testnet ? "\xc4" : "\x05";
				return DWADS_Base58::checkEncode( $version . self::hash160( $redeem ) );

			case 'p2wpkh':
				return DWADS_Bech32::encodeSegwit( $testnet ? 'tb' : 'bc', 0, $hash );
		}
		return new WP_Error( 'dwads_addr_type', __( 'Unknown address type.', 'dwads' ) );
	}
}
