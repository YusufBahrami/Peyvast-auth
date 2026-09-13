<?php
namespace Peyvast\Auth\Infrastructure\Persistence;

use Peyvast\Auth\Infrastructure\WordPress\GuestSession;
use Peyvast\Auth\Infrastructure\Persistence\DatabaseLock;

defined( 'ABSPATH' ) || exit;

final class OtpChallengeStore {
	private const PURGE_BATCH_SIZE  = 200;
	private const PURGE_ITERATIONS  = 50;

	public function find_active( string $identifier_hash, string $purpose ) {
		global $wpdb;
		$where = 'identifier_hash=%s AND purpose=%s AND consumed_at IS NULL AND verified_at IS NULL AND expires_at > %s';
		$args = array( $identifier_hash, $purpose, gmdate( 'Y-m-d H:i:s' ) );
		$this->append_guest_scope( $where, $args );
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PEYVAST_AUTH_OTP_TABLE . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT 1', $args ) );
	}

	public function find_cooldown( string $identifier_hash, string $purpose ) {
		global $wpdb;
		$where = 'identifier_hash=%s AND purpose=%s AND consumed_at IS NULL AND verified_at IS NULL AND resend_available_at > %s';
		$args = array( $identifier_hash, $purpose, gmdate( 'Y-m-d H:i:s' ) );
		$this->append_guest_scope( $where, $args );
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PEYVAST_AUTH_OTP_TABLE . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT 1', $args ) );
	}

