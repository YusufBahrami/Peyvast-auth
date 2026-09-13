<?php
namespace Peyvast\Auth\Infrastructure\WordPress;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Scheduling\Scheduler;

defined( 'ABSPATH' ) || exit;

final class Installer {
	private const HEALTH_OPTION = 'peyvast_auth_schema_health';
	private const HEALTH_TTL = HOUR_IN_SECONDS;

	/**
	 * Whether the active database engine supports the plugin's locking model.
	 * Detected at runtime; WordPress Studio's SQLite layer is not supported.
	 */
	public static function database_supported(): bool {
		static $supported = null;
		if ( $supported !== null ) {
			return $supported;
		}
		global $wpdb;
		if ( class_exists( 'WP_MySQL_On_SQLite' ) ) {
			$supported = false;
			return $supported;
		}
		$info    = strtolower( (string) $wpdb->db_server_info() );
		$supported = strpos( $info, 'maria' ) !== false
			|| preg_match( '/^(5\.[567]|8\.\d|10\.\d|11\.\d|\d{2}\.\d)/', $info ) === 1
			|| $wpdb->dbh instanceof \mysqli;
		return $supported;
	}

	/** Admin notice when running on a non-MySQL engine (Studio SQLite dev sites). */
	public static function register_notices(): void {
		if ( self::database_supported() ) {
			return;
		}
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Peyvast Auth is running on SQLite (WordPress Studio compatibility layer). MySQL or MariaDB is required for production; authentication delivery requires the encrypted OTP delivery store.', 'peyvast-auth' ) . '</p></div>';
			}
		);
	}

	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() || $network_wide ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: plugin version. */
						__( 'Peyvast Auth %s does not support WordPress Multisite. Activate it only on a single-site WordPress installation.', 'peyvast-auth' ),
						PEYVAST_AUTH_VERSION
					)
				),
				esc_html__( 'Peyvast Auth activation not supported', 'peyvast-auth' ),
				array( 'response' => 500 )
			);
		}
		self::create_tables();
		if ( ! self::schema_ready() ) {
			wp_die(
				esc_html__( 'Peyvast Auth could not verify its database schema. Activation has been stopped until all required tables, columns and indexes are available.', 'peyvast-auth' ),
				esc_html__( 'Peyvast Auth activation failed', 'peyvast-auth' ),
				array( 'response' => 500 )
			);
		}
		Settings::install_defaults();
		if ( ! get_option( 'peyvast_auth_identifier_key' ) ) {
			add_option( 'peyvast_auth_identifier_key', wp_generate_password( 64, true, true ), '', false );
		}
		// Activation may run before Action Scheduler initializes: persist the rebuild state
		// here and let Scheduler::boot() enqueue from action_scheduler_init.
		update_option( self::HEALTH_OPTION, array( 'status' => 'ready', 'checked_at' => time() ), false );
		\Peyvast\Auth\Infrastructure\Persistence\PhoneIdentityIndex::mark_rebuild( false );
	}

	/** Repair/verify the plugin schema on demand. Used only after a runtime health failure. */
	public static function ensure_schema(): bool {
		self::create_tables();
		return self::schema_ready();
	}

	public static function watchdog(): void {
		self::create_tables();
		$ready = self::schema_ready();
		update_option( self::HEALTH_OPTION, array( 'status' => $ready ? 'ready' : 'repairable', 'checked_at' => time() ), false );
	}
	public static function schema_ready(): bool {
		global $wpdb;
		$schemas = array(
			PEYVAST_AUTH_OTP_TABLE            => array(
				'columns' => array( 'id', 'challenge_id', 'identifier_type', 'identifier_hash', 'identifier_value', 'user_id', 'purpose', 'channels', 'otp_hash', 'expires_at', 'resend_available_at', 'attempts', 'verified_at', 'consumed_at', 'processing_at', 'guest_session_hash', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'challenge_id', 'identifier_hash', 'identifier_purpose_state', 'otp_token_state', 'user_id', 'expires_at', 'purpose', 'guest_session_hash', 'processing_at', 'updated_at' ),
			),
			PEYVAST_AUTH_PHONE_IDENTITY_TABLE => array(
				'columns' => array( 'id', 'user_id', 'source', 'canonical_hash', 'canonical_phone', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'user_source', 'canonical_hash', 'source_canonical_hash', 'user_id' ),
			),
			PEYVAST_AUTH_LOG_TABLE            => array(
				'columns' => array( 'id', 'level', 'channel', 'event', 'provider', 'user_id', 'request_id', 'message', 'context', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'level', 'channel', 'event', 'provider', 'user_id', 'request_id', 'created_at', 'updated_at' ),
			),
			PEYVAST_AUTH_SECURITY_TABLE       => array(
				'columns' => array( 'bucket_key', 'scope', 'algo', 'tokens', 'capacity', 'last_refill_at', 'count', 'window_started_at', 'stage', 'blocked_until', 'token_hash', 'version', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'blocked_until', 'updated_at', 'token_hash' ),
			),
			PEYVAST_AUTH_OTP_DELIVERY_TABLE  => array(
				'columns' => array( 'challenge_id', 'payload', 'iv', 'tag', 'attempts', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'updated_at' ),
			),
		);

		foreach ( $schemas as $table => $expected ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				return false;
			}
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ) );
			if ( ! $status || strtolower( (string) ( $status->Engine ?? '' ) ) !== 'innodb' ) return false;
			$columns = $wpdb->get_col( 'DESCRIBE ' . $table );
			if ( ! $columns || array_diff( $expected['columns'], $columns ) ) {
				return false;
			}
			$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . $table );
			if ( ! $indexes ) {
				return false;
			}
			$names = array();
			foreach ( $indexes as $index ) {
				$names[] = (string) $index->Key_name;
			}
			if ( array_diff( $expected['indexes'], array_unique( $names ) ) ) {
				return false;
			}
		}
		return true;
	}

	public static function deactivate(): void {
		// Plugin data is never deleted on deactivation.
		Scheduler::deactivate();
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$otp_table = PEYVAST_AUTH_OTP_TABLE;
		dbDelta(
			"CREATE TABLE {$otp_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            challenge_id char(36) NOT NULL,
            identifier_type varchar(20) NOT NULL,
            identifier_hash char(64) NOT NULL,
            identifier_value varchar(191) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            purpose varchar(32) NOT NULL,
            channels varchar(190) NOT NULL DEFAULT '',
            otp_hash char(64) NOT NULL,
            expires_at datetime NOT NULL,
            resend_available_at datetime NOT NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            verified_at datetime NULL,
            consumed_at datetime NULL,
            processing_at datetime NULL,
            guest_session_hash char(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY challenge_id (challenge_id),
            KEY identifier_hash (identifier_hash),
            KEY identifier_purpose_state (identifier_hash, purpose, consumed_at, verified_at, expires_at),
            KEY otp_token_state (otp_hash, purpose, verified_at, consumed_at),
            KEY user_id (user_id),
            KEY expires_at (expires_at),
            KEY purpose (purpose),
            KEY guest_session_hash (guest_session_hash),
            KEY processing_at (processing_at),
            KEY updated_at (updated_at)
        ) ENGINE=InnoDB {$charset};"
		);

		$identity_table = PEYVAST_AUTH_PHONE_IDENTITY_TABLE;
		dbDelta(
			"CREATE TABLE {$identity_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            source varchar(32) NOT NULL,
            canonical_hash char(64) NOT NULL,
            canonical_phone varchar(20) NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_source (user_id, source),
            KEY canonical_hash (canonical_hash),
            KEY source_canonical_hash (source, canonical_hash),
            KEY user_id (user_id)
        ) ENGINE=InnoDB {$charset};"
		);

		$log_table = PEYVAST_AUTH_LOG_TABLE;
		dbDelta(
			"CREATE TABLE {$log_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(16) NOT NULL,
            channel varchar(32) NOT NULL DEFAULT '',
            event varchar(64) NOT NULL DEFAULT '',
            provider varchar(64) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            request_id varchar(64) NOT NULL DEFAULT '',
            message text NOT NULL,
            context longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
            PRIMARY KEY  (id),
            KEY level (level),
            KEY channel (channel),
            KEY event (event),
            KEY provider (provider),
            KEY user_id (user_id),
            KEY request_id (request_id),
            KEY created_at (created_at),
            KEY updated_at (updated_at)
        ) ENGINE=InnoDB {$charset};"
		);

		$security_table = PEYVAST_AUTH_SECURITY_TABLE;
		dbDelta(
			"CREATE TABLE {$security_table} (
            bucket_key char(40) NOT NULL,
            scope varchar(32) NOT NULL DEFAULT '',
            algo tinyint(1) NOT NULL DEFAULT 1,
            tokens double NOT NULL DEFAULT 0,
            capacity smallint(5) unsigned NOT NULL DEFAULT 0,
            last_refill_at bigint(20) NOT NULL DEFAULT 0,
            count bigint(20) unsigned NOT NULL DEFAULT 0,
            window_started_at bigint(20) NOT NULL DEFAULT 0,
            stage smallint(5) unsigned NOT NULL DEFAULT 0,
            blocked_until bigint(20) NULL,
            token_hash char(64) NULL,
            version bigint(20) NOT NULL DEFAULT 0,
            created_at bigint(20) NOT NULL DEFAULT 0,
            updated_at bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY  (bucket_key),
            KEY blocked_until (blocked_until),
            KEY updated_at (updated_at),
            KEY token_hash (token_hash)
        ) ENGINE=InnoDB {$charset};"
		);
		$delivery_table = PEYVAST_AUTH_OTP_DELIVERY_TABLE;
		dbDelta(
			"CREATE TABLE {$delivery_table} (
            challenge_id char(36) NOT NULL,
            payload varbinary(2048) NULL,
            iv varbinary(64) NULL,
            tag varbinary(64) NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
            PRIMARY KEY  (challenge_id),
            KEY updated_at (updated_at)
        ) ENGINE=InnoDB {$charset};"
		);
		self::ensure_innodb();
	}

	private static function ensure_innodb(): void {
		global $wpdb;
		foreach ( array( PEYVAST_AUTH_OTP_TABLE, PEYVAST_AUTH_PHONE_IDENTITY_TABLE, PEYVAST_AUTH_LOG_TABLE, PEYVAST_AUTH_SECURITY_TABLE, PEYVAST_AUTH_OTP_DELIVERY_TABLE ) as $table ) {
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ) );
			if ( $status && strtolower( (string) ( $status->Engine ?? '' ) ) !== 'innodb' ) {
				$wpdb->query( 'ALTER TABLE ' . $table . ' ENGINE=InnoDB' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}
}
