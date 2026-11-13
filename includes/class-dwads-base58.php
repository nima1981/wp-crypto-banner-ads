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
 * Base58 / Base58Check encoding.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base58 helpers.
 */
final class DWADS_Base58 {

	const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

	/**
	 * Encode raw bytes.
	 *
	 * @param string $bytes Binary string.
	 * @return string
	 */
	public static function encode( $bytes ) {
		$bytes = (string) $bytes;
		if ( '' === $bytes ) {
			return '';
		}
		$dec = DWADS_BigInt::fromHex( bin2hex( $bytes ) );
		$out = '';
		while ( DWADS_BigInt::cmp( $dec, '0' ) > 0 ) {
			$mod = (int) DWADS_BigInt::mod( $dec, '58' );
			$out = self::ALPHABET[ $mod ] . $out;
			$dec = DWADS_BigInt::divQ( $dec, '58' );
		}
		$len = strlen( $bytes );
		$pad = 0;
		while ( $pad < $len && "\0" === $bytes[ $pad ] ) {
			$out = '1' . $out;
			$pad++;
		}
		return $out;
	}

	/**
	 * Decode to raw bytes.
	 *
	 * @param string $str Base58 text.
	 * @return string|null
	 */
	public static function decode( $str ) {
		$str = trim( (string) $str );
		if ( '' === $str ) {
			return '';
		}
		$dec = '0';
		$len = strlen( $str );
		for ( $i = 0; $i < $len; $i++ ) {
			$pos = strpos( self::ALPHABET, $str[ $i ] );
			if ( false === $pos ) {
				return null;
			}
			$dec = DWADS_BigInt::add( DWADS_BigInt::mul( $dec, '58' ), (string) $pos );
		}
		$hex = DWADS_BigInt::toHex( $dec, 0 );
		if ( strlen( $hex ) % 2 ) {
			$hex = '0' . $hex;
		}
		$bin = hex2bin( $hex );
		if ( false === $bin ) {
			return null;
		}
		$pad = 0;
		while ( $pad < $len && '1' === $str[ $pad ] ) {
			$bin = "\0" . $bin;
			$pad++;
		}
		return $bin;
	}

	/**
	 * Base58Check encode.
	 *
	 * @param string $payload Raw payload bytes.
	 * @return string
	 */
	public static function checkEncode( $payload ) {
		$check = substr( hash( 'sha256', hash( 'sha256', $payload, true ), true ), 0, 4 );
		return self::encode( $payload . $check );
	}

	/**
	 * Base58Check decode.
	 *
	 * @param string $str Encoded text.
	 * @return string|null Raw payload without checksum.
	 */
	public static function checkDecode( $str ) {
		$bin = self::decode( $str );
		if ( null === $bin || strlen( $bin ) < 5 ) {
			return null;
		}
		$payload = substr( $bin, 0, -4 );
		$check   = substr( $bin, -4 );
		if ( ! hash_equals( substr( hash( 'sha256', hash( 'sha256', $payload, true ), true ), 0, 4 ), $check ) ) {
			return null;
		}
		return $payload;
	}
}
