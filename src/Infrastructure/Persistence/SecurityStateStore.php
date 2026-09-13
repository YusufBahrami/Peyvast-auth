<?php
namespace Peyvast\Auth\Infrastructure\Persistence;

use Peyvast\Auth\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/** Authoritative security state (DB); the cache replica is status-only. */
final class SecurityStateStore {

	public const ALGO_TOKEN_BUCKET = 1;
	public const ALGO_FIXED_WINDOW = 2;

	public const CACHE_GROUP = 'peyvast_auth_security';

	/** Fixed lock order; never reorder. */
	public const DIM_ORDER = array( 'ip', 'network', 'identifier', 'combination' );

	private const GEN_ROW = '__generation__';

	/** @var array<string,bool> per-request memo for read-only bucket lookups. */
	private static array $read_memo = array();

	private static bool $generation_loaded = false;
	private static int $generation         = 1;

	private static function table(): string {
		return PEYVAST_AUTH_SECURITY_TABLE;
	}

	/** HMAC-keyed bucket key; raw identifiers are never stored. */
	public static function bucket_key( string $scope, string $dim, string $value ): string {
		$secret = (string) get_option( 'peyvast_auth_identifier_key', wp_salt( 'auth' ) );
		return sha1( hash_hmac( 'sha256', $scope . '|' . $dim . '|' . $value, $secret ) );
	}

	/** Evaluate one guarded request in a single short transaction. */
	public static function evaluate( string $scope, array $buckets ): array {
		global $wpdb;
		if ( ! self::begin_transaction() ) {
			return self::denied_all( false, array(), 'storage_unavailable', 'begin_transaction', 'query' );
		}

		try {
			$rows     = array();
			$verdicts = array();

			foreach ( self::DIM_ORDER as $dim ) {
				$entry = self::find_dimension( $buckets, $dim );
				if ( ! $entry ) {
					continue;
				}
				$row = self::lock_row( $wpdb, self::bucket_key( $scope, $dim, $entry['value'] ), (int) $entry['algo'], (int) $entry['limit'], (int) $entry['window'] );
				if ( $row === null ) {
					$operation = ! empty( $wpdb->last_error ) ? 'lock_row_query' : 'lock_row_insert';
					self::rollback_transaction();
					return self::denied_all( false, array(), 'storage_unavailable', $operation, ! empty( $wpdb->last_error ) ? 'query' : 'duplicate_key' );
				}
				$rows[ $dim ] = $row;

				// Active block: no mutations.
				if ( ! empty( $row['blocked_until'] ) && (int) $row['blocked_until'] > time() ) {
					if ( ! self::commit_transaction() ) {
						self::rollback_transaction();
						return self::denied_all( false, array(), 'storage_unavailable', 'commit_transaction', 'query' );
					}
					return array(
						'allowed'  => false,
						'blocked'  => true,
						'verdicts' => array(),
						'rows'     => $rows,
					);
				}
			}

			$now     = time();
			$updates = array();
			foreach ( $rows as $dim => $row ) {
				$entry  = self::find_dimension( $buckets, $dim );
				$algo   = (int) $entry['algo'];
				$limit  = max( 1, (int) $entry['limit'] );
				$window = max( 1, (int) $entry['window'] );

				if ( $algo === self::ALGO_TOKEN_BUCKET ) {
					$refill_rate           = $limit / $window;
					$refilled              = min( (float) $row['tokens'] + max( 0, $now - (int) $row['last_refill_at'] ) * $refill_rate, (float) $limit );
					$verdicts[ $dim ]      = $refilled >= 1;
					$row['refilled']       = $refilled;
					$row['tokens']         = $verdicts[ $dim ] ? $refilled - 1 : $refilled;
					$row['last_refill_at'] = $now;
					$rows[ $dim ]          = $row;
					continue;
				}

				$expired                  = ( (int) $row['window_started_at'] + $window ) <= $now;
				$count                    = $expired ? 1 : min( (int) $row['count'] + 1, $limit + 1 );
				$verdicts[ $dim ]         = $count <= $limit;
				$row['count']             = $count;
				$row['window_started_at'] = $expired ? $now : (int) $row['window_started_at'];
				$rows[ $dim ]             = $row;
			}

			// No buckets locked (defensive): allow.
			$allowed = $verdicts === array() || ! in_array( false, $verdicts, true );

			// Denials persist only the rejecting dimensions.
			foreach ( $rows as $dim => $row ) {
				$dim_allowed = $verdicts[ $dim ] ?? false;
				if ( $allowed && $dim_allowed ) {
					$updates[] = self::update_statement( $row, true );
				} elseif ( ! $allowed && ! $dim_allowed ) {
					$updates[] = self::update_statement( $row, false );
				}
			}
			foreach ( $updates as $statement ) {
				if ( $statement[0] !== '' ) {
					$updated = $wpdb->query( $wpdb->prepare( $statement[0], $statement[1] ) );
				if ( $updated === false ) {
					self::rollback_transaction();
					return self::denied_all( false, array(), 'storage_unavailable', 'state_update', 'query' );
				}
				}
			}
			if ( ! self::commit_transaction() ) {
				self::rollback_transaction();
				return self::denied_all( false, array(), 'storage_unavailable', 'commit_transaction', 'query' );
			}
		} catch ( \Throwable $e ) {
			self::rollback_transaction();
			return self::denied_all( false, array(), 'storage_unavailable', 'evaluate_exception', 'exception' );
		}

		return array(
			'allowed'  => $allowed,
			'blocked'  => false,
			'verdicts' => $verdicts,
			'rows'     => $rows,
		);
	}

