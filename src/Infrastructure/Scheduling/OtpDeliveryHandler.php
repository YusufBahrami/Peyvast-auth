<?php
namespace Peyvast\Auth\Infrastructure\Scheduling;

use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Persistence\OtpChallengeStore;
use Peyvast\Auth\Infrastructure\Persistence\OtpDeliveryStore;
use Peyvast\Auth\Infrastructure\Persistence\DatabaseLock;
use Peyvast\Auth\Infrastructure\Providers\ProviderManager;

defined( 'ABSPATH' ) || exit;

/**
 * OTP provider delivery.
 *
 * Two entry points share one implementation:
 *
 * - {@see self::deliver_now()} performs a single delivery attempt inside the
 *   current request. This is the 1.0.1 path for every new user-facing OTP.
 * - {@see self::deliver()} drains already-queued Action Scheduler jobs with
 *   challenge re-validation and bounded retries. New 1.0.1 OTP requests never
 *   enqueue these jobs; they exist so in-flight 1.0.0 transactions stay safe.
 */
final class OtpDeliveryHandler {

	/** Retries are scheduled as `( challenge_id, attempt )` for 0 … MAX_ATTEMPTS - 1. */
	public const MAX_ATTEMPTS    = 3;
	private const RETRY_BASE_SEC = 30;
	private const RETRY_CAP_SEC  = 10 * MINUTE_IN_SECONDS;

	/** Aggregate delivery outcome for a single synchronous attempt. */
	public const STATUS_DELIVERED = 'delivered';
	public const STATUS_FAILED    = 'failed';

	/** Machine-readable reasons, safe for internal logging and error data. */
	public const REASON_DELIVERED        = 'delivered';
	public const REASON_PROVIDER_FAILED  = 'provider_failed';
	public const REASON_CHALLENGE_INVALID = 'challenge_invalid';
	public const REASON_PAYLOAD_INVALID  = 'payload_invalid';
	public const REASON_PAYLOAD_MISSING  = 'payload_missing';
	public const REASON_CHANNELS_MISSING = 'channels_missing';
	public const REASON_LOCK_UNAVAILABLE = 'lock_unavailable';

