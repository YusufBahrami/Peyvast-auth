<?php
namespace Peyvast\Auth\Infrastructure\Scheduling;

use Peyvast\Auth\Application\Migration\PhoneMigrationService;
use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Persistence\OtpChallengeStore;
use Peyvast\Auth\Infrastructure\Persistence\OtpDeliveryStore;
use Peyvast\Auth\Infrastructure\Persistence\PhoneIdentityIndex;
use Peyvast\Auth\Infrastructure\Persistence\SecurityStateStore;
use Peyvast\Auth\Infrastructure\WordPress\Installer;

defined( 'ABSPATH' ) || exit;

/** Centralized Action Scheduler ownership: hooks, lifecycle, unique scheduling. */
final class Scheduler {

	public const GROUP = 'peyvast-auth';

	/** Recurring maintenance actions. */
	public const HOOK_PURGE_OTP_CHALLENGES = 'peyvast_auth/purge_otp_challenges';
	public const HOOK_PURGE_LOGS           = 'peyvast_auth/purge_logs';
	public const HOOK_PURGE_SECURITY_STATE = 'peyvast_auth/purge_security_state';
	public const HOOK_SCHEMA_WATCHDOG      = 'peyvast_auth/schema_watchdog';
	public const HOOK_MIGRATION_WATCHDOG   = 'peyvast_auth/phone_migration_watchdog';

	/** Chained single / async actions. */
	public const HOOK_PHONE_MIGRATION_BATCH = 'peyvast_auth/process_phone_migration';
	public const HOOK_PHONE_INDEX_REBUILD   = 'peyvast_auth/rebuild_phone_identity_index';
	public const HOOK_LAZY_PHONE_MIGRATION  = 'peyvast_auth/lazy_migrate_user';
	public const HOOK_DELIVER_OTP           = 'peyvast_auth/deliver_otp';

	/** Expired OTP challenges are purged every 15 minutes. */
	public const PURGE_OTP_INTERVAL = 15 * MINUTE_IN_SECONDS;

	/** First run delay for recurring maintenance. */
	public const RECURRING_FIRST_RUN = HOUR_IN_SECONDS;

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		self::register_handlers();

