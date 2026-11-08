<?php
namespace Peyvast\Auth\Presentation\Frontend;

use Peyvast\Auth\Application\Authentication\GoogleAuthenticationService;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Authentication\AuthenticationCapabilities;
use Peyvast\Auth\Infrastructure\WordPress\GuestSession;

defined( 'ABSPATH' ) || exit;

/** Neutral presentation state and rendering helpers; owns no integration markup. */
final class AuthState {
	private static $icon_cache = array();
	private static function attr( array $attrs, string $key, string $fallback = '' ): string {
		return isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) && trim( $attrs[ $key ] ) !== '' ? trim( $attrs[ $key ] ) : $fallback;
	}

	private static function css_value( $value ): string {
		$value = trim( (string) $value );
		return preg_match( '/^[a-zA-Z0-9#%().,_+\\-\\s\\/]+$/', $value ) ? $value : '';
	}

	private static function bool_attr( array $attrs, string $key, bool $fallback = false ): bool {
		if ( ! array_key_exists( $key, $attrs ) ) {
			return $fallback;
		}
		if ( is_string( $attrs[ $key ] ) ) {
			return filter_var( $attrs[ $key ], FILTER_VALIDATE_BOOLEAN );
		}
		return (bool) $attrs[ $key ];
	}

	private static function t( string $key ): string {
		$map = array(
			'phone_or_email'  => __( 'Mobile number or email', 'peyvast-auth' ),
			'phone'           => __( 'Mobile number', 'peyvast-auth' ),
			'email'           => __( 'Email address', 'peyvast-auth' ),
			'password'        => __( 'Password', 'peyvast-auth' ),
			'otp'             => __( 'Verification code', 'peyvast-auth' ),
			'first_name'      => __( 'First name', 'peyvast-auth' ),
			'last_name'       => __( 'Last name', 'peyvast-auth' ),
			'continue'        => __( 'Continue', 'peyvast-auth' ),
			'verify'          => __( 'Verify and continue', 'peyvast-auth' ),
			'login'           => __( 'Sign in', 'peyvast-auth' ),
			'register'        => __( 'Create account', 'peyvast-auth' ),
			'forgot'          => __( 'Forgot your password?', 'peyvast-auth' ),
			'new_password'    => __( 'New password', 'peyvast-auth' ),
			'save_password'   => __( 'Save password', 'peyvast-auth' ),
			'resend'          => __( 'Resend code', 'peyvast-auth' ),
			'password_method' => __( 'Continue with password', 'peyvast-auth' ),
			'google'          => __( 'Continue with Google', 'peyvast-auth' ),
			'back'            => __( 'Back', 'peyvast-auth' ),
		);
		return $map[ $key ] ?? '';
	}

	private static function redirect_context(): string {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '';
		}
		if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
			return '';
		}
		if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() ) {
			return '';
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return '';
		}

		$home_host         = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$current_uri       = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' );
		$current_url       = home_url( $current_uri ?: '/' );
		$current_page_id   = absint( Settings::get( 'redirect.login_page_id', 0 ) );
		$current_login_url = $current_page_id ? get_permalink( $current_page_id ) : '';

		$candidates = array();
		if ( ! empty( $_GET['peyvast_return_to'] ) ) {
			$candidates[] = esc_url_raw( wp_unslash( $_GET['peyvast_return_to'] ) );
		}
		$referrer = wp_get_referer();
		if ( $referrer ) {
			$candidates[] = $referrer;
		}

		foreach ( $candidates as $candidate ) {
			$validated = wp_validate_redirect( $candidate, '' );
			if ( ! $validated ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $validated, PHP_URL_HOST ) );
			if ( ! $home_host || ! $host || ! hash_equals( $home_host, $host ) ) {
				continue;
			}
			if ( $current_login_url && untrailingslashit( $validated ) === untrailingslashit( $current_login_url ) ) {
				continue;
			}
			if ( untrailingslashit( $validated ) === untrailingslashit( $current_url ) ) {
				continue;
			}
			return esc_url_raw( $validated );
		}
		return '';
	}

	private static function safe_icon( $icon ): string {
		$cache_key = is_scalar( $icon ) ? (string) $icon : md5( (string) wp_json_encode( $icon ) );
		if ( array_key_exists( $cache_key, self::$icon_cache ) ) {
			return self::$icon_cache[ $cache_key ];
		}

		$id  = 0;
		$key = '';
		if ( is_array( $icon ) ) {
			$key = isset( $icon['icon'] ) ? sanitize_key( (string) $icon['icon'] ) : '';
			$id  = isset( $icon['id'] ) ? absint( $icon['id'] ) : 0;
			if ( ! $id && isset( $icon['svg']['id'] ) ) {
				$id = absint( $icon['svg']['id'] );
			}
		} elseif ( is_numeric( $icon ) ) {
			$id = absint( $icon );
		} elseif ( is_string( $icon ) ) {
			$value = trim( $icon );
			if ( $value === '' ) {
				return '';
			}
			if ( strpos( $value, '<' ) === 0 ) {
				if ( preg_match( '#^<i\s+class="[a-zA-Z0-9 _\-]+"\s*/?>$#i', $value ) || preg_match( '#^<i\s+class="[a-zA-Z0-9 _\-]+"\s*></i>$#i', $value ) ) {
					self::$icon_cache[ $cache_key ] = $value;
					return $value;
				}
				return '';
			}
			if ( ctype_digit( (string) $value ) ) {
				$id = absint( $value );
			} else {
				$key = sanitize_key( $value );
			}
		}

		if ( $id > 0 ) {
			$attachment_icon = self::svg_attachment_icon( $id );
			if ( $attachment_icon !== '' ) {
				self::$icon_cache[ $cache_key ] = $attachment_icon;
				return $attachment_icon;
			}
		}

		$allowed = array( 'check', 'close', 'error', 'info', 'warning' );
		if ( $key !== '' && in_array( $key, $allowed, true ) ) {
			$file = PEYVAST_AUTH_DIR . 'assets/img/' . $key . '.svg';
			if ( is_readable( $file ) ) {
				$raw                            = file_get_contents( $file );
				self::$icon_cache[ $cache_key ] = $raw === false ? '' : trim( (string) $raw );
				return self::$icon_cache[ $cache_key ];
			}
		}
		self::$icon_cache[ $cache_key ] = '';
		return self::$icon_cache[ $cache_key ];
	}

	private static function svg_attachment_icon( int $attachment_id ): string {
		if ( 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
			return '';
		}

		$file = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || ! is_readable( $file ) ) {
			return '';
		}

		$file_size = filesize( $file );
		if ( false === $file_size || $file_size > 262144 ) {
			return '';
		}

		$svg = file_get_contents( $file );
		if ( ! is_string( $svg ) || ! preg_match( '/^\s*<svg\b/i', $svg ) ) {
			return '';
		}

		$allowed = array(
			'svg'      => array( 'xmlns' => true, 'viewBox' => true, 'width' => true, 'height' => true, 'role' => true, 'aria-hidden' => true, 'focusable' => true, 'class' => true ),
			'g'        => array( 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'class' => true ),
			'path'     => array( 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'class' => true ),
			'circle'   => array( 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ),
			'rect'     => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ),
			'line'     => array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ),
			'polyline' => array( 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ),
			'polygon'  => array( 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ),
		);

		return wp_kses( $svg, $allowed );
	}

	public static function render_icon( $icon ): string {
		return self::safe_icon( $icon );
	}


	private static function icon_value( array $attrs, string $key ) {
		return array_key_exists( $key, $attrs ) ? $attrs[ $key ] : '';
	}

	private static function notice_icon( $icon ): string {
		return self::safe_icon( $icon );
	}

	private static function loading_icon(): string {
		return '<span class="peyvast-auth-button__loading-icon" aria-hidden="true"></span>';
	}

	/** Substitute static variables server-side; case-insensitive. */
	private static function server_static_vars( string $text, int $otp_length, int $valid_minutes, string $site_name ): string {
		return str_ireplace(
			array( '{site_name}', '{otp_length}', '{valid_minutes}' ),
			array( esc_html( $site_name ), (string) $otp_length, (string) $valid_minutes ),
			$text
		);
	}

	/** Build one "OTP sent to" template with placeholders resolved server-side. */
	private static function otp_information( array $attrs, string $key, string $default, int $otp_length, int $valid_minutes, string $site_name ): string {
		$value = wp_kses_post( self::attr( $attrs, $key, $default ) );
		return self::server_static_vars( $value, $otp_length, $valid_minutes, $site_name );
	}

	public static function prepare( array $attrs = array() ): array {
		$settings                 = Settings::all();
		$otp_length               = max( 4, min( 8, (int) ( $settings['otp']['length'] ?? 6 ) ) );
		$expiration_seconds       = max( 30, (int) ( $settings['otp']['expiration_seconds'] ?? 300 ) );
		$expiration_minutes       = max( 1, (int) ceil( $expiration_seconds / 60 ) );
		$server_site_name         = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$fields                   = is_array( $settings['registration']['fields'] ?? null ) ? $settings['registration']['fields'] : array();
		$capabilities             = AuthenticationCapabilities::resolve( $settings );
		$otp_login_phone          = $capabilities['otp_login_phone_enabled'];
		$otp_login_email          = $capabilities['otp_login_email_enabled'];
		$password_enabled         = ! empty( $settings['authentication']['password_login']['enabled'] );
		$password_phone           = ! empty( $settings['authentication']['password_login']['phone'] );
		$password_email           = ! empty( $settings['authentication']['password_login']['email'] );
		$reset_enabled            = ! empty( $settings['authentication']['password_reset']['enabled'] );
		$google_enabled           = ! empty( $settings['google']['enabled'] ) && (string) ( $settings['google']['client_id'] ?? '' ) !== '';
		$identifier_field         = AuthenticationCapabilities::field( 'otp_login', $settings );
		$identifier_email_enabled = $otp_login_email;

		$stage_titles       = array(
			'phone'    => self::attr( $attrs, 'phone_title', __( 'Sign in or create an account', 'peyvast-auth' ) ),
			'password' => self::attr( $attrs, 'password_title', __( 'Sign in with password', 'peyvast-auth' ) ),
			'otp'      => self::attr( $attrs, 'otp_title', __( 'Enter the verification code', 'peyvast-auth' ) ),
			'register' => self::attr( $attrs, 'register_title', __( 'Complete your account', 'peyvast-auth' ) ),
			'forgot'   => self::attr( $attrs, 'forgot_title', __( 'Reset your password', 'peyvast-auth' ) ),
			'reset'    => self::attr( $attrs, 'reset_title', __( 'Set a new password', 'peyvast-auth' ) ),
		);
		$stage_descriptions = array(
			'phone'    => self::attr( $attrs, 'phone_description', '' ),
			'password' => self::attr( $attrs, 'password_description', '' ),
			'otp'      => self::attr( $attrs, 'otp_description', '' ),
			'register' => self::attr( $attrs, 'register_description', '' ),
			'forgot'   => self::attr( $attrs, 'forgot_description', '' ),
			'reset'    => self::attr( $attrs, 'reset_description', '' ),
		);
		$show_descriptions  = array(
			'phone'    => self::bool_attr( $attrs, 'show_phone_description' ),
			'password' => self::bool_attr( $attrs, 'show_password_description' ),
			'otp'      => self::bool_attr( $attrs, 'show_otp_description' ),
			'register' => self::bool_attr( $attrs, 'show_register_description' ),
			'forgot'   => self::bool_attr( $attrs, 'show_forgot_description' ),
			'reset'    => self::bool_attr( $attrs, 'show_reset_description' ),
		);

		// {method} in titles/descriptions resolves against each stage flow.
		$stage_method_purposes = array(
			'phone'    => 'otp_login',
			'otp'      => 'otp_login',
			'register' => 'otp_login',
			'password' => 'password_login',
			'forgot'   => 'password_reset',
			'reset'    => 'password_reset',
		);
		foreach ( $stage_titles as $_stage_key => $_stage_title ) {
			$stage_titles[ $_stage_key ]       = AuthenticationCapabilities::apply_method_placeholders( $_stage_title, $stage_method_purposes[ $_stage_key ], $settings );
			$stage_descriptions[ $_stage_key ] = AuthenticationCapabilities::apply_method_placeholders( $stage_descriptions[ $_stage_key ], $stage_method_purposes[ $_stage_key ], $settings );
			$stage_titles[ $_stage_key ]       = self::server_static_vars( $stage_titles[ $_stage_key ], $otp_length, $expiration_minutes, $server_site_name );
			$stage_descriptions[ $_stage_key ] = self::server_static_vars( $stage_descriptions[ $_stage_key ], $otp_length, $expiration_minutes, $server_site_name );
		}

		$identifier_label_default        = $identifier_field['label'];
		$identifier_label                = AuthenticationCapabilities::apply_method_placeholders( self::attr( $attrs, 'identifier_label', $identifier_label_default ), 'otp_login', $settings );
		$identifier_placeholder_default  = $identifier_field['placeholder'];
		$identifier_placeholder          = AuthenticationCapabilities::apply_method_placeholders( self::attr( $attrs, 'identifier_placeholder', $identifier_placeholder_default ), 'otp_login', $settings );
		$first_name_placeholder          = self::attr( $attrs, 'first_name_placeholder', __( 'Enter your first name', 'peyvast-auth' ) );
		$last_name_placeholder           = self::attr( $attrs, 'last_name_placeholder', __( 'Enter your last name', 'peyvast-auth' ) );
		$email_placeholder               = self::attr( $attrs, 'email_placeholder', __( 'Enter your email address', 'peyvast-auth' ) );
		$password_placeholder            = self::attr( $attrs, 'password_placeholder', __( 'Enter your password', 'peyvast-auth' ) );
		$password_identifier_placeholder = AuthenticationCapabilities::apply_method_placeholders( self::attr( $attrs, 'password_identifier_placeholder', $identifier_placeholder_default ), 'password_login', $settings );
		$forgot_identifier_placeholder   = AuthenticationCapabilities::apply_method_placeholders( self::attr( $attrs, 'forgot_identifier_placeholder', $identifier_placeholder_default ), 'password_reset', $settings );
		$new_password_placeholder        = self::attr( $attrs, 'new_password_placeholder', __( 'Enter a new password', 'peyvast-auth' ) );

		$id                     = substr( hash( 'sha256', wp_generate_uuid4() ), 0, 10 );
		GuestSession::ensure();
		$nonce                  = wp_create_nonce( 'peyvast_auth' );
		$preview_stage          = sanitize_key( (string) ( $attrs['__peyvast_preview_stage'] ?? '' ) );
		$active_stage           = in_array( $preview_stage, array( 'phone', 'password', 'otp', 'register', 'forgot', 'reset' ), true ) ? $preview_stage : 'phone';
		$return_to              = self::redirect_context();
		$form_id                = 'peyvast-auth-form-' . $id;
		$first_name_id          = 'peyvast-auth-first-name-' . $id;
		$last_name_id           = 'peyvast-auth-last-name-' . $id;
		$email_id               = 'peyvast-auth-register-email-' . $id;
		$password_id            = 'peyvast-auth-register-password-' . $id;
		$identifier_id          = 'peyvast-auth-identifier-' . $id;
		$password_identifier_id = 'peyvast-auth-password-identifier-' . $id;
		$password_login_id      = 'peyvast-auth-login-password-' . $id;
		$forgot_id              = 'peyvast-auth-forgot-id-' . $id;
		$new_password_id        = 'peyvast-auth-new-password-' . $id;

		$texts = array(
			'continue' => self::attr( $attrs, 'continue_text', self::t( 'continue' ) ),
			'verify'   => self::attr( $attrs, 'verify_text', self::t( 'verify' ) ),
			'login'    => self::attr( $attrs, 'login_text', self::t( 'login' ) ),
			'register' => self::attr( $attrs, 'register_text', self::t( 'register' ) ),
			'forgot'   => self::attr( $attrs, 'forgot_text', self::t( 'forgot' ) ),
			'forgot_send' => self::attr( $attrs, 'forgot_send_text', self::attr( $attrs, 'forgot_text', self::t( 'continue' ) ) ),
			'resend'   => self::attr( $attrs, 'resend_text', self::t( 'resend' ) ),
			'google'   => self::attr( $attrs, 'google_text', self::t( 'google' ) ),
		);
		$icons = array();
		foreach ( array( 'continue', 'verify', 'login', 'register', 'forgot', 'google', 'reset_password' ) as $action ) {
			$value            = self::icon_value( $attrs, $action . '_icon' );
			$icons[ $action ] = $value !== '' && $value !== array() && $value !== 0 ? self::safe_icon( $value ) : '';
		}
		$notice_icons = array();
		foreach ( array( 'success', 'error', 'warning', 'info', 'close' ) as $type ) {
			$notice_icons[ $type ] = self::notice_icon( $type === 'success' ? 'check' : $type );
		}

		$message_defaults    = array(
			'otp_sent_message'                => __( 'A verification code was sent to {identifier}', 'peyvast-auth' ),
			'otp_verified'                    => __( 'Your verification code has been verified', 'peyvast-auth' ),
			'registration_information'        => __( 'Complete the required account information to finish registration', 'peyvast-auth' ),
			'required'                        => __( 'Please do not leave this field empty', 'peyvast-auth' ),
			'invalid_phone'                   => __( 'Please enter a valid mobile number', 'peyvast-auth' ),
			'invalid_phone_or_email'          => __( 'Please enter a valid mobile number or email', 'peyvast-auth' ),
			'invalid_phone_email_or_username' => __( 'Please enter a valid mobile number, email, or username', 'peyvast-auth' ),
			'incorrect_identifier'            => __( 'The mobile number or email is incorrect', 'peyvast-auth' ),
			'invalid_email'                   => __( 'Please enter a valid email', 'peyvast-auth' ),
			'weak_password'                   => __( 'Please choose a password with at least 8 characters', 'peyvast-auth' ),
			'generic_error'                   => __( 'A server error occurred. Please try again in a few minutes', 'peyvast-auth' ),
			'delivery_failed'                 => __( 'The verification code could not be sent. Please try again in a few minutes', 'peyvast-auth' ),
			'too_many_requests'               => __( 'The number of requests exceeds the allowed limit', 'peyvast-auth' ),
			'otp_invalid'                     => __( 'The verification code is incorrect', 'peyvast-auth' ),
			'otp_incomplete'                  => __( 'Verification code must be {otp_length} digits', 'peyvast-auth' ),
			'session_invalid'                 => __( 'Your verification session is no longer available. Please request a new code', 'peyvast-auth' ),
			'login_success'                   => __( 'You have signed in successfully', 'peyvast-auth' ),
			'registration_success'            => __( 'Your account was created successfully', 'peyvast-auth' ),
			'password_reset_success'          => __( 'Your password was reset successfully', 'peyvast-auth' ),
		);
		$messages            = $message_defaults;
		$initial_notice      = '';
		$initial_notice_type = 'warning';
		$wc_notice           = sanitize_key( (string) ( $_GET['peyvast_wc_notice'] ?? '' ) );
		if ( $wc_notice === 'guest_checkout' ) {
			$initial_notice = __( 'Please sign in or register before continuing to checkout.', 'peyvast-auth' );
		} elseif ( $wc_notice === 'order_receipt' ) {
			$initial_notice = __( 'Please sign in before viewing your order receipt.', 'peyvast-auth' );
		}
		$custom_otp_sent = self::attr( $attrs, 'information_otp_sent', '' );
		if ( $custom_otp_sent !== '' ) {
			$messages['otp_sent_message'] = wp_kses_post( $custom_otp_sent );
		}
		$messages['otp_sent_message'] = self::server_static_vars( $messages['otp_sent_message'], $otp_length, $expiration_minutes, $server_site_name );
		$config             = array(
			'rest'                        => rest_url( 'peyvast-auth/v1' ),
			'nonce'                       => $nonce,
			'rest_nonce'                  => wp_create_nonce( 'wp_rest' ),
			'rest_api'                    => true,
			'logged_in'                   => is_user_logged_in(),
			'security_enabled'            => ! empty( $settings['security']['protection_enabled'] ),
			'preview_stage'               => $preview_stage,
			'preview_only'                => $preview_stage !== '',
			'return_to'                   => $return_to,
			'otp_length'                  => $otp_length,
			'otp_valid_seconds'           => $expiration_seconds,
			'otp_valid_minutes'           => $expiration_minutes,
			'web_otp_enabled'             => ! empty( $settings['otp']['web_otp_enabled'] ),
			'otp_login_phone_enabled'     => $otp_login_phone,
			'otp_login_email_enabled'     => $otp_login_email,
			'capabilities'                => $capabilities,
			'identifier_field'            => $identifier_field,
			'password_enabled'            => $capabilities['password_enabled'],
			'password_phone_enabled'      => $capabilities['password_phone_enabled'],
			'password_email_enabled'      => $capabilities['password_email_enabled'],
			'password_user_login_enabled' => ! empty( $settings['authentication']['password_login']['user_login'] ),
			'reset_enabled'               => $reset_enabled,
			'reset_phone_enabled'         => $capabilities['reset_phone_enabled'],
			'reset_email_enabled'         => $capabilities['reset_email_enabled'],
			'otp_email_for_phone'         => ! empty( $settings['authentication']['otp_email_for_phone'] ),
			'identifier_fields'           => array(
				'otp_login'      => AuthenticationCapabilities::field( 'otp_login', $settings ),
				'password_login' => AuthenticationCapabilities::field( 'password_login', $settings ),
				'password_reset' => AuthenticationCapabilities::field( 'password_reset', $settings ),
			),
			'google_enabled'              => $google_enabled,
			'google_client_id'            => (string) ( $settings['google']['client_id'] ?? '' ),
			'google_nonce'                => $google_enabled ? GoogleAuthenticationService::issue_nonce() : '',
			'site_name'                   => $server_site_name,
			'registration_fields'         => array(
				'first_name' => array(
					'enabled'  => ! empty( $fields['first_name']['enabled'] ),
					'required' => ! empty( $fields['first_name']['required'] ),
				),
				'last_name' => array(
					'enabled'  => ! empty( $fields['last_name']['enabled'] ),
					'required' => ! empty( $fields['last_name']['required'] ),
				),
				'email'     => array(
					'enabled'  => ! empty( $fields['email']['enabled'] ),
					'required' => ! empty( $fields['email']['required'] ),
				),
				'password'  => array(
					'enabled'  => ! empty( $fields['password']['enabled'] ),
					'required' => ! empty( $fields['password']['required'] ),
				),
			),
			'texts'                       => array( 'resend_timer_template' => self::attr( $attrs, 'resend_timer_template', __( 'Resend verification code in {seconds}', 'peyvast-auth' ) ) ),
			'icons'                       => array(
				'loading' => self::loading_icon(),
				'notice'  => $notice_icons,
			),
			'initial_notice'              => $initial_notice,
			'initial_notice_type'         => $initial_notice_type,
			'information'                 => array(
				'otp_sent'     => self::otp_information( $attrs, 'information_otp_sent', '', $otp_length, $expiration_minutes, $server_site_name ),
				'registration' => self::server_static_vars( wp_kses_post( self::attr( $attrs, 'information_registration', $message_defaults['registration_information'] ) ), $otp_length, $expiration_minutes, $server_site_name ),
			),
			'messages'                    => $messages,
			'i18n'                        => array_merge(
				$messages,
				array(
					'close'                => __( 'Close', 'peyvast-auth' ),
					'otp_sent'             => __( 'The verification code has been sent.', 'peyvast-auth' ),
					'otp_resent'           => __( 'A new verification code has been sent.', 'peyvast-auth' ),
					'restored'             => __( 'The code sent to you remains valid for {valid_minutes} minutes. If you have not received it, use Resend code.', 'peyvast-auth' ),
					'and'                  => __( 'and', 'peyvast-auth' ),
					'resend_timer_default' => __( 'Resend verification code in {seconds}', 'peyvast-auth' ),
					'sending'              => __( 'Sending', 'peyvast-auth' ),
					'channel_email'        => __( 'email', 'peyvast-auth' ),
					'channel_sms'          => __( 'mobile', 'peyvast-auth' ),
					'back'                 => __( 'Back', 'peyvast-auth' ),
				)
			),
		);

		return array(
			'attrs'                    => $attrs,
			'settings'                 => $settings,
			'fields'                   => $fields,
			'otp_length'               => $otp_length,
			'otp_login_phone'          => $otp_login_phone,
			'otp_login_email'          => $otp_login_email,
			'password_enabled'         => $password_enabled,
			'password_phone'           => $password_phone,
			'password_email'           => $password_email,
			'reset_enabled'            => $reset_enabled,
			'google_enabled'           => $google_enabled,
			'identifier_email_enabled' => $identifier_email_enabled,
			'identifier_field'         => $identifier_field,
			'stage_titles'             => $stage_titles,
			'stage_descriptions'       => $stage_descriptions,
			'show_descriptions'        => $show_descriptions,
			'labels'                   => array(
				'identifier' => $identifier_label,
			),
			'placeholders'             => array(
				'identifier'          => $identifier_placeholder,
				'first_name'          => $first_name_placeholder,
				'last_name'           => $last_name_placeholder,
				'email'               => $email_placeholder,
				'password'            => $password_placeholder,
				'password_identifier' => $password_identifier_placeholder,
				'forgot_identifier'   => $forgot_identifier_placeholder,
				'new_password'        => $new_password_placeholder,
			),
			'ids'                      => array(
				'identifier'          => $identifier_id,
				'password_identifier' => $password_identifier_id,
				'password_login'      => $password_login_id,
				'first_name'          => $first_name_id,
				'last_name'           => $last_name_id,
				'email'               => $email_id,
				'password'            => $password_id,
				'forgot'              => $forgot_id,
				'new_password'        => $new_password_id,
				'instance'            => $id,
				'form'                => $form_id,
			),
			'texts'                    => $texts,
			'icons'                    => $icons,
			'config'                   => $config,
			'active_stage'             => $active_stage,
			'notice_position'          => sanitize_key( (string) ( $attrs['notice_position'] ?? '' ) ),
		);
	}
}