	public function create( array $data ) {
		global $wpdb;
		$formats  = array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );
		$inserted = $wpdb->insert( PEYVAST_AUTH_OTP_TABLE, $data, $formats );
		if ( ! $inserted ) {
			return null;
		}
		$data['id'] = (int) $wpdb->insert_id;
		return (object) $data;
	}

	public function find_challenge( string $challenge_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PEYVAST_AUTH_OTP_TABLE . ' WHERE challenge_id=%s LIMIT 1', $challenge_id ) );
	}

	public function guest_session_matches( $row ): bool {
		if ( ! $row || (string) ( $row->guest_session_hash ?? '' ) === '' ) {
			return true;
		}
		return GuestSession::is_bound( (string) $row->guest_session_hash );
	}

	/** Verify an OTP under the challenge row lock; attempts are serialized. */
	public function verify_code( int $id, string $otp_hash, string $token_hash, int $limit ) {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PEYVAST_AUTH_OTP_TABLE . ' WHERE id=%d FOR UPDATE', $id ) );
			if ( ! $row ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new \WP_Error( 'invalid_otp', __( 'The verification code is invalid or expired.', 'peyvast-auth' ) );
			}
			if ( ! empty( $row->consumed_at ) || ! empty( $row->verified_at ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new \WP_Error( 'otp_used', __( 'This verification session has already been used.', 'peyvast-auth' ) );
			}
			if ( strtotime( (string) $row->expires_at ) <= time() ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new \WP_Error( 'otp_expired', __( 'The verification code has expired. Please request a new code.', 'peyvast-auth' ) );
			}
			if ( (int) $row->attempts >= $limit ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new \WP_Error( 'otp_locked', __( 'The number of requests exceeds the allowed limit.', 'peyvast-auth' ) );
			}

			if ( ! hash_equals( (string) $row->otp_hash, $otp_hash ) ) {
				$attempts = min( $limit, (int) $row->attempts + 1 );
				$locked   = $attempts >= $limit;
				$data     = array(
					'attempts'   => $attempts,
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				);
				$formats  = array( '%d', '%s' );
				if ( $locked ) {
					$data['consumed_at'] = gmdate( 'Y-m-d H:i:s' );
					$formats[]           = '%s';
				}
				$updated = $wpdb->update(
					PEYVAST_AUTH_OTP_TABLE,
					$data,
					array(
						'id'          => $id,
						'verified_at' => null,
						'consumed_at' => null,
					),
					$formats,
					array( '%d', '%s', '%s' )
				);
				if ( (int) $updated !== 1 ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					return new \WP_Error( 'otp_used', __( 'This verification session has already been used.', 'peyvast-auth' ) );
				}
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new \WP_Error( $locked ? 'otp_locked' : 'invalid_otp', $locked ? __( 'The number of requests exceeds the allowed limit.', 'peyvast-auth' ) : __( 'The verification code is incorrect.', 'peyvast-auth' ) );
			}

			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . PEYVAST_AUTH_OTP_TABLE . ' SET verified_at=%s, otp_hash=%s, updated_at=%s WHERE id=%d AND verified_at IS NULL AND consumed_at IS NULL',
					gmdate( 'Y-m-d H:i:s' ),
					$token_hash,
					gmdate( 'Y-m-d H:i:s' ),
					$id
				)
			);
			if ( (int) $updated !== 1 ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new \WP_Error( 'otp_used', __( 'This verification session has already been used.', 'peyvast-auth' ) );
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$row->verified_at = gmdate( 'Y-m-d H:i:s' );
			$row->otp_hash    = $token_hash;
			return $row;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new \WP_Error( 'storage_failed', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
		}
	}

	/** Atomically claim a verified token for a protected operation without consuming it. */
	public function begin_verified_token_processing( string $token, string $purpose, string $identifier_type = '', string $identifier_hash = '', ?int $user_id = null ) {
		global $wpdb;
		if ( ! $this->begin_transaction() ) {
			return new \WP_Error( 'storage_failed', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
		}
		try {
			$where = 'otp_hash=%s AND purpose=%s AND verified_at IS NOT NULL AND consumed_at IS NULL AND (processing_at IS NULL OR processing_at < %s)';
			$args  = array( hash( 'sha256', $token ), $purpose, gmdate( 'Y-m-d H:i:s', time() - 300 ) );
			if ( $identifier_type !== '' ) {
				$where .= ' AND identifier_type=%s AND identifier_hash=%s';
				$args[] = $identifier_type;
				$args[] = $identifier_hash;
			}
			if ( $user_id !== null ) {
				$where .= ' AND (user_id=%d OR user_id=0)';
				$args[] = $user_id;
			}
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PEYVAST_AUTH_OTP_TABLE . ' WHERE ' . $where . ' FOR UPDATE', $args ) );
			if ( ! $row || strtotime( (string) $row->expires_at ) <= time() || ! $this->guest_session_matches( $row ) ) {
				$this->rollback_transaction();
				return null;
			}
			$now = gmdate( 'Y-m-d H:i:s' );
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . PEYVAST_AUTH_OTP_TABLE . ' SET processing_at=%s, updated_at=%s WHERE id=%d AND consumed_at IS NULL', $now, $now, (int) $row->id ) );
			if ( false === $updated || 1 !== (int) $updated ) {
				$this->rollback_transaction();
				return null;
			}
			if ( ! $this->commit_transaction() ) {
				$this->rollback_transaction();
				return new \WP_Error( 'storage_failed', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
			}
			$row->processing_at = $now;
			return $row;
		} catch ( \Throwable $e ) {
			$this->rollback_transaction();
			return new \WP_Error( 'storage_failed', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ) );
		}
	}

	public function finish_verified_token_processing( int $id ): bool {
		global $wpdb;
		if ( ! $this->begin_transaction() ) {
			return false;
		}
		try {
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . PEYVAST_AUTH_OTP_TABLE . ' SET consumed_at=%s, processing_at=NULL, updated_at=%s WHERE id=%d AND verified_at IS NOT NULL AND consumed_at IS NULL AND processing_at IS NOT NULL', gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ), $id ) );
			if ( false === $updated || 1 !== (int) $updated || ! $this->commit_transaction() ) {
				$this->rollback_transaction();
				return false;
			}
			return true;
		} catch ( \Throwable $e ) {
			$this->rollback_transaction();
			return false;
		}
	}

	public function release_verified_token_processing( int $id ): bool {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE ' . PEYVAST_AUTH_OTP_TABLE . ' SET processing_at=NULL, updated_at=%s WHERE id=%d AND consumed_at IS NULL', gmdate( 'Y-m-d H:i:s' ), $id ) );
		return false !== $result;
	}

	/** Read-only lookup used to route a verified OTP into login or registration. */
	public function find_challenge_by_verified_token( string $token, string $purpose ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . PEYVAST_AUTH_OTP_TABLE . ' WHERE otp_hash=%s AND purpose=%s AND verified_at IS NOT NULL AND consumed_at IS NULL LIMIT 1',
				hash( 'sha256', $token ),
				$purpose
			)
		);
		return $row && strtotime( (string) $row->expires_at ) > time() && $this->guest_session_matches( $row ) ? $row : null;
	}

	/**
	 * Invalidate a challenge under its per-challenge lifecycle lock.
	 *
	 * The timeout is the bounded wait on that lock; callers performing a
	 * bounded cleanup retry may pass a shorter timeout so contention cannot add
	 * a second full-length wait to an already-failed request. A value of 0
	 * performs a single non-blocking attempt.
	 */
	public function invalidate( string $challenge_id, int $timeout = 5 ): bool {
		$lock = DatabaseLock::acquire( 'otp-challenge:' . hash( 'sha256', $challenge_id ), $timeout );
		if ( ! $lock ) return false;
		try {
			return $this->invalidate_locked( $challenge_id );
		} finally {
			$lock->release();
		}
	}

	/** Must only be called while the per-challenge lifecycle lock is held. */
	public function invalidate_locked( string $challenge_id ): bool {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . PEYVAST_AUTH_OTP_TABLE . ' SET consumed_at=%s, processing_at=NULL, updated_at=%s WHERE challenge_id=%s AND consumed_at IS NULL',
			gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ), $challenge_id
		) );
		return false !== $result;
	}

	/** Bounded batched purge of expired/consumed challenges, index-backed. */
	public function purge(): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		for ( $i = 0; $i < self::PURGE_ITERATIONS; $i++ ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . PEYVAST_AUTH_OTP_TABLE . ' WHERE (expires_at < %s) OR (consumed_at IS NOT NULL AND updated_at < %s) LIMIT ' . self::PURGE_BATCH_SIZE,
					$cutoff,
					$cutoff
				)
			);
			if ( ! $deleted || $deleted < self::PURGE_BATCH_SIZE ) {
				break;
			}
		}
	}
	private function append_guest_scope( string &$where, array &$args ): void {
		if ( function_exists( 'is_user_logged_in' ) && \is_user_logged_in() ) return;
		if ( ! class_exists( GuestSession::class ) ) return;
		$where .= ' AND guest_session_hash=%s';
		$args[] = GuestSession::hash();
	}

	private function begin_transaction(): bool {
		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return false !== $result && $wpdb->last_error === '';
	}

	private function commit_transaction(): bool {
		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return false !== $result && $wpdb->last_error === '';
	}

	private function rollback_transaction(): bool {
		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return false !== $result && $wpdb->last_error === '';
	}

}
