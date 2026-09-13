<?php
namespace Peyvast\Auth\Application\OTP;

use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Identity\IdentityResolver;
use Peyvast\Auth\Domain\OTP\OtpPolicy;
use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Persistence\DatabaseLock;
use Peyvast\Auth\Infrastructure\Persistence\OtpChallengeStore;
use Peyvast\Auth\Infrastructure\Persistence\OtpDeliveryStore;
use Peyvast\Auth\Infrastructure\Scheduling\OtpDeliveryHandler;
use Peyvast\Auth\Infrastructure\Scheduling\Scheduler;
use Peyvast\Auth\Domain\Security\Guard;
use Peyvast\Auth\Domain\Authentication\AuthenticationCapabilities;
use Peyvast\Auth\Presentation\Privacy\InformationPresentation;
use Peyvast\Auth\Infrastructure\WordPress\GuestSession;

defined( 'ABSPATH' ) || exit;

final class OtpService {
	private IdentityResolver $identities;
	private OtpChallengeStore $challenges;
	private OtpPolicy $policy;

	public function __construct( ?IdentityResolver $identities = null, ?OtpChallengeStore $challenges = null, ?OtpPolicy $policy = null ) {
		$this->identities = $identities ?? new IdentityResolver();
		$this->challenges = $challenges ?? new OtpChallengeStore();
		$this->policy     = $policy ?? new OtpPolicy();
	}

