<?php
namespace Peyvast\Auth\Infrastructure\Providers\SMS;

use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Providers\ProviderInterface;
use Peyvast\Auth\Infrastructure\Providers\ProviderResult;

defined( 'ABSPATH' ) || exit;

final class IpPanelUsernamePassword implements ProviderInterface {
	public function id(): string {
		return 'ippanel_username_password'; }

	public function send( string $recipient, string $otp, array $context = array() ): ProviderResult {
		$c = Settings::get( 'providers.sms_credentials.ippanel_username_password', array() );
		if ( empty( $c['username'] ) || empty( $c['password'] ) || empty( $c['from'] ) ) {
			return new ProviderResult( false, $this->id(), 'Not configured' );
		}
		$phone           = PhoneNumber::storage_value( PhoneNumber::canonical_value( $recipient ), 'country_code' );
		$timeout         = (int) Settings::get( 'providers.sms.timeout', 10 );
		$request_method  = '';
		$request_url     = '';
		$request_payload = null;
		$request_headers = array();
		if ( ! empty( $c['pattern_code'] ) ) {
			// Credentials travel in the POST body, never in the URL.
			$request_method  = 'POST';
			$request_url     = 'https://ippanel.com/patterns/pattern';
			$request_payload = array(
				'username'     => $c['username'],
				'password'     => $c['password'],
				'from'         => $c['from'],
				'to'           => $phone,
				'input_data'   => wp_json_encode( array( (string) ( $c['input_name'] ?: 'verification-code' ) => (string) $otp ) ),
				'pattern_code' => $c['pattern_code'],
			);
			$response        = wp_remote_post( $request_url, array( 'timeout' => $timeout, 'body' => $request_payload ) );
		} else {
			$request_method  = 'POST';
			$request_url     = 'https://ippanel.com/services.jspd';
			$request_payload = array(
				'uname'   => $c['username'],
				'pass'    => $c['password'],
				'from'    => $c['from'],
				'message' => (string) $otp,
				'to'      => wp_json_encode( array( $phone ) ),
				'op'      => 'send',
			);
			$response        = wp_remote_post(
				$request_url,
				array(
					'timeout' => $timeout,
					'body'    => $request_payload,
				)
			);
		}
		$request_payload = self::redact( $request_payload );
		if ( is_wp_error( $response ) ) {
			return new ProviderResult(
				false,
				$this->id(),
				'HTTP request failed',
				array(
					'request'         => array(
						'method'  => $request_method,
						'url'     => $request_url,
						'headers' => $request_headers,
						'body'    => $request_payload,
					),
					'transport_error' => array(
						'code'    => $response->get_error_code(),
						'message' => $response->get_error_message(),
					),
				)
			);
		}
		$status           = wp_remote_retrieve_response_code( $response );
		$body             = trim( wp_strip_all_tags( wp_remote_retrieve_body( $response ) ) );
		$numeric          = preg_match( '/^-?\d+$/', $body ) === 1;
		// Success is a positive numeric message ID; negative codes reject even with HTTP 200.
		$success = $status >= 200 && $status < 300 && $numeric && (int) $body > 0;
		return new ProviderResult(
			$success,
			$this->id(),
			$success ? '' : 'Provider rejected request',
			array(
				'request'         => array(
					'method'  => $request_method,
					'url'     => $request_url,
					'headers' => $request_headers,
					'body'    => $request_payload,
				),
				'response'        => array( 'http_status' => $status ),
				'http_status'     => $status,
				'provider_code'   => $numeric ? $body : null,
				'response_length' => strlen( $body ),
				'response_hash'   => hash( 'sha256', $body ),
			)
		);
	}

	private static function redact( array $payload ): array {
		foreach ( array( 'username', 'password', 'input_data', 'message' ) as $key ) {
			if ( isset( $payload[ $key ] ) ) {
				$payload[ $key ] = '[redacted]';
			}
		}
		return $payload;
	}
}
