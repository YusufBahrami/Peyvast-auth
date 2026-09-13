<?php
namespace Peyvast\Auth\Presentation\Frontend;

use Peyvast\Auth\Core\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Assets {
	private static bool $front_enqueued         = false;
	private static bool $admin_enqueued         = false;
	private static bool $admin_scripts_enqueued = false;

	public static function enqueue_front(): void {
		if ( self::$front_enqueued ) {
			return;
		}
		self::$front_enqueued = true;
		wp_enqueue_style( 'peyvast-auth', PEYVAST_AUTH_URL . 'assets/css/auth.css', array(), PEYVAST_AUTH_VERSION );
		wp_enqueue_script( 'peyvast-auth', PEYVAST_AUTH_URL . 'assets/js/auth.js', array(), PEYVAST_AUTH_VERSION, true );
		if ( Settings::get( 'google.enabled', false ) && Settings::get( 'google.client_id', '' ) !== '' ) {
			wp_enqueue_script( 'peyvast-auth-google-gsi', 'https://accounts.google.com/gsi/client', array(), null, true );
		}
	}

	public static function enqueue_admin_styles(): void {
		if ( self::$admin_enqueued ) {
			return;
		}
		self::$admin_enqueued = true;
		wp_enqueue_style( 'peyvast-auth-admin', PEYVAST_AUTH_URL . 'assets/css/admin.css', array(), PEYVAST_AUTH_VERSION );
	}

	public static function enqueue_admin_scripts(): void {
		if ( self::$admin_scripts_enqueued ) {
			return;
		}
		self::$admin_scripts_enqueued = true;
		// Loaded in the head so admin controls work without the footer.
		wp_enqueue_script( 'peyvast-auth-admin', PEYVAST_AUTH_URL . 'assets/js/admin.js', array( 'wp-i18n' ), PEYVAST_AUTH_VERSION, false );
		if ( self::is_settings_screen() ) {
			wp_enqueue_script( 'peyvast-auth-admin-migration', PEYVAST_AUTH_URL . 'assets/js/admin-migration.js', array( 'peyvast-auth-admin' ), PEYVAST_AUTH_VERSION, false );
			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations( 'peyvast-auth-admin-migration', 'peyvast-auth', PEYVAST_AUTH_DIR . 'languages' );
			}
		}
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'peyvast-auth-admin', 'peyvast-auth', PEYVAST_AUTH_DIR . 'languages' );
		}
		wp_localize_script(
			'peyvast-auth-admin',
			'PEYVAST_AUTH_ADMIN',
			array(
				'rest'                   => rest_url( 'peyvast-auth/v1' ),
				'rest_nonce'             => wp_create_nonce( 'wp_rest' ),
				'nonce'                  => wp_create_nonce( 'peyvast_auth_settings' ),
				'saving'                 => __( 'Saving changes…', 'peyvast-auth' ),
				'saveLabel'              => __( 'Save changes', 'peyvast-auth' ),
				'unsaved'                => __( 'Unsaved changes', 'peyvast-auth' ),
				'saved'                  => __( 'Changes saved.', 'peyvast-auth' ),
				'error'                  => __( 'The settings could not be saved. Please try again.', 'peyvast-auth' ),
				'invalidPhone'           => __( 'Please enter a valid Iranian mobile number.', 'peyvast-auth' ),
				'migrating'              => __( 'Starting migration…', 'peyvast-auth' ),
				'migrationError'         => __( 'Mobile number migration failed. Please review the logs.', 'peyvast-auth' ),
				'migrationBusy'          => __( 'Another Batch Migration is already running. Please wait for it to finish.', 'peyvast-auth' ),
				'migrationCompletedWithIssues' => __( 'Batch Migration completed with %1$d conflicts and %2$d failed records.', 'peyvast-auth' ),
				'migrationNeedsReview'   => __( 'The configured phone source changed or is incomplete. Review the source before starting Batch Migration.', 'peyvast-auth' ),
				'migrationNotStarted'    => __( 'No Batch Migration has been started.', 'peyvast-auth' ),
				'migrationCompleted'     => __( 'Batch Migration completed.', 'peyvast-auth' ),
				'migrationDismissError' => __( 'The migration notice could not be closed. Please try again.', 'peyvast-auth' ),
				'migrationBackupConfirm' => __( 'Confirm that you have a current database backup before starting this background migration. Usernames are read as source data only and are never changed.', 'peyvast-auth' ),
				'phoneMetaEmpty'         => __( 'Select an existing user meta key or enter a manual key first.', 'peyvast-auth' ),
				'phoneMetaChecking'      => __( 'Checking sample values…', 'peyvast-auth' ),
				'phoneMetaError'         => __( 'User meta validation failed.', 'peyvast-auth' ),
				'phoneMetaValid'         => __( 'Valid — %1$d/%2$d values recognized as valid phone numbers.', 'peyvast-auth' ),
				'phoneMetaPartial'       => __( 'Partial — %1$d/%2$d values were recognized as valid phone numbers.', 'peyvast-auth' ),
				'phoneMetaInvalid'       => __( 'Invalid — %3$d/%2$d values could not be recognized as valid phone numbers.', 'peyvast-auth' ),
				'securityBlockConfirm'   => __( 'Clear all active security blocks? Blocks and rate-limit counters will be reset. This cannot be undone.', 'peyvast-auth' ),
				'securityBlocking'       => __( 'Clearing security blocks…', 'peyvast-auth' ),
				'securityBlockedCleared' => __( 'All security blocks and rate-limit counters were cleared.', 'peyvast-auth' ),
				'securityBlockError'     => __( 'Security blocks could not be cleared. Please try again.', 'peyvast-auth' ),
				'logTimelineTitle'       => __( 'Request timeline', 'peyvast-auth' ),
				'logContextTitle'        => __( 'Full request context', 'peyvast-auth' ),
				'logLabels'              => array(
					'id'       => __( 'ID', 'peyvast-auth' ),
					'time'     => __( 'Time', 'peyvast-auth' ),
					'level'    => __( 'Severity', 'peyvast-auth' ),
					'channel'  => __( 'Channel', 'peyvast-auth' ),
					'event'    => __( 'Event', 'peyvast-auth' ),
					'provider' => __( 'Provider', 'peyvast-auth' ),
					'user'     => __( 'User', 'peyvast-auth' ),
					'request'  => __( 'Request ID', 'peyvast-auth' ),
				),
			)
		);
	}

	private static function is_settings_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return $page === 'peyvast-auth-settings';
	}

	public static function enqueue_admin(): void {
		self::enqueue_admin_styles();
		self::enqueue_admin_scripts();
	}
}
