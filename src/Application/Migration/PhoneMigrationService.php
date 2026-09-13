<?php
namespace Peyvast\Auth\Application\Migration;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Persistence\DatabaseLock;
use Peyvast\Auth\Infrastructure\Scheduling\Scheduler;
use Peyvast\Auth\Infrastructure\WordPress\MigrationNoticeStore;

defined( 'ABSPATH' ) || exit;

/** Batch phone migration as a chain of idempotent Action Scheduler single actions. */
final class PhoneMigrationService {
	private const JOB_KEY      = 'peyvast_auth_phone_migration_batch';
	private const CANCEL_KEY   = 'peyvast_auth_phone_migration_cancelled';
	private const LOCK_KEY     = 'peyvast_auth_phone_migration_lock';
	private const BATCH_SIZE   = 50;
	private const MAX_RETRIES  = 5;
	private const RETRY_BASE   = 5; // seconds; doubles per attempt, capped.
	private const RETRY_CAP    = 5 * MINUTE_IN_SECONDS;
	private const STALL_SECONDS = 600;

	private static string $lock_owner = '';
	private static ?DatabaseLock $state_lock = null;

	public static function start(): array {
		$lock = \Peyvast\Auth\Infrastructure\Persistence\DatabaseLock::acquire( 'migration:job-start', 5 );
		if ( ! $lock ) {
			return array_merge( self::empty_status(), array( 'status' => 'busy' ) );
		}
		try {
			$existing = self::get_status();
			if ( $existing['status'] === 'running' ) {
				return $existing;
			}

			// Clear the previous job's dismissal tombstone so an old worker cannot cancel this run.
			delete_option( self::CANCEL_KEY );
			$source = PhoneMigrationSourceResolver::snapshot();
			if ( ( $source['legacy_type'] ?? '' ) === 'user_meta' && empty( $source['legacy_key'] ) ) {
				return self::mark_needs_review();
			}

			$job = array_merge(
				self::empty_status(),
				array(
					'id'         => wp_generate_uuid4(),
					'status'     => 'running',
					'source'     => $source,
					'total'      => self::count_candidates(),
					'started_at' => time(),
					'updated_at' => time(),
					'lock_until' => time() + 300,
				)
			);
			self::save( $job );
			if ( Scheduler::schedule_single( Scheduler::HOOK_PHONE_MIGRATION_BATCH, array( $job['id'], 0 ), 1, true ) <= 0 ) {
				$job['status'] = 'failed';
				$job['error'] = 'initial_schedule_failed';
				$job['updated_at'] = time();
				self::save( $job );
				MigrationNoticeStore::mark( 'default', array( 'status' => 'failed', 'updated_at' => time(), 'job_id' => $job['id'] ) );
				return $job;
			}
			MigrationNoticeStore::mark(
				'default',
				array(
					'status'     => 'running',
					'updated_at' => time(),
					'job_id'     => $job['id'],
				)
			);
			return $job;
		} finally {
			$lock->release();
		}
	}