	/** Read-only precheck; never authoritative for a denial. */
	public static function precheck( string $scope, string $dim, string $id ) {
		global $wpdb;
		$bucket = self::bucket_key( $scope, $dim, $id );
		if ( array_key_exists( $bucket, self::$read_memo ) ) {
			return self::$read_memo[ $bucket ];
		}
		$row                        = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE bucket_key = %s', $bucket ) );
		self::$read_memo[ $bucket ] = $row ? (array) $row : null;
		return self::$read_memo[ $bucket ];
	}

	/** Record a confirmed failure (password failures, invalid identifiers). */
	public static function record_failure( string $scope, array $buckets, array $progressive = array() ): bool {
		global $wpdb;
		if ( ! self::do_transaction() ) {
			return false;
		}
		try {
			$now = time();
			$progressive_enabled = ! empty( $progressive['enabled'] );
			$initial = max( 1, (int) ( $progressive['initial_duration'] ?? 60 ) );
			$multiplier = max( 1, (int) ( $progressive['multiplier'] ?? 5 ) );
			$max_duration = max( $initial, (int) ( $progressive['max_duration'] ?? 3600 ) );
			$decay = max( 1, (int) ( $progressive['decay_seconds'] ?? 3600 ) );
			foreach ( self::DIM_ORDER as $dim ) {
				$entry = self::find_dimension( $buckets, $dim );
				if ( ! $entry ) continue;
				$algo   = (int) $entry['algo'];
				$limit  = max( 1, (int) $entry['limit'] );
				$window = max( 1, (int) $entry['window'] );
				$bucket = self::bucket_key( $scope, $dim, $entry['value'] );
				$row    = self::lock_row( $wpdb, $bucket, $algo, $limit, $window );
				if ( ! $row ) { self::rollback_transaction(); return false; }
				if ( ! empty( $row['blocked_until'] ) && (int) $row['blocked_until'] > $now ) continue;

				$threshold = false;
				if ( $algo === self::ALGO_TOKEN_BUCKET ) {
					$refill_rate = $limit / $window;
					$refilled = min( (float) $row['tokens'] + max( 0, $now - (int) $row['last_refill_at'] ) * $refill_rate, (float) $limit );
					$tokens = max( 0, $refilled - 1 );
					$threshold = $tokens <= 0;
					$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET tokens=%f,last_refill_at=%d,updated_at=%d,version=version+1 WHERE bucket_key=%s', $tokens, $now, $now, $bucket ) );
				} else {
					$expired = ( (int) $row['window_started_at'] + $window ) <= $now;
					$count = $expired ? 1 : min( (int) $row['count'] + 1, $limit + 1 );
					$threshold = $count >= $limit;
					$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET count=%d,window_started_at=%d,updated_at=%d,version=version+1 WHERE bucket_key=%s', $count, $expired ? $now : (int) $row['window_started_at'], $now, $bucket ) );
				}
				if ( false === $updated ) { self::rollback_transaction(); return false; }
				unset( self::$read_memo[ $bucket ] );

				if ( $progressive_enabled && $threshold ) {
					$prev_end = (int) ( $row['blocked_until'] ?? 0 );
					$past_clean = $prev_end > 0 && ( $now - $prev_end ) >= $decay;
					$stage = $past_clean ? 1 : max( 1, (int) ( $row['stage'] ?? 0 ) + 1 );
					$duration = min( $max_duration, (int) ( $initial * pow( $multiplier, max( 0, $stage - 1 ) ) ) );
					$token_hash = null;
					if ( $dim === 'ip' ) {
						$token_hash = hash( 'sha256', bin2hex( random_bytes( 32 ) ) );
					}
					$blocked_until = $now + $duration;
					$block_update = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET blocked_until=%d,stage=%d,token_hash=COALESCE(token_hash,%s),updated_at=%d,version=version+1 WHERE bucket_key=%s', $blocked_until, $stage, $token_hash ?: '', $now, $bucket ) );
					if ( false === $block_update ) { self::rollback_transaction(); return false; }
				}
			}
			if ( ! self::commit_transaction() ) { self::rollback_transaction(); return false; }
			return true;
		} catch ( \Throwable $e ) {
			self::rollback_transaction();
			return false;
		}
	}

