<?php
namespace Peyvast\Auth\Domain\Phone;

defined( 'ABSPATH' ) || exit;

/** Immutable mobile number value object; the single normalization boundary. */
final class PhoneNumber {
	private string $canonical;
	/** @var array<string,self|null> Request-local normalization cache. */
	private static array $parse_cache = array();

	private function __construct( string $canonical ) {
		$this->canonical = $canonical; }

	public static function from_input( $input ): ?self {
		$value = self::normalized_text( $input );
		if ( $value === null ) {
			return null;
		}
		if ( array_key_exists( $value, self::$parse_cache ) ) {
			return self::$parse_cache[ $value ];
		}
		$result = self::parse_uncached( $value );
		if ( count( self::$parse_cache ) >= 256 ) {
			array_shift( self::$parse_cache );
		}
		self::$parse_cache[ $value ] = $result;
		return $result;
	}

	private static function parse_uncached( string $value ): ?self {
		$digits = self::digits_for_identity( $value );
		if ( preg_match( '/[^0-9+().\-\s\x{00A0}]/u', $digits ) === 1 ) {
			return null;
		}
		if ( substr_count( $digits, '+' ) > 1 || ( strpos( $digits, '+' ) !== false && strpos( $digits, '+' ) !== 0 ) ) {
			return null;
		}
		if ( strpos( $digits, '+' ) === 0 ) {
			$digits = substr( $digits, 1 );
		}
		$digits = preg_replace( '/[().\-\s\x{00A0}]+/u', '', $digits ) ?: '';
		if ( $digits === '' || ! ctype_digit( $digits ) ) {
			return null;
		}

		if ( strpos( $digits, '0098' ) === 0 ) {
			$digits   = substr( $digits, 2 );
			$national = substr( $digits, 2 );
		} elseif ( strpos( $digits, '98' ) === 0 ) {
			$national = substr( $digits, 2 );
		} elseif ( strpos( $digits, '0' ) === 0 ) {
			$national = substr( $digits, 1 );
		} elseif ( preg_match( '/^9\\d{9}$/', $digits ) === 1 ) {
			$national = $digits;
		} else {
			return null;
		}
		$countries = self::countries();
		foreach ( $countries as $country ) {
			$code    = preg_quote( (string) ( $country['code'] ?? '' ), '/' );
			$pattern = self::numbering_pattern( (string) ( $country['mobile_pattern'] ?? '' ) );
			if ( $code === '' || $pattern === '' ) {
				continue;
			}
			// Canonical value is always country code + national digits, no leading '+'.
			if ( preg_match( '/^' . $code . '(' . $pattern . ')$/', $digits, $matches ) === 1 ) {
				return new self( (string) $country['code'] . (string) $matches[1] );
			}
		}
		// National forms resolve against the configured default country plan.
		$configured = (string) \Peyvast\Auth\Core\Config\Settings::get( 'phone.country', 'ir' );
		$default    = $countries[ $configured ] ?? ( $countries['ir'] ?? ( $countries ? current( $countries ) : array() ) );
		$pattern    = self::numbering_pattern( (string) ( $default['mobile_pattern'] ?? '9\\d{9}' ) );
		if ( preg_match( '/^(' . $pattern . ')$/', $national, $matches ) !== 1 ) {
			return null;
		}
		return new self( (string) ( $default['code'] ?? '98' ) . (string) $matches[1] );
	}

