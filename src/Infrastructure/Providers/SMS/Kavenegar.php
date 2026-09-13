<?php
namespace Peyvast\Auth\Infrastructure\Providers\SMS;

use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Providers\ProviderInterface;
use Peyvast\Auth\Infrastructure\Providers\ProviderResult;

defined( 'ABSPATH' ) || exit;

final class Kavenegar implements ProviderInterface {
	public function id(): string {
		return 'kavenegar'; }
	public function send( string $recipient, string $otp, array $context = array() ): ProviderResult {
		$c = Settings::get( 'providers.sms_credentials.kavenegar', array() );
		if ( empty( $c['api_key'] ) || empty( $c['template'] ) ) {
			return new ProviderResult( false, $this->id(), 'Not configured' );
		}
		$phone         = PhoneNumber::storage_value( PhoneNumber::canonical_value( $recipient ), 'leading_zero' );
		$endpoint      = sprintf( 'https://api.kavenegar.com/v1/%s/verify/lookup.json', rawurlencode( $c['api_key'] ) );
		$safe_endpoint = 'https://api.kavenegar.com/v1/[redacted]/verify/lookup.json';
		$request_body  = array(
			'receptor' => $phone,
			'token'    => $otp,
			'template' => $c['template'],
		);
		$safe_body    = $request_body;
		$safe_body['token'] = '[redacted]';
		$response      = wp_remote_post(
			$endpoint,
			array(
				'timeout' => (int) Settings::get( 'providers.sms.timeout', 10 ),
				'body'    => $request_body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new ProviderResult(
				false,
				$this->id(),
				'HTTP request failed',
				array(
					'request'         => array(
						'method' => 'POST',
						'url'    => $safe_endpoint,
						'body'   => $safe_body,
					),
					'transport_error' => array(
						'code'    => $response->get_error_code(),
						'message' => $response->get_error_message(),
					),
				)
			);
		}
		$status         = wp_remote_retrieve_response_code( $response );
		$body           = wp_remote_retrieve_body( $response );
		$json           = json_decode( $body, true );
		$providerStatus = $json['return']['status'] ?? null;
		$success        = $status >= 200 && $status < 300 && (int) $providerStatus === 200;
		return new ProviderResult(
			$success,
			$this->id(),
			$success ? '' : 'Provider rejected request',
			array(
				'request'          => array(
					'method' => 'POST',
					'url'    => $safe_endpoint,
					'body'   => $safe_body,
				),
				'response'         => array( 'http_status' => $status ),
				'provider_code'    => $providerStatus,
				'provider_message' => isset( $json['return']['message'] ) ? sanitize_text_field( (string) $json['return']['message'] ) : null,
				'response_length'  => strlen( $body ),
				'response_hash'    => hash( 'sha256', $body ),
			)
		);
	}
}
