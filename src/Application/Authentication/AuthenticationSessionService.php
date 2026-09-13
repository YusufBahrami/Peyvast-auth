<?php
namespace Peyvast\Auth\Application\Authentication;

use Peyvast\Auth\Domain\Identity\IdentityResolver;
use Peyvast\Auth\Domain\Identity\IdentityResolution;
use Peyvast\Auth\Infrastructure\Persistence\OtpChallengeStore;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Scheduling\Scheduler;
use Peyvast\Auth\Infrastructure\WordPress\GuestSession;
use Peyvast\Auth\Domain\Authentication\AuthenticationAccountPolicy;
use Peyvast\Auth\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class AuthenticationSessionService {
	private $identities;
	private $challenges;

	public function __construct( ?IdentityResolver $identities = null, ?OtpChallengeStore $challenges = null ) {
		$this->identities = $identities ?: new IdentityResolver();
		$this->challenges = $challenges ?: new OtpChallengeStore();
	}

	public function resolve( $identifier ) {
		return $this->identities->resolve( (string) $identifier ); }

	public function login( \WP_User $user, bool $remember = false, string $method = 'custom' ) {
		if ( ! AuthenticationAccountPolicy::can_login( $user, $method ) ) {
			return new \WP_Error( 'account_not_allowed', __( 'This account is not allowed to sign in at this time.', 'peyvast-auth' ) );
		}
		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		if ( class_exists( GuestSession::class ) ) GuestSession::rotate();
		// Lazy migration is auxiliary: queued per user so it can never delay the login.
		if ( Settings::get( 'migration.lazy_enabled', false ) ) {
			if ( Scheduler::schedule_async( Scheduler::HOOK_LAZY_PHONE_MIGRATION, array( (int) $user->ID ), true ) <= 0 ) {
				Logger::warning( 'migration', 'lazy_migration_schedule_failed', 'Lazy phone migration could not be queued; authentication was not blocked.', array(), (int) $user->ID );
			}
		}
		do_action( 'peyvast_auth_user_logged_in', $user );
		return true;
	}

	public function consume_otp_login( $token, bool $remember = false ) {
		$token = sanitize_text_field( (string) $token );
		$row   = $this->challenges->find_challenge_by_verified_token( $token, 'otp_login' );
		if ( ! $row ) return self::invalid_token();
		$identity_hash = (string) $row->identifier_hash;
		$processing = $this->challenges->begin_verified_token_processing( $token, 'otp_login', (string) $row->identifier_type, $identity_hash, (int) $row->user_id );
		if ( ! $processing || is_wp_error( $processing ) ) return self::invalid_token();
		$type = (string) $processing->identifier_type;
		$user = get_user_by( 'id', (int) $processing->user_id );
		if ( ! $user instanceof \WP_User ) {
			$this->challenges->release_verified_token_processing( (int) $processing->id );
			return self::invalid_token();
		}
		$resolution = IdentityResolution::existing( $user, $type );
		if ( ! $resolution->is_existing() ) {
			$this->challenges->release_verified_token_processing( (int) $processing->id );
			return self::invalid_token();
		}
		if ( ! $this->challenges->finish_verified_token_processing( (int) $processing->id ) ) {
			return new \WP_Error( 'storage_failed', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
		}
		$login = $this->login( $resolution->user(), $remember, 'otp' );
		if ( is_wp_error( $login ) ) {
			return $login;
		}
		return $resolution->user();
	}

	public function redirect( $origin = '' ) {
		$settings = Settings::get( 'redirect', array() );
		$mode     = (string) ( $settings['mode'] ?? 'origin' );
		if ( $mode === 'disable' ) {
			return '';
		}
		if ( $mode === 'page' && ! empty( $settings['page_id'] ) ) {
			$url = get_permalink( (int) $settings['page_id'] );
			if ( $url ) {
				return esc_url_raw( $url );
			}
		}
		if ( $mode === 'custom' && ! empty( $settings['custom_url'] ) ) {
			$url = wp_validate_redirect( (string) $settings['custom_url'], '' );
			if ( $url ) {
				return esc_url_raw( $url );
			}
		}
		if ( $mode === 'origin' && $origin ) {
			$url = wp_validate_redirect( (string) $origin, '' );
			if ( $url ) {
				$host = wp_parse_url( $url, PHP_URL_HOST );
				$site = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
				if ( $host && $site && strtolower( (string) $host ) === strtolower( (string) $site ) ) {
					return esc_url_raw( $url );
				}
			}
		}
		$fallback = (string) ( $settings['fallback_mode'] ?? 'home' );
		if ( $fallback === 'disable' ) {
			return '';
		}
		if ( $fallback === 'page' && ! empty( $settings['fallback_page_id'] ) ) {
			$url = get_permalink( (int) $settings['fallback_page_id'] );
			if ( $url ) {
				return esc_url_raw( $url );
			}
		}
		if ( $fallback === 'custom' && ! empty( $settings['fallback_custom_url'] ) ) {
			$url = wp_validate_redirect( (string) $settings['fallback_custom_url'], '' );
			if ( $url ) {
				return esc_url_raw( $url );
			}
		}
		return home_url( '/' );
	}

	private static function invalid_token() {
		return new \WP_Error( 'invalid_token', __( 'The authentication session is no longer valid. Please try again.', 'peyvast-auth' ) );
	}
}
