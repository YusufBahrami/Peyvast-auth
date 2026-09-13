<?php
namespace Peyvast\Auth\Application\Password;

use Peyvast\Auth\Domain\Identity\IdentityResolver;
use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Security\Guard;
use Peyvast\Auth\Application\Authentication\AuthenticationSessionService;
use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Persistence\DatabaseLock;
use Peyvast\Auth\Infrastructure\Persistence\OtpChallengeStore;
use Peyvast\Auth\Domain\Authentication\AuthenticationAccountPolicy;

defined( 'ABSPATH' ) || exit;

final class PasswordService {
	private $identities;
	private $session;
	private $challenges;

	public function __construct( ?IdentityResolver $identities = null, ?AuthenticationSessionService $session = null, ?OtpChallengeStore $challenges = null ) {
		$this->identities = $identities ?: new IdentityResolver();
		$this->session    = $session ?: new AuthenticationSessionService( $this->identities );
		$this->challenges = $challenges ?: new OtpChallengeStore();
	}

	public function login( $identifier, $password, bool $remember = false ) {
		$identifier    = trim( (string) $identifier );
		$settings      = Settings::all();
		$is_email      = strpos( $identifier, '@' ) !== false;
		$is_phone      = ! $is_email && PhoneNumber::valid_input( $identifier );
		$is_user_login = ! $is_email && ! $is_phone && $identifier !== '';
		if ( empty( $settings['authentication']['password_login']['enabled'] ) ) return self::invalid_credentials();
		if ( $is_email && empty( $settings['authentication']['password_login']['email'] ) ) return self::invalid_credentials();
		if ( $is_phone && empty( $settings['authentication']['password_login']['phone'] ) ) return self::invalid_credentials();
		if ( ! $is_email && ! $is_phone && empty( $settings['authentication']['password_login']['user_login'] ) ) return self::invalid_credentials();
		if ( $is_email && ! is_email( sanitize_email( $identifier ) ) ) return self::invalid_credentials();
		if ( ! $is_email && ! $is_phone ) {
			$identifier = sanitize_user( $identifier, true );
			if ( $identifier === '' || strlen( $identifier ) > 60 ) return self::invalid_credentials();
		}
		if ( ! Guard::precheck( 'password_login', array( 'identifier' => $identifier ) ) ) return self::invalid_credentials();
		$resolution = $is_email ? $this->identities->email( $identifier ) : ( $is_phone ? $this->identities->phone( $identifier ) : $this->identities->user_login( $identifier ) );
		$user       = $resolution->is_existing() ? $resolution->user() : null;
		if ( ! $user || ! wp_check_password( (string) $password, $user->user_pass ) ) {
			if ( ! Guard::record_failure( 'password_login', array( 'identifier' => $identifier ) ) ) return new \WP_Error( 'security_unavailable', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
			Logger::notice( 'auth', 'password_login_failed', 'Failed login.', array( 'identifier_type' => $is_email ? 'email' : ( $is_phone ? 'phone' : 'user_login' ) ), $user ? (int) $user->ID : 0 );
			return self::invalid_credentials();
		}
		// Keep WordPress's post-password boundary so plugins can veto the login.
		$auth_user = apply_filters( 'wp_authenticate_user', $user, (string) $password );
		if ( is_wp_error( $auth_user ) ) {
			if ( ! Guard::record_failure( 'password_login', array( 'identifier' => $identifier ) ) ) {
				return new \WP_Error( 'security_unavailable', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
			}
			return self::invalid_credentials();
		}
		$user = $auth_user instanceof \WP_User ? $auth_user : $user;
		// Run the core 'authenticate' chain seeded with the verified user so SSO/2FA/audit
		// plugins participate; core handlers short-circuit on a WP_User seed, so the check holds.
		$auth_user = apply_filters( 'authenticate', $user, $identifier, (string) $password );
		if ( is_wp_error( $auth_user ) ) {
			if ( ! Guard::record_failure( 'password_login', array( 'identifier' => $identifier ) ) ) {
				return new \WP_Error( 'security_unavailable', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
			}
			return self::invalid_credentials();
		}
		$user = $auth_user instanceof \WP_User ? $auth_user : $user;
		if ( ! AuthenticationAccountPolicy::can_login( $user, 'password' ) ) {
			return self::invalid_credentials();
		}
		// Rehash like core when the algorithm or cost changed.
		if ( wp_password_needs_rehash( (string) $user->user_pass, (int) $user->ID ) ) {
			wp_set_password( (string) $password, (int) $user->ID );
			$user = get_user_by( 'id', (int) $user->ID ) ?: $user;
		}
		Guard::record_success( 'password_login', Guard::snapshot( 'password_login', array( 'identifier' => $identifier ) ) );
		$session_result = $this->session->login( $user, $remember, 'password' );
		if ( is_wp_error( $session_result ) ) {
			return self::invalid_credentials();
		}
		Logger::log( 'info', 'auth', 'password_login', 'Password sign-in completed.', array(), (int) $user->ID );
		return true;
	}

	public function reset( \WP_User $user, $password, string $verification_token = '' ) {
		if ( strlen( (string) $password ) < 8 ) return new \WP_Error( 'weak_password', __( 'Please choose a password with at least 8 characters.', 'peyvast-auth' ) );
		$lock = DatabaseLock::acquire( 'password-reset:user:' . (int) $user->ID, 5 );
		if ( ! $lock ) return new \WP_Error( 'storage_busy', __( 'This request is already being processed. Please try again shortly.', 'peyvast-auth' ) );
		$processing = null;
		try {
			if ( $verification_token !== '' ) {
				$processing = $this->challenges->begin_verified_token_processing( sanitize_text_field( $verification_token ), 'password_reset', '','', (int) $user->ID );
				if ( is_wp_error( $processing ) ) return $processing;
				if ( ! $processing || (int) $processing->user_id !== (int) $user->ID ) return new \WP_Error( 'invalid_token', __( 'The password reset session is no longer valid. Please start again.', 'peyvast-auth' ) );
			}

			// Token stays in processing until the password is persisted, verified and sessions revoked.
			$result = wp_update_user(
				array(
					'ID'       => (int) $user->ID,
					'user_pass' => (string) $password,
				)
			);
			if ( is_wp_error( $result ) ) {
				$this->release_processing( $processing );
				return new \WP_Error( 'storage_failed', __( 'The password could not be saved. Please try again shortly.', 'peyvast-auth' ) );
			}
			$updated_user = get_user_by( 'id', (int) $user->ID );
			if ( ! $updated_user instanceof \WP_User || ! wp_check_password( (string) $password, (string) $updated_user->user_pass, (int) $updated_user->ID ) ) {
				$this->release_processing( $processing );
				return new \WP_Error( 'storage_failed', __( 'The password could not be saved. Please try again shortly.', 'peyvast-auth' ) );
			}
			// Revoke every previously issued WordPress session.
			\WP_Session_Tokens::get_instance( (int) $user->ID )->destroy_all();
			if ( is_user_logged_in() && get_current_user_id() === (int) $user->ID ) {
				wp_clear_auth_cookie();
				wp_set_current_user( 0 );
			}
			// Finalize only after the update fully succeeded.
			if ( $processing ) {
				if ( ! $this->challenges->finish_verified_token_processing( (int) $processing->id ) ) {
					Logger::error( 'auth', 'reset_token_finalize_failed', 'Password updated but the reset token could not be finalized; the challenge expires and is purged automatically.', array( 'recovery' => true ), (int) $user->ID );
				}
				$processing = null;
			}
			Logger::log( 'info', 'auth', 'password_reset', 'Password reset completed.', array(), (int) $user->ID );
			return true;
		} finally { $lock->release(); }
	}

	private function release_processing( $processing ): void {
		if ( $processing && (int) $processing->id > 0 ) {
			$this->challenges->release_verified_token_processing( (int) $processing->id );
		}
	}

	private static function invalid_credentials() { return new \WP_Error( 'invalid_credentials', __( 'username or password is not valid.', 'peyvast-auth' ) ); }
}