	/** Process one batch; args are [job_id, attempt] so retries stay unique. */
	public static function process_job( string $job_id = '', int $attempt = 0 ): void {
		$attempt = max( 0, (int) $attempt );
		$job     = self::get_status();
		if ( $job['status'] !== 'running' || ( $job_id !== '' && $job['id'] !== $job_id ) || self::is_cancelled( $job['id'] ) ) {
			return; // Canceled, replaced or already completed.
		}
		if ( ! self::acquire_lock() ) {
			// Lock held elsewhere: retry with backoff, then let the watchdog recover the chain.
			if ( $attempt < self::MAX_RETRIES ) {
				if ( ! self::schedule_retry( $job['id'], $attempt + 1, self::backoff( $attempt ) ) ) {
				$job['status'] = 'failed'; $job['error'] = 'retry_schedule_failed'; $job['lock_until'] = 0; self::save( $job );
				MigrationNoticeStore::mark( 'default', array( 'status' => 'failed', 'updated_at' => time(), 'job_id' => $job['id'] ) ); return;
			}
			}
			return;
		}

		try {
			$engine = new PhoneMigrationEngine();
			$ids    = self::candidate_ids( $job['source'], (int) $job['last_id'], self::BATCH_SIZE );
			if ( self::is_cancelled( $job['id'] ) ) {
				return;
			}
			if ( ! $ids ) {
				$job['status']       = 'completed';
				$job['completed_at'] = time();
				$job['updated_at']   = time();
				$job['lock_until']   = 0;
				self::save( $job );
				MigrationNoticeStore::mark(
					'default',
					array(
						'status'     => 'completed',
						'updated_at' => time(),
						'job_id'     => $job['id'],
					)
				);
				return;
			}
			foreach ( $ids as $user_id ) {
				$user           = get_user_by( 'id', $user_id );
				$job['last_id'] = $user_id;
				++$job['processed'];
				if ( ! $user instanceof \WP_User ) {
					++$job['failed'];
					$job['failure_counts']['user_unavailable'] = ( $job['failure_counts']['user_unavailable'] ?? 0 ) + 1;
					continue;
				}
				$result = $engine->migrate_user( $user, false, $job['source'] );
				self::apply_result( $job, $result );
			}
			if ( self::is_cancelled( $job['id'] ) ) {
				return;
			}
			$job['updated_at'] = time();
			$job['lock_until'] = time() + 300;
			self::save( $job );
			// Deliberately not unique: duplicates are prevented by the database lock, not the scheduler.
			if ( Scheduler::schedule_single( Scheduler::HOOK_PHONE_MIGRATION_BATCH, array( $job['id'], 0 ), 1, false ) <= 0 ) {
				$job['status'] = 'failed'; $job['error'] = 'continuation_schedule_failed'; $job['lock_until'] = 0; $job['updated_at'] = time();
				self::save( $job ); MigrationNoticeStore::mark( 'default', array( 'status' => 'failed', 'updated_at' => time(), 'job_id' => $job['id'] ) ); return;
			}
			MigrationNoticeStore::mark(
				'default',
				array(
					'status'     => 'running',
					'updated_at' => time(),
					'job_id'     => $job['id'],
				)
			);
		} catch ( \Throwable $e ) {
			if ( self::is_cancelled( $job['id'] ) ) {
				return;
			}
			if ( $attempt < self::MAX_RETRIES ) {
				$job['status']     = 'running';
				$job['updated_at'] = time();
				$job['lock_until'] = 0;
				self::save( $job );
				if ( ! self::schedule_retry( $job['id'], $attempt + 1, self::backoff( $attempt ) ) ) {
				$job['status'] = 'failed'; $job['error'] = 'retry_schedule_failed'; $job['lock_until'] = 0; self::save( $job );
				MigrationNoticeStore::mark( 'default', array( 'status' => 'failed', 'updated_at' => time(), 'job_id' => $job['id'] ) ); return;
			}
				MigrationNoticeStore::mark(
					'default',
					array(
						'status'     => 'running',
						'updated_at' => time(),
						'job_id'     => $job['id'],
					)
				);
				return;
			}
			$job['status']     = 'failed';
			$job['error']      = 'batch_exception';
			$job['updated_at'] = time();
			$job['lock_until'] = 0;
			self::save( $job );
			MigrationNoticeStore::mark(
				'default',
				array(
					'status'     => 'failed',
					'updated_at' => time(),
					'job_id'     => $job['id'],
				)
			);
		} finally {
			self::release_lock();
		}
	}

