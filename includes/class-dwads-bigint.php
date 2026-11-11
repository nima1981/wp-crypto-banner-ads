<?php
/**
 * Minimal arbitrary-precision integer helper.
 *
 * Uses the GMP extension when available, BCMath otherwise. All values are
 * decimal strings so the two backends can share the same call sites.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decimal string big integer maths.
 */
final class DWADS_BigInt {

	/** @var string|null Backend: gmp, bc or none. */
	private static $mode = null;

	/**
	 * Detect the numeric backend once per request.
	 *
	 * @return string
	 */
	public static function mode() {
		if ( null === self::$mode ) {
			if ( function_exists( 'gmp_add' ) ) {
				self::$mode = 'gmp';
			} elseif ( function_exists( 'bcadd' ) ) {
				self::$mode = 'bc';
				bcscale( 0 );
			} else {
				self::$mode = 'none';
			}
		}
		return self::$mode;
	}

	/**
	 * Whether address derivation can run on this server.
	 *
	 * @return bool
	 */
	public static function available() {
		return 'none' !== self::mode();
	}

	/**
	 * Normalise a decimal string (strip leading zeros, keep a sign).
	 *
	 * @param string $a Value.
	 * @return string
	 */
	public static function normalize( $a ) {
		$a   = (string) $a;
		$neg = false;
		if ( '' === $a ) {
			return '0';
		}
		if ( '-' === $a[0] ) {
			$neg = true;
			$a   = substr( $a, 1 );
		}
		$a = ltrim( $a, '0' );
		if ( '' === $a ) {
			return '0';
		}
		return $neg ? '-' . $a : $a;
	}

	/**
	 * Convert a hex string to decimal.
	 *
	 * @param string $hex Hex digits.
	 * @return string
	 */
	public static function fromHex( $hex ) {
		$hex = strtolower( preg_replace( '/[^0-9a-fA-F]/', '', (string) $hex ) );
		$hex = ltrim( $hex, '0' );
		if ( '' === $hex ) {
			return '0';
		}
		if ( 'gmp' === self::mode() ) {
			return gmp_strval( gmp_init( $hex, 16 ) );
		}
		$dec = '0';
		$len = strlen( $hex );
		for ( $i = 0; $i < $len; $i++ ) {
			$dec = bcadd( bcmul( $dec, '16' ), (string) hexdec( $hex[ $i ] ) );
		}
		return self::normalize( $dec );
	}

	/**
	 * Convert a decimal string to hex.
	 *
	 * @param string $dec       Decimal value.
	 * @param int    $pad_bytes Pad to this many bytes (0 for no padding).
	 * @return string
	 */
	public static function toHex( $dec, $pad_bytes = 32 ) {
		$dec = self::normalize( (string) $dec );
		if ( 'gmp' === self::mode() ) {
			$hex = '0' === $dec ? '0' : gmp_strval( gmp_init( $dec, 10 ), 16 );
		} else {
			$hex = '';
			$v   = $dec;
			if ( '-' === substr( $v, 0, 1 ) ) {
				return '0';
			}
			while ( bccomp( $v, '0' ) > 0 ) {
				$hex = dechex( (int) bcmod( $v, '16' ) ) . $hex;
				$v   = bcdiv( $v, '16', 0 );
			}
			if ( '' === $hex ) {
				$hex = '0';
			}
		}
		$hex = ltrim( $hex, '0' );
		if ( '' === $hex ) {
			$hex = '0';
		}
		if ( $pad_bytes > 0 && strlen( $hex ) < $pad_bytes * 2 ) {
			$hex = str_pad( $hex, $pad_bytes * 2, '0', STR_PAD_LEFT );
		}
		return strtolower( $hex );
	}

	/**
	 * Add.
	 *
	 * @param string $a Left.
	 * @param string $b Right.
	 * @return string
	 */
	public static function add( $a, $b ) {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( 'gmp' === self::mode() ) {
			return gmp_strval( gmp_add( gmp_init( $a, 10 ), gmp_init( $b, 10 ) ) );
		}
		return self::normalize( bcadd( $a, $b ) );
	}

