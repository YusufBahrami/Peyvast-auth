<?php
namespace Peyvast\Auth\Presentation\Privacy;

defined( 'ABSPATH' ) || exit;

final class EmailMasker {
	public static function mask( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return '';
		}
		[$local, $domain] = explode( '@', $email, 2 );
		$length           = strlen( $local );
		if ( $length <= 1 ) {
			$masked = '***';
		} else {
			// Exactly three stars; at most five visible characters.
			$visible = min( 5, max( 1, intdiv( $length, 2 ) ) );
			$masked  = substr( $local, 0, $visible ) . '***';
		}
		return $masked . '@' . $domain;
	}
}
