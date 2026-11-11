<?php
/**
 * Minimal secp256k1 arithmetic: point decompression, addition, and k*G.
 *
 * Only public-key operations are implemented. No private keys exist anywhere in
 * this plugin, by design.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * secp256k1 curve helper.
 */
final class DWADS_Secp256k1 {

	const P_HEX  = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F';
	const N_HEX  = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';
	const GX_HEX = '79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798';
	const GY_HEX = '483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8';

	/** @var bool */
	private static $ready = false;

	/** @var string */
	private static $p;

	/** @var string */
	private static $n;

	/** @var string */
	private static $gx;

	/** @var string */
	private static $gy;

	/**
	 * Load constants once per request.
	 *
	 * @throws RuntimeException When no big integer backend is available.
	 * @return void
	 */
	private static function init() {
		if ( self::$ready ) {
			return;
		}
		if ( ! DWADS_BigInt::available() ) {
			throw new RuntimeException( 'Crypto Banner Ads requires the PHP GMP or BCMath extension.' );
		}
		self::$p     = DWADS_BigInt::fromHex( self::P_HEX );
		self::$n     = DWADS_BigInt::fromHex( self::N_HEX );
		self::$gx    = DWADS_BigInt::fromHex( self::GX_HEX );
		self::$gy    = DWADS_BigInt::fromHex( self::GY_HEX );
		self::$ready = true;
	}

	/**
	 * Curve order as a decimal string.
	 *
	 * @return string
	 */
	public static function order() {
		self::init();
		return self::$n;
	}

	/**
	 * Decompress a 33 byte public key into an affine point.
	 *
	 * @param string $pub_hex Compressed public key hex.
	 * @return array|null Array with x and y decimal strings.
	 */
	public static function decompress( $pub_hex ) {
		self::init();
		$pub_hex = strtolower( preg_replace( '/[^0-9a-f]/i', '', (string) $pub_hex ) );
		if ( 66 !== strlen( $pub_hex ) ) {
			return null;
		}
		$prefix = substr( $pub_hex, 0, 2 );
		if ( '02' !== $prefix && '03' !== $prefix ) {
			return null;
		}
		$p = self::$p;
		$x = DWADS_BigInt::fromHex( substr( $pub_hex, 2 ) );
		if ( DWADS_BigInt::cmp( $x, $p ) >= 0 ) {
			return null;
		}
		$alpha = DWADS_BigInt::mod(
			DWADS_BigInt::add(
				DWADS_BigInt::mulmod( $x, DWADS_BigInt::mulmod( $x, $x, $p ), $p ),
				'7'
			),
			$p
		);
		if ( '0' === $alpha ) {
			return null;
		}
		$exp  = DWADS_BigInt::divQ( DWADS_BigInt::add( $p, '1' ), '4' );
		$beta = DWADS_BigInt::modpow( $alpha, $exp, $p );
		if ( 0 !== DWADS_BigInt::cmp( DWADS_BigInt::mulmod( $beta, $beta, $p ), $alpha ) ) {
			return null;
		}
		$want_odd = ( '03' === $prefix );
		$is_odd   = ( 0 !== DWADS_BigInt::cmp( DWADS_BigInt::mod( $beta, '2' ), '0' ) );
		if ( $want_odd !== $is_odd ) {
			$beta = DWADS_BigInt::sub( $p, $beta );
		}
		return array(
			'x' => $x,
			'y' => $beta,
		);
	}

	/**
	 * Compress an affine point.
	 *
	 * @param array|null $point Point.
	 * @return string|null 33 byte hex or null.
	 */
	public static function compress( $point ) {
		if ( null === $point || ! isset( $point['x'], $point['y'] ) ) {
			return null;
		}
		$odd = ( 0 !== DWADS_BigInt::cmp( DWADS_BigInt::mod( $point['y'], '2' ), '0' ) );
		return ( $odd ? '03' : '02' ) . DWADS_BigInt::toHex( $point['x'], 32 );
	}

	/**
	 * Affine point doubling.
	 *
	 * @param array|null $a Point.
	 * @return array|null
	 */
	public static function doublePoint( $a ) {
		self::init();
		if ( null === $a ) {
			return null;
		}
		$p = self::$p;
		if ( '0' === DWADS_BigInt::normalize( $a['y'] ) ) {
			return null;
		}
		$num = DWADS_BigInt::mul( '3', DWADS_BigInt::mulmod( $a['x'], $a['x'], $p ) );
		$den = DWADS_BigInt::mul( '2', $a['y'] );
		$inv = DWADS_BigInt::modinv( $den, $p );
		if ( null === $inv ) {
			return null;
		}
		$lambda = DWADS_BigInt::mulmod( $num, $inv, $p );
		$x3     = DWADS_BigInt::mod(
			DWADS_BigInt::sub(
				DWADS_BigInt::mulmod( $lambda, $lambda, $p ),
				DWADS_BigInt::mul( '2', $a['x'] )
			),
			$p
		);
		$y3     = DWADS_BigInt::mod(
			DWADS_BigInt::sub(
				DWADS_BigInt::mulmod( $lambda, DWADS_BigInt::sub( $a['x'], $x3 ), $p ),
				$a['y']
			),
			$p
		);
		return array(
			'x' => $x3,
			'y' => $y3,
		);
	}

