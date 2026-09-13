<?php
namespace Peyvast\Auth\Domain\Identity;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Infrastructure\Persistence\PhoneIdentityIndex;

defined( 'ABSPATH' ) || exit;

final class IdentityResolver {
	public function phone( $input ) {
		$phone = PhoneNumber::from_input( $input );
		if ( ! $phone ) {
			return IdentityResolution::invalid( 'phone' );
		}
		$canonical         = $phone->canonical();
		$hash              = $phone->hash();
		$source            = Settings::phone_source();
		$sources           = array( PhoneIdentityIndex::SOURCE_PRIMARY );
		$legacy_configured = ( $source['type'] ?? '' ) === 'user_meta' && ! empty( $source['key'] );
		$legacy_ready      = ! $legacy_configured || PhoneIdentityIndex::legacy_ready();
		if ( $legacy_configured && ! $legacy_ready ) {
			return IdentityResolution::not_ready( 'phone', $canonical );
		}
		if ( $legacy_ready && $legacy_configured ) {
			$sources[] = PhoneIdentityIndex::SOURCE_LEGACY;
		}
		$primary_ready = PhoneIdentityIndex::primary_ready();
		if ( ! $primary_ready ) {
			// A phone-shaped username is a bounded lookup; never scan the table during a rebuild.
			$candidate_ids = array();
			foreach ( $this->login_formats( $phone ) as $login ) {
				$user = get_user_by( 'login', $login );
				if ( $user instanceof \WP_User ) {
					$candidate_ids[] = (int) $user->ID;
				}
			}
			$candidate_ids = array_values( array_unique( array_map( 'intval', $candidate_ids ) ) );
			if ( ! $candidate_ids ) {
				return IdentityResolution::not_ready( 'phone', $canonical );
			}
		} else {
			$candidate_ids = PhoneIdentityIndex::candidate_user_ids( $hash, $sources );
			if ( count( $candidate_ids ) > PhoneIdentityIndex::MAX_CANDIDATES ) {
				return IdentityResolution::conflict( 'phone', $canonical, array() );
			}
		}
		foreach ( $this->login_formats( $phone ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof \WP_User ) {
				$candidate_ids[] = (int) $user->ID;
			}
		}
		$candidate_ids = array_values( array_unique( array_map( 'intval', $candidate_ids ) ) );
		// A missing entry is a valid new-account case; only an unavailable index blocks resolution.
		if ( ! $candidate_ids && ! $primary_ready ) {
			return IdentityResolution::not_ready( 'phone', $canonical );
		}
		if ( ! $candidate_ids ) {
			return IdentityResolution::not_found( 'phone', $canonical );
		}
		$owners = array();
		$denied = array();
		foreach ( $candidate_ids as $user_id ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$decision = $this->phone_candidate(
				$user,
				$phone,
				$legacy_ready ? $source : array(
					'type' => 'user_login',
					'key'  => '',
				)
			);
			if ( $decision['owner'] ) {
				$owners[ $user_id ] = array(
					'user'    => $user,
					'sources' => $decision['sources'],
				);
			} elseif ( $decision['denied'] ) {
				$denied[ $user_id ] = $decision['sources'];
			}
		}
		if ( count( $owners ) > 1 ) {
			return IdentityResolution::conflict(
				'phone',
				$canonical,
				array_values(
					array_map(
						static function ( $owner ) {
							return $owner['sources'];
						},
						$owners
					)
				)
			);
		}
		if ( count( $owners ) === 1 && ! $denied ) {
			$owner = array_values( $owners )[0];
			return IdentityResolution::existing( $owner['user'], 'phone', $canonical, $owner['sources'] ); }
		if ( count( $owners ) === 1 && $denied ) {
			$owner_sources = array_values( $owners )[0]['sources'];
			return IdentityResolution::conflict( 'phone', $canonical, array_values( array_merge( array( $owner_sources ), $denied ) ) ); }
		if ( $denied ) {
			return IdentityResolution::denied( 'phone', $canonical, array_values( $denied ) );
		}
		return IdentityResolution::not_found( 'phone', $canonical );
	}


