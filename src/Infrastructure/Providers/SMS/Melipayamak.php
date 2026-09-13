<?php
namespace Peyvast\Auth\Infrastructure\Providers\SMS;

use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Providers\ProviderInterface;
use Peyvast\Auth\Infrastructure\Providers\ProviderResult;

defined( 'ABSPATH' ) || exit;

final class Melipayamak implements ProviderInterface {
	public function id(): string {
		return 'melipayamak';
	}

	public function send( string $recipient, string $otp, array $context = array() ): ProviderResult {
		$c = Settings::get( 'providers.sms_credentials.melipayamak', array() );
		if ( empty( $c['username'] ) || empty( $c['password'] ) || empty( $c['template_id'] ) ) {
			return new ProviderResult( false, $this->id(), 'Not configured' );
		}
		if ( ! class_exists( 'SoapClient' ) ) {
			return new ProviderResult( false, $this->id(), 'SOAP extension unavailable' );
		}

		$phone     = PhoneNumber::storage_value( PhoneNumber::canonical_value( $recipient ), 'leading_zero' );
		$wsdl      = 'https://api.payamak-panel.com/post/Send.asmx?wsdl';
		$resultKey = 'SendByBaseNumber';
		$data      = array();

		try {
			@ini_set( 'soap.wsdl_cache_enabled', '0' );
			$client = new \SoapClient(
				$wsdl,
				array(
					'encoding'           => 'UTF-8',
					'exceptions'         => true,
					'trace'              => true,
					'connection_timeout' => (int) Settings::get( 'providers.sms.timeout', 10 ),
				)
			);

			// Melipayamak's official SendByBaseNumber (method 1) contract:
			// username, password, text[] variables, one recipient, bodyId.
			$data = array(
				'username' => $c['username'],
				'password' => $c['password'],
				'text'     => array( $otp ),
				'to'       => $phone,
				'bodyId'   => (int) $c['template_id'],
			);

			$response = $client->{$resultKey}( $data );
			$raw      = is_object( $response ) && isset( $response->{$resultKey . 'Result'} ) ? (string) $response->{$resultKey . 'Result'} : (string) ( $response->Result ?? '' );
			$success  = ctype_digit( $raw ) && strlen( $raw ) > 15;

			$safe_data = $data;
			$safe_data['username'] = '[redacted]';
			$safe_data['password'] = '[redacted]';

			return new ProviderResult(
				$success,
				$this->id(),
				$success ? '' : 'Provider rejected request',
				array(
					'request'         => array(
						'method'     => 'SOAP ' . $resultKey,
						'url'        => $wsdl,
						'parameters' => $safe_data,
					),
					'response'        => array(),
					'provider_code'   => $raw,
					'response_length' => strlen( $raw ),
					'response_hash'   => hash( 'sha256', $raw ),
				)
			);
		} catch ( \Throwable $e ) {
			$safe_data = $data;
			$safe_data['username'] = '[redacted]';
			$safe_data['password'] = '[redacted]';
			return new ProviderResult(
				false,
				$this->id(),
				'SOAP request failed',
				array(
					'request' => array(
						'method'     => 'SOAP ' . $resultKey,
						'url'        => $wsdl,
						'parameters' => $safe_data,
					),
					'transport_error' => array(
						'exception_class' => get_class( $e ),
						'message'         => $e->getMessage(),
					),
				)
			);
		}
	}
}
