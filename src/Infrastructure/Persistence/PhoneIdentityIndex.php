<?php
namespace Peyvast\Auth\Infrastructure\Persistence;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Persistence\DatabaseLock;
use Peyvast\Auth\Infrastructure\Scheduling\Scheduler;

defined( 'ABSPATH' ) || exit;

final class PhoneIdentityIndex {
	public const SOURCE_PRIMARY     = 'primary';
	public const SOURCE_LEGACY      = 'legacy_meta';
	private const STATE_OPTION           = 'peyvast_auth_phone_identity_index_state';
	private const BATCH_SIZE             = 250;
	public const MAX_CANDIDATES          = 64;
	private const GENERATION_OPTION      = 'peyvast_auth_phone_identity_index_generation';
	private const MAX_REBUILD_ATTEMPTS   = 20;
	private const REBUILD_RETRY_BASE_SEC = 5;
	private const REBUILD_RETRY_CAP_SEC  = 10 * MINUTE_IN_SECONDS;

	public static function boot(): void {
		// The rebuild handler belongs to the Scheduler layer; only live index hooks are wired here.
		add_action( 'added_user_meta', array( self::class, 'on_meta_change' ), 10, 4 );
		add_action( 'updated_user_meta', array( self::class, 'on_meta_change' ), 10, 4 );
		add_action( 'deleted_user_meta', array( self::class, 'on_deleted_meta' ), 10, 4 );
		add_action( 'delete_user', array( self::class, 'delete_user' ), 10, 1 );
	}

	public static function available(): bool {
		if ( ! self::table_available() ) {
			return false;
		}
		// Only a positive probe is memoized: the schema self-heals, so a negative result
		// must not outlive a repair performed later in the same request.
		static $schema_checked = false;
		if ( $schema_checked ) {
			return true;
		}
		$health = get_option( 'peyvast_auth_schema_health', array() );
		$ready  = is_array( $health ) && (string) ( $health['status'] ?? '' ) === 'ready'
			? true
			: \Peyvast\Auth\Infrastructure\WordPress\Installer::schema_ready();
		if ( $ready ) {
			$schema_checked = true;
		}
		return $ready;
	}

	/** Distinguishes schema health from rebuild readiness for admin/runtime diagnostics. */
	public static function availability_state(): string {
		if ( ! self::table_available() ) {
			return 'schema_missing';
		}
		if ( ! \Peyvast\Auth\Infrastructure\WordPress\Installer::schema_ready() ) {
			return 'schema_invalid';
		}
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		if ( (string) ( $state['primary_status'] ?? '' ) === 'ready' && self::state_primary_ready( $state ) ) {
			return 'index_ready';
		}
		if ( (string) ( $state['phase'] ?? '' ) === 'failed' || (int) ( $state['failure_count'] ?? 0 ) >= self::MAX_REBUILD_ATTEMPTS ) {
			return 'index_failed';
		}
		if ( Scheduler::available() === false ) {
			return 'scheduler_unavailable';
		}
		return 'index_building';
	}

