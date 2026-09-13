<?php
namespace Peyvast\Auth\Infrastructure\Providers\SMS;

use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Providers\ProviderInterface;
use Peyvast\Auth\Infrastructure\Providers\ProviderResult;

defined( 'ABSPATH' ) || exit;

final class SmsIr implements ProviderInterface {
	public function id(): string {
		return 'smsir'; }
	public function send( string $recipient, string $otp, array $context = array() ): ProviderResult {
		$c = Settings::get( 'providers.sms_credentials.smsir', array() );
		if ( empty( $c['api_key'] ) || empty( $c['template_id'] ) ) {
			return new ProviderResult( false, $this->id(), 'Not configured' );
		}
		$body            = array(
			'mobile'     => PhoneNumber::storage_value( PhoneNumber::canonical_value( $recipient ), 'leading_zero' ),
			'templateId' => (int) $c['template_id'],
			'parameters' => array(
				array(
					'name'  => (string) ( $c['parameter_name'] ?: 'Code' ),
					'value' => (string) $otp,
				),
			),
		);
		$request_url     = 'https://api.sms.ir/v1/send/verify';
		$request_headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'x-api-key'    => $c['api_key'],
		);
		$safe_headers    = $request_headers;
		$safe_headers['x-api-key'] = '[redacted]';
		$safe_body       = $body;
		$safe_body['parameters'] = array( array( 'name' => $c['parameter_name'] ?: 'Code', 'value' => '[redacted]' ) );
		$r               = wp_remote_post(
			$request_url,
			array(
				'timeout' => (int) Settings::get( 'providers.sms.timeout', 10 ),
				'headers' => $request_headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return new ProviderResult(
				false,
				$this->id(),
				'HTTP request failed',
				array(
					'request'         => array(
						'method'  => 'POST',
						'url'     => $request_url,
						'headers' => $safe_headers,
						'body'    => $safe_body,
					),
					'transport_error' => array(
						'code'    => $r->get_error_code(),
						'message' => $r->get_error_message(),
					),
				)
			);
		}
		$status           = wp_remote_retrieve_response_code( $r );
		$raw              = wp_remote_retrieve_body( $r );
		$json             = json_decode( $raw, true );
		$success          = false;
		if ( $status >= 200 && $status < 300 && is_array( $json ) ) {
			$success = ( isset( $json['isSuccessful'] ) && $json['isSuccessful'] === true ) || ( isset( $json['status'] ) && (int) $json['status'] === 1 ); }
		return new ProviderResult(
			(bool) $success,
			$this->id(),
			$success ? '' : 'Provider rejected request',
			array(
				'request'          => array(
					'method'  => 'POST',
					'url'     => $request_url,
					'headers' => $safe_headers,
					'body'    => $safe_body,
				),
				'response'         => array( 'http_status' => $status ),
				'http_status'      => $status,
				'provider_code'    => $json['status'] ?? $json['code'] ?? null,
				'provider_message' => isset( $json['message'] ) ? sanitize_text_field( (string) $json['message'] ) : null,
				'response_length'  => strlen( $raw ),
				'response_hash'    => hash( 'sha256', $raw ),
			)
		);
	}
}
