<?php
namespace Peyvast\Auth\Infrastructure\Providers\SMS;

use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Providers\ProviderInterface;
use Peyvast\Auth\Infrastructure\Providers\ProviderResult;

defined( 'ABSPATH' ) || exit;

final class Msgway implements ProviderInterface {
	public function id(): string {
		return 'msgway'; }
	public function send( string $recipient, string $otp, array $context = array() ): ProviderResult {
		$c = Settings::get( 'providers.sms_credentials.msgway', array() );
		if ( empty( $c['api_key'] ) || empty( $c['template_id'] ) ) {
			return new ProviderResult( false, $this->id(), 'Not configured' );
		}
		$body            = array(
			'mobile'     => PhoneNumber::storage_value( PhoneNumber::canonical_value( $recipient ), 'leading_zero' ),
			'method'     => 'sms',
			'templateID' => (int) $c['template_id'],
			'params'     => array( $otp ),
		);
		$safe_body       = $body;
		$safe_body['params'] = array( '[redacted]' );
		$request_url     = 'https://api.msgway.com/send';
		$request_headers = array(
			'Content-Type' => 'application/json',
			'apiKey'       => $c['api_key'],
		);
		$safe_headers    = $request_headers;
		$safe_headers['apiKey'] = '[redacted]';
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
		$success          = $status >= 200 && $status < 300 && empty( $json['error'] );
		return new ProviderResult(
			$success,
			$this->id(),
			$success ? '' : 'Provider rejected request',
			array(
				'request'         => array(
					'method'  => 'POST',
					'url'     => $request_url,
					'headers' => $safe_headers,
					'body'    => $safe_body,
				),
				'response'        => array( 'http_status' => $status ),
				'http_status'     => $status,
				'provider_code'   => $json['code'] ?? $json['status'] ?? null,
				'response_length' => strlen( $raw ),
				'response_hash'   => hash( 'sha256', $raw ),
			)
		);
	}
}