	public static function contains( int $user_id, string $source, string $canonical ): bool {
		global $wpdb;
		if ( $user_id <= 0 || ! self::table_available() || $canonical === '' ) {
			return false;
		}
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . PEYVAST_AUTH_PHONE_IDENTITY_TABLE . ' WHERE user_id=%d AND source=%s AND canonical_hash=%s LIMIT 1',
				$user_id,
				$source,
				PhoneNumber::hash_value( $canonical )
			)
		);
	}

	public static function candidate_user_ids( string $canonical_hash, array $sources ): array {
		global $wpdb;
		if ( ! self::table_available() ) {
			return array();
		}
		$sources = array_values( array_unique( array_filter( array_map( 'sanitize_key', $sources ) ) ) );
		if ( $canonical_hash === '' || ! $sources ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $sources ), '%s' ) );
		$args         = array_merge( array( $canonical_hash ), $sources );
		$rows         = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT user_id FROM ' . PEYVAST_AUTH_PHONE_IDENTITY_TABLE . ' WHERE canonical_hash=%s AND source IN (' . $placeholders . ') GROUP BY user_id ORDER BY user_id ASC LIMIT ' . ( self::MAX_CANDIDATES + 1 ),
				$args
			)
		);
		return array_values( array_unique( array_map( 'intval', (array) $rows ) ) );
	}

	public static function sync_primary( int $user_id ): bool {
		global $wpdb;
		if ( $user_id <= 0 || ! self::table_available() ) {
			self::record_live_failure( self::SOURCE_PRIMARY );
			return false;
		}
		if ( property_exists( $wpdb, 'last_error' ) ) $wpdb->last_error = '';
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM ' . $wpdb->usermeta . ' WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id ASC LIMIT 1', $user_id, '_peyvast_auth_phone' ) );
		$error = property_exists( $wpdb, 'last_error' ) ? (string) $wpdb->last_error : '';
		if ( false === $value && $error !== '' ) {
			self::record_live_failure( self::SOURCE_PRIMARY );
			return false;
		}
		if ( null === $value ) $value = get_user_meta( $user_id, '_peyvast_auth_phone', true );
		return self::replace( $user_id, self::SOURCE_PRIMARY, PhoneNumber::canonical_value( (string) $value ) );
	}

	public static function sync_legacy( \WP_User $user ): bool {
		global $wpdb;
		$source    = Settings::phone_source();
		$canonical = '';
		if ( ( $source['type'] ?? '' ) === 'user_meta' && ! empty( $source['key'] ) ) {
			if ( property_exists( $wpdb, 'last_error' ) ) $wpdb->last_error = '';
			$value = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM ' . $wpdb->usermeta . ' WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id ASC LIMIT 1', (int) $user->ID, (string) $source['key'] ) );
			$error = property_exists( $wpdb, 'last_error' ) ? (string) $wpdb->last_error : '';
			if ( false === $value && $error !== '' ) {
				self::record_live_failure( self::SOURCE_LEGACY );
				return false;
			}
			if ( null === $value ) $value = get_user_meta( $user->ID, (string) $source['key'], true );
			$canonical = PhoneNumber::canonical_value( (string) $value );
		}
		return self::replace( (int) $user->ID, self::SOURCE_LEGACY, $canonical );
	}

	/** Efficient rebuild-only batch replacement; does not mutate readiness counters per row. */
	private static function replace_batch( array $items, string $source ): bool {
		if ( ! $items || ! self::table_available() ) {
			return true;
		}
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$values = array();
		$args = array();
		foreach ( $items as $item ) {
			$user_id = (int) ( $item['user_id'] ?? 0 );
			$canonical = (string) ( $item['canonical'] ?? '' );
			if ( $user_id <= 0 || $canonical === '' ) {
				continue;
			}
			$values[] = '(%d,%s,%s,%s,%s)';
			$args[] = $user_id;
			$args[] = $source;
			$args[] = PhoneNumber::hash_value( $canonical );
			$args[] = PhoneNumber::peyvast_value( $canonical );
			$args[] = $now;
		}
		if ( $values ) {
			$sql = 'INSERT INTO ' . PEYVAST_AUTH_PHONE_IDENTITY_TABLE . ' (user_id,source,canonical_hash,canonical_phone,updated_at) VALUES ' . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE canonical_hash=VALUES(canonical_hash), canonical_phone=VALUES(canonical_phone), updated_at=VALUES(updated_at)';
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $args ) ) ) {
				return false;
			}
		}
		$empty_ids = array();
		foreach ( $items as $item ) {
			if ( empty( $item['canonical'] ) && ! empty( $item['user_id'] ) ) {
				$empty_ids[] = (int) $item['user_id'];
			}
		}
		if ( $empty_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $empty_ids ), '%d' ) );
			$delete_args = array_merge( array( $source ), $empty_ids );
			$sql = 'DELETE FROM ' . PEYVAST_AUTH_PHONE_IDENTITY_TABLE . ' WHERE source=%s AND user_id IN (' . $placeholders . ')';
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $delete_args ) ) ) {
				return false;
			}
		}
		return true;
	}

	public static function replace( int $user_id, string $source, string $canonical ): bool {
		global $wpdb;
		if ( ! self::table_available() || $user_id <= 0 || ! in_array( $source, array( self::SOURCE_PRIMARY, self::SOURCE_LEGACY ), true ) ) {
			self::record_live_failure( $source );
			return false;
		}
		if ( $canonical === '' ) {
			$result = $wpdb->delete(
				PEYVAST_AUTH_PHONE_IDENTITY_TABLE,
				array(
					'user_id' => $user_id,
					'source'  => $source,
				),
				array( '%d', '%s' )
			);
			if ( $result === false ) {
				self::record_live_failure( $source );
				return false;
			}
			self::record_live_success( $source );
			return true;
		}
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . PEYVAST_AUTH_PHONE_IDENTITY_TABLE . ' (user_id, source, canonical_hash, canonical_phone, updated_at) VALUES (%d, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE canonical_hash=VALUES(canonical_hash), canonical_phone=VALUES(canonical_phone), updated_at=VALUES(updated_at)',
				$user_id,
				$source,
				PhoneNumber::hash_value( $canonical ),
				PhoneNumber::peyvast_value( $canonical ),
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		if ( $result === false ) {
			self::record_live_failure( $source );
			return false;
		}
		self::record_live_success( $source );
		return true;
	}

	public static function ensure_rebuild_scheduled(): void {
		$state = get_option( self::STATE_OPTION, array() );
		// Legacy installs may lack counters; rebuild once.
		if ( ! is_array( $state ) || empty( $state['phase'] ) || empty( $state['generation'] ) ) {
			self::mark_rebuild();
			return;
		}
		// A completed-but-not-ready index restarts and reschedules a rebuild.
		if ( (string) ( $state['phase'] ?? '' ) === 'complete' && ! self::state_primary_ready( $state ) ) {
			$state               = self::mark_rebuild_state( Settings::phone_source() );
			$state['updated_at'] = time();
			update_option( self::STATE_OPTION, $state, false );
			self::schedule_rebuild();
			return;
		}
		// Re-arm a rebuild whose action chain was lost; recovery must not stall.
		if ( (string) ( $state['phase'] ?? '' ) !== 'complete' && ! Scheduler::has_scheduled( Scheduler::HOOK_PHONE_INDEX_REBUILD ) ) {
			self::schedule_rebuild();
		}
	}

	public static function mark_rebuild( bool $schedule = true ): array {
		$source         = Settings::phone_source();
		$legacy_enabled = ( $source['type'] ?? '' ) === 'user_meta';
		$generation     = (int) get_option( self::GENERATION_OPTION, 0 ) + 1;
		$state          = array(
			'phase'                 => 'clear_primary',
			'primary_status'        => 'not_ready',
			'legacy_status'         => $legacy_enabled && ! empty( $source['key'] ) ? 'not_ready' : ( $legacy_enabled ? 'needs_configuration' : 'disabled' ),
			'legacy_key'            => (string) ( $source['key'] ?? '' ),
			'last_id'               => 0,
			'last_meta_id'          => 0,
			'last_clear_id'         => 0,
			'updated_at'            => time(),
			'started_at'            => time(),
			'completed_at'          => 0,
			'expected_users'        => 0,
			'indexed_users'         => 0,
			'failure_count'         => 0,
			'primary_failure_count' => 0,
			'legacy_failure_count'  => 0,
			'generation'            => $generation,
		);
		update_option( self::STATE_OPTION, $state, false );
		// Keep the generation option in sync so readiness compares match.
		update_option( self::GENERATION_OPTION, $generation, false );
		if ( $schedule && Scheduler::available() ) {
			self::schedule_rebuild();
		}
		return self::status();
	}

	public static function status(): array {
		$source         = Settings::phone_source();
		$state          = get_option( self::STATE_OPTION, array() );
		$state          = is_array( $state ) ? $state : array();
		$legacy_enabled = ( $source['type'] ?? '' ) === 'user_meta';
		$legacy_status  = ! $legacy_enabled
			? 'disabled'
			: ( empty( $source['key'] ) ? 'needs_configuration' : ( ( $state['legacy_key'] ?? '' ) !== (string) $source['key'] ? 'not_ready' : (string) ( $state['legacy_status'] ?? 'not_ready' ) ) );
		return array(
			'primary_status'         => (string) ( $state['primary_status'] ?? 'not_ready' ),
			'legacy_status'          => $legacy_status,
			'source'                 => $source,
			'phase'                  => (string) ( $state['phase'] ?? '' ),
			'last_id'                => (int) ( $state['last_id'] ?? 0 ),
			'updated_at'             => (int) ( $state['updated_at'] ?? 0 ),
			'generation'             => (int) ( $state['generation'] ?? 0 ),
			'expected_users'         => (int) ( $state['expected_users'] ?? 0 ),
			'indexed_users'          => (int) ( $state['indexed_users'] ?? 0 ),
			'failure_count'          => (int) ( $state['failure_count'] ?? 0 ),
			'primary_failure_count'  => (int) ( $state['primary_failure_count'] ?? 0 ),
			'legacy_failure_count'   => (int) ( $state['legacy_failure_count'] ?? 0 ),
			'started_at'             => (int) ( $state['started_at'] ?? 0 ),
			'completed_at'           => (int) ( $state['completed_at'] ?? 0 ),
		);
	}

	public static function primary_ready(): bool {
		if ( ! self::table_available() ) {
			return false;
		}
		return self::state_primary_ready( get_option( self::STATE_OPTION, array() ) );
	}

	/** Ready only for the current generation: no failures, completed rebuild. */
	private static function state_primary_ready( $state ): bool {
		if ( ! is_array( $state ) ) {
			return false;
		}
		if ( (string) ( $state['primary_status'] ?? '' ) !== 'ready' ) {
			return false;
		}
		$generation = (int) get_option( self::GENERATION_OPTION, 0 );
		if ( $generation <= 0 || (int) ( $state['generation'] ?? 0 ) !== $generation ) {
			return false;
		}
		return (int) ( $state['primary_failure_count'] ?? 0 ) === 0 && (int) ( $state['completed_at'] ?? 0 ) > 0;
	}

	public static function legacy_ready(): bool {
		return self::table_available() && (string) ( self::status()['legacy_status'] ?? '' ) === 'ready';
	}

	/** Process one rebuild batch; the generation arg drops stale chains. */
	public static function process_rebuild( $generation = 0, int $attempt = 0 ): void {
		$lock = DatabaseLock::acquire( 'phone-index:rebuild', 0 );
		if ( ! $lock ) {
			self::schedule_rebuild( self::rebuild_backoff( (int) $attempt ), (int) $generation, (int) $attempt + 1 );
			return;
		}
		try {
			self::process_rebuild_locked( (int) $generation, (int) $attempt );
		} finally {
			$lock->release();
		}
	}

	private static function process_rebuild_locked( int $generation, int $attempt ): void {
		global $wpdb;
		$source = Settings::phone_source();
		$state  = get_option( self::STATE_OPTION, array() );
		$state  = is_array( $state ) ? $state : array();

		// Drop stale chains superseded by a newer rebuild generation.
		$current_generation = (int) get_option( self::GENERATION_OPTION, 0 );
		if ( $generation > 0 && $current_generation > 0 && $generation !== $current_generation ) {
			return;
		}

		if ( ( $state['phase'] ?? '' ) === '' || ( $state['legacy_key'] ?? '' ) !== (string) ( $source['key'] ?? '' ) ) {
			$state = self::mark_rebuild_state( $source );
		}
		// A completed-but-not-ready index restarts recovery; counters reset per generation.
		if ( (string) ( $state['phase'] ?? '' ) === 'complete' && ! self::state_primary_ready( $state ) ) {
			$state = self::mark_rebuild_state( $source );
		}
		$phase = (string) ( $state['phase'] ?? 'clear_primary' );

		if ( $phase === 'clear_primary' || $phase === 'clear_legacy' ) {
			$target = $phase === 'clear_primary' ? self::SOURCE_PRIMARY : self::SOURCE_LEGACY;
			$last_clear_id = (int) ( $state['last_clear_id'] ?? 0 );
			$ids = $wpdb->get_col( $wpdb->prepare(
				'SELECT id FROM ' . PEYVAST_AUTH_PHONE_IDENTITY_TABLE . ' WHERE source=%s AND id>%d ORDER BY id ASC LIMIT ' . self::BATCH_SIZE,
				$target,
				$last_clear_id
			) );
			if ( false === $ids ) {
				self::fail_rebuild( $state, $generation, $attempt, 'delete_scan_failed', $target === self::SOURCE_PRIMARY ? 'primary' : '' );
				return;
			}
			if ( $ids ) {
				$ids = array_map( 'intval', $ids );
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$args = array_merge( array( $target ), $ids );
				$deleted = $wpdb->query( $wpdb->prepare(
					'DELETE FROM ' . PEYVAST_AUTH_PHONE_IDENTITY_TABLE . ' WHERE source=%s AND id IN (' . $placeholders . ')',
					$args
				) );
				if ( false === $deleted ) {
					self::fail_rebuild( $state, $generation, $attempt, 'delete_failed', $target === self::SOURCE_PRIMARY ? 'primary' : '' );
					return;
				}
				$state['last_clear_id'] = end( $ids );
				$state['updated_at'] = time();
				update_option( self::STATE_OPTION, $state, false );
				self::schedule_rebuild( 1, $generation, 0, false );
				return;
			}
			$state['last_id'] = 0;
			$state['last_clear_id'] = 0;
			$state['phase']   = $phase === 'clear_primary' ? 'build_primary' : 'build_legacy';
			update_option( self::STATE_OPTION, $state, false );
			$phase = (string) $state['phase'];
		}

		if ( $phase === 'build_primary' ) {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT um.umeta_id, um.user_id, um.meta_value FROM ' . $wpdb->usermeta . ' um WHERE um.meta_key=%s AND um.umeta_id>%d AND NOT EXISTS (SELECT 1 FROM ' . $wpdb->usermeta . ' prior WHERE prior.user_id=um.user_id AND prior.meta_key=um.meta_key AND prior.umeta_id < um.umeta_id) ORDER BY um.umeta_id ASC LIMIT ' . self::BATCH_SIZE . ' FOR UPDATE',
				'_peyvast_auth_phone',
				(int) ( $state['last_meta_id'] ?? 0 )
			) );
			if ( false === $rows ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				$state['primary_status'] = 'not_ready';
				self::fail_rebuild( $state, $generation, $attempt, 'read_failed', 'primary' );
				return;
			}
			if ( $rows ) {
				$batch = array();
				$batch_indexed = 0;
				foreach ( $rows as $row ) {
					$canonical = PhoneNumber::canonical_value( (string) ( $row->meta_value ?? '' ) );
					$batch[] = array( 'user_id' => (int) $row->user_id, 'canonical' => $canonical );
					if ( $canonical !== '' ) { ++$batch_indexed; }
				}
				if ( ! self::replace_batch( $batch, self::SOURCE_PRIMARY ) ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					self::fail_rebuild( $state, $generation, $attempt, 'primary_batch_upsert_failed', 'primary' );
					return;
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					self::fail_rebuild( $state, $generation, $attempt, 'primary_batch_commit_failed', 'primary' );
					return;
				}
				$state['expected_users'] = (int) ( $state['expected_users'] ?? 0 ) + $batch_indexed;
				$state['indexed_users']  = (int) ( $state['indexed_users'] ?? 0 ) + $batch_indexed;
				$state['last_meta_id'] = (int) end( $rows )->umeta_id;
				$state['updated_at'] = time();
				update_option( self::STATE_OPTION, $state, false );
				self::schedule_rebuild( 1, $generation, 0, false );
				return;
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$state['primary_status'] = ( (int) ( $state['indexed_users'] ?? 0 ) === (int) ( $state['expected_users'] ?? 0 ) && (int) ( $state['primary_failure_count'] ?? 0 ) === 0 ) ? 'ready' : 'not_ready';
			$state['completed_at']   = time();
			$state['generation']     = max( 1, (int) ( $state['generation'] ?? 0 ) );
			$state['last_id']        = 0;
			$state['phase']          = 'clear_legacy';
			$state['legacy_status']  = ( ( $source['type'] ?? '' ) === 'user_meta' && ! empty( $source['key'] ) ) ? 'not_ready' : ( ( $source['type'] ?? '' ) === 'user_meta' ? 'needs_configuration' : 'disabled' );
			update_option( self::STATE_OPTION, $state, false );
			self::schedule_rebuild( 1, $generation, 0, false );
			return;
		}

		if ( $phase === 'build_legacy' ) {
			$key = (string) ( $source['key'] ?? '' );
			if ( $key === '' ) {
				$state['legacy_status'] = ( ( $source['type'] ?? '' ) === 'user_meta' ) ? 'needs_configuration' : 'disabled';
				$state['phase']         = 'complete';
				update_option( self::STATE_OPTION, $state, false );
				return;
			}
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT um.umeta_id, um.user_id, um.meta_value FROM ' . $wpdb->usermeta . ' um WHERE um.meta_key=%s AND um.meta_value<>%s AND um.umeta_id>%d AND NOT EXISTS (SELECT 1 FROM ' . $wpdb->usermeta . ' prior WHERE prior.user_id=um.user_id AND prior.meta_key=um.meta_key AND prior.umeta_id < um.umeta_id) ORDER BY um.umeta_id ASC LIMIT ' . self::BATCH_SIZE . ' FOR UPDATE', $key, '', (int) ( $state['last_meta_id'] ?? 0 ) ) );
			if ( false === $rows ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				$state['legacy_status'] = 'not_ready';
				self::fail_rebuild( $state, $generation, $attempt, 'legacy_read_failed', 'legacy' );
				return;
			}
			if ( $rows ) {
				$batch = array();
				foreach ( $rows as $row ) {
					$batch[] = array(
						'user_id'  => (int) $row->user_id,
						'canonical' => PhoneNumber::canonical_value( (string) $row->meta_value ),
					);
				}
				if ( ! self::replace_batch( $batch, self::SOURCE_LEGACY ) ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					self::fail_rebuild( $state, $generation, $attempt, 'legacy_batch_upsert_failed', 'legacy' );
					return;
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					self::fail_rebuild( $state, $generation, $attempt, 'legacy_batch_commit_failed', 'legacy' );
					return;
				}
				$state['last_meta_id'] = (int) end( $rows )->umeta_id;
				$state['updated_at'] = time();
				update_option( self::STATE_OPTION, $state, false );
				self::schedule_rebuild( 1, $generation, 0, false );
				return;
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$state['legacy_status'] = (int) ( $state['legacy_failure_count'] ?? 0 ) === 0 ? 'ready' : 'not_ready';
			$state['phase']         = 'complete';
			$state['updated_at']    = time();
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	private static function mark_rebuild_state( array $source ): array {
		$legacy_enabled = ( $source['type'] ?? '' ) === 'user_meta';
		$generation     = (int) get_option( self::GENERATION_OPTION, 0 ) + 1;
		$state          = array(
			'phase'                 => 'clear_primary',
			'primary_status'        => 'not_ready',
			'legacy_status'         => $legacy_enabled && ! empty( $source['key'] ) ? 'not_ready' : ( $legacy_enabled ? 'needs_configuration' : 'disabled' ),
			'legacy_key'            => (string) ( $source['key'] ?? '' ),
			'last_id'               => 0,
			'last_meta_id'          => 0,
			'last_clear_id'         => 0,
			'updated_at'            => time(),
			'started_at'            => time(),
			'completed_at'          => 0,
			'expected_users'        => 0,
			'indexed_users'         => 0,
			'failure_count'         => 0,
			'primary_failure_count' => 0,
			'legacy_failure_count'  => 0,
			'generation'            => $generation,
		);
		update_option( self::STATE_OPTION, $state, false );
		update_option( self::GENERATION_OPTION, $generation, false );
		return $state;
	}


	private static function record_live_failure( string $source ): void {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['phase'] ) || empty( $state['generation'] ) ) {
			// No usable state: start a rebuild rather than inventing counters.
			self::mark_rebuild();
			return;
		}
		$state['failure_count'] = (int) ( $state['failure_count'] ?? 0 ) + 1;
		if ( $source === self::SOURCE_PRIMARY ) {
			$state['primary_failure_count'] = (int) ( $state['primary_failure_count'] ?? 0 ) + 1;
			$state['primary_status']        = 'not_ready';
		} else {
			$state['legacy_failure_count'] = (int) ( $state['legacy_failure_count'] ?? 0 ) + 1;
			if ( ( $state['legacy_status'] ?? '' ) === 'ready' ) {
				$state['legacy_status'] = 'not_ready';
			}
		}
		$state['updated_at'] = time();
		update_option( self::STATE_OPTION, $state, false );
		if ( $source === self::SOURCE_PRIMARY ) {
			// Ensure a rebuild is scheduled; a transient failure must not strand the index.
			self::schedule_rebuild();
		}
	}

	private static function record_live_success( string $source ): void {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			return;
		}
		if ( ( $state['phase'] ?? '' ) === 'complete' && (int) ( $state['primary_failure_count'] ?? 0 ) === 0 && $source === self::SOURCE_PRIMARY ) {
			// A live sync already proves the row was written; rebuild progress belongs to workers.
			$state['primary_status'] = 'ready';
			$state['completed_at']   = (int) ( $state['completed_at'] ?? time() );
			$state['updated_at']     = time();
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	/** Exponential backoff capped at 10 minutes: 5s, 10s, 20s, 40s, ... */
	private static function rebuild_backoff( int $attempt ): int {
		return min( self::REBUILD_RETRY_CAP_SEC, self::REBUILD_RETRY_BASE_SEC * ( 2 ** max( 0, $attempt ) ) );
	}

	/** Schedule one rebuild batch; continuations stay non-unique, serialized by lock. */
	private static function schedule_rebuild( int $delay = 1, int $generation = 0, int $attempt = 0, bool $unique = true ): bool {
		if ( $generation <= 0 ) $generation = (int) get_option( self::GENERATION_OPTION, 0 );
		$scheduled = Scheduler::schedule_single( Scheduler::HOOK_PHONE_INDEX_REBUILD, array( $generation, $attempt ), max( 1, $delay ), $unique ) > 0;
		if ( ! $scheduled ) {
			Logger::error( 'migration', 'phone_index_schedule_failed', 'Phone identity index rebuild could not be scheduled.', array( 'generation' => $generation, 'attempt' => $attempt ) );
		}
		return $scheduled;
	}

	/**
	 * Record a rebuild failure, schedule a bounded retry with exponential
	 * backoff, or (after MAX_REBUILD_ATTEMPTS) stop and rely on boot-time
	 * recovery to re-arm the chain.
	 */
	private static function fail_rebuild( array $state, int $generation, int $attempt, string $error, string $dim = '' ): void {
		$state['failure_count'] = (int) ( $state['failure_count'] ?? 0 ) + 1;
		if ( $dim === 'primary' ) {
			$state['primary_failure_count'] = (int) ( $state['primary_failure_count'] ?? 0 ) + 1;
			$state['primary_status']        = 'not_ready';
		} elseif ( $dim === 'legacy' ) {
			$state['legacy_failure_count'] = (int) ( $state['legacy_failure_count'] ?? 0 ) + 1;
			$state['legacy_status']        = 'not_ready';
		}
		$state['error']      = $error;
		$state['updated_at'] = time();
		update_option( self::STATE_OPTION, $state, false );
		if ( $attempt < self::MAX_REBUILD_ATTEMPTS ) {
			self::schedule_rebuild( self::rebuild_backoff( $attempt ), $generation, $attempt + 1 );
			return;
		}
		Logger::error(
			'identity',
			'identity_index_rebuild_stopped',
			'Phone identity index rebuild stopped after repeated failures; it will be re-armed on the next request.',
			array(
				'error'    => $error,
				'attempts' => $attempt + 1,
			)
		);
	}

	private static function table_available(): bool {
		global $wpdb;
		static $available = null;
		if ( true === $available ) {
			return true;
		}
		$available = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', PEYVAST_AUTH_PHONE_IDENTITY_TABLE ) ) === PEYVAST_AUTH_PHONE_IDENTITY_TABLE;
		if ( ! $available ) {
			static $repair_attempted = false;
			// Repair is attempted at most once per request; a negative result is never cached as truth.
			if ( ! $repair_attempted ) {
				$repair_attempted = true;
				if ( \Peyvast\Auth\Infrastructure\WordPress\Installer::ensure_schema() ) {
					$available = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', PEYVAST_AUTH_PHONE_IDENTITY_TABLE ) ) === PEYVAST_AUTH_PHONE_IDENTITY_TABLE;
				}
			}
		}
		return $available;
	}

	public static function on_meta_change( $meta_id, $user_id, $meta_key, $meta_value ): void {
		$user_id  = (int) $user_id;
		$meta_key = (string) $meta_key;
		if ( $meta_key === '_peyvast_auth_phone' ) {
			self::sync_primary( $user_id );
			return;
		}
		$source = Settings::phone_source();
		if ( ( $source['type'] ?? '' ) === 'user_meta' && ! empty( $source['key'] ) && $meta_key === (string) $source['key'] ) {
			$user = get_user_by( 'id', $user_id );
			if ( $user instanceof \WP_User ) {
				self::sync_legacy( $user );
			}
		}
	}

	public static function delete_user( int $user_id ): void {
		global $wpdb;
		if ( $user_id <= 0 || ! self::table_available() ) {
			return;
		}
		$wpdb->delete( PEYVAST_AUTH_PHONE_IDENTITY_TABLE, array( 'user_id' => $user_id ), array( '%d' ) );
	}

	public static function on_deleted_meta( $meta_ids, $user_id, $meta_key, $meta_value ): void {
		try {
			self::sync_deleted_meta( $meta_ids, $user_id, $meta_key, $meta_value );
		} catch ( \Throwable $e ) {
			// Index cleanup must never make authoritative metadata deletion fatal.
			self::record_live_failure( self::SOURCE_PRIMARY );
		}
	}

	private static function sync_deleted_meta( $meta_ids, $user_id, $meta_key, $meta_value ): void {
		$user_id = (int) $user_id;
		if ( (string) $meta_key === '_peyvast_auth_phone' ) {
			self::replace( $user_id, self::SOURCE_PRIMARY, '' );
		}
		$source = Settings::phone_source();
		if ( ( $source['type'] ?? '' ) === 'user_meta' && ! empty( $source['key'] ) && (string) $meta_key === (string) $source['key'] ) {
			self::replace( $user_id, self::SOURCE_LEGACY, '' );
		}
	}
}
