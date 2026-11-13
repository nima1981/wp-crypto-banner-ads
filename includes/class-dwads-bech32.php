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
 * Bech32 encoding (BIP-173), enough for native segwit v0 addresses.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bech32 helpers.
 */
final class DWADS_Bech32 {

	const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

	/**
	 * Generator constants for the BCH checksum.
	 *
	 * @var int[]
	 */
	private static $gen = array( 0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3 );

	/**
	 * Encode a segwit address.
	 *
	 * @param string $hrp          Human readable part (bc, tb).
	 * @param int    $version      Witness version.
	 * @param string $program      Witness program bytes.
	 * @return string
	 */
	public static function encodeSegwit( $hrp, $version, $program ) {
		$data = array_merge( array( (int) $version ), self::convertBits( $program, 8, 5 ) );
		return $hrp . '1' . self::encodeData( $hrp, $data );
	}

	/**
	 * Regroup bits between byte sizes.
	 *
	 * @param string $bytes Input bytes.
	 * @param int    $from  Input bit size.
	 * @param int    $to    Output bit size.
	 * @return int[]
	 */
	private static function convertBits( $bytes, $from, $to ) {
		$acc   = 0;
		$bits  = 0;
		$out   = array();
		$maxv  = ( 1 << $to ) - 1;
		$len   = strlen( $bytes );
		for ( $i = 0; $i < $len; $i++ ) {
			$acc   = ( $acc << $from ) | ord( $bytes[ $i ] );
			$bits += $from;
			while ( $bits >= $to ) {
				$bits -= $to;
				$out[] = ( $acc >> $bits ) & $maxv;
			}
		}
		if ( $bits > 0 ) {
			$out[] = ( $acc << ( $to - $bits ) ) & $maxv;
		}
		return $out;
	}

	/**
	 * BCH checksum over the expanded HRP and data.
	 *
	 * @param int[] $values 5-bit values.
	 * @return int
	 */
	private static function polymod( $values ) {
		$chk = 1;
		foreach ( $values as $value ) {
			$top = $chk >> 25;
			$chk = ( ( $chk & 0x1ffffff ) << 5 ) ^ $value;
			for ( $i = 0; $i < 5; $i++ ) {
				if ( ( $top >> $i ) & 1 ) {
					$chk ^= self::$gen[ $i ];
				}
			}
		}
		return $chk;
	}

	/**
	 * Expand the HRP for checksumming.
	 *
	 * @param string $hrp HRP.
	 * @return int[]
	 */
	private static function hrpExpand( $hrp ) {
		$out = array();
		$len = strlen( $hrp );
		for ( $i = 0; $i < $len; $i++ ) {
			$out[] = ord( $hrp[ $i ] ) >> 5;
		}
		$out[] = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$out[] = ord( $hrp[ $i ] ) & 31;
		}
		return $out;
	}

	/**
	 * Encode data with checksum, without the HRP prefix.
	 *
	 * @param string $hrp  HRP.
	 * @param int[]  $data 5-bit values.
	 * @return string
	 */
	public static function encodeData( $hrp, $data ) {
		$values = array_merge( self::hrpExpand( $hrp ), $data, array( 0, 0, 0, 0, 0, 0 ) );
		$mod    = self::polymod( $values ) ^ 1;
		$out    = '';
		foreach ( $data as $value ) {
			$out .= self::CHARSET[ $value ];
		}
		for ( $i = 0; $i < 6; $i++ ) {
			$out .= self::CHARSET[ ( $mod >> ( 5 * ( 5 - $i ) ) ) & 31 ];
		}
		return $out;
	}
}