	/** Recurring watchdog: re-arms a chain stalled by a crashed worker. */
	public static function watchdog(): void {
		$job = self::get_status();
		if ( $job['status'] !== 'running' || self::is_cancelled( $job['id'] ) ) {
			return;
		}
		if ( (int) ( $job['updated_at'] ?? 0 ) > 0 && (int) $job['updated_at'] < time() - self::STALL_SECONDS ) {
			$job['lock_until'] = 0;
			$job['updated_at'] = time();
			self::save( $job );
			MigrationNoticeStore::mark( 'default', array( 'status' => 'running', 'updated_at' => time(), 'job_id' => $job['id'] ) );
			if ( Scheduler::schedule_single( Scheduler::HOOK_PHONE_MIGRATION_BATCH, array( $job['id'], 0 ), 5, true ) <= 0 ) {
				$job['status'] = 'failed'; $job['error'] = 'watchdog_schedule_failed'; $job['updated_at'] = time(); self::save( $job );
				MigrationNoticeStore::mark( 'default', array( 'status' => 'failed', 'updated_at' => time(), 'job_id' => $job['id'] ) );
			}
		}
	}

	/** Async lazy migration handler (one unique action per user); best-effort. */
	public static function process_lazy( $user_id = 0 ): void {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! Settings::get( 'migration.lazy_enabled', false ) ) {
			return;
		}
		$engine = new PhoneMigrationEngine();
		$result = $engine->lazy_migrate_user( $user_id );
		if ( ( $result['status'] ?? '' ) === 'failed' ) {
			Logger::warning(
				'migration',
				'lazy_migration_background_failed',
				'Background lazy phone migration failed.',
				array( 'reason' => (string) ( $result['reason'] ?? 'unknown' ) ),
				$user_id
			);
		}
	}

	public static function get_status(): array {
		return array_merge( self::empty_status(), (array) get_option( self::JOB_KEY, array() ) );
	}

	public static function status_summary( ?array $job = null ): array {
		$job = $job ?: self::get_status();
		return array_intersect_key( $job, array_flip( array( 'id', 'status', 'source', 'total', 'processed', 'migrated', 'already_current', 'invalid', 'missing', 'conflict', 'failed', 'skipped', 'failure_counts', 'started_at', 'updated_at', 'completed_at', 'error' ) ) ) + array(
			'has_notice'   => self::has_notice(),
			'lazy_enabled' => (bool) Settings::get( 'migration.lazy_enabled', false ),
		);
	}

	public static function has_notice(): bool {
		return MigrationNoticeStore::exists( 'default' ); }
	/**
	 * Close the migration UI and reset the batch migration lifecycle.
	 *
	 * Closing is a destructive UI action by design: queued migration work,
	 * persisted progress and the presentation notice are all cleared, so the
	 * next visit behaves as if no batch migration had been started.
	 */
	public static function dismiss_notice(): bool {
		$state_lock = DatabaseLock::acquire( 'migration:state-lock', 5 );
		if ( ! $state_lock ) {
			return false;
		}
		try {
			$job = self::get_status();
		$job_id = (string) ( $job['id'] ?? '' );

		// Tombstone first, so a worker already holding the old job cannot recreate it.
		if ( $job_id !== '' ) {
			update_option( self::CANCEL_KEY, $job_id, false );
		}
		Scheduler::cancel_phone_migration();
		MigrationNoticeStore::dismiss( 'default' );
		delete_option( self::JOB_KEY );
		delete_option( self::LOCK_KEY );
		self::$lock_owner = '';
			return true;
		} finally {
			$state_lock->release();
		}
	}

	public static function mark_needs_review(): array {
		$job = array_merge(
			self::empty_status(),
			array(
				'status'     => 'needs_review',
				'source'     => PhoneMigrationSourceResolver::snapshot(),
				'updated_at' => time(),
			)
		);
		self::save( $job );
		MigrationNoticeStore::mark(
			'default',
			array(
				'status'     => 'needs_review',
				'updated_at' => time(),
			)
		);
		return $job;
	}

	private static function empty_status(): array {
		return array(
			'id'              => '',
			'status'          => 'idle',
			'source'          => PhoneMigrationSourceResolver::snapshot(),
			'last_id'         => 0,
			'total'           => 0,
			'processed'       => 0,
			'migrated'        => 0,
			'already_current' => 0,
			'invalid'         => 0,
			'missing'         => 0,
			'conflict'        => 0,
			'failed'          => 0,
			'skipped'         => 0,
			'failure_counts'  => array(),
			'started_at'      => 0,
			'updated_at'      => 0,
			'completed_at'    => 0,
			'lock_until'      => 0,
			'error'           => '',
		);
	}

	private static function candidate_ids( array $source, int $last_id, int $limit ): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d", $last_id, $limit ) ) );
	}

	private static function count_candidates(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
	}

	private static function apply_result( array &$job, array $result ): void {
		$status = (string) ( $result['status'] ?? 'skipped' );
		if ( isset( $job[ $status ] ) ) {
			++$job[ $status ];
			if ( in_array( $status, array( 'failed', 'invalid', 'missing', 'conflict' ), true ) ) {
				$reason = (string) ( $result['reason'] ?? 'unknown' );
				$job['failure_counts'][ $reason ] = ( $job['failure_counts'][ $reason ] ?? 0 ) + 1;
			}
			return;
		}
		++$job['skipped'];
		$reason                           = (string) ( $result['reason'] ?? 'unknown' );
		$job['failure_counts'][ $reason ] = ( $job['failure_counts'][ $reason ] ?? 0 ) + 1;
	}

	private static function is_cancelled( string $job_id ): bool {
		$cancelled = (string) get_option( self::CANCEL_KEY, '' );
		return $job_id !== '' && $cancelled !== '' && hash_equals( $cancelled, $job_id );
	}

	private static function save( array $job ): void {
		$current = get_option( self::JOB_KEY, array() );
		$current_id = is_array( $current ) ? (string) ( $current['id'] ?? '' ) : '';
		$job_id = (string) ( $job['id'] ?? '' );

		// A dismissed job's worker must never overwrite a newer job (Close/recreate race).
		if ( $current_id !== '' && $job_id !== '' && $current_id !== $job_id ) {
			return;
		}
		update_option( self::JOB_KEY, $job, false );
	}

	/**
	 * Schedule a unique retry for the given attempt; uniqueness means two
	 * callers can never create duplicate retry actions with the same args.
	 */
	private static function schedule_retry( string $job_id, int $attempt, int $delay = 5 ): bool {
		return Scheduler::schedule_single( Scheduler::HOOK_PHONE_MIGRATION_BATCH, array( $job_id, $attempt ), max( 1, $delay ), true ) > 0;
	}

	/** Exponential backoff capped at 5 minutes: 5s, 10s, 20s, 40s, ... */
	private static function backoff( int $attempt ): int {
		return min( self::RETRY_CAP, self::RETRY_BASE * ( 2 ** max( 0, $attempt ) ) );
	}

	private static function acquire_lock(): bool {
		// add_option() is atomic, but deleting an expired option before it is not.
		if ( self::$state_lock !== null ) {
			return false;
		}
		$state_lock = DatabaseLock::acquire( 'migration:state-lock', 5 );
		if ( ! $state_lock ) {
			return false;
		}

		$now  = time();
		$lock = get_option( self::LOCK_KEY, array() );
		if ( is_array( $lock ) && (int) ( $lock['expires_at'] ?? 0 ) > $now ) {
			$state_lock->release();
			return false;
		}
		if ( is_array( $lock ) && ! empty( $lock ) ) {
			delete_option( self::LOCK_KEY );
		}
		$owner    = wp_generate_uuid4();
		$acquired = add_option(
			self::LOCK_KEY,
			array(
				'owner'      => $owner,
				'expires_at' => $now + 290,
			),
			'',
			false
		);
		if ( $acquired ) {
			self::$lock_owner = $owner;
			self::$state_lock = $state_lock;
			return true;
		}
		$state_lock->release();
		return false;
	}

	private static function release_lock(): void {
		$lock = get_option( self::LOCK_KEY, array() );
		if ( is_array( $lock ) && ! empty( self::$lock_owner ) && hash_equals( (string) ( $lock['owner'] ?? '' ), self::$lock_owner ) ) {
			delete_option( self::LOCK_KEY );
		}
		self::$lock_owner = '';
		if ( self::$state_lock !== null ) {
			self::$state_lock->release();
			self::$state_lock = null;
		}
	}
}