	/**
	 * Affine point addition.
	 *
	 * @param array|null $a First point.
	 * @param array|null $b Second point.
	 * @return array|null Null means the point at infinity.
	 */
	public static function addPoints( $a, $b ) {
		self::init();
		if ( null === $a ) {
			return $b;
		}
		if ( null === $b ) {
			return $a;
		}
		$p = self::$p;
		if ( 0 === DWADS_BigInt::cmp( $a['x'], $b['x'] ) ) {
			if ( 0 !== DWADS_BigInt::cmp( $a['y'], $b['y'] ) ) {
				return null;
			}
			return self::doublePoint( $a );
		}
		$num = DWADS_BigInt::sub( $b['y'], $a['y'] );
		$den = DWADS_BigInt::sub( $b['x'], $a['x'] );
		$inv = DWADS_BigInt::modinv( $den, $p );
		if ( null === $inv ) {
			return null;
		}
		$lambda = DWADS_BigInt::mulmod( $num, $inv, $p );
		$x3     = DWADS_BigInt::mod(
			DWADS_BigInt::sub(
				DWADS_BigInt::sub( DWADS_BigInt::mulmod( $lambda, $lambda, $p ), $a['x'] ),
				$b['x']
			),
			$p
		);
		$y3     = DWADS_BigInt::mod(
			DWADS_BigInt::sub(
				DWADS_BigInt::mulmod( $lambda, DWADS_BigInt::sub( $a['x'], $x3 ), $p ),
				$a['y']
			),
			$p
		);
		return array(
			'x' => $x3,
			'y' => $y3,
		);
	}

	/**
	 * Jacobian doubling (a = 0 curve).
	 *
	 * @param string $x X.
	 * @param string $y Y.
	 * @param string $z Z.
	 * @return array{0:string,1:string,2:string}
	 */
	private static function jacDouble( $x, $y, $z ) {
		$p = self::$p;
		if ( '0' === $z || '0' === DWADS_BigInt::normalize( $y ) ) {
			return array( '0', '0', '0' );
		}
		$y2 = DWADS_BigInt::mulmod( $y, $y, $p );
		$s  = DWADS_BigInt::mulmod( '4', DWADS_BigInt::mulmod( $x, $y2, $p ), $p );
		$m  = DWADS_BigInt::mulmod( '3', DWADS_BigInt::mulmod( $x, $x, $p ), $p );
		$x3 = DWADS_BigInt::mod(
			DWADS_BigInt::sub( DWADS_BigInt::mulmod( $m, $m, $p ), DWADS_BigInt::mul( '2', $s ) ),
			$p
		);
		$y4 = DWADS_BigInt::mulmod( $y2, $y2, $p );
		$y3 = DWADS_BigInt::mod(
			DWADS_BigInt::sub(
				DWADS_BigInt::mulmod( $m, DWADS_BigInt::sub( $s, $x3 ), $p ),
				DWADS_BigInt::mul( '8', $y4 )
			),
			$p
		);
		$z3 = DWADS_BigInt::mod( DWADS_BigInt::mul( '2', DWADS_BigInt::mulmod( $y, $z, $p ) ), $p );
		return array( $x3, $y3, $z3 );
	}

