<?php
namespace Peyvast\Auth\Integrations\Bricks;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class Integration {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		if ( ! self::enabled() ) {
			return;
		}

		add_action( 'init', array( self::class, 'register_elements' ), 11 );
		add_filter( 'bricks/builder/elements', array( self::class, 'builder_elements' ), 10 );
		add_filter( 'bricks/dynamic_tags_list', array( self::class, 'tags' ), 10, 1 );
		add_filter( 'bricks/dynamic_data/render_tag', array( self::class, 'render_tag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( self::class, 'render_content' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( self::class, 'render_content' ), 20, 2 );
	}

	public static function enabled(): bool {
		return (bool) Settings::get( 'integrations.bricks', true );
	}

	public static function register_elements(): void {
		if ( ! self::enabled() || ! class_exists( 'Bricks\Elements' ) ) {
			return;
		}

		$auth_file = PEYVAST_AUTH_DIR . 'src/Integrations/Bricks/AuthElement.php';
		$back_file = PEYVAST_AUTH_DIR . 'src/Integrations/Bricks/BackButtonElement.php';

		if ( is_readable( $auth_file ) ) {
			self::register_element_once( $auth_file, 'peyvast-auth', AuthElement::class );
		}

		if ( is_readable( $back_file ) ) {
			self::register_element_once( $back_file, 'peyvast-auth-back', BackButtonElement::class );
		}
	}

	public static function builder_elements( array $elements ): array {
		if ( ! self::enabled() ) {
			return $elements;
		}

		foreach ( array( 'peyvast-auth', 'peyvast-auth-back' ) as $name ) {
			if ( ! in_array( $name, $elements, true ) ) {
				$elements[] = $name;
			}
		}

		return $elements;
	}

	private static function register_element_once( string $file, string $name, string $class ): void {
		static $registered = array();

		if ( isset( $registered[ $name ] ) ) {
			return;
		}

		try {
			\Bricks\Elements::register_element( $file, $name, $class );
			$registered[ $name ] = true;
		} catch ( \Throwable $error ) {
			Logger::error(
				'bricks',
				'element_registration_failed',
				'Bricks element registration failed.',
				array(
					'element'           => $name,
					'exception_class'   => get_class( $error ),
					'exception_message' => $error->getMessage(),
				)
			);
		}
	}

	public static function tags( array $tags ): array {
		if ( ! self::enabled() ) {
			return $tags;
		}

		$tags[] = array(
			'name'  => '{peyvast_auth_user_phone}',
			'label' => __( 'User mobile number', 'peyvast-auth' ),
			'group' => __( 'Peyvast Authentication', 'peyvast-auth' ),
		);

		return $tags;
	}

	public static function render_tag( $tag, $post, $context = 'text' ) {
		if ( ! self::enabled() || ! is_string( $tag ) ) {
			return $tag;
		}

		$name = trim( str_replace( array( '{', '}' ), '', $tag ) );

		if ( 'peyvast_auth_user_phone' !== $name ) {
			return $tag;
		}

		$value = self::user_phone_value();

		return 'textarea' === $context ? $value : esc_html( $value );
	}

	public static function render_content( $content, $post = null, $context = 'text' ) {
		$tag = '{peyvast_auth_user_phone}';

		if (
			! self::enabled()
			|| ! is_string( $content )
			|| false === strpos( $content, $tag )
		) {
			return $content;
		}

		$value = self::user_phone_value();

		if ( 'textarea' !== $context ) {
			$value = esc_html( $value );
		}

		return str_replace( $tag, $value, $content );
	}

	private static function user_phone_value(): string {
		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return '';
		}

		$stored = get_user_meta( $user->ID, '_peyvast_auth_phone', true );

		if ( ! is_scalar( $stored ) || '' === trim( (string) $stored ) ) {
			return '';
		}

		return PhoneNumber::storage_value( $stored, 'leading_zero' );
	}
}