	public function send( string $identifier, string $purpose = 'otp_login', bool $force_resend = false ) {
		$input_identifier = trim( (string) $identifier );
		if ( ! in_array( $purpose, array( 'otp_login', 'password_reset' ), true ) ) {
			return new \WP_Error( 'invalid_purpose', __( 'This authentication request is not available.', 'peyvast-auth' ) );
		}
		[$type, $normalized] = $this->parse_identifier( $input_identifier, $purpose === 'otp_login' );
		if ( $type === '' ) {
			return new \WP_Error( 'invalid_identifier', __( 'Please enter a valid mobile number or email address.', 'peyvast-auth' ) );
		}
		$guard = Guard::evaluate( $purpose === 'password_reset' ? Guard::SCOPE_PASSWORD_RESET : Guard::SCOPE_OTP_SEND, array( 'identifier' => $normalized ) );
		if ( ! $guard['allowed'] ) {
			return new \WP_Error( $guard['code'] === 'security_unavailable' ? 'security_unavailable' : ( $guard['blocked'] ? 'security_blocked' : 'send_rate_limited' ), __( 'The number of requests exceeds the allowed limit.', 'peyvast-auth' ), $guard );
		}
		if ( $purpose === 'password_reset' && ! in_array( $type, array( 'phone', 'email' ), true ) ) {
			return new \WP_Error( 'invalid_identifier', __( 'Unable to process the password reset request.', 'peyvast-auth' ) );
		}
		$resolution = $type === 'email' ? $this->identities->email( $normalized ) : ( $type === 'user_login' ? $this->identities->user_login( $normalized ) : $this->identities->phone( $normalized ) );
		if ( $resolution->is_conflict() && $purpose !== 'password_reset' ) return new \WP_Error( 'identity_conflict', __( 'The information provided could not be used to continue. Please contact the site administrator.', 'peyvast-auth' ) );
		if ( $resolution->is_denied() && $purpose !== 'password_reset' ) return new \WP_Error( 'identity_denied', __( 'The information provided could not be used to continue. Please try another sign-in method.', 'peyvast-auth' ) );
		$user         = $resolution->is_existing() ? $resolution->user() : null;
		// Unknown reset identifiers must not be distinguishable from existing accounts.
		$unknown_reset = $purpose === 'password_reset' && ! $user instanceof \WP_User;
		$settings     = Settings::all();
		$capabilities = AuthenticationCapabilities::resolve( $settings );
		$allowed      = $capabilities['available_identifiers'][ $purpose === 'password_reset' ? 'password_reset' : 'otp_login' ];
		if ( ! in_array( $type, $allowed, true ) || ! $this->method_allowed( $purpose, $type, $settings ) ) return new \WP_Error( 'invalid_identifier', __( 'The requested authentication method is not available.', 'peyvast-auth' ) );
		if ( $purpose === 'otp_login' && ! $user && ! Settings::get( 'registration.enabled', true ) ) return new \WP_Error( 'registration_disabled', __( 'User registration is currently disabled.', 'peyvast-auth' ) );
		$neutral_email_request = $type === 'email' && ! $user && $capabilities['email_otp_enabled'];
		$hash = $this->identifier_hash( $type, $normalized );
		// Unknown identifiers get a real-shaped response without creating or sending anything.
		if ( $neutral_email_request || $unknown_reset ) {
			return $this->neutral_response( $type, $normalized, $input_identifier, $purpose );
		}
		$lock = DatabaseLock::acquire( 'otp-send:' . $purpose . ':' . $hash, 5 );
		if ( ! $lock ) return $purpose === 'password_reset' ? new \WP_Error( 'reset_unavailable', __( 'Unable to process the password reset request.', 'peyvast-auth' ) ) : new \WP_Error( 'storage_busy', __( 'This request is already being processed. Please try again shortly.', 'peyvast-auth' ) );			try {
				$existing = $this->challenges->find_active( $hash, $purpose );
			$cooldown = $this->challenges->find_cooldown( $hash, $purpose );
			if ( $cooldown ) {
				if ( $existing && ! $force_resend ) return $this->response_for( $existing, true, $user, $normalized, array(), $input_identifier );
				return new \WP_Error(
					'send_rate_limited',
					__( 'The number of requests exceeds the allowed limit.', 'peyvast-auth' ),
					array( 'retry_after' => max( 0, strtotime( (string) $cooldown->resend_available_at ) - time() ) )
				);
			}
			$otp      = $this->policy->generate();
			$channels = $this->channels( $purpose, $type, $normalized, $user, $settings );
			if ( ! $channels ) return $purpose === 'password_reset' ? new \WP_Error( 'reset_unavailable', __( 'Unable to process the password reset request.', 'peyvast-auth' ) ) : new \WP_Error( 'delivery_unavailable', __( 'No delivery method is currently available for this request.', 'peyvast-auth' ) );
			$row = $this->new_challenge( $type, $hash, $user ? (int) $user->ID : 0, $purpose, array_keys( $channels ), $otp );
			if ( ! $row ) return $purpose === 'password_reset' ? new \WP_Error( 'reset_unavailable', __( 'Unable to process the password reset request.', 'peyvast-auth' ) ) : new \WP_Error( 'storage_failed', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );

			// Encrypted at rest before leaving this scope; never in scheduler args, logs or responses.
			if ( ! OtpDeliveryStore::store( (string) $row->challenge_id, $otp, $channels ) ) {
				$this->invalidate_without_payload( (string) $row->challenge_id );
				return $purpose === 'password_reset' ? new \WP_Error( 'reset_unavailable', __( 'Unable to process the password reset request.', 'peyvast-auth' ) ) : new \WP_Error( 'storage_failed', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
			}
			if ( $existing && ! $this->invalidate_and_cleanup_challenge( (string) $existing->challenge_id ) ) {
				// The replaced challenge is still usable, so its replacement must not survive without a payload.
				$this->invalidate_and_cleanup_challenge( (string) $row->challenge_id );
				return new \WP_Error( 'storage_failed', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
			}

			// Delivery is in-request: nothing is scheduled and no code can arrive later.
			$delivery = OtpDeliveryHandler::deliver_now( (string) $row->challenge_id );
			if ( ! empty( $delivery['success'] ) ) {
				return $this->response_for( $row, false, $user, $normalized, array_keys( $channels ), $input_identifier, OtpDeliveryHandler::STATUS_DELIVERED );
			}

			// A challenge must not stay usable for a code the user was told failed.
			$this->invalidate_and_cleanup_challenge( (string) $row->challenge_id );
			return new \WP_Error(
				'delivery_failed',
				__( 'The verification code could not be sent. Please try again in a few minutes.', 'peyvast-auth' ),
				array( 'reason' => (string) ( $delivery['reason'] ?? '' ) )
			);
		} finally { $lock->release(); }
	}

	/** Enumeration-neutral success shape for unknown identifiers; nothing is stored or sent. */
	private function neutral_response( string $type, string $normalized, string $input_identifier, string $purpose ): array {
		$cooldown = max( 1, (int) Settings::get( 'otp.resend_cooldown', 120 ) );
		$expires  = max( 1, (int) Settings::get( 'otp.expiration_seconds', 300 ) );
		$row      = (object) array(
			'challenge_id'        => wp_generate_uuid4(),
			'identifier_type'     => $type,
			'channels'            => $type === 'email' ? 'email' : 'sms',
			'resend_available_at' => gmdate( 'Y-m-d H:i:s', time() + $cooldown ),
			'expires_at'          => gmdate( 'Y-m-d H:i:s', time() + $expires ),
			'user_id'             => 0,
		);
		return $this->response_for( $row, false, null, $normalized, array(), $input_identifier );
	}

	private function new_challenge( string $type, string $hash, int $user_id, string $purpose, array $channels, string $otp ) {
		$now = time();
		$session_hash = '';
		if ( ( ! function_exists( 'is_user_logged_in' ) || ! \is_user_logged_in() ) && class_exists( GuestSession::class ) ) $session_hash = GuestSession::hash();
		return $this->challenges->create( array( 'challenge_id' => wp_generate_uuid4(), 'identifier_type' => $type, 'identifier_hash' => $hash, 'identifier_value' => '', 'user_id' => $user_id, 'purpose' => $purpose, 'channels' => implode( ',', $channels ), 'otp_hash' => OtpPolicy::hash_code( $otp ), 'guest_session_hash' => $session_hash, 'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $this->policy->expiration_seconds() ), 'resend_available_at' => gmdate( 'Y-m-d H:i:s', $now + $this->policy->resend_cooldown() ), 'attempts' => 0, 'verified_at' => null, 'consumed_at' => null, 'processing_at' => null, 'created_at' => gmdate( 'Y-m-d H:i:s', $now ), 'updated_at' => gmdate( 'Y-m-d H:i:s', $now ) ) );
	}

	public function verify( string $challenge_id, string $otp ) {
		$row = $this->challenges->find_challenge( $challenge_id );
		if ( ! $row || ! $this->challenges->guest_session_matches( $row ) ) return new \WP_Error( 'session_invalid', __( 'Your verification session is no longer available. Please request a new code.', 'peyvast-auth' ) );
		$guard = Guard::evaluate( Guard::SCOPE_OTP_VERIFY, array( 'identifier' => (string) $row->identifier_hash ) );
		if ( ! $guard['allowed'] ) return new \WP_Error( $guard['blocked'] ? 'security_blocked' : 'verify_rate_limited', __( 'The number of requests exceeds the allowed limit.', 'peyvast-auth' ), $guard );
		$token = wp_generate_password( 48, false, false );
		$result = $this->challenges->verify_code( (int) $row->id, OtpPolicy::hash_code( trim( $otp ) ), hash( 'sha256', $token ), max( 1, (int) Settings::get( 'security.verify_limit', 5 ) ) );
		if ( is_wp_error( $result ) ) return $result;
		return array( 'verification_token' => $token, 'user_exists' => (int) $result->user_id > 0, 'next_stage' => (int) $result->user_id > 0 ? 'login' : 'register', 'purpose' => (string) $result->purpose, 'identifier_type' => (string) $result->identifier_type );
	}
	/**
	 * Delivery state for a challenge whose code may still be in flight.
	 *
	 * 1.0.1 delivers new codes inside the send request, so this endpoint is only
	 * meaningful for transactions left over from earlier versions. It never
	 * reports `delivered` for a challenge that still has a staged payload, and
	 * never reports success for a consumed or expired challenge.
	 */
	public function delivery_status( string $challenge_id ) {
		$row = $this->challenges->find_challenge( sanitize_text_field( $challenge_id ) );
		if ( ! $row || ! $this->challenges->guest_session_matches( $row ) ) {
			return new \WP_Error( 'invalid_token', __( 'The delivery session is no longer valid.', 'peyvast-auth' ) );
		}

		$challenge_id = (string) $row->challenge_id;
		$delivery     = OtpDeliveryStore::row( $challenge_id );
		if ( $delivery ) {
			return array( 'delivery_status' => $this->staged_delivery_status( $challenge_id, (int) $delivery->attempts ) );
		}
		if ( ! empty( $row->consumed_at ) ) return array( 'delivery_status' => 'failed' );
		if ( strtotime( (string) $row->expires_at ) <= time() ) return array( 'delivery_status' => 'failed' );
		// Payloads are removed only once delivery settles, so absence is positive evidence.
		return array( 'delivery_status' => 'delivered' );
	}

	/**
	 * Delivery status implied by a challenge that still has a staged payload.
	 *
	 * A staged payload is ambiguous: it belongs either to a queued transaction
	 * from an earlier version whose delivery is still scheduled, or to a failed
	 * synchronous attempt that kept its payload because challenge invalidation
	 * could not be confirmed. Only a scheduled action proves the first, and
	 * reporting the second as pending would show the OTP stage for a code
	 * nothing will deliver.
	 *
	 * `as_has_scheduled_action()` compares arguments exactly — the bundled store
	 * leaves partial argument matching off — so every possible retry attempt is
	 * probed by its full argument set, starting with the one the attempt count
	 * implies. Without a usable Action Scheduler the pending state is preserved
	 * rather than reported as failed.
	 */
	private function staged_delivery_status( string $challenge_id, int $attempts ): string {
		$pending = $attempts > 0 ? 'retrying' : 'queued';
		if ( $challenge_id === '' || ! Scheduler::available() ) {
			return $pending;
		}
		$expected = min( max( 0, $attempts ), OtpDeliveryHandler::MAX_ATTEMPTS - 1 );
		$probes   = array( $expected );
		for ( $attempt = 0; $attempt < OtpDeliveryHandler::MAX_ATTEMPTS; $attempt++ ) {
			if ( $attempt !== $expected ) {
				$probes[] = $attempt;
			}
		}
		foreach ( $probes as $attempt ) {
			if ( Scheduler::has_scheduled( Scheduler::HOOK_DELIVER_OTP, array( $challenge_id, $attempt ) ) ) {
				return $pending;
			}
		}
		return 'failed';
	}

	/**
	 * Invalidate a challenge that was never deliverable.
	 *
	 * Used when staging failed outright, so there is no payload to retain as
	 * delivery evidence: a live challenge without one is later read as
	 * "delivered". The locked invalidation is attempted first; the direct
	 * conditional UPDATE that follows cannot race a legitimate consumer, because
	 * this challenge was created in the current request, has no staged payload,
	 * and its code was never sent.
	 */
	private function invalidate_without_payload( string $challenge_id ): void {
		if ( $challenge_id === '' || $this->confirm_challenge_invalidation( $challenge_id ) ) {
			return;
		}
		$this->challenges->invalidate_locked( $challenge_id );
		$row = $this->challenges->find_challenge( $challenge_id );
		if ( ! $row || empty( $row->consumed_at ) ) {
			Logger::warning(
				'provider',
				'otp_challenge_invalidation_failed',
				'OTP challenge invalidation could not be confirmed after a direct write; it will expire on its own.',
				array( 'challenge_id' => substr( $challenge_id, 0, 8 ) )
			);
		}
	}

	/**
	 * Invalidate a challenge and discard its staged payload only after the
	 * database confirms the challenge can no longer be used.
	 *
	 * The staging row is the only delivery evidence in this data model: a
	 * usable challenge with no payload is read as "delivered" by
	 * {@see self::response_for()} and {@see self::delivery_status()}. Deleting
	 * the payload while invalidation failed would turn a failed delivery into a
	 * false success on the next request, so the payload is kept until
	 * invalidation is confirmed. Existing expiration and orphan cleanup remain
	 * responsible for eventual removal of a retained payload.
	 *
	 * @return bool True when the challenge is confirmed unusable and its payload
	 *              was discarded.
	 */
	private function invalidate_and_cleanup_challenge( string $challenge_id ): bool {
		if ( $challenge_id === '' ) {
			return false;
		}
		if ( ! $this->confirm_challenge_invalidation( $challenge_id ) ) {
			Logger::warning(
				'provider',
				'otp_delivery_cleanup_deferred',
				'OTP challenge cleanup could not be confirmed; the staging payload was kept so the challenge cannot be mistaken for a delivered one.',
				array( 'challenge_id' => substr( $challenge_id, 0, 8 ) )
			);
			return false;
		}
		OtpDeliveryStore::delete( $challenge_id );
		return true;
	}

	/**
	 * Invalidate a challenge and confirm the result from the database.
	 *
	 * Uses the existing per-challenge lifecycle lock through
	 * {@see OtpChallengeStore::invalidate()} only; the retry is bounded to two
	 * attempts and the second uses a shorter lock wait, so a contended lock
	 * cannot add a second full-length wait to an already-failed request.
	 */
	private function confirm_challenge_invalidation( string $challenge_id ): bool {
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$this->challenges->invalidate( $challenge_id, $attempt === 0 ? 5 : 1 );
			$row = $this->challenges->find_challenge( $challenge_id );
			if ( $row && ! empty( $row->consumed_at ) ) {
				return true;
			}
			if ( $attempt === 0 ) {
				usleep( 25000 ); // 25 ms: the lifecycle lock is held only for short operations.
			}
		}
		return false;
	}

	public function preview( string $token, string $purpose ) { return $this->challenges->find_challenge_by_verified_token( $token, $purpose ); }
	private function parse_identifier( string $identifier, bool $allow_user_login = false ): array {
		$phone = PhoneNumber::from_input( $identifier );
		if ( $phone ) return array( 'phone', $phone->canonical() );
		$email = strtolower( sanitize_email( $identifier ) );
		if ( is_email( $email ) ) return array( 'email', $email );
		if ( $allow_user_login ) { $login = sanitize_user( $identifier, true ); if ( $login !== '' && strlen( $login ) <= 60 ) return array( 'user_login', $login ); }
		return array( '', '' );
	}
	private function identifier_hash( string $type, string $value ): string {
		$secret = (string) get_option( 'peyvast_auth_identifier_key', wp_salt( 'auth' ) );
		return hash_hmac( 'sha256', $type . ':' . $value, $secret );
	}
	private function method_allowed( string $purpose, string $type, array $settings ): bool {
		// Phone OTP sign-in always remains available: registration depends on it.
		if ( $purpose === 'otp_login' && $type === 'phone' ) return true;
		$path = $purpose === 'password_reset' ? ( $settings['authentication']['password_reset'] ?? array() ) : ( $settings['authentication']['otp_login'] ?? array() );
		return ! empty( $path[ $type ] );
	}
	private function account_phone( $user ): string { $phone = PhoneNumber::canonical_value( (string) get_user_meta( $user->ID, '_peyvast_auth_phone', true ) ); return $phone ?: ( PhoneNumber::from_input( (string) $user->user_login ) ? PhoneNumber::canonical_value( $user->user_login ) : '' ); }
	private function channels( string $purpose, string $type, string $normalized, $user, array $settings ): array {
		$c = AuthenticationCapabilities::resolve( $settings );
		if ( $type === 'email' ) return $c['email_otp_enabled'] ? array( 'email' => $normalized ) : array();
		if ( $type === 'user_login' ) {
			if ( $purpose !== 'otp_login' || ! $user instanceof \WP_User ) return array();
			$login = $settings['authentication']['otp_login'] ?? array(); $out = array(); $phone = $this->account_phone( $user );
			if ( ! empty( $login['phone'] ) && $phone !== '' && $c['phone_otp_delivery_enabled'] ) $out['sms'] = $phone;
			if ( ! empty( $login['email'] ) && $c['email_otp_enabled'] && is_email( (string) $user->user_email ) ) $out['email'] = (string) $user->user_email;
			return $out;
		}
		$dual = ( $purpose === 'otp_login' && ! empty( $settings['authentication']['otp_email_for_phone'] ) ) || ( $purpose === 'password_reset' && ! empty( $settings['authentication']['password_reset']['email_copy_for_phone'] ) );
		$out = $c['phone_otp_delivery_enabled'] ? array( 'sms' => $normalized ) : array();
		if ( $dual && $user instanceof \WP_User && is_email( (string) $user->user_email ) ) $out['email'] = (string) $user->user_email;
		return $out;
	}
	private function response_for( $row, bool $restored, $user, string $normalized, array $channels = array(), string $input_identifier = '', string $delivery_status = '' ): array {
		// Expose only the canonical machine-readable form of the identifier.
		$type = (string) $row->identifier_type; $channels = $channels ?: array_values( array_filter( explode( ',', (string) $row->channels ) ) ); $destinations = array();
		foreach ( $channels as $channel ) {
			$channel = strtolower( trim( (string) $channel ) );
			if ( $channel === 'sms' ) {
				$destinations[] = array( 'type' => 'phone', 'channel' => 'sms', 'value' => $type === 'user_login' && $user instanceof \WP_User ? $this->account_phone( $user ) : $normalized, 'source' => $type === 'phone' ? 'user_input' : 'server' );
			}
			if ( $channel === 'email' ) {
				$destinations[] = array( 'type' => 'email', 'channel' => 'email', 'value' => $user instanceof \WP_User ? (string) $user->user_email : $normalized, 'source' => $type === 'email' ? 'user_input' : 'server' );
			}
		}
		// Secondary-channel presence stays in the public contract; destinations are masked.
		$public_channels      = $channels;
		$public_destinations  = $destinations;
		$information          = InformationPresentation::build( $type, $input_identifier, $public_destinations );
		if ( $delivery_status === '' && ! empty( $row->challenge_id ) ) {
			$challenge_id = (string) $row->challenge_id;				$delivery_row = OtpDeliveryStore::row( $challenge_id );
				if ( $delivery_row ) {
					$delivery_status = $this->staged_delivery_status( $challenge_id, (int) $delivery_row->attempts );
				}
		}
		return array( 'challenge_id' => (string) $row->challenge_id, 'identifier_type' => $type, 'delivery_status' => $delivery_status ?: 'delivered', 'restored' => $restored, 'retry_after' => max( 0, strtotime( (string) $row->resend_available_at ) - time() ), 'expires_in' => max( 0, strtotime( (string) $row->expires_at ) - time() ), 'channels' => $public_channels, 'information' => $information );
	}
}
