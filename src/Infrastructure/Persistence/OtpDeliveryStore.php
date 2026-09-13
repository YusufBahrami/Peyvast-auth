<?php
namespace Peyvast\Auth\Infrastructure\Persistence;

use Peyvast\Auth\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypted-at-rest delivery staging store for OTP codes.
 *
 * 1.0.1 stages the code and recipients here and delivers them inside the
 * same request; the table also remains the queue for OTP delivery actions
 * created by earlier versions.
 */
final class OtpDeliveryStore {

	private const KEY_CONTEXT       = 'peyvast-auth:otp-delivery:v1';
	private const MAX_PAYLOAD_BYTES = 2048;

	public static function table(): string {
		return PEYVAST_AUTH_OTP_DELIVERY_TABLE;
	}

	public static function available(): bool {
		global $wpdb;
		// Only a positive probe is memoized: the schema can be repaired later in the same request.
		static $table_present = false;
		if ( $table_present ) {
			return true;
		}
		$table_present = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) );
		return $table_present;
	}

	/**
	 * Encrypt and persist the code and recipients as the delivery staging record.
	 *
	 * A null result means the staging record could not be created (the table or
	 * OpenSSL is unavailable, encryption failed, or the write failed). The
	 * caller must treat that as a hard failure and must not invoke a provider:
	 * the staging record is the delivery input for the synchronous path.
	 */
	public static function store( string $challenge_id, string $otp, array $channels = array() ) {
		if ( $challenge_id === '' || $otp === '' || ! $channels || ! self::available() || ! function_exists( 'openssl_encrypt' ) ) {
			return null;
		}
		$encrypted = self::encrypt( $otp, $channels );
		if ( ! $encrypted ) {
			return null;
		}
		global $wpdb;
		$now      = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'challenge_id' => $challenge_id,
			'payload'      => $encrypted['payload'],
			'iv'           => $encrypted['iv'],
			'tag'          => $encrypted['tag'],
			'attempts'     => 0,
			'created_at'   => $now,
			'updated_at'   => $now,
		);
		$inserted = $wpdb->insert( self::table(), $data, array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		if ( ! $inserted ) {
			return null;
		}
		$data['id'] = (int) $wpdb->insert_id;
		return (object) $data;
	}

	/** Re-encrypt a queued payload with a reduced channel set (failed channels only). */
	public static function rewrite( string $challenge_id, string $otp, array $channels ): bool {
		if ( $challenge_id === '' || $otp === '' || ! $channels || ! self::available() || ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}
		$encrypted = self::encrypt( $otp, $channels );
		if ( ! $encrypted ) {
			return false;
		}
		global $wpdb;
		$updated = $wpdb->update(
			self::table(),
			array(
				'payload'    => $encrypted['payload'],
				'iv'         => $encrypted['iv'],
				'tag'        => $encrypted['tag'],
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'challenge_id' => $challenge_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
		return false !== $updated;
	}

	public static function row( string $challenge_id ) {
		if ( $challenge_id === '' || ! self::available() ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE challenge_id=%s LIMIT 1', $challenge_id ) );
	}

	/** Decrypt the queued delivery payload. Returns array('otp','channels') or null. */
	public static function payload( string $challenge_id ): ?array {
		return self::payload_from_row( self::row( $challenge_id ) );
	}

	/** Decrypt an already-selected delivery row without issuing another SELECT. */
	public static function payload_from_row( $row ): ?array {
		if ( ! $row || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		$decoded = self::decrypt( (string) $row->payload, (string) $row->iv, (string) $row->tag );
		if ( null === $decoded || empty( $decoded['otp'] ) || ! is_array( $decoded['channels'] ?? null ) ) {
			return null;
		}
		return array(
			'otp'      => (string) $decoded['otp'],
			'channels' => array_map( 'strval', $decoded['channels'] ),
		);
	}

	public static function mark_attempt( string $challenge_id ): bool {
		if ( $challenge_id === '' || ! self::available() ) {
			return false;
		}
		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET attempts=attempts+1, updated_at=%s WHERE challenge_id=%s',
				gmdate( 'Y-m-d H:i:s' ),
				$challenge_id
			)
		);
		return false !== $updated;
	}

	public static function delete( string $challenge_id ): bool {
		if ( $challenge_id === '' || ! self::available() ) {
			return false;
		}
		global $wpdb;
		return false !== $wpdb->delete( self::table(), array( 'challenge_id' => $challenge_id ), array( '%s' ) );
	}

	/** Bounded purge of orphaned delivery rows older than the grace period. */
	public static function purge_orphans(): void {
		if ( ! self::available() ) {
			return;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$now    = gmdate( 'Y-m-d H:i:s' );
		for ( $i = 0; $i < 20; $i++ ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . self::table() . ' WHERE updated_at < %s AND challenge_id NOT IN (
						SELECT challenge_id FROM ' . PEYVAST_AUTH_OTP_TABLE . '
						WHERE consumed_at IS NULL AND verified_at IS NULL AND expires_at > %s
					) LIMIT 200',
					$cutoff,
					$now
				)
			);
			if ( ! $deleted || $deleted < 200 ) {
				break;
			}
		}
	}

	/** The delivery key is derived from wp-config salts (never stored in the database). */
	private static function key(): string {
		return hash( 'sha256', self::KEY_CONTEXT . '|' . wp_salt( 'auth' ), true );
	}

	private static function encrypt( string $otp, array $channels ): ?array {
		$payload = wp_json_encode(
			array(
				'otp'      => $otp,
				'channels' => $channels,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( false === $payload ) {
			return null;
		}
		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $payload, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext || strlen( (string) $tag ) !== 16 ) {
			return null;
		}
		$encoded = base64_encode( $ciphertext );
		if ( strlen( $encoded ) > self::MAX_PAYLOAD_BYTES ) {
			Logger::notice( 'provider', 'otp_delivery_payload_too_large', 'Queued OTP delivery payload exceeds the column limit; asynchronous delivery is unavailable.', array() );
			return null;
		}
		return array(
			'payload' => $encoded,
			'iv'      => base64_encode( $iv ),
			'tag'     => base64_encode( $tag ),
		);
	}

	private static function decrypt( string $payload, string $iv, string $tag ): ?array {
		$ciphertext = base64_decode( $payload, true );
		$iv_raw     = base64_decode( $iv, true );
		$tag_raw    = base64_decode( $tag, true );
		if ( false === $ciphertext || false === $iv_raw || false === $tag_raw ) {
			return null;
		}
		$json = openssl_decrypt( $ciphertext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv_raw, $tag_raw );
		if ( false === $json ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}