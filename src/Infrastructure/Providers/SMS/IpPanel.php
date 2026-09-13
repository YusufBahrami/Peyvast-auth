<?php
namespace Peyvast\Auth\Infrastructure\Providers\SMS;

use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Providers\ProviderInterface;
use Peyvast\Auth\Infrastructure\Providers\ProviderResult;

defined( 'ABSPATH' ) || exit;

final class IpPanel implements ProviderInterface {
	public function id(): string {
		return 'ippanel'; }

	public function send( string $recipient, string $otp, array $context = array() ): ProviderResult {
		$c = Settings::get( 'providers.sms_credentials.ippanel', array() );
		if ( empty( $c['api_key'] ) || empty( $c['pattern_code'] ) || empty( $c['originator'] ) ) {
			return new ProviderResult( false, $this->id(), 'Not configured' );
		}
		$phone           = '+' . PhoneNumber::storage_value( PhoneNumber::canonical_value( $recipient ), 'country_code' );
		$payload         = array(
			'sending_type' => 'pattern',
			'from_number'  => $c['originator'],
			'code'         => $c['pattern_code'],
			'recipients'   => array( $phone ),
			'params'       => array( (string) ( $c['parameter_name'] ?: 'code' ) => (string) $otp ),
		);
		$request_url     = 'https://edge.ippanel.com/v1/api/send';
		$request_headers = array(
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'Authorization' => (string) $c['api_key'],
		);
		$safe_headers    = $request_headers;
		$safe_headers['Authorization'] = '[redacted]';
		$safe_payload    = $payload;
		$safe_payload['params'] = array( (string) ( $c['parameter_name'] ?: 'code' ) => '[redacted]' );
		$response        = wp_remote_post(
			$request_url,
			array(
				'timeout' => (int) Settings::get( 'providers.sms.timeout', 10 ),
				'headers' => $request_headers,
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new ProviderResult(
				false,
				$this->id(),
				'HTTP request failed',
				array(
					'request'         => array(
						'method'  => 'POST',
						'url'     => $request_url,
						'headers' => $safe_headers,
						'body'    => $safe_payload,
					),
					'transport_error' => array(
						'code'    => $response->get_error_code(),
						'message' => $response->get_error_message(),
					),
				)
			);
		}
		$status           = wp_remote_retrieve_response_code( $response );
		$body             = wp_remote_retrieve_body( $response );
		$json             = json_decode( $body, true );
		$success          = $status >= 200 && $status < 300 && ! self::has_error( $json );
		return new ProviderResult(
			$success,
			$this->id(),
			$success ? '' : 'Provider rejected request',
			array(
				'request'         => array(
					'method'  => 'POST',
					'url'     => $request_url,
					'headers' => $safe_headers,
					'body'    => $safe_payload,
				),
				'response'        => array( 'http_status' => $status ),
				'http_status'     => $status,
				'provider_code'   => is_array( $json ) ? ( $json['code'] ?? $json['status'] ?? null ) : null,
				'response_length' => strlen( $body ),
				'response_hash'   => hash( 'sha256', $body ),
			)
		);
	}

	private static function has_error( $json ): bool {
		if ( ! is_array( $json ) ) {
			return false;
		}
		if ( isset( $json['status'] ) && is_numeric( $json['status'] ) && (int) $json['status'] >= 400 ) {
			return true;
		}
		if ( ! empty( $json['error'] ) ) {
			return true;
		}
		return false;
	}
}
