<?php
namespace Peyvast\Auth\Application\Migration;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Persistence\DatabaseLock;
use Peyvast\Auth\Infrastructure\Persistence\PhoneIdentityIndex;

defined( 'ABSPATH' ) || exit;

final class PhoneMigrationEngine {
	public function migrate_user( \WP_User $user, bool $lazy = false, ?array $source_snapshot = null ): array {
		$source_snapshot = $source_snapshot ?? PhoneMigrationSourceResolver::snapshot();
		$resolver = new PhoneMigrationSourceResolver();
		$resolved = $resolver->resolve( $user, $source_snapshot );
		$target = (string) get_user_meta( $user->ID, '_peyvast_auth_phone', true );
		$target_canonical = PhoneNumber::canonical_value( $target );

		if ( $resolved['source'] === 'primary' ) {
			
			$conflict = $this->has_target_conflict( $resolved['canonical_phone'], (int) $user->ID, $source_snapshot );
			if ( ! $conflict['ready'] ) {
				return array( 'status' => 'failed', 'reason' => 'identity_index_not_ready' );
			}
			if ( $conflict['conflict'] ) {
				return array( 'status' => 'conflict', 'reason' => 'duplicate_phone_identity' );
			}
			if ( $target_canonical !== $resolved['canonical_phone'] ) {
				return array( 'status' => $target_canonical !== '' ? 'conflict' : 'failed', 'reason' => $target_canonical !== '' ? 'target_phone_conflict' : 'primary_resolution_mismatch' );
			}
			$expected_hash = PhoneNumber::hash_value( $resolved['canonical_phone'] );
			if ( (string) get_user_meta( $user->ID, '_peyvast_auth_phone_canonical', true ) !== $expected_hash ) {
				if ( false === update_user_meta( $user->ID, '_peyvast_auth_phone_canonical', $expected_hash ) || (string) get_user_meta( $user->ID, '_peyvast_auth_phone_canonical', true ) !== $expected_hash ) {
					return array( 'status' => 'failed', 'reason' => 'canonical_hash_repair_failed' );
				}
			}
			if ( ! PhoneIdentityIndex::available() || ! PhoneIdentityIndex::sync_primary( (int) $user->ID ) ) {
				return array( 'status' => 'failed', 'reason' => 'identity_index_sync_failed' );
			}
			return array( 'status' => 'already_current', 'source' => 'primary' );
		}

		if ( $resolved['status'] !== 'valid' ) {
			return array( 'status' => $resolved['status'], 'reason' => $resolved['reason'] );
		}
		$canonical = $resolved['canonical_phone'];
		$lock = DatabaseLock::acquire( 'migration:phone:' . PhoneNumber::hash_value( $canonical ), 5 );
		if ( ! $lock ) return array( 'status' => 'failed', 'reason' => 'storage_busy' );

		try {
			$target = (string) get_user_meta( $user->ID, '_peyvast_auth_phone', true );
			$target_canonical = PhoneNumber::canonical_value( $target );
			if ( $target_canonical !== '' && $target_canonical !== $canonical ) {
				$this->log_result( $user->ID, 'conflict', 'target_phone_conflict', $canonical, $lazy );
				return array( 'status' => 'conflict', 'reason' => 'target_phone_conflict' );
			}
			if ( $target_canonical === $canonical && $target === PhoneNumber::peyvast_value( $canonical ) ) {
				$expected_hash = PhoneNumber::hash_value( $canonical );
				$stored_hash = (string) get_user_meta( $user->ID, '_peyvast_auth_phone_canonical', true );
				if ( $stored_hash !== $expected_hash ) {
					$hash_result = update_user_meta( $user->ID, '_peyvast_auth_phone_canonical', $expected_hash );
					if ( false === $hash_result || (string) get_user_meta( $user->ID, '_peyvast_auth_phone_canonical', true ) !== $expected_hash ) {
						$this->log_result( $user->ID, 'failed', 'canonical_hash_repair_failed', $canonical, $lazy );
						return array( 'status' => 'failed', 'reason' => 'canonical_hash_repair_failed' );
					}
				}
				if ( ! PhoneIdentityIndex::available() || ! PhoneIdentityIndex::sync_primary( (int) $user->ID ) ) {
					return array( 'status' => 'failed', 'reason' => 'identity_index_sync_failed' );
				}
				return array( 'status' => 'already_current' );
			}
			if ( ! PhoneIdentityIndex::available() || ! PhoneIdentityIndex::primary_ready() ) {
				return array( 'status' => 'failed', 'reason' => 'identity_index_not_ready' );
			}
			$conflict = $this->has_target_conflict( $canonical, (int) $user->ID, $source_snapshot );
			if ( ! $conflict['ready'] ) {
				return array( 'status' => 'failed', 'reason' => 'identity_index_not_ready' );
			}
			if ( $conflict['conflict'] ) {
				$this->log_result( $user->ID, 'conflict', 'duplicate_phone_identity', $canonical, $lazy );
				return array( 'status' => 'conflict', 'reason' => 'duplicate_phone_identity' );
			}

			$stored = PhoneNumber::peyvast_value( $canonical );
			$hash = PhoneNumber::hash_value( $canonical );
			$old_phone = $target;
			$old_hash = (string) get_user_meta( $user->ID, '_peyvast_auth_phone_canonical', true );
			$ok1 = update_user_meta( $user->ID, '_peyvast_auth_phone', $stored );
			$ok2 = update_user_meta( $user->ID, '_peyvast_auth_phone_canonical', $hash );
			$actual = (string) get_user_meta( $user->ID, '_peyvast_auth_phone', true );
			$actual_hash = (string) get_user_meta( $user->ID, '_peyvast_auth_phone_canonical', true );
			$indexed = PhoneIdentityIndex::contains( (int) $user->ID, PhoneIdentityIndex::SOURCE_PRIMARY, $canonical );
			if ( false === $ok1 || false === $ok2 || $actual !== $stored || $actual_hash !== $hash || ! $indexed ) {
				$restored_phone = $this->restore_meta( $user->ID, '_peyvast_auth_phone', $old_phone );
				$restored_hash = $this->restore_meta( $user->ID, '_peyvast_auth_phone_canonical', $old_hash );
				if ( ! $restored_phone || ! $restored_hash ) {
					update_user_meta( $user->ID, '_peyvast_auth_migration_recovery', array( 'state' => 'phone_identity_inconsistent', 'updated_at' => time() ) );
					Logger::error( 'migration', 'phone_identity_recovery_required', 'Phone migration rollback failed; manual repair is required.', array( 'recovery' => true ), (int) $user->ID );
				}
				return array( 'status' => 'failed', 'reason' => 'storage_failed' );
			}
			return array( 'status' => 'migrated', 'changed' => (bool) ( $ok1 || $ok2 ) );
		} finally {
			$lock->release();
		}
	}