	public static function normalize( $input ): string {
		$phone = self::from_input( $input );
		return $phone ? $phone->canonical() : ''; }
	public static function is_valid( $input ): bool {
		return self::from_input( $input ) instanceof self; }
	public static function canonical_value( $input ): string {
		return self::normalize( $input ); }
	public static function valid_input( $input ): bool {
		return self::is_valid( $input ); }
	public static function storage_value( $input, ?string $format = null ): string {
		$phone = self::from_input( $input );
		return $phone ? $phone->storage( $format ?: 'leading_zero' ) : ''; }
	/** Preserve a validated input representation for presentation; digits only. */
	public static function display_value( $input ): string {
		$value = self::normalized_text( $input );
		if ( $value === null ) return '';
		return self::presentation_digits( $value );
	}
	/** Stable international database representation without a leading plus sign. */
	public static function peyvast_value( $input ): string {
		$phone = self::from_input( $input );
		return $phone ? $phone->country_code() : ''; }
	public static function hash_value( $input ): string {
		$phone = self::from_input( $input );
		return $phone ? $phone->hash() : ''; }
	public static function lookup_values( $input ): array {
		$phone = self::from_input( $input );
		if ( ! $phone ) {
			return array();
		}
		$canonical = $phone->canonical();
		return array_values( array_unique( array(
			$canonical,
			$phone->local(),
			$phone->raw(),
			'+' . $canonical,
			'00' . $canonical,
		) ) );
	}
	public static function mask_value( $input ): string {
		$phone = self::from_input( $input );
		return $phone ? $phone->mask() : (string) $input; }

	/** Numbering plans are data: add countries with code and mobile pattern. */
	public static function countries(): array {
		static $countries = null;
		if ( $countries !== null ) {
			return $countries;
		}
		$countries = array(
			'ir' => array(
				'code'           => '98',
				'dial'           => '+98',
				'label'          => __( 'Iran', 'peyvast-auth' ),
				'mobile_pattern' => '9\\d{9}',
			),
		);
		return $countries;
	}
	public function canonical(): string {
		return $this->canonical; }
	public function country_code(): string {
		return $this->canonical; }
	public function e164(): string {
		return '+' . $this->canonical; }
	public function local(): string {
		return '0' . substr( $this->canonical, 2 ); }
	public function raw(): string {
		return substr( $this->canonical, 2 ); }
	public function storage( string $format = 'leading_zero' ): string {
		switch ( $format ) {
			case 'country_code':
				return $this->country_code();
			case 'raw':
				return $this->raw();
			default:
				return $this->local();
		}
	}
	public function hash(): string {
		$key = (string) get_option( 'peyvast_auth_identifier_key', '' );
		if ( $key === '' ) {
			$key = wp_salt( 'auth' );
		}
		return hash_hmac( 'sha256', $this->canonical, $key );
	}
	public function mask(): string {
		return substr( $this->local(), 0, 4 ) . '••••' . substr( $this->local(), -3 ); }

	private static function numbering_pattern( string $pattern ): string {
		// Country data holds only the numbering-plan body; anchors belong to the parser.
		$pattern = trim( $pattern );
		$pattern = preg_replace( '/^\^/', '', $pattern ) ?? $pattern;
		$pattern = preg_replace( '/\$$/', '', $pattern ) ?? $pattern;
		return $pattern;
	}

	private static function normalized_text( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		return $value === '' ? null : $value;
	}
	private static function presentation_digits( string $value ): string {
		// Presentation and identity parsing must agree on script conversion.
		return strtr( $value, self::digit_map() );
	}

	private static function digits_for_identity( string $value ): string {
		// Persian/Arabic numerals are always accepted.
		return strtr( $value, self::digit_map() );
	}


	private static function digit_map(): array {
		static $map = null;
		if ( $map !== null ) {
			return $map;
		}
		$map = array(
			'۰' => '0',
			'۱' => '1',
			'۲' => '2',
			'۳' => '3',
			'۴' => '4',
			'۵' => '5',
			'۶' => '6',
			'۷' => '7',
			'۸' => '8',
			'۹' => '9',
			'٠' => '0',
			'١' => '1',
			'٢' => '2',
			'٣' => '3',
			'٤' => '4',
			'٥' => '5',
			'٦' => '6',
			'٧' => '7',
			'٨' => '8',
			'٩' => '9',
		);
		return $map;
	}
}