	/**
	 * Deliver a challenge immediately, inside the current request.
	 *
	 * Exactly one attempt is made; nothing is scheduled and no retry state is
	 * persisted. The caller owns failure cleanup: on a failed result it must
	 * invalidate the challenge and delete the staging row so an undelivered
	 * code can never arrive later.
	 *
	 * @return array{success: bool, status: string, channels: string[], failed: string[], reason: string}
	 */
	public static function deliver_now( string $challenge_id ): array {
		if ( $challenge_id === '' ) {
			return self::result( false, self::REASON_CHALLENGE_INVALID );
		}
		$lock = DatabaseLock::acquire( self::lock_key( $challenge_id ), 5 );
		if ( ! $lock ) {
			return self::result( false, self::REASON_LOCK_UNAVAILABLE );
		}
		try {
			$context = self::load_context( $challenge_id, new OtpChallengeStore() );
			if ( is_string( $context ) ) {
				return self::result( false, $context );
			}
			if ( ! is_array( $context ) ) {
				return self::result( false, self::REASON_PAYLOAD_MISSING );
			}
			$channels = $context['channels'];
			$outcome  = self::send_channels(
				$context['payload'],
				$channels,
				array(
					'purpose' => (string) $context['challenge']->purpose,
					'user_id' => (int) $context['challenge']->user_id,
					'async'   => false,
				)
			);

			if ( ! $outcome['failed'] ) {
				OtpDeliveryStore::delete( $challenge_id );
				Logger::notice(
					'provider',
					'otp_delivery_completed',
					'OTP delivery completed during the request.',
					array(
						'channels'     => $channels,
						'challenge_id' => substr( $challenge_id, 0, 8 ),
						'sync'         => true,
					),
					(int) $context['challenge']->user_id
				);
				return self::result( true, self::REASON_DELIVERED, $channels, array() );
			}

			// No background retry: a code reported as failed must never arrive later.
			Logger::error(
				'provider',
				'otp_delivery_failed',
				'OTP delivery failed during the request; the challenge was invalidated.',
				array(
					'challenge_id' => substr( $challenge_id, 0, 8 ),
					'failed'       => $outcome['failed'],
					'sync'         => true,
				),
				(int) $context['challenge']->user_id
			);
			return self::result( false, self::REASON_PROVIDER_FAILED, $outcome['delivered'], $outcome['failed'] );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Async delivery of an already-queued payload with bounded retries.
	 *
	 * Retained for Action Scheduler jobs created before 1.0.1. Each execution
	 * re-validates the challenge, so invalidated, replaced, expired, or consumed
	 * challenges are never delivered and duplicate executions are no-ops.
	 *
	 * @param string $challenge_id Challenge to deliver.
	 * @param int    $attempt      Zero-based delivery attempt.
	 */
	public static function deliver( string $challenge_id = '', int $attempt = 0 ): void {
		if ( $challenge_id === '' ) {
			return;
		}
		$attempt  = max( 0, (int) $attempt );
		$store = new OtpChallengeStore();
		$lock  = DatabaseLock::acquire( self::lock_key( $challenge_id ), 5 );
		if ( ! $lock ) return;
		try {
			$context = self::load_context( $challenge_id, $store );
			if ( null === $context ) {
				// No payload (already delivered or cleaned): duplicate runs are no-ops.
				return;
			}
			if ( is_string( $context ) ) {
				// A queued delivery that can never succeed is discarded, never retried forever.
				OtpDeliveryStore::delete( $challenge_id );
				return;
			}

		$challenge = $context['challenge'];
		$channels  = $context['channels'];

		// Every configured channel is mandatory; only failed channels are retried.
		$outcome = self::send_channels(
			$context['payload'],
			$channels,
			array(
				'purpose' => (string) $challenge->purpose,
				'user_id' => (int) $challenge->user_id,
				'async'   => true,
			)
		);
		$failed   = $outcome['failed'];

		if ( ! $failed ) {
			OtpDeliveryStore::delete( $challenge_id );
			Logger::notice(
				'provider',
				'otp_delivery_completed',
				'Queued OTP delivery completed.',
				array(
					'channels'     => $channels,
					'challenge_id' => substr( $challenge_id, 0, 8 ),
				),
				(int) $challenge->user_id
			);
			return;
		}

		OtpDeliveryStore::mark_attempt( $challenge_id );
		if ( $attempt >= self::MAX_ATTEMPTS - 1 ) {
			// Final attempt: a code that never reached every channel must not remain usable.
			$store->invalidate_locked( $challenge_id );
			OtpDeliveryStore::delete( $challenge_id );
			Logger::error(
				'provider',
				'otp_delivery_failed',
				'Queued OTP delivery permanently failed; the challenge was invalidated.',
				array(
					'challenge_id' => substr( $challenge_id, 0, 8 ),
					'attempts'     => $attempt + 1,
					'failed'       => $failed,
				),
				(int) $challenge->user_id
			);
			return;
		}

		// Partial success: keep only the failed channels so delivered ones are not resent.
		$remaining = array();
		foreach ( $failed as $channel ) {
			$remaining[ $channel ] = (string) ( $context['payload']['channels'][ $channel ] ?? '' );
		}
		if ( ! OtpDeliveryStore::rewrite( $challenge_id, $context['payload']['otp'], $remaining ) ) {
			$store->invalidate_locked( $challenge_id );
			OtpDeliveryStore::delete( $challenge_id );
			Logger::error( 'provider', 'otp_delivery_state_update_failed', 'OTP delivery state could not be persisted after partial delivery; the challenge was invalidated.', array( 'challenge_id' => substr( $challenge_id, 0, 8 ) ), (int) $challenge->user_id );
			return;
		}
		if ( Scheduler::schedule_single( Scheduler::HOOK_DELIVER_OTP, array( $challenge_id, $attempt + 1 ), self::backoff( $attempt ), true ) <= 0 ) {
			$store->invalidate_locked( $challenge_id );
			OtpDeliveryStore::delete( $challenge_id );
			Logger::error( 'provider', 'otp_delivery_retry_schedule_failed', 'OTP delivery retry could not be scheduled; the challenge was invalidated.', array( 'challenge_id' => substr( $challenge_id, 0, 8 ) ), (int) $challenge->user_id );
			return;
		}
		Logger::notice(
			'provider',
			'otp_delivery_partial_retry',
			'Some OTP delivery channels failed; a retry for the remaining channels was scheduled.',
			array(
				'channels'     => $failed,
				'challenge_id' => substr( $challenge_id, 0, 8 ),
			),
			(int) $challenge->user_id
		);
		} finally {
			$lock->release();
		}
	}

	/**
	 * Load and validate a deliverable challenge/payload pair.
	 *
	 * Must be called while holding the per-challenge lock.
	 *
	 * @return array{challenge: object, payload: array, channels: string[]}|string|null
	 *         Delivery context, a failure reason constant, or null when there is
	 *         nothing queued to deliver.
	 */
	private static function load_context( string $challenge_id, OtpChallengeStore $store ) {
		$challenge = $store->find_challenge( $challenge_id );
		$delivery  = OtpDeliveryStore::row( $challenge_id );
		if ( ! $delivery ) {
			return null;
		}

		// Never deliver for a challenge that is no longer the live one.
		if ( ! $challenge || ! empty( $challenge->consumed_at ) || ! empty( $challenge->verified_at ) || strtotime( (string) $challenge->expires_at ) <= time() ) {
			Logger::notice(
				'provider',
				'otp_delivery_cancelled',
				'An OTP delivery was cancelled because the challenge is no longer valid.',
				array( 'challenge_id' => substr( $challenge_id, 0, 8 ) ),
				(int) ( $challenge->user_id ?? 0 )
			);
			return self::REASON_CHALLENGE_INVALID;
		}

		$payload = OtpDeliveryStore::payload_from_row( $delivery );
		if ( null === $payload || $payload['otp'] === '' ) {
			// Undecryptable payload: drop it rather than resend blind.
			Logger::error(
				'provider',
				'otp_delivery_payload_invalid',
				'OTP delivery payload could not be decrypted; the delivery was discarded.',
				array( 'challenge_id' => substr( $challenge_id, 0, 8 ) ),
				(int) $challenge->user_id
			);
			return self::REASON_PAYLOAD_INVALID;
		}

		$channels = array_values( array_filter( array_map( 'strval', array_keys( $payload['channels'] ) ) ) );
		if ( ! $channels ) {
			return self::REASON_CHANNELS_MISSING;
		}

		return array(
			'challenge' => $challenge,
			'payload'   => $payload,
			'channels'  => $channels,
		);
	}

	/**
	 * Send the payload through the existing provider abstraction, one channel
	 * at a time. All configured channels are mandatory.
	 *
	 * @return array{delivered: string[], failed: string[]}
	 */
	private static function send_channels( array $payload, array $channels, array $context ): array {
		$delivered = array();
		$failed    = array();
		foreach ( $channels as $channel ) {
			$recipient = (string) ( $payload['channels'][ $channel ] ?? '' );
			if ( $recipient === '' ) {
				$failed[] = $channel;
				continue;
			}
			$result = $channel === 'sms'
				? ProviderManager::send_sms( $recipient, $payload['otp'], $context )
				: ProviderManager::send_email( $recipient, $payload['otp'], $context );
			if ( $result->success ) {
				$delivered[] = $channel;
			} else {
				$failed[] = $channel;
			}
		}
		return array(
			'delivered' => $delivered,
			'failed'    => $failed,
		);
	}

	/** Shared per-challenge lifecycle lock key (delivery and verification agree). */
	private static function lock_key( string $challenge_id ): string {
		return 'otp-challenge:' . hash( 'sha256', $challenge_id );
	}

	private static function result( bool $success, string $reason, array $delivered = array(), array $failed = array() ): array {
		return array(
			'success'  => $success,
			'status'   => $success ? self::STATUS_DELIVERED : self::STATUS_FAILED,
			'channels' => array_values( $delivered ),
			'failed'   => array_values( $failed ),
			'reason'   => $reason,
		);
	}

	/** Exponential backoff capped at 10 minutes: 30s, 1m, 2m, 4m, ... */
	private static function backoff( int $attempt ): int {
		return min( self::RETRY_CAP_SEC, self::RETRY_BASE_SEC * ( 2 ** max( 0, $attempt ) ) );
	}
}