	public function lazy_migrate_user( $user_id ): array {
		if ( ! Settings::get( 'migration.lazy_enabled', false ) ) return array( 'status' => 'disabled' );
		$user = get_user_by( 'id', absint( $user_id ) );
		if ( ! $user instanceof \WP_User ) return array( 'status' => 'failed', 'reason' => 'user_unavailable' );
		try {
			return $this->migrate_user( $user, true, PhoneMigrationSourceResolver::snapshot() );
		} catch ( \Throwable $e ) {
			Logger::error( 'migration', 'lazy_migration_failed', 'Lazy phone migration failed.', array( 'error_class' => get_class( $e ) ), (int) $user->ID );
			return array( 'status' => 'failed', 'reason' => 'exception' );
		}
	}

	private function has_target_conflict( string $canonical, int $user_id, array $source_snapshot ): array {
		$sources = array( PhoneIdentityIndex::SOURCE_PRIMARY );
		$legacy_configured = ( $source_snapshot['legacy_type'] ?? '' ) === 'user_meta' && ! empty( $source_snapshot['legacy_key'] );
		if ( $legacy_configured ) {
			if ( ! PhoneIdentityIndex::legacy_ready() ) {
				return array( 'ready' => false, 'conflict' => false );
			}
			$sources[] = PhoneIdentityIndex::SOURCE_LEGACY;
		}
		if ( ! PhoneIdentityIndex::primary_ready() ) {
			return array( 'ready' => false, 'conflict' => false );
		}

		// A ready index is authoritative, so do not re-resolve every candidate through WP_User.
		$indexed_ids = PhoneIdentityIndex::candidate_user_ids( PhoneNumber::hash_value( $canonical ), $sources );
		foreach ( $indexed_ids as $candidate_id ) {
			if ( (int) $candidate_id > 0 && (int) $candidate_id !== $user_id ) {
				return array( 'ready' => true, 'conflict' => true );
			}
		}

		global $wpdb;
		$login_formats = PhoneNumber::lookup_values( $canonical );
		if ( $login_formats ) {
			$placeholders = implode( ',', array_fill( 0, count( $login_formats ), '%s' ) );
			$login_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login IN ({$placeholders})", $login_formats ) );
			foreach ( (array) $login_ids as $login_id ) {
				if ( (int) $login_id > 0 && (int) $login_id !== $user_id ) {
					return array( 'ready' => true, 'conflict' => true );
				}
			}
		}
		return array( 'ready' => true, 'conflict' => false );
	}

	private function restore_meta( int $user_id, string $key, string $value ): bool {
		if ( $value === '' ) {
			$result = delete_user_meta( $user_id, $key );
			return false !== $result && (string) get_user_meta( $user_id, $key, true ) === '';
		}
		$result = update_user_meta( $user_id, $key, $value );
		return false !== $result && (string) get_user_meta( $user_id, $key, true ) === $value;
	}

	private function log_result( int $user_id, string $status, string $reason, string $canonical, bool $lazy ): void {
		Logger::log(
			in_array( $status, array( 'failed', 'conflict' ), true ) ? 'warning' : 'info',
			'migration',
			$lazy ? 'lazy_phone_migration' : 'batch_phone_migration',
			'Phone migration result.',
			array(
				'mode' => $lazy ? 'lazy' : 'batch',
				'status' => $status,
				'reason' => $reason,
				'phone' => PhoneNumber::mask_value( $canonical ),
			),
			$user_id
		);
	}
}
