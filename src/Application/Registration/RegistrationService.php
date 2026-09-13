<?php
namespace Peyvast\Auth\Application\Registration;

use Peyvast\Auth\Domain\Identity\IdentityResolver;
use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Application\Authentication\AuthenticationSessionService;
use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Persistence\DatabaseLock;
use Peyvast\Auth\Infrastructure\Persistence\OtpChallengeStore;
use Peyvast\Auth\Infrastructure\Persistence\PhoneIdentityIndex;

defined( 'ABSPATH' ) || exit;

final class RegistrationService {
	private $identities;
	private $session;
	private $challenges;

	public function __construct( ?IdentityResolver $identities = null, ?AuthenticationSessionService $session = null, ?OtpChallengeStore $challenges = null ) {
		$this->identities = $identities ?: new IdentityResolver();
		$this->session    = $session ?: new AuthenticationSessionService( $this->identities );
		$this->challenges = $challenges ?: new OtpChallengeStore();
	}

	public function execute( array $data, $verified_phone, string $verification_token = '' ) {
		if ( ! Settings::get( 'registration.enabled', true ) ) {
			return new \WP_Error( 'registration_disabled', __( 'User registration is currently disabled.', 'peyvast-auth' ) );
		}
		if ( ! PhoneNumber::valid_input( $verified_phone ) || $verification_token === '' ) {
			return new \WP_Error( 'invalid_token', __( 'The registration session is no longer valid. Please start again.', 'peyvast-auth' ) );
		}

		$canonical  = PhoneNumber::canonical_value( $verified_phone );
		$phone_lock = DatabaseLock::acquire( 'registration:phone:' . PhoneNumber::hash_value( $canonical ), 5 );
		if ( ! $phone_lock ) {
			return new \WP_Error( 'storage_busy', __( 'This request is already being processed. Please try again shortly.', 'peyvast-auth' ) );
		}

		$email_lock    = null;
		$username_lock = null;
		try {
			$resolution     = $this->resolve_phone_for_registration( $canonical );
			$identity_error = $this->identity_error( $resolution );
			if ( $identity_error ) {
				return $identity_error;
			}

			// An already-resolved account continues as a login.
			if ( $resolution->is_existing() ) {
				$row = $this->begin_phone_token( $verification_token, $canonical, (int) $resolution->user()->ID );
				if ( ! $row || is_wp_error( $row ) ) return self::invalid_token();
				if ( ! $this->challenges->finish_verified_token_processing( (int) $row->id ) ) {
					return new \WP_Error( 'storage_failed', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
				}
				$login = $this->session->login( $resolution->user(), false, 'registration' );
				if ( is_wp_error( $login ) ) return $login;
				return array( 'authenticated_user_id' => (int) $resolution->user()->ID );
			}

			$fields             = Settings::get( 'registration.fields', array() );
			$first_name_enabled = ! empty( $fields['first_name']['enabled'] );
			$last_name_enabled  = ! empty( $fields['last_name']['enabled'] );
			$first_name         = $first_name_enabled ? sanitize_text_field( $data['first_name'] ?? '' ) : '';
			$last_name          = $last_name_enabled ? sanitize_text_field( $data['last_name'] ?? '' ) : '';
			$email_enabled      = ! empty( $fields['email']['enabled'] );
			$email              = $email_enabled ? strtolower( sanitize_email( $data['email'] ?? '' ) ) : '';
			$password_enabled   = ! empty( $fields['password']['enabled'] );
			$password           = $password_enabled ? (string) ( $data['password'] ?? '' ) : '';

			if ( ! empty( $fields['first_name']['enabled'] ) && ! empty( $fields['first_name']['required'] ) && trim( $first_name ) === '' ) {
				return self::field_error( 'required_field', __( 'Please complete all required fields.', 'peyvast-auth' ), 'first_name' );
			}
			if ( ! empty( $fields['last_name']['enabled'] ) && ! empty( $fields['last_name']['required'] ) && trim( $last_name ) === '' ) {
				return self::field_error( 'required_field', __( 'Please complete all required fields.', 'peyvast-auth' ), 'last_name' );
			}
			if ( $email_enabled ) {
				if ( ! empty( $fields['email']['required'] ) && $email === '' ) {
					return self::field_error( 'required_field', __( 'Please complete all required fields.', 'peyvast-auth' ), 'email' );
				}
				if ( $email !== '' && ! is_email( $email ) ) {
					return self::field_error( 'invalid_email', __( 'Please enter a valid email address.', 'peyvast-auth' ), 'email' );
				}
			}
			if ( $password_enabled && ! empty( $fields['password']['required'] ) && $password === '' ) {
				return self::field_error( 'required_field', __( 'Please complete all required fields.', 'peyvast-auth' ), 'password' );
			}
			if ( $password_enabled && $password !== '' && strlen( $password ) < 8 ) {
				return self::field_error( 'weak_password', __( 'Please choose a password with at least 8 characters.', 'peyvast-auth' ), 'password' );
			}
			if ( $password === '' ) {
				$password = wp_generate_password( 24, true, true );
			}

			if ( $email_enabled && $email !== '' ) {
				$email_lock = DatabaseLock::acquire( 'registration:email:' . hash( 'sha256', $email ), 5 );
				if ( ! $email_lock ) {
					return new \WP_Error( 'storage_busy', __( 'This request is already being processed. Please try again shortly.', 'peyvast-auth' ) );
				}
				if ( email_exists( $email ) ) {
					return self::field_error( 'email_exists', __( 'This email ID is already registered.', 'peyvast-auth' ), 'email' );
				}
			}

			$final          = $this->resolve_phone_for_registration( $canonical );
			$identity_error = $this->identity_error( $final );
			if ( $identity_error ) {
				return $identity_error;
			}
			if ( $final->is_existing() ) {
				$row = $this->begin_phone_token( $verification_token, $canonical, (int) $final->user()->ID );
				if ( ! $row || is_wp_error( $row ) ) return self::invalid_token();
				if ( ! $this->challenges->finish_verified_token_processing( (int) $row->id ) ) {
					return new \WP_Error( 'storage_failed', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
				}
				$login = $this->session->login( $final->user(), false, 'registration' );
				if ( is_wp_error( $login ) ) return $login;
				return array( 'authenticated_user_id' => (int) $final->user()->ID );
			}

			$username_mode = (string) Settings::get( 'registration.username_generation', 'phone' );
			$username      = '';
			if ( $username_mode === 'random' ) {
				for ( $attempt = 0; $attempt < 10; $attempt++ ) {
					$candidate      = 'user_' . strtolower( wp_generate_password( 10, false, false ) );
					$candidate_lock = DatabaseLock::acquire( 'registration:username:' . hash( 'sha256', $candidate ), 5 );
					if ( ! $candidate_lock ) {
						return new \WP_Error( 'storage_busy', __( 'This request is already being processed. Please try again shortly.', 'peyvast-auth' ) );
					}
					if ( ! username_exists( $candidate ) ) {
						$username      = $candidate;
						$username_lock = $candidate_lock;
						break;
					}
					$candidate_lock->release();
				}
				if ( $username === '' ) {
					return new \WP_Error( 'username_exists', __( 'This username is already in use. Please try again.', 'peyvast-auth' ) );
				}
			} else {
				$username      = PhoneNumber::storage_value( $canonical, (string) Settings::get( 'registration.username_phone_format', 'country_code' ) );
				$username_lock = DatabaseLock::acquire( 'registration:username:' . hash( 'sha256', $username ), 5 );
				if ( ! $username_lock ) {
					return new \WP_Error( 'storage_busy', __( 'This request is already being processed. Please try again shortly.', 'peyvast-auth' ) );
				}
				if ( $username === '' || username_exists( $username ) ) {
					return new \WP_Error( 'identity_conflict', __( 'The configured phone username format is unavailable or already in use.', 'peyvast-auth' ) );
				}
			}

			// Re-resolve under all locks, immediately before claim/create.
			$final          = $this->resolve_phone_for_registration( $canonical );
			$identity_error = $this->identity_error( $final );
			if ( $identity_error ) {
				return $identity_error;
			}
			if ( $final->is_existing() ) {
				$row = $this->begin_phone_token( $verification_token, $canonical, (int) $final->user()->ID );
				if ( ! $row || is_wp_error( $row ) ) return self::invalid_token();
				if ( ! $this->challenges->finish_verified_token_processing( (int) $row->id ) ) {
					return new \WP_Error( 'storage_failed', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
				}
				$login = $this->session->login( $final->user(), false, 'registration' );
				if ( is_wp_error( $login ) ) return $login;
				return array( 'authenticated_user_id' => (int) $final->user()->ID );
			}
			if ( $email_enabled && $email !== '' && email_exists( $email ) ) {
				return self::field_error( 'email_exists', __( 'This email ID is already registered.', 'peyvast-auth' ), 'email' );
			}
			if ( username_exists( $username ) ) {
				return new \WP_Error( 'username_exists', __( 'This username is already in use. Please try again.', 'peyvast-auth' ) );
			}

			$claimed = $this->begin_phone_token( $verification_token, $canonical, 0 );
			if ( ! $claimed || is_wp_error( $claimed ) ) return self::invalid_token();
			// Token stays in processing until user, meta, index, WooCommerce sync and session all succeed.
			$claimed_token_id = (int) $claimed->id;

			$display_name = trim( $first_name . ' ' . $last_name );
			$user_id = wp_insert_user(
				array(
					'user_login'   => $username,
					'user_pass'    => $password,
					'user_email'   => $email,
					'display_name' => $display_name ?: $username,
					'first_name'   => $first_name,
					'last_name'    => $last_name,
					'nickname'     => $display_name ?: $username,
					'role'         => self::default_role(),
				)
			);
			if ( is_wp_error( $user_id ) ) {
				$this->challenges->release_verified_token_processing( (int) $claimed->id );
				return $user_id;
			}

			$stored = PhoneNumber::peyvast_value( $canonical );
			$hash   = PhoneNumber::hash_value( $canonical );
			$meta_ok_1 = update_user_meta( $user_id, '_peyvast_auth_phone', $stored );
			$meta_ok_2 = update_user_meta( $user_id, '_peyvast_auth_phone_canonical', $hash );
			$index_ok  = PhoneIdentityIndex::sync_primary( (int) $user_id );
			$actual_phone = (string) get_user_meta( $user_id, '_peyvast_auth_phone', true );
			$actual_hash  = (string) get_user_meta( $user_id, '_peyvast_auth_phone_canonical', true );
			if ( false === $meta_ok_1 || false === $meta_ok_2 || ! $index_ok || $actual_phone !== $stored || $actual_hash !== $hash || ! PhoneIdentityIndex::contains( (int) $user_id, PhoneIdentityIndex::SOURCE_PRIMARY, $canonical ) ) {
				$this->rollback_created_user( (int) $user_id, $claimed_token_id );
				return new \WP_Error( 'registration_failed', __( 'The account could not be created. Please try again shortly.', 'peyvast-auth' ) );
			}

			do_action( 'peyvast_auth_sync_woocommerce_phone', (int) $user_id, $canonical );
			$user = get_user_by( 'id', $user_id );
			if ( ! $user instanceof \WP_User ) {
				$this->rollback_created_user( (int) $user_id, $claimed_token_id );
				return new \WP_Error( 'registration_failed', __( 'The account could not be created. Please try again shortly.', 'peyvast-auth' ) );
			}
			$login = $this->session->login( $user, false, 'registration' );
			if ( is_wp_error( $login ) ) {
				// A policy veto after creation rolls back the committed account.
				$this->rollback_created_user( (int) $user_id, $claimed_token_id );
				return $login;
			}
			// Account and session exist: finalize last; failure here must not delete them.
			if ( ! $this->challenges->finish_verified_token_processing( $claimed_token_id ) ) {
				Logger::error( 'auth', 'registration_token_finalize_failed', 'Account created but the registration token could not be finalized; the challenge expires and is purged automatically.', array( 'recovery' => true, 'challenge_id' => substr( (string) $claimed->challenge_id, 0, 8 ) ), (int) $user_id );
			}
			Logger::log( 'info', 'auth', 'registration', 'Account registration completed.', array(), (int) $user_id );
			return (int) $user_id;
		} finally {
			if ( $username_lock ) {
				$username_lock->release();
			}
			if ( $email_lock ) {
				$email_lock->release();
			}
			$phone_lock->release();
		}
	}

	private static function delete_created_user( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		return function_exists( 'wp_delete_user' ) ? (bool) wp_delete_user( $user_id ) : false;
	}

	private static function default_role(): string {
		// The default role belongs to WordPress/WooCommerce.
		$default = sanitize_key( (string) get_option( 'default_role', 'subscriber' ) );
		return get_role( $default ) ? $default : 'subscriber';
	}

	private function resolve_phone_for_registration( string $canonical ) {
		$resolution = $this->identities->phone( $canonical );
		return $resolution->is_not_ready() ? $this->identities->phone_for_registration( $canonical ) : $resolution;
	}

	private function identity_error( $resolution ) {
		if ( $resolution->is_conflict() ) {
			return new \WP_Error( 'identity_conflict', __( 'The information provided could not be used to continue. Please contact the site administrator.', 'peyvast-auth' ) );
		}
		if ( $resolution->is_denied() ) {
			return new \WP_Error( 'identity_denied', __( 'The information provided could not be used to continue. Please try another sign-in method.', 'peyvast-auth' ) );
		}
		if ( $resolution->is_not_ready() ) {
			return new \WP_Error( 'identity_unavailable', __( 'Authentication is temporarily unavailable for this identifier. Please try again shortly.', 'peyvast-auth' ) );
		}
		return null;
	}

	private static function invalid_token() {
		return new \WP_Error( 'invalid_token', __( 'The registration session is no longer valid. Please start again.', 'peyvast-auth' ) );
	}

	private static function field_error( string $code, string $message, string $field ) {
		return new \WP_Error( $code, $message, array( 'field' => $field ) );
	}

	private function begin_phone_token( string $token, string $canonical, int $user_id ) {
		$key           = (string) get_option( 'peyvast_auth_identifier_key', wp_salt( 'auth' ) );
		$expected_hash = hash_hmac( 'sha256', 'phone:' . $canonical, $key );
		return $this->challenges->begin_verified_token_processing( sanitize_text_field( $token ), 'otp_login', 'phone', $expected_hash, $user_id );
	}

	private function rollback_created_user( int $user_id, int $token_id ): void {
		if ( $token_id > 0 ) $this->challenges->release_verified_token_processing( $token_id );
		if ( $user_id <= 0 ) return;
		if ( ! self::delete_created_user( $user_id ) ) {
			update_user_meta( $user_id, '_peyvast_auth_registration_recovery', array( 'state' => 'orphaned', 'created_at' => time() ) );
			Logger::error( 'auth', 'registration_orphaned_account', 'Registration cleanup failed; manual recovery is required.', array( 'recovery' => true ), $user_id, '', Logger::request_id() );
		}
	}
}