	/** Version-guarded success reset; never erases events recorded after the decision. */
	public static function reset_success( array $snapshots ): bool {
		global $wpdb;
		if ( ! $snapshots ) {
			return true;
		}
		if ( ! self::do_transaction() ) {
			return false;
		}
		try {
			foreach ( $snapshots as $snapshot ) {
				$bucket  = (string) ( $snapshot['bucket'] ?? '' );
				$version = (int) ( $snapshot['version'] ?? 0 );
				if ( $bucket === '' ) {
					continue;
				}
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT version FROM ' . self::table() . ' WHERE bucket_key = %s FOR UPDATE', $bucket ) );
				if ( ! $row ) {
					continue;
				}
				$now     = time();
				$updated = $wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . self::table() . ' SET
                       tokens = capacity, last_refill_at = %d,
                       count = 0, window_started_at = %d,
                       updated_at = %d, version = version + 1
                     WHERE bucket_key = %s AND version = %d',
						$now,
						$now,
						$now,
						$bucket,
						$version
					)
				);
				if ( $updated === false ) {
					self::rollback_transaction();
					return false;
				}
				unset( self::$read_memo[ $bucket ] );
			}
			if ( ! self::commit_transaction() ) {
				self::rollback_transaction();
				return false;
			}
			return true;
		} catch ( \Throwable $e ) {
			self::rollback_transaction();
			return false;
		}
	}

	/** Atomic no-downgrade block write; concurrent writers cannot shorten a block. */
	public static function apply_block( string $bucket, int $blocked_until, int $stage, $token_hash ): void {
		global $wpdb;
		$now    = time();
		$hash   = $token_hash !== null ? $token_hash : '';
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (bucket_key, scope, algo, blocked_until, stage, token_hash, created_at, updated_at, version)
             VALUES (%s, %s, %d, %d, %d, %s, %d, %d, 1)
             ON DUPLICATE KEY UPDATE
               blocked_until = GREATEST(IFNULL(blocked_until, 0), %d),
               stage = GREATEST(stage, %d),
               token_hash = IFNULL(token_hash, %s),
               updated_at = %d,
               version = version + 1',
				$bucket,
				'',
				self::ALGO_FIXED_WINDOW,
				$blocked_until,
				$stage,
				$hash,
				$now,
				$now,
				$blocked_until,
				$stage,
				$hash,
				$now
			)
		);
		if ( $result === false ) {
			Logger::error(
				'security',
				'security_state_unavailable',
				'Security state block write failed.',
				array(
					'operation'   => 'apply_block',
					'error_class' => 'database_write',
				)
			);
		}
	}

	public static function read_block( string $bucket ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT blocked_until, stage, token_hash FROM ' . self::table() . ' WHERE bucket_key = %s', $bucket ) );
		return $row ? (array) $row : null;
	}

	/** Atomic generation bump and cache replica refresh. */
	public static function bump_generation(): int {
		global $wpdb;
		$now = time();
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (bucket_key, scope, algo, count, created_at, updated_at, version)
             VALUES (%s, %s, %d, 1, %d, %d, 1)
             ON DUPLICATE KEY UPDATE count = count + 1, updated_at = %d, version = version + 1',
				self::GEN_ROW,
				'generation',
				self::ALGO_FIXED_WINDOW,
				$now,
				$now,
				$now
			)
		);
		$row                     = $wpdb->get_var( $wpdb->prepare( 'SELECT count FROM ' . self::table() . ' WHERE bucket_key = %s', self::GEN_ROW ) );
		self::$generation        = max( 1, (int) $row );
		self::$generation_loaded = true;
		wp_cache_set( self::cache_key( 'generation' ), (string) self::$generation, self::CACHE_GROUP, DAY_IN_SECONDS );
		return self::$generation;
	}

	/** Blog-id scope for shared object caches so sites never share status. */
	private static function cache_site(): string {
		static $site = null;
		if ( $site === null ) {
			$site = (string) get_current_blog_id();
		}
		return $site;
	}

	private static function cache_key( string $name ): string {
		return self::cache_site() . ':' . $name;
	}

	/* Status fast path */

	public static function generation(): int {
		if ( self::$generation_loaded ) {
			return self::$generation;
		}
		global $wpdb;
		$cached = wp_cache_get( self::cache_key( 'generation' ), self::CACHE_GROUP );
		if ( $cached !== false ) {
			self::$generation        = max( 1, (int) $cached );
			self::$generation_loaded = true;
			return self::$generation;
		}
		$row                     = $wpdb->get_row( $wpdb->prepare( 'SELECT count FROM ' . self::table() . ' WHERE bucket_key = %s', self::GEN_ROW ) );
		self::$generation        = $row ? (int) $row->count : 1;
		self::$generation_loaded = true;
		wp_cache_set( self::cache_key( 'generation' ), (string) self::$generation, self::CACHE_GROUP, DAY_IN_SECONDS );
		return self::$generation;
	}

	/** Positive marker; TTL ≤ remaining block lifetime. */
	public static function set_status_ip_blocked( string $identity, int $blocked_until ): void {
		$remaining = (int) $blocked_until - time();
		if ( $remaining <= 0 ) {
			return;
		}
		$gen = self::generation();
		wp_cache_set( 'status:' . self::cache_site() . ':' . $gen . ':' . sha1( $identity ), '1', self::CACHE_GROUP, min( 300, $remaining ) );
	}

	/** Admin reset: clear all blocks and counters, keep rows and schema. */
	public static function clear_all_blocks(): int {
		global $wpdb;
		$gen = self::bump_generation();
		$now = time();
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . '
                 SET blocked_until = NULL,
                     token_hash = NULL,
                     stage = 0,
                     tokens = capacity,
                     last_refill_at = %d,
                     count = 0,
                     window_started_at = 0,
                     updated_at = %d,
                     version = version + 1
                 WHERE bucket_key <> %s',
				$now,
				$now,
				self::GEN_ROW
			)
		);
		self::$read_memo = array();
		return $gen;
	}

	/** Bounded batched purge of stale rows; keeps the generation row. */
	public static function purge(): void {
		global $wpdb;
		$cutoff = time() - DAY_IN_SECONDS * 7;
		$table  = self::table();
		for ( $i = 0; $i < 20; $i++ ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . $table . ' WHERE (blocked_until IS NOT NULL AND blocked_until < %d) OR (updated_at < %d AND bucket_key <> %s) LIMIT 200',
					$cutoff,
					$cutoff,
					self::GEN_ROW
				)
			);
			if ( ! $deleted || $deleted < 200 ) {
				break;
			}
		}
	}

	/* Internal helpers */

	private static function begin_transaction(): bool {
		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return false !== $result && $wpdb->last_error === '';
	}

	private static function do_transaction(): bool {
		return self::begin_transaction();
	}

	private static function commit_transaction(): bool {
		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return false !== $result && $wpdb->last_error === '';
	}

	private static function rollback_transaction(): bool {
		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return false !== $result && $wpdb->last_error === '';
	}

	private static function find_dimension( array $buckets, string $dim ) {
		foreach ( $buckets as $bucket ) {
			if ( (string) ( $bucket['dim'] ?? '' ) === $dim ) {
				return $bucket;
			}
		}
		return null;
	}

	private static function lock_row( $wpdb, string $bucket, int $algo, int $capacity, int $window ): ?array {
		// One retry for the duplicate-key race; never loop on storage failure.
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$wpdb->last_error = '';
			$row              = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE bucket_key = %s FOR UPDATE', $bucket ) );
			if ( $row ) {
				return (array) $row;
			}
			if ( ! empty( $wpdb->last_error ) ) {
				return null;
			}
			$now              = time();
			$wpdb->last_error = '';
			$inserted         = $wpdb->insert(
				self::table(),
				array(
					'bucket_key'        => $bucket,
					'scope'             => '',
					'algo'              => $algo,
					'tokens'            => $algo === self::ALGO_TOKEN_BUCKET ? $capacity : 0,
					'capacity'          => $capacity,
					'last_refill_at'    => $algo === self::ALGO_TOKEN_BUCKET ? $now : 0,
					'count'             => 0,
					'window_started_at' => 0,
					'created_at'        => $now,
					'updated_at'        => $now,
				),
				array( '%s', '%s', '%d', '%f', '%d', '%d', '%d', '%d', '%d', '%d' )
			);

			if ( $inserted ) {
				$wpdb->last_error = '';
				$row              = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE bucket_key = %s FOR UPDATE', $bucket ) );
				if ( ! $row && ! empty( $wpdb->last_error ) ) {
					return null;
				}
				return $row ? (array) $row : null;
			}
			if ( ! empty( $wpdb->last_error ) ) {
				$duplicate = stripos( (string) $wpdb->last_error, 'duplicate' ) !== false || stripos( (string) $wpdb->last_error, '1062' ) !== false;
				if ( ! $duplicate ) {
					return null;
				}
			}
			// Duplicate-key race: re-lock the winner's row.
		}
		return null;
	}

	/** UPDATE statement (+params) for a committed row state. */
	private static function update_statement( array $row, bool $allowed ): array {
		$bucket = (string) $row['bucket_key'];
		$now    = time();

		if ( (int) $row['algo'] === self::ALGO_TOKEN_BUCKET ) {
			if ( ! $allowed ) {
				// Zero-write denied token buckets: state already represents the denial.
				return array( '', array() );
			}
			return array(
				'UPDATE ' . self::table() . ' SET tokens = %f, last_refill_at = %d, updated_at = %d, version = version + 1 WHERE bucket_key = %s',
				array( $row['tokens'], $row['last_refill_at'], $now, $bucket ),
			);
		}

		// FIXED_WINDOW: both allowed and the bounded rejection persist.
		return array(
			'UPDATE ' . self::table() . ' SET count = %d, window_started_at = %d, updated_at = %d, version = version + 1 WHERE bucket_key = %s',
			array( $row['count'], $row['window_started_at'], $now, $bucket ),
		);
	}

	private static function denied_all( bool $blocked, array $rows, string $code = 'security_denied', string $operation = '', string $error_category = '' ): array {
		return array(
			'allowed'        => false,
			'blocked'        => $blocked,
			'verdicts'       => array(),
			'rows'           => $rows,
			'code'           => $code,
			'operation'      => $operation,
			'error_category' => $error_category,
		);
	}
}