	/**
	 * Add an affine point to a Jacobian point.
	 *
	 * @param string $x1 Jacobian X.
	 * @param string $y1 Jacobian Y.
	 * @param string $z1 Jacobian Z.
	 * @param string $x2 Affine x.
	 * @param string $y2 Affine y.
	 * @return array{0:string,1:string,2:string}
	 */
	private static function jacAddAffine( $x1, $y1, $z1, $x2, $y2 ) {
		$p = self::$p;
		if ( '0' === $z1 ) {
			return array( $x2, $y2, '1' );
		}
		$z1z1 = DWADS_BigInt::mulmod( $z1, $z1, $p );
		$u2   = DWADS_BigInt::mulmod( $x2, $z1z1, $p );
		$s2   = DWADS_BigInt::mulmod( $y2, DWADS_BigInt::mulmod( $z1, $z1z1, $p ), $p );
		$h    = DWADS_BigInt::mod( DWADS_BigInt::sub( $u2, $x1 ), $p );
		$r    = DWADS_BigInt::mod( DWADS_BigInt::sub( $s2, $y1 ), $p );
		if ( '0' === $h ) {
			if ( '0' === $r ) {
				return self::jacDouble( $x1, $y1, $z1 );
			}
			return array( '0', '0', '0' );
		}
		$hh  = DWADS_BigInt::mulmod( $h, $h, $p );
		$hhh = DWADS_BigInt::mulmod( $h, $hh, $p );
		$v   = DWADS_BigInt::mulmod( $x1, $hh, $p );
		$x3  = DWADS_BigInt::mod(
			DWADS_BigInt::sub(
				DWADS_BigInt::sub( DWADS_BigInt::mulmod( $r, $r, $p ), $hhh ),
				DWADS_BigInt::mul( '2', $v )
			),
			$p
		);
		$y3  = DWADS_BigInt::mod(
			DWADS_BigInt::sub(
				DWADS_BigInt::mulmod( $r, DWADS_BigInt::sub( $v, $x3 ), $p ),
				DWADS_BigInt::mulmod( $y1, $hhh, $p )
			),
			$p
		);
		$z3  = DWADS_BigInt::mulmod( $z1, $h, $p );
		return array( $x3, $y3, $z3 );
	}

	/**
	 * Convert Jacobian coordinates back to affine.
	 *
	 * @param string $x X.
	 * @param string $y Y.
	 * @param string $z Z.
	 * @return array|null
	 */
	private static function jacToAffine( $x, $y, $z ) {
		if ( '0' === $z ) {
			return null;
		}
		$p     = self::$p;
		$zinv  = DWADS_BigInt::modinv( $z, $p );
		if ( null === $zinv ) {
			return null;
		}
		$zinv2 = DWADS_BigInt::mulmod( $zinv, $zinv, $p );
		return array(
			'x' => DWADS_BigInt::mulmod( $x, $zinv2, $p ),
			'y' => DWADS_BigInt::mulmod( $y, DWADS_BigInt::mulmod( $zinv2, $zinv, $p ), $p ),
		);
	}

	/**
	 * Scalar multiplication of the generator point.
	 *
	 * @param string $k_dec Scalar as decimal string.
	 * @return array|null
	 */
	public static function mulG( $k_dec ) {
		self::init();
		$k = DWADS_BigInt::normalize( $k_dec );
		if ( '0' === $k ) {
			return null;
		}
		$k = DWADS_BigInt::mod( $k, self::$n );
		if ( '0' === $k ) {
			return null;
		}
		$hex = DWADS_BigInt::toHex( $k, 32 );
		$x   = '0';
		$y   = '0';
		$z   = '0';
		$len = strlen( $hex );
		for ( $i = 0; $i < $len; $i++ ) {
			$nibble = hexdec( $hex[ $i ] );
			for ( $bit = 3; $bit >= 0; $bit-- ) {
				list( $x, $y, $z ) = self::jacDouble( $x, $y, $z );
				if ( ( $nibble >> $bit ) & 1 ) {
					list( $x, $y, $z ) = self::jacAddAffine( $x, $y, $z, self::$gx, self::$gy );
				}
			}
		}
		return self::jacToAffine( $x, $y, $z );
	}

	/**
	 * BIP32 public child key tweak: K + tweak*G.
	 *
	 * @param string $pub_hex   Compressed parent public key.
	 * @param string $tweak_dec Tweak as decimal string.
	 * @return array|WP_Error Affine point.
	 */
	public static function tweakAdd( $pub_hex, $tweak_dec ) {
		self::init();
		$tweak = DWADS_BigInt::normalize( $tweak_dec );
		if ( DWADS_BigInt::cmp( $tweak, self::$n ) >= 0 ) {
			return new WP_Error( 'dwads_tweak', __( 'Derived key material was out of range. Try the next address index.', 'dwads' ) );
		}
		$point = self::decompress( $pub_hex );
		if ( null === $point ) {
			return new WP_Error( 'dwads_pubkey', __( 'The extended public key is not valid.', 'dwads' ) );
		}
		$tweak_point = self::mulG( $tweak );
		if ( null === $tweak_point ) {
			return $point;
		}
		$sum = self::addPoints( $tweak_point, $point );
		if ( null === $sum ) {
			return new WP_Error( 'dwads_point', __( 'Derivation produced the point at infinity. Try the next address index.', 'dwads' ) );
		}
		return $sum;
	}
}
