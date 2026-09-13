<?php
namespace Peyvast\Auth\Core\Config;

use Peyvast\Auth\Domain\Phone\PhoneNumber;

defined( 'ABSPATH' ) || exit;

final class Settings {
	private static $booted = false;
	private static $cache  = null;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'update_option_' . PEYVAST_AUTH_OPTION, array( self::class, 'after_update' ), 10, 3 );
	}

	public static function defaults(): array {
		return array(
			'general'        => array(
				'enabled'                  => true,
				'delete_data_on_uninstall' => false,
			),
			'registration'   => array(
				'enabled'               => true,
				'fields'                => array(
					'first_name' => array(
						'enabled'  => true,
						'required' => true,
					),
					'last_name' => array(
						'enabled'  => true,
						'required' => true,
					),
					'email'     => array(
						'enabled'  => true,
						'required' => false,
					),
					'password'  => array(
						'enabled'  => true,
						'required' => true,
					),
				),
				'username_generation'   => 'phone',
				'username_phone_format' => 'country_code',
			),
			'authentication' => array(
				'otp_login'           => array(
					'phone' => true,
					'email' => true,
				),
				'otp_email_for_phone' => true,
				'password_login'      => array(
					'enabled'    => true,
					'phone'      => true,
					'email'      => true,
					'user_login' => true,
				),
				'password_reset'      => array(
					'enabled'              => true,
					'phone'                => true,
					'email'                => true,
					'email_copy_for_phone' => false,
				),
			),
			'otp'            => array(
				'length'             => 6,
				'resend_cooldown'    => 120,
				'expiration_seconds' => 300,
				'web_otp_enabled'    => true,
			),
			'phone'          => array(
				'country' => 'ir',
			),
			'woocommerce'    => array(
				'my_account_redirect'     => false,
				'guest_checkout_redirect' => false,
				'guest_checkout_notice'   => false,
				'order_receipt_redirect'  => false,
				'order_receipt_notice'    => false,
				'save_name'               => true,
				'save_email'              => true,
				'save_phone'              => true,
				'phone_storage_format'    => 'leading_zero',
			),
			'providers'      => array(
				'sms'             => array(
					'active'  => 'none',
					// Delivery is in-request, so the request waits for the provider; 10 s bounds a hung gateway.
					'timeout' => 10,
				),
				'sms_credentials' => array(
					'kavenegar'                 => array(
						'api_key'  => '',
						'template' => '',
					),
					'melipayamak'               => array(
						'username'    => '',
						'password'    => '',
						'template_id' => '',
					),
					'msgway'                    => array(
						'api_key'     => '',
						'template_id' => '',
					),
					'smsir'                     => array(
						'api_key'        => '',
						'template_id'    => '',
						'parameter_name' => 'Code',
					),
					'ippanel'                   => array(
						'api_key'        => '',
						'pattern_code'   => '',
						'originator'     => '',
						'parameter_name' => 'code',
					),
					'ippanel_username_password' => array(
						'username'     => '',
						'password'     => '',
						'from'         => '',
						'pattern_code' => '',
						'input_name'   => 'verification-code',
					),
				),
				'email'           => array(
					'sender_name'    => '',
					'sender_address' => '',
					'subject'        => '',
					'body'           => '',
				),
			),
			'migration'      => array(
				'lazy_enabled'          => false,
				'phone_source_type'     => 'off',
				'phone_source_meta_key' => '',
			),
			'security'       => array(
				'protection_enabled' => true,
				'request_limit'      => 5,
				'request_window'     => 600,
				'verify_limit'       => 5,
				'verify_window'      => 600,
				'ip_multiplier'      => 3,
				'progressive'        => array(
					'enabled'          => true,
					'initial_duration' => 60,
					'multiplier'       => 5,
					'max_duration'     => 3600,
					'decay_seconds'    => 3600,
				),
			),
			'logging'        => array(
				'enabled'        => false,
				'minimum_level'  => 'info',
				'retention_days' => 30,
			),
			'integrations'   => array(
				'bricks' => true,
				'blocks' => true,
			),
			'redirect'       => array(
				'mode'                 => 'origin',
				'page_id'              => '',
				'custom_url'           => '',
				'fallback_mode'        => 'home',
				'fallback_page_id'     => '',
				'fallback_custom_url'  => '',
				'login_page_id'        => '',
				'logged_in_mode'       => 'home',
				'logged_in_page_id'    => '',
				'logged_in_custom_url' => '',
				'woocommerce_logout_mode' => 'disable',
				'woocommerce_logout_page_id' => '',
				'woocommerce_logout_custom_url' => '',
				'wordpress_logout_mode' => 'disable',
				'wordpress_logout_page_id' => '',
				'wordpress_logout_custom_url' => '',
			),
			'google'         => array(
				'enabled'   => false,
				'client_id' => '',
			),

		);
	}


	public static function default_email_sender_name(): string {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return $site !== '' ? $site : __( 'Your website', 'peyvast-auth' );
	}

	public static function default_email_sender_address(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( trim( $host ) ) : '';
		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return $host !== '' ? 'noreply@' . $host : '';
	}

	public static function default_email_subject(): string {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return sprintf( __( 'Verification code for %s', 'peyvast-auth' ), $site ?: __( 'Your website', 'peyvast-auth' ) );
	}

	public static function default_email_body(): string {
		$otp_label   = esc_html__( 'Your verification code', 'peyvast-auth' );
		$instruction = esc_html__( 'Enter the one-time code below to continue.', 'peyvast-auth' );
		$expiry      = esc_html__( 'This code is valid for {expiration} minutes.', 'peyvast-auth' );
		$ignore      = esc_html__( 'If you did not request this code, you can safely ignore this message.', 'peyvast-auth' );

		return '<div style="max-width:560px;margin:32px auto;padding:0 16px">
	<div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">
		<div style="padding:30px 28px 12px">
			<div style="font-size:26px;font-weight:600;color:#2e3136;margin-bottom:10px">{site_name}</div>
			<h1 style="font-size:24px;line-height:1.5;margin:0;color:#111827">' . $otp_label . '</h1>
			<p style="font-size:15px;line-height:2;color:#4b5563;margin:12px 0 0">' . $instruction . '</p>
		</div>
		<div style="padding:8px 28px 30px">
			<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:24px;text-align:center">
				<div style="font-size:12px;color:#6b7280;margin-bottom:12px">' . $otp_label . '</div>
				<div style="display:inline-block;background:#111827;color:#ffffff;border-radius:6px;padding:16px 22px;font-size:32px;line-height:1;font-weight:700;letter-spacing:9px;font-family:Tahoma,Arial,sans-serif;direction:ltr;unicode-bidi:plaintext">{otp}</div>
			</div>
			<p style="font-size:14px;line-height:2;color:#6b7280;margin:18px 0 0">' . $expiry . '</p>
			<p style="font-size:13px;line-height:2;color:#9ca3af;margin:10px 0 0">' . $ignore . '</p>
		</div>
	</div>
	<div style="font-size:12px;color:#9ca3af;text-align:center;margin-top:16px">{site_name}</div>
</div>';
	}

	public static function install_defaults(): void {
		$saved = get_option( PEYVAST_AUTH_OPTION, null );
		if ( ! is_array( $saved ) ) {
			$defaults = self::defaults();
			$defaults['providers']['email']['sender_name']    = self::default_email_sender_name();
			$defaults['providers']['email']['sender_address'] = self::default_email_sender_address();
			add_option( PEYVAST_AUTH_OPTION, $defaults, '', false );
			self::$cache = $defaults;
			return;
		}
		$merged = self::resolve( $saved );

		update_option( PEYVAST_AUTH_OPTION, $merged, false );
		self::$cache = $merged;
	}

	/** Merge saved settings over defaults. */
	private static function resolve( array $saved ): array {
		return self::merge( self::defaults(), $saved );
	}

	public static function all(): array {
		if ( self::$cache !== null ) {
			return self::$cache;
		}
		$saved       = get_option( PEYVAST_AUTH_OPTION, array() );
		$saved       = is_array( $saved ) ? $saved : array();
		self::$cache = self::resolve( $saved );

		return self::$cache;
	}


	public static function get( string $path, $default = null ) {
		$value = self::all();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}
			$value = $value[ $segment ];
		}
		return $value;
	}

	public static function register(): void {
		register_setting(
			'peyvast_auth_settings_group',
			PEYVAST_AUTH_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function after_update( array $old, array $new, string $option = PEYVAST_AUTH_OPTION ): void {
		self::$cache = self::resolve( $new );

		$old_source = self::source_signature( $old );
		$new_source = self::source_signature( $new );
		if ( $old_source !== $new_source ) {
			\Peyvast\Auth\Application\Migration\PhoneMigrationService::mark_needs_review();
			\Peyvast\Auth\Infrastructure\Persistence\PhoneIdentityIndex::mark_rebuild();
		}
	}


	public static function phone_source(): array {
		return self::source_from_settings( self::all() );
	}

	private static function source_from_settings( array $settings ): array {
		$migration = is_array( $settings['migration'] ?? null ) ? $settings['migration'] : array();
		$type      = self::allowed( $migration['phone_source_type'] ?? '', array( 'off', 'existing_meta', 'manual_meta' ), 'off' );
		if ( $type === 'off' ) {
			return array(
				'type' => 'off',
				'key'  => '',
			);
		}
		$key = self::sanitize_meta_key( (string) ( $migration['phone_source_meta_key'] ?? '' ) );
		if ( $key === '' || in_array( $key, array( '_peyvast_auth_phone', '_peyvast_auth_phone_canonical' ), true ) ) {
			return array(
				'type' => 'user_meta',
				'key'  => '',
			);
		}

		return array(
			'type' => 'user_meta',
			'key'  => $key,
		);
	}

	private static function source_signature( array $settings ): string {
		$source = self::source_from_settings( $settings );
		return (string) ( $source['type'] ?? '' ) . '|' . (string) ( $source['key'] ?? '' );
	}

	public static function sanitize( $input ): array {
		$d       = self::defaults();
		$current = self::all();
		$in      = is_array( $input ) ? $input : array();
		$o       = self::merge( $d, $current );

		$o['general']['enabled']                  = ! empty( $in['general']['enabled'] );
		$o['general']['delete_data_on_uninstall'] = ! empty( $in['general']['delete_data_on_uninstall'] );
		$o['registration']['enabled']             = ! empty( $in['registration']['enabled'] );

		$o['registration']['username_generation']   = self::allowed( $in['registration']['username_generation'] ?? '', array( 'random', 'phone' ), 'phone' );
		$o['registration']['username_phone_format'] = self::allowed( $in['registration']['username_phone_format'] ?? '', array( 'raw', 'country_code', 'leading_zero' ), 'country_code' );
		if ( $o['registration']['username_generation'] !== 'phone' ) {
			$o['registration']['username_phone_format'] = 'country_code';
		}

		foreach ( array_keys( $d['registration']['fields'] ) as $field ) {
			$o['registration']['fields'][ $field ]['enabled']  = ! empty( $in['registration']['fields'][ $field ]['enabled'] );
			$o['registration']['fields'][ $field ]['required'] = ! empty( $in['registration']['fields'][ $field ]['required'] );
			if ( ! $o['registration']['fields'][ $field ]['enabled'] ) {
				$o['registration']['fields'][ $field ]['required'] = false;
			}
		}

		// Phone OTP sign-in is always on: registration depends on it.
		$o['authentication']['otp_login']['phone'] = true;
		$o['authentication']['otp_login']['email'] = ! empty( $in['authentication']['otp_login']['email'] );
		$o['authentication']['otp_email_for_phone'] = ! empty( $in['authentication']['otp_email_for_phone'] );

		$migration_input                     = is_array( $in['migration'] ?? null ) ? $in['migration'] : array();
		$o['migration']['phone_source_type'] = self::allowed( $migration_input['phone_source_type'] ?? '', array( 'off', 'existing_meta', 'manual_meta' ), 'off' );
		$submitted_key                       = array_key_exists( 'phone_source_meta_key', $migration_input )
			? (string) $migration_input['phone_source_meta_key']
			: (string) ( $current['migration']['phone_source_meta_key'] ?? '' );
		$meta_key                            = self::validate_meta_key( $submitted_key );
		if ( in_array( $meta_key, array( '_peyvast_auth_phone', '_peyvast_auth_phone_canonical' ), true ) ) {
			add_settings_error( 'peyvast_auth_settings', 'invalid_meta_key', __( 'The selected user meta key is reserved by Peyvast.', 'peyvast-auth' ) );
			$meta_key = '';
		}
		$o['migration']['phone_source_meta_key'] = $meta_key;

		$o['authentication']['password_login']['enabled']    = ! empty( $in['authentication']['password_login']['enabled'] );
		$o['authentication']['password_login']['phone']      = ! empty( $in['authentication']['password_login']['phone'] );
		$o['authentication']['password_login']['email']      = ! empty( $in['authentication']['password_login']['email'] );
		$o['authentication']['password_login']['user_login'] = ! empty( $in['authentication']['password_login']['user_login'] );

		$o['authentication']['password_reset']['enabled']              = ! empty( $in['authentication']['password_reset']['enabled'] );
		$o['authentication']['password_reset']['phone']                = ! empty( $in['authentication']['password_reset']['phone'] );
		$o['authentication']['password_reset']['email']                = ! empty( $in['authentication']['password_reset']['email'] );
		unset( $o['authentication']['password_reset']['user_login'] );
		$o['authentication']['password_reset']['email_copy_for_phone'] = $o['authentication']['password_reset']['phone'] && $o['authentication']['password_reset']['email'] && ! empty( $in['authentication']['password_reset']['email_copy_for_phone'] );

		$submitted_otp_length = absint( $in['otp']['length'] ?? $d['otp']['length'] );
		$o['otp']['length'] = in_array( $submitted_otp_length, array( 4, 5, 6, 7, 8 ), true ) ? $submitted_otp_length : 6;
		$o['otp']['resend_cooldown']    = max( 10, min( 3600, absint( $in['otp']['resend_cooldown'] ?? 120 ) ) );
		$submitted_expiration           = absint( $in['otp']['expiration_seconds'] ?? $d['otp']['expiration_seconds'] );
		$o['otp']['expiration_seconds'] = max( 30, min( 86400, $submitted_expiration ) );
		$o['otp']['web_otp_enabled']    = ! empty( $in['otp']['web_otp_enabled'] );

		$o['phone']['country'] = self::allowed( $in['phone']['country'] ?? '', array_keys( PhoneNumber::countries() ), 'ir' );

		$wc = is_array( $in['woocommerce'] ?? null ) ? $in['woocommerce'] : array();

		$o['woocommerce']['my_account_redirect']     = ! empty( $wc['my_account_redirect'] );
		$o['woocommerce']['guest_checkout_redirect'] = ! empty( $wc['guest_checkout_redirect'] );
		$o['woocommerce']['guest_checkout_notice']   = ! empty( $wc['guest_checkout_notice'] );
		$o['woocommerce']['order_receipt_redirect']  = ! empty( $wc['order_receipt_redirect'] );
		$o['woocommerce']['order_receipt_notice']    = ! empty( $wc['order_receipt_notice'] );
		$o['woocommerce']['save_name']               = ! empty( $wc['save_name'] );
		$o['woocommerce']['save_email']              = ! empty( $wc['save_email'] );
		$o['woocommerce']['save_phone']              = ! empty( $wc['save_phone'] );
		$o['woocommerce']['phone_storage_format']    = self::allowed( $wc['phone_storage_format'] ?? '', array( 'country_code', 'leading_zero', 'raw' ), 'leading_zero' );

		$sms_active                       = self::allowed( $in['providers']['sms']['active'] ?? '', array( 'none', 'kavenegar', 'melipayamak', 'msgway', 'smsir', 'ippanel', 'ippanel_username_password' ), 'none' );
		$o['providers']['sms']['active']  = $sms_active;
		$o['providers']['sms']['timeout'] = max( 5, min( 60, absint( $in['providers']['sms']['timeout'] ?? 10 ) ) );

		foreach ( $d['providers']['sms_credentials'] as $name => $fields ) {
			foreach ( $fields as $field => $default ) {
				$submitted    = $in['providers']['sms_credentials'][ $name ][ $field ] ?? null;
				$currentValue = $current['providers']['sms_credentials'][ $name ][ $field ] ?? $default;
				if ( $submitted === null ) {
					$value = $currentValue;
				} else {
					$value = is_string( $submitted ) ? $submitted : '';
					if ( $value === '' && self::is_secret_field( $field ) ) {
						$value = $currentValue;
					}
				}
				$o['providers']['sms_credentials'][ $name ][ $field ] = self::sanitize_provider_value( $field, $value );
			}
		}


		// Email always uses the built-in WordPress adapter; presentation settings live under providers.email.
		$email = is_array( $in['providers']['email'] ?? null ) ? $in['providers']['email'] : array();
		$o['providers']['email']['sender_name']    = sanitize_text_field( $email['sender_name'] ?? self::default_email_sender_name() );
		$o['providers']['email']['sender_address'] = sanitize_email( $email['sender_address'] ?? self::default_email_sender_address() );
		$o['providers']['email']['subject']        = sanitize_text_field( $email['subject'] ?? '' );
		$o['providers']['email']['body']           = wp_kses_post( $email['body'] ?? '' );
		if ( $o['providers']['email']['sender_name'] === '' ) {
			$o['providers']['email']['sender_name'] = self::default_email_sender_name();
		}
		if ( $o['providers']['email']['sender_address'] === '' ) {
			$o['providers']['email']['sender_address'] = self::default_email_sender_address();
		}

		// Request (send/reset) and verify (OTP) pairs; clamped below.
		$o['security']['request_limit']  = max( 1, min( 1000, absint( $in['security']['request_limit'] ?? $d['security']['request_limit'] ) ) );
		$o['security']['request_window'] = max( 1, min( 86400, absint( $in['security']['request_window'] ?? $d['security']['request_window'] ) ) );
		$o['security']['verify_limit']   = max( 1, min( 1000, absint( $in['security']['verify_limit'] ?? $d['security']['verify_limit'] ) ) );
		$o['security']['verify_window']  = max( 1, min( 86400, absint( $in['security']['verify_window'] ?? $d['security']['verify_window'] ) ) );

		$o['security']['protection_enabled'] = ! empty( $in['security']['protection_enabled'] );
		$o['security']['ip_multiplier']      = max( 1, min( 10, absint( $in['security']['ip_multiplier'] ?? 3 ) ) );

		$o['security']['progressive']['enabled']          = ! empty( $in['security']['progressive']['enabled'] );
		$o['security']['progressive']['initial_duration'] = max( 30, min( 3600, absint( $in['security']['progressive']['initial_duration'] ?? 60 ) ) );
		$o['security']['progressive']['multiplier']       = max( 2, min( 10, absint( $in['security']['progressive']['multiplier'] ?? 5 ) ) );
		$o['security']['progressive']['max_duration']     = max( 60, min( 86400, absint( $in['security']['progressive']['max_duration'] ?? 3600 ) ) );
		$o['security']['progressive']['decay_seconds']    = max( 60, min( 86400, absint( $in['security']['progressive']['decay_seconds'] ?? 3600 ) ) );
		if ( (int) $o['security']['progressive']['max_duration'] < (int) $o['security']['progressive']['initial_duration'] ) {
			$o['security']['progressive']['max_duration'] = (int) $o['security']['progressive']['initial_duration'];
		}

		$o['migration']['lazy_enabled'] = ! empty( $migration_input['lazy_enabled'] );

		$o['logging']['enabled']        = ! empty( $in['logging']['enabled'] );
		$o['logging']['minimum_level']  = self::allowed( $in['logging']['minimum_level'] ?? '', array( 'debug', 'info', 'notice', 'warning', 'error', 'critical' ), 'info' );
		$o['logging']['retention_days'] = max( 1, min( 365, absint( $in['logging']['retention_days'] ?? 30 ) ) );

		$o['integrations']['bricks'] = ! empty( $in['integrations']['bricks'] );
		$o['integrations']['blocks'] = ! empty( $in['integrations']['blocks'] );

		$o['redirect']['mode']                 = self::allowed( $in['redirect']['mode'] ?? '', array( 'origin', 'page', 'custom', 'disable' ), 'origin' );
		$page_id                               = absint( $in['redirect']['page_id'] ?? 0 );
		$fallback_page_id                      = absint( $in['redirect']['fallback_page_id'] ?? 0 );
		$login_page_id                         = absint( $in['redirect']['login_page_id'] ?? 0 );
		$logged_in_page_id                     = absint( $in['redirect']['logged_in_page_id'] ?? 0 );
		$woocommerce_logout_page_id            = absint( $in['redirect']['woocommerce_logout_page_id'] ?? 0 );
		$wordpress_logout_page_id              = absint( $in['redirect']['wordpress_logout_page_id'] ?? 0 );
		$o['redirect']['page_id']              = $page_id > 0 ? (string) $page_id : '';
		$o['redirect']['custom_url']           = self::safe_internal_url( (string) ( $in['redirect']['custom_url'] ?? '' ) );
		$o['redirect']['fallback_mode']        = self::allowed( $in['redirect']['fallback_mode'] ?? '', array( 'home', 'page', 'custom', 'disable' ), 'home' );
		$o['redirect']['fallback_page_id']     = $fallback_page_id > 0 ? (string) $fallback_page_id : '';
		$o['redirect']['fallback_custom_url']  = self::safe_internal_url( (string) ( $in['redirect']['fallback_custom_url'] ?? '' ) );
		$o['redirect']['login_page_id']        = $login_page_id > 0 ? (string) $login_page_id : '';
		$o['redirect']['logged_in_mode']       = self::allowed( $in['redirect']['logged_in_mode'] ?? '', array( 'home', 'page', 'custom', 'disable' ), 'home' );
		$o['redirect']['logged_in_page_id']    = $logged_in_page_id > 0 ? (string) $logged_in_page_id : '';
		$o['redirect']['logged_in_custom_url'] = self::safe_internal_url( (string) ( $in['redirect']['logged_in_custom_url'] ?? '' ) );
		$o['redirect']['woocommerce_logout_mode'] = self::allowed( $in['redirect']['woocommerce_logout_mode'] ?? '', array( 'home', 'page', 'custom', 'disable' ), 'disable' );
		$o['redirect']['woocommerce_logout_page_id'] = $woocommerce_logout_page_id > 0 ? (string) $woocommerce_logout_page_id : '';
		$o['redirect']['woocommerce_logout_custom_url'] = self::safe_internal_url( (string) ( $in['redirect']['woocommerce_logout_custom_url'] ?? '' ) );
		$o['redirect']['wordpress_logout_mode'] = self::allowed( $in['redirect']['wordpress_logout_mode'] ?? '', array( 'home', 'page', 'custom', 'disable' ), 'disable' );
		$o['redirect']['wordpress_logout_page_id'] = $wordpress_logout_page_id > 0 ? (string) $wordpress_logout_page_id : '';
		$o['redirect']['wordpress_logout_custom_url'] = self::safe_internal_url( (string) ( $in['redirect']['wordpress_logout_custom_url'] ?? '' ) );

		// Validate after assigning the final submitted value so invalid IDs cannot
		// overwrite the sanitized empty value below.
		if ( $login_page_id > 0 ) {
			$login_page = get_post( $login_page_id );
			if ( ! $login_page instanceof \WP_Post || $login_page->post_type !== 'page' || ! in_array( $login_page->post_status, array( 'publish', 'private' ), true ) ) {
				add_settings_error( 'peyvast_auth_settings', 'invalid_login_page', __( 'No login page selected. WooCommerce redirects will use the alternate page unless a published or private page is selected.', 'peyvast-auth' ) );
				$o['redirect']['login_page_id'] = '';
			}
		}

		foreach ( array( 'woocommerce_logout_page_id' => $woocommerce_logout_page_id, 'wordpress_logout_page_id' => $wordpress_logout_page_id ) as $logout_key => $logout_page_id ) {
			if ( $logout_page_id <= 0 ) { continue; }
			$page = get_post( $logout_page_id );
			if ( ! $page instanceof \WP_Post || $page->post_type !== 'page' || ! in_array( $page->post_status, array( 'publish', 'private' ), true ) ) {
				add_settings_error( 'peyvast_auth_settings', 'invalid_' . $logout_key, __( 'The selected logout destination page is unavailable. Please choose a published or private page.', 'peyvast-auth' ) );
				$o['redirect'][ $logout_key ] = '';
			}
		}

		$o['google']['enabled']   = ! empty( $in['google']['enabled'] );
		$o['google']['client_id'] = sanitize_text_field( $in['google']['client_id'] ?? '' );

		$result = self::merge( $d, $o );
		unset( $result['authentication']['password_reset']['user_login'], $result['authentication']['password_reset']['email_only'] );
		return $result;
	}

	private static function sanitize_provider_value( string $field, string $value ): string {
		return sanitize_text_field( $value );
	}

	private static function is_secret_field( string $field ): bool {
		return in_array( $field, array( 'api_key', 'password' ), true );
	}

	private static function validate_meta_key( string $key ): string {
		$key = trim( $key );
		if ( $key === '' || strlen( $key ) > 191 ) {
			return '';
		}
		return preg_match( '/^[A-Za-z0-9_\-.:\/]+$/', $key ) === 1 ? $key : '';
	}

	private static function sanitize_meta_key( string $key ): string {
		$validated = self::validate_meta_key( $key );
		return $validated;
	}

	/** Resolve a configured internal logout destination; empty means preserve platform default. */
	public static function logout_destination( string $flow ): string {
		$prefix = $flow === 'woocommerce' ? 'woocommerce_logout' : 'wordpress_logout';
		$settings = self::get( 'redirect', array() );
		$mode = (string) ( $settings[ $prefix . '_mode' ] ?? 'disable' );
		if ( $mode === 'disable' ) { return ''; }
		if ( $mode === 'home' ) { return esc_url_raw( home_url( '/' ) ); }
		if ( $mode === 'page' ) {
			$url = get_permalink( (int) ( $settings[ $prefix . '_page_id' ] ?? 0 ) );
			return $url ? esc_url_raw( $url ) : '';
		}
		if ( $mode === 'custom' ) {
			return self::safe_internal_url( (string) ( $settings[ $prefix . '_custom_url' ] ?? '' ) );
		}
		return '';
	}


	private static function safe_internal_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		$validated = wp_validate_redirect( $url, '' );
		if ( $validated === '' ) {
			return '';
		}
		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host      = wp_parse_url( $validated, PHP_URL_HOST );
		if ( ! $site_host || ! $host || strtolower( (string) $site_host ) !== strtolower( (string) $host ) ) {
			return '';
		}
		return esc_url_raw( $validated );
	}

	private static function allowed( $value, array $allowed, string $fallback ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private static function merge( array $base, array $override ): array {
		foreach ( $base as $key => $default ) {
			if ( ! array_key_exists( $key, $override ) ) {
				continue;
			}
			$value        = $override[ $key ];
			$base[ $key ] = is_array( $default ) && is_array( $value )
				? self::merge( $default, $value )
				: $value;
		}
		return $base;
	}
}