	/** Authoritative phone resolution for registration; independent of index rebuild state. */
	public function phone_for_registration( $input ) {
		$phone = PhoneNumber::from_input( $input );
		if ( ! $phone ) {
			return IdentityResolution::invalid( 'phone' );
		}
		$canonical = $phone->canonical();
		$source    = Settings::phone_source();
		if ( ! PhoneIdentityIndex::primary_ready() ) {
			return IdentityResolution::not_ready( 'phone', $canonical );
		}
		$legacy_configured = ( $source['type'] ?? '' ) === 'user_meta' && ! empty( $source['key'] );
		if ( $legacy_configured && ! PhoneIdentityIndex::legacy_ready() ) {
			return IdentityResolution::not_ready( 'phone', $canonical );
		}
		$sources = array( PhoneIdentityIndex::SOURCE_PRIMARY );
		if ( $legacy_configured ) {
			$sources[] = PhoneIdentityIndex::SOURCE_LEGACY;
		}
		$ids = PhoneIdentityIndex::candidate_user_ids( $phone->hash(), $sources );
		if ( count( $ids ) > PhoneIdentityIndex::MAX_CANDIDATES ) {
			return IdentityResolution::conflict( 'phone', $canonical, array() );
		}
		foreach ( $this->login_formats( $phone ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof \WP_User ) {
				$ids[] = (int) $user->ID;
			}
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return IdentityResolution::not_found( 'phone', $canonical );
		}

		$owners = array();
		$denied = array();
		foreach ( $ids as $user_id ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$decision = $this->phone_candidate( $user, $phone, $source );
			if ( $decision['owner'] ) {
				$owners[ $user_id ] = array( 'user' => $user, 'sources' => $decision['sources'] );
			} elseif ( $decision['denied'] ) {
				$denied[ $user_id ] = $decision['sources'];
			}
		}
		if ( count( $owners ) > 1 || ( count( $owners ) === 1 && $denied ) ) {
			$sources = array_values( array_map( static function ( $owner ) { return $owner['sources']; }, $owners ) );
			$sources = array_merge( $sources, array_values( $denied ) );
			return IdentityResolution::conflict( 'phone', $canonical, $sources );
		}
		if ( count( $owners ) === 1 ) {
			$owner = array_values( $owners )[0];
			return IdentityResolution::existing( $owner['user'], 'phone', $canonical, $owner['sources'] );
		}
		if ( $denied ) {
			return IdentityResolution::denied( 'phone', $canonical, array_values( $denied ) );
		}
		return IdentityResolution::not_found( 'phone', $canonical );
	}


	public function email( $input ) {
		global $wpdb;
		$email = strtolower( sanitize_email( (string) $input ) );
		if ( $email === '' || ! is_email( $email ) ) {
			return IdentityResolution::invalid( 'email' );
		}
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM ' . $wpdb->users . ' WHERE user_email=%s ORDER BY ID ASC LIMIT 2', $email ) );
		if ( count( $ids ) > 1 ) {
			return IdentityResolution::conflict( 'email', $email, array_fill( 0, count( $ids ), array( 'email' ) ) );
		}
		if ( ! $ids ) {
			return IdentityResolution::not_found( 'email', $email );
		}
		$user = get_user_by( 'id', (int) $ids[0] );
		return $user instanceof \WP_User ? IdentityResolution::existing( $user, 'email', $email, array( 'email' ) ) : IdentityResolution::not_found( 'email', $email );
	}

	public function user_login( $input ) {
		$login = sanitize_user( (string) $input, true );
		if ( $login === '' ) {
			return IdentityResolution::invalid( 'user_login' );
		}
		$user = get_user_by( 'login', $login );
		return $user instanceof \WP_User ? IdentityResolution::existing( $user, 'user_login', $login, array( 'user_login' ) ) : IdentityResolution::not_found( 'user_login', $login );
	}

	public function resolve( $input ) {
		$input = trim( (string) $input );
		if ( strpos( $input, '@' ) !== false ) {
			return $this->email( $input );
		} return $this->phone( $input ); }

	private function login_formats( PhoneNumber $phone ): array {
		// Single authoritative lookup-format contract lives on the PhoneNumber value object.
		return PhoneNumber::lookup_values( $phone->canonical() ); }

	private function phone_candidate( \WP_User $user, PhoneNumber $requested, array $source ): array {
		$requested_canonical = $requested->canonical();
		$primary             = PhoneNumber::canonical_value( (string) get_user_meta( $user->ID, '_peyvast_auth_phone', true ) );
		$legacy              = '';
		if ( ( $source['type'] ?? '' ) === 'user_meta' && ! empty( $source['key'] ) ) {
			$legacy = PhoneNumber::canonical_value( (string) get_user_meta( $user->ID, (string) $source['key'], true ) );
		}
		$login_phone = PhoneNumber::from_input( (string) $user->user_login );
		$login       = $login_phone ? $login_phone->canonical() : '';
		$matches     = array();
		if ( $primary !== '' && hash_equals( $primary, $requested_canonical ) ) {
			$matches[] = 'primary';
		}
		if ( $legacy !== '' && hash_equals( $legacy, $requested_canonical ) ) {
			$matches[] = 'legacy_meta';
		}
		if ( $login !== '' && hash_equals( $login, $requested_canonical ) ) {
			$matches[] = 'user_login';
		}
		if ( $primary !== '' ) {
			if ( $matches && in_array( 'primary', $matches, true ) ) {
				return array(
					'owner'   => true,
					'denied'  => false,
					'sources' => $matches,
				);
			}
			if ( $matches ) {
				return array(
					'owner'   => false,
					'denied'  => true,
					'sources' => $matches,
				);
			}
			return array(
				'owner'   => false,
				'denied'  => false,
				'sources' => array(),
			);
		}
		if ( $legacy !== '' ) {
			if ( in_array( 'legacy_meta', $matches, true ) ) {
				return array(
					'owner'   => true,
					'denied'  => false,
					'sources' => $matches,
				);
			}
			if ( in_array( 'user_login', $matches, true ) ) {
				return array(
					'owner'   => false,
					'denied'  => true,
					'sources' => array( 'legacy_meta', 'user_login' ),
				);
			}
			return array(
				'owner'   => false,
				'denied'  => false,
				'sources' => array(),
			);
		}
		return in_array( 'user_login', $matches, true ) ? array(
			'owner'   => true,
			'denied'  => false,
			'sources' => array( 'user_login' ),
		) : array(
			'owner'   => false,
			'denied'  => false,
			'sources' => array(),
		);
	}
}