	/**
	 * Subtract.
	 *
	 * @param string $a Left.
	 * @param string $b Right.
	 * @return string
	 */
	public static function sub( $a, $b ) {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( 'gmp' === self::mode() ) {
			return gmp_strval( gmp_sub( gmp_init( $a, 10 ), gmp_init( $b, 10 ) ) );
		}
		return self::normalize( bcsub( $a, $b ) );
	}

	/**
	 * Multiply.
	 *
	 * @param string $a Left.
	 * @param string $b Right.
	 * @return string
	 */
	public static function mul( $a, $b ) {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( 'gmp' === self::mode() ) {
			return gmp_strval( gmp_mul( gmp_init( $a, 10 ), gmp_init( $b, 10 ) ) );
		}
		return self::normalize( bcmul( $a, $b ) );
	}

	/**
	 * Multiply then reduce.
	 *
	 * @param string $a Left.
	 * @param string $b Right.
	 * @param string $m Modulus.
	 * @return string
	 */
	public static function mulmod( $a, $b, $m ) {
		return self::mod( self::mul( $a, $b ), $m );
	}

	/**
	 * Non-negative remainder.
	 *
	 * @param string $a Value.
	 * @param string $m Modulus.
	 * @return string
	 */
	public static function mod( $a, $m ) {
		$a = self::normalize( $a );
		$m = self::normalize( $m );
		if ( 'gmp' === self::mode() ) {
			return gmp_strval( gmp_mod( gmp_init( $a, 10 ), gmp_init( $m, 10 ) ) );
		}
		$r = bcmod( $a, $m );
		if ( '-' === substr( $r, 0, 1 ) ) {
			$r = bcadd( $r, $m );
		}
		return self::normalize( $r );
	}

	/**
	 * Compare.
	 *
	 * @param string $a Left.
	 * @param string $b Right.
	 * @return int -1, 0 or 1.
	 */
	public static function cmp( $a, $b ) {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( 'gmp' === self::mode() ) {
			return gmp_cmp( gmp_init( $a, 10 ), gmp_init( $b, 10 ) );
		}
		return bccomp( $a, $b );
	}

	/**
	 * Truncating division.
	 *
	 * @param string $a Dividend.
	 * @param string $b Divisor.
	 * @return string
	 */
	public static function divQ( $a, $b ) {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( '0' === $b ) {
			return '0';
		}
		if ( 'gmp' === self::mode() ) {
			return gmp_strval( gmp_div_q( gmp_init( $a, 10 ), gmp_init( $b, 10 ), GMP_ROUND_ZERO ) );
		}
		return self::normalize( bcdiv( $a, $b, 0 ) );
	}

	/**
	 * Modular inverse.
	 *
	 * @param string $a Value.
	 * @param string $m Modulus.
	 * @return string|null Null when no inverse exists.
	 */
	public static function modinv( $a, $m ) {
		$a  = self::mod( $a, $m );
		$r0 = self::normalize( $m );
		$r1 = $a;
		$t0 = '0';
		$t1 = '1';
		while ( self::cmp( $r1, '0' ) > 0 ) {
			$q               = self::divQ( $r0, $r1 );
			list( $r0, $r1 ) = array( $r1, self::sub( $r0, self::mul( $q, $r1 ) ) );
			list( $t0, $t1 ) = array( $t1, self::sub( $t0, self::mul( $q, $t1 ) ) );
		}
		if ( 0 !== self::cmp( $r0, '1' ) ) {
			return null;
		}
		return self::mod( $t0, $m );
	}

	/**
	 * Modular exponentiation.
	 *
	 * @param string $base Base.
	 * @param string $exp  Exponent.
	 * @param string $m    Modulus.
	 * @return string
	 */
	public static function modpow( $base, $exp, $m ) {
		$base = self::mod( $base, $m );
		$exp  = self::normalize( $exp );
		if ( 'gmp' === self::mode() ) {
			return gmp_strval( gmp_powm( gmp_init( $base, 10 ), gmp_init( $exp, 10 ), gmp_init( $m, 10 ) ) );
		}
		$result = '1';
		$acc    = $base;
		$e      = $exp;
		while ( self::cmp( $e, '0' ) > 0 ) {
			if ( 0 === self::cmp( self::mod( $e, '2' ), '1' ) ) {
				$result = self::mulmod( $result, $acc, $m );
			}
			$e   = self::divQ( $e, '2' );
			$acc = self::mulmod( $acc, $acc, $m );
		}
		return $result;
	}
}