		// Action Scheduler is safe only after its data store initializes (`action_scheduler_init`).
		// Recurring maintenance is ensured there on every request, recovering missing jobs.
		add_action( 'action_scheduler_init', array( self::class, 'ensure_recurring_trigger' ), 15 );
		add_action( 'action_scheduler_init', array( self::class, 'ensure_recurring_actions' ), 20 );
		add_action( 'action_scheduler_init', array( self::class, 'ensure_index_rebuild' ), 21 );
		add_action( 'action_scheduler_ensure_recurring_actions', array( self::class, 'ensure_recurring_actions' ) );
	}

	/** Register every plugin-owned action handler. */
	private static function register_handlers(): void {
		add_action( self::HOOK_PURGE_OTP_CHALLENGES, array( self::class, 'purge_otp_challenges' ), 10 );
		add_action( self::HOOK_PURGE_OTP_CHALLENGES, array( OtpDeliveryStore::class, 'purge_orphans' ), 20 );
		add_action( self::HOOK_PURGE_LOGS, array( Logger::class, 'purge' ) );
		add_action( self::HOOK_PURGE_SECURITY_STATE, array( SecurityStateStore::class, 'purge' ) );
		add_action( self::HOOK_SCHEMA_WATCHDOG, array( Installer::class, 'watchdog' ) );
		add_action( self::HOOK_MIGRATION_WATCHDOG, array( PhoneMigrationService::class, 'watchdog' ) );
		add_action( self::HOOK_PHONE_MIGRATION_BATCH, array( PhoneMigrationService::class, 'process_job' ), 10, 2 );
		add_action( self::HOOK_LAZY_PHONE_MIGRATION, array( PhoneMigrationService::class, 'process_lazy' ), 10, 1 );
		add_action( self::HOOK_PHONE_INDEX_REBUILD, array( PhoneIdentityIndex::class, 'process_rebuild' ), 10, 2 );
		add_action( self::HOOK_DELIVER_OTP, array( OtpDeliveryHandler::class, 'deliver' ), 10, 2 );
	}

	/** Execute instance-based OTP challenge maintenance through a valid callable. */
	public static function purge_otp_challenges(): void {
		( new OtpChallengeStore() )->purge();
	}

	/**
	 * Whether the active Action Scheduler runtime is usable by this plugin.
	 */
	public static function available(): bool {
		return class_exists( '\ActionScheduler', false )
			&& method_exists( '\ActionScheduler', 'is_initialized' )
			&& \ActionScheduler::is_initialized()
			&& function_exists( 'as_has_scheduled_action' );
	}

	/** Keep the daily recurring-actions trigger scheduled via the bundled runtime. */
	public static function ensure_recurring_trigger(): void {
		if ( ! self::available() ) {
			self::log_unavailable();
			return;
		}
		$recurring = new \ActionScheduler_RecurringActionScheduler();
		$recurring->schedule_recurring_scheduler_hook();
	}

	/** Ensure every recurring maintenance action exists exactly once. */
	public static function ensure_recurring_actions(): void {
		if ( ! self::available() ) {
			self::log_unavailable();
			return;
		}
		$recurring = array(
			self::HOOK_PURGE_OTP_CHALLENGES => self::PURGE_OTP_INTERVAL,
			self::HOOK_PURGE_LOGS           => DAY_IN_SECONDS,
			self::HOOK_PURGE_SECURITY_STATE => DAY_IN_SECONDS,
			self::HOOK_SCHEMA_WATCHDOG      => DAY_IN_SECONDS,
			self::HOOK_MIGRATION_WATCHDOG   => DAY_IN_SECONDS,
		);
		foreach ( $recurring as $hook => $interval ) {
			if ( as_has_scheduled_action( $hook, null, self::GROUP ) ) {
				continue;
			}
			try {
				$action_id = as_schedule_recurring_action( time() + self::RECURRING_FIRST_RUN, $interval, $hook, array(), self::GROUP, true );
				if ( ! $action_id ) self::log_schedule_failure( $hook, new \RuntimeException( 'Action Scheduler returned no action ID.' ) );
			} catch ( \Throwable $e ) {
				self::log_schedule_failure( $hook, $e );
			}
		}
	}

	/** Re-arm a phone-identity index rebuild whose chain was lost. */
	public static function ensure_index_rebuild(): void {
		PhoneIdentityIndex::ensure_rebuild_scheduled();
	}

	/** Schedule a recurring action if none is pending or running. Returns 0 on failure. */
	public static function schedule_recurring( string $hook, int $interval, int $first_delay = 0 ): int {
		if ( ! self::available() ) {
			self::log_unavailable();
			return 0;
		}
		try {
			return (int) as_schedule_recurring_action( time() + max( 1, $first_delay ), $interval, $hook, array(), self::GROUP, true );
		} catch ( \Throwable $e ) {
			self::log_schedule_failure( $hook, $e );
			return 0;
		}
	}

	/** Schedule a one-off (delayed, retry, chained) action. Returns 0 on failure. */
	public static function schedule_single( string $hook, array $args = array(), int $delay = 1, bool $unique = false, int $priority = 10 ): int {
		if ( ! self::available() ) {
			self::log_unavailable();
			return 0;
		}
		try {
			return (int) as_schedule_single_action( time() + max( 1, $delay ), $hook, $args, self::GROUP, $unique, $priority );
		} catch ( \Throwable $e ) {
			self::log_schedule_failure( $hook, $e );
			return 0;
		}
	}

	/** Queue work that should run as soon as possible. Returns 0 on failure. */
	public static function schedule_async( string $hook, array $args = array(), bool $unique = false, int $priority = 10 ): int {
		if ( ! self::available() ) {
			self::log_unavailable();
			return 0;
		}
		try {
			return (int) as_enqueue_async_action( $hook, $args, self::GROUP, $unique, $priority );
		} catch ( \Throwable $e ) {
			self::log_schedule_failure( $hook, $e );
			return 0;
		}
	}

	/** Whether any pending or running action exists for the hook (or its args). */
	public static function has_scheduled( string $hook, $args = null ): bool {
		if ( ! self::available() ) {
			return false;
		}
		return (bool) as_has_scheduled_action( $hook, $args, self::GROUP );
	}

	/** Cancel the active phone-migration batch chain only. */
	public static function cancel_phone_migration(): void {
		if ( self::available() ) {
			as_unschedule_all_actions( self::HOOK_PHONE_MIGRATION_BATCH, array(), self::GROUP );
		}
	}

	public static function deactivate(): void {
		if ( self::available() ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	private static function log_schedule_failure( string $hook, \Throwable $e ): void {
		Logger::error( 'scheduler', 'action_schedule_failed', 'Action Scheduler could not enqueue a background action.', array( 'hook' => sanitize_key( str_replace( '/', '_', $hook ) ), 'error_class' => get_class( $e ) ) );
	}

	private static function log_unavailable(): void {
		static $logged = false;
		if ( $logged ) {
			return;
		}
		$logged = true;
		Logger::error(
			'scheduler',
			'action_scheduler_unavailable',
			'Action Scheduler is not initialized; background work could not be scheduled. Confirm the bundled bootstrap is loaded.',
			array()
		);
	}
}