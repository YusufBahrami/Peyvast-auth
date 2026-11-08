<?php
namespace Peyvast\Auth\Integrations\Blocks;

use Peyvast\Auth\Presentation\Frontend\Assets;
use Peyvast\Auth\Presentation\Frontend\AuthState;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Authentication\AuthenticationCapabilities;

defined( 'ABSPATH' ) || exit;

final class Integration {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'init', array( self::class, 'register' ), 20 );
	}

	public static function register(): void {
		if ( ! Settings::get( 'integrations.blocks', true ) || ! function_exists( 'register_block_type' ) ) {
			return;
		}
		self::register_assets();
		register_block_type( PEYVAST_AUTH_DIR . 'blocks/auth' );
		register_block_type( PEYVAST_AUTH_DIR . 'blocks/back-button' );
	}

	private static function register_assets(): void {
		wp_register_style( 'peyvast-auth', PEYVAST_AUTH_URL . 'assets/css/auth.css', array(), PEYVAST_AUTH_VERSION );
		wp_register_style( 'peyvast-auth-editor', PEYVAST_AUTH_URL . 'assets/css/blocks-editor.css', array( 'peyvast-auth' ), PEYVAST_AUTH_VERSION );
		wp_register_script( 'peyvast-auth-blocks-editor', PEYVAST_AUTH_URL . 'blocks/editor.js', array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n', 'wp-server-side-render' ), (string) filemtime( PEYVAST_AUTH_DIR . 'blocks/editor.js' ), true );
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'peyvast-auth-blocks-editor', 'peyvast-auth', PEYVAST_AUTH_DIR . 'languages' );
		}
		if ( ! function_exists( 'wp_add_inline_script' ) ) {
			return;
		}
		$metadata = array();
		foreach ( array( 'auth', 'back-button' ) as $block ) {
			$file = PEYVAST_AUTH_DIR . 'blocks/' . $block . '/block.json';
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$data = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$metadata[ $data['name'] ?? $block ] = array(
				'apiVersion'  => (int) ( $data['apiVersion'] ?? 2 ),
				'title'       => (string) ( $data['title'] ?? '' ),
				'category'    => (string) ( $data['category'] ?? 'widgets' ),
				'icon'        => $data['icon'] ?? 'shield-alt',
				'description' => (string) ( $data['description'] ?? '' ),
				'keywords'    => is_array( $data['keywords'] ?? null ) ? $data['keywords'] : array(),
				'textdomain'  => (string) ( $data['textdomain'] ?? 'peyvast-auth' ),
				'attributes'  => is_array( $data['attributes'] ?? null ) ? $data['attributes'] : array(),
				'supports'    => is_array( $data['supports'] ?? null ) ? $data['supports'] : array(),
			);
		}
		wp_add_inline_script( 'peyvast-auth-blocks-editor', 'window.PEYVAST_AUTH_BLOCK_METADATA = ' . wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';', 'before' );
	}

	private static function preview_stage( array $attributes ): string {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return '';
		}
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) && ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		$stage = sanitize_key( (string) wp_unslash( $_GET['peyvast_preview_stage'] ?? '' ) );
		return in_array( $stage, array( 'phone', 'password', 'otp', 'register', 'forgot', 'reset' ), true ) ? $stage : '';
	}

	public static function render_auth( array $attributes = array() ): string {
		Assets::enqueue_front();
		$render_attributes = $attributes;
		$preview           = self::preview_stage( $attributes );
		if ( $preview !== '' ) {
			$render_attributes['__peyvast_preview_stage'] = $preview;
		}
		$state  = AuthState::prepare( $render_attributes );
		$stages = self::stages( $state, $render_attributes );
		$style  = self::block_style_vars( $render_attributes );
		$extra  = array(
			'class'          => 'peyvast-auth peyvast-auth--block',
			'data-peyvast-auth' => '',
		);
		if ( $style !== '' ) {
			$extra['style'] = $style;
		}
		$wrapper_attrs = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( $extra ) : 'class="peyvast-auth peyvast-auth--block" data-peyvast-auth' . ( $style !== '' ? ' style="' . esc_attr( $style ) . '"' : '' );
		$html          = '<script type="application/json" data-peyvast-auth-config>' . wp_json_encode( $state['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>';
		$html         .= '<form class="peyvast-auth__form" id="' . esc_attr( $state['ids']['form'] ) . '" data-peyvast-auth-form novalidate>';
		foreach ( array( 'phone', 'password', 'otp', 'register', 'forgot', 'reset' ) as $stage ) {
			$html .= self::stage(
				array(
					'stage'               => $stage,
					'title'               => $state['stage_titles'][ $stage ],
					'description'         => $state['stage_descriptions'][ $stage ],
					'description_enabled' => $state['show_descriptions'][ $stage ],
					'content'             => $stages[ $stage ]['content'] ?? '',
					'actions'             => $stages[ $stage ]['actions'] ?? '',
					'active'              => $state['active_stage'],
				)
			);
		}
		$position = in_array( $state['notice_position'], array( 'top', 'bottom', 'left', 'right' ), true ) ? ' peyvast-auth__notices--' . esc_attr( $state['notice_position'] ) : '';
		return '<div ' . $wrapper_attrs . '>' . $html . '<div class="peyvast-auth__notices' . $position . '" data-peyvast-auth-notice-queue aria-live="polite" aria-atomic="false"></div></form></div>';
	}

	public static function render_back_button( array $attributes = array() ): string {
		Assets::enqueue_front();
		$text           = isset( $attributes['text'] ) && is_string( $attributes['text'] ) && trim( $attributes['text'] ) !== '' ? trim( $attributes['text'] ) : self::t( 'back' );
		$show_text      = array_key_exists( 'show_text', $attributes ) ? filter_var( $attributes['show_text'], FILTER_VALIDATE_BOOLEAN ) : true;
		$show_icon      = ! empty( $attributes['show_icon'] );
		$position_value = sanitize_key( (string) ( $attributes['icon_position'] ?? 'before' ) );
		$position       = in_array( $position_value, array( 'after', 'right' ), true ) ? 'after' : 'before';
		$aria_label     = isset( $attributes['aria_label'] ) && is_string( $attributes['aria_label'] ) && trim( $attributes['aria_label'] ) !== '' ? sanitize_text_field( (string) $attributes['aria_label'] ) : $text;
		$icon           = $show_icon ? self::safe_icon( $attributes['icon'] ?? 0 ) : '';
		$content        = $position === 'after' ? ( $show_text ? '<span class="peyvast-auth-back__text">' . esc_html( $text ) . '</span>' : '' ) . $icon : $icon . ( $show_text ? '<span class="peyvast-auth-back__text">' . esc_html( $text ) . '</span>' : '' );
		$classes        = array( 'peyvast-auth-back' );
		if ( ! empty( $attributes['icon_space'] ) ) {
			$classes[] = 'has-icon-space';
		}
		$wrapper = array(
			'class'                 => implode( ' ', $classes ),
			'data-peyvast-auth-action' => 'back',
		);
		if ( ! $show_text ) {
			$wrapper['aria-label'] = $aria_label;
		}
		if ( ! empty( $attributes['target'] ) ) {
			$wrapper['data-peyvast-auth-target'] = sanitize_text_field( (string) $attributes['target'] );
		}
		$style_vars = self::back_button_style_vars( $attributes );
		if ( $style_vars !== '' ) {
			$wrapper['style'] = $style_vars;
		}
		$attributes_html = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( $wrapper ) : 'class="' . esc_attr( $wrapper['class'] ) . '" data-peyvast-auth-action="back"' . ( ! empty( $wrapper['aria-label'] ) ? ' aria-label="' . esc_attr( $wrapper['aria-label'] ) . '"' : '' ) . ( ! empty( $wrapper['data-peyvast-auth-target'] ) ? ' data-peyvast-auth-target="' . esc_attr( $wrapper['data-peyvast-auth-target'] ) . '"' : '' ) . ( $style_vars !== '' ? ' style="' . esc_attr( $style_vars ) . '"' : '' );
		return '<button type="button" ' . $attributes_html . '>' . $content . '</button>';
	}

	private static function css_scalar( $value ) {
		if ( is_array( $value ) ) {
			foreach ( array( 'value', 'size', 'fontSize', 'color', 'slug' ) as $key ) {
				if ( array_key_exists( $key, $value ) && $value[ $key ] !== '' && $value[ $key ] !== null ) {
								return self::css_scalar( $value[ $key ] );
				}
			}
		}
		if ( is_object( $value ) ) {
			foreach ( array( 'value', 'size', 'fontSize', 'color', 'slug' ) as $key ) {
				if ( isset( $value->{$key} ) && $value->{$key} !== '' ) {
								return self::css_scalar( $value->{$key} );
				}
			}
		}
		return is_array( $value ) || is_object( $value ) ? '' : $value;
	}

	private static function css_atom( $value ): string {
		$value = trim( (string) self::css_scalar( $value ) );
		return preg_match( '/^[a-zA-Z0-9#%().,_+\-\s\/]+$/', $value ) ? $value : '';
	}

	private static function css_shadow( $value ): string {
		$value = trim( (string) self::css_scalar( $value ) );
		if ( preg_match( '/^var:preset\\|shadow\\|([a-zA-Z0-9_-]+)$/', $value, $matches ) ) {
			return 'var(--wp--preset--shadow--' . $matches[1] . ')';
		}
		return self::css_atom( $value );
	}

	private static function css_dimension( $value ): string {
		$value = self::css_scalar( $value );
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value === 0.0 ? '0' : rtrim( rtrim( number_format( (float) $value, 6, '.', '' ), '0' ), '.' ) . 'px';
		}
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return '';
		}
		if ( preg_match( '/^-?(?:\d+(?:\.\d+)?|\.\d+)$/', $value ) ) {
			return $value === '0' ? '0' : $value . 'px';
		}
		return self::css_atom( $value );
	}

	private static function css_box( $value ): string {
		if ( is_string( $value ) ) {
			return self::css_dimension( $value );
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		$parts = array();
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$parts[ $side ] = self::css_dimension( $value[ $side ] ?? '' );
		}
		return count(
			array_filter(
				$parts,
				static function ( $value ) {
					return $value !== '';
				}
			)
		) === 4 ? implode( ' ', $parts ) : '';
	}

	private static function css_border( $value ): string {
		if ( is_string( $value ) ) {
			return self::css_atom( $value );
		}
		if ( ! is_array( $value ) && ! is_object( $value ) ) {
			return '';
		}
		$raw = is_object( $value ) ? get_object_vars( $value ) : $value;
		if ( isset( $raw['color'] ) || isset( $raw['width'] ) || isset( $raw['style'] ) ) {
			return implode( ' ', array_filter( array( self::css_atom( $raw['width'] ?? '' ), self::css_atom( $raw['style'] ?? '' ), self::css_atom( $raw['color'] ?? '' ) ) ) );
		}
		$sides = array();
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			if ( isset( $raw[ $side ] ) ) {
				$sides[ $side ] = self::css_border( $raw[ $side ] );
			}
		}
		return count( $sides ) === 4 && count( array_unique( $sides ) ) === 1 ? (string) reset( $sides ) : '';
	}

	private static function add_var( array &$vars, string $name, $value, string $type = 'atom' ): void {
		$css = $type === 'box' ? self::css_box( $value ) : ( $type === 'border' ? self::css_border( $value ) : ( $type === 'dimension' ? self::css_dimension( $value ) : ( $type === 'shadow' ? self::css_shadow( $value ) : self::css_atom( $value ) ) ) );
		if ( $css !== '' ) {
			$vars[ '--peyvast-auth-' . $name ] = $css;
		}
	}

	private static function add_box_sides( array &$vars, string $name, $value ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$css = self::css_dimension( $value[ $side ] ?? '' );
			if ( $css !== '' ) {
				$vars[ '--peyvast-auth-' . $name . '-' . $side ] = $css;
			}
		}
	}

	private static function add_border_vars( array &$vars, string $name, $value ): void {
		if ( is_string( $value ) || ( ! is_array( $value ) && ! is_object( $value ) ) ) {
			return;
		}
		$raw = is_object( $value ) ? get_object_vars( $value ) : $value;
		$has_sides = false;
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side_name ) {
			$has_sides = $has_sides || isset( $raw[ $side_name ] );
		}
		$sides = array();
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side_name ) {
			$side = $has_sides ? ( $raw[ $side_name ] ?? array() ) : $raw;
			$side = is_object( $side ) ? get_object_vars( $side ) : $side;
			if ( ! is_array( $side ) || ( ! isset( $side['width'] ) && ! isset( $side['style'] ) && ! isset( $side['color'] ) ) ) {
				continue;
			}
			$parts = array(
				'width' => self::css_dimension( $side['width'] ?? '' ),
				'style' => self::css_atom( $side['style'] ?? '' ),
				'color' => self::css_atom( $side['color'] ?? '' ),
			);
			foreach ( $parts as $part => $css ) {
				if ( $css !== '' ) {
					$vars[ '--peyvast-auth-' . $name . '-' . $side_name . '-' . $part ] = $css;
				}
			}
			$border = self::css_border( $side );
			if ( $border !== '' ) {
				$sides[ $side_name ] = $border;
				$vars[ '--peyvast-auth-' . $name . '-' . $side_name ] = $border;
			}
		}
		if ( isset( $raw['radius'] ) ) {
			$radius = self::css_dimension( $raw['radius'] );
			if ( $radius !== '' ) {
				$vars[ '--peyvast-auth-' . $name . '-radius' ] = $radius;
			}
		}
		if ( count( $sides ) === 4 && count( array_unique( $sides ) ) === 1 ) {
			$first_side = is_array( $raw['top'] ?? null ) ? $raw['top'] : ( is_object( $raw['top'] ?? null ) ? get_object_vars( $raw['top'] ) : $raw['top'] ?? $raw );
			foreach (
				array(
					'width' => self::css_dimension( is_array( $first_side ) ? ( $first_side['width'] ?? '' ) : '' ),
					'style' => self::css_atom( is_array( $first_side ) ? ( $first_side['style'] ?? '' ) : '' ),
					'color' => self::css_atom( is_array( $first_side ) ? ( $first_side['color'] ?? '' ) : '' ),
				) as $part => $css
			) {
				if ( $css !== '' ) {
					$vars[ '--peyvast-auth-' . $name . '-' . $part ] = $css;
				}
			}
		}
	}

	private static function block_style_vars( array $attrs ): string {
		$vars = array();
		$map  = array( array( 'stage-title-font-size', 'stage_title_font_size' ), array( 'stage-title-font-weight', 'stage_title_font_weight' ), array( 'stage-title-line-height', 'stage_title_line_height' ), array( 'stage-title-letter-spacing', 'stage_title_letter_spacing' ), array( 'stage-title-text-align', 'stage_title_text_align' ), array( 'stage-title-color', 'stage_title_text_color' ), array( 'stage-description-font-size', 'stage_description_font_size' ), array( 'stage-description-font-weight', 'stage_description_font_weight' ), array( 'stage-description-line-height', 'stage_description_line_height' ), array( 'stage-description-letter-spacing', 'stage_description_letter_spacing' ), array( 'stage-description-text-align', 'stage_description_text_align' ), array( 'stage-description-color', 'stage_description_text_color' ), array( 'stage-description-background', 'stage_description_background' ), array( 'container-header-gap', 'container_header_gap', 'dimension' ), array( 'container-header-margin', 'container_header_margin', 'box' ), array( 'container-content-gap', 'container_content_gap', 'dimension' ), array( 'container-content-margin', 'container_content_margin', 'box' ), array( 'container-actions-gap', 'container_actions_gap', 'dimension' ), array( 'container-actions-margin', 'container_actions_margin', 'box' ), array( 'information-padding', 'information_padding', 'box' ), array( 'information-margin', 'information_margin', 'box' ), array( 'information-background', 'information_background' ), array( 'information-border', 'information_border', 'border' ), array( 'information-font-size', 'information_font_size' ), array( 'information-font-weight', 'information_font_weight' ), array( 'information-line-height', 'information_line_height' ), array( 'information-letter-spacing', 'information_letter_spacing' ), array( 'information-color', 'information_text_color' ), array( 'form-label-font-size', 'form_label_font_size' ), array( 'form-label-font-weight', 'form_label_font_weight' ), array( 'form-label-line-height', 'form_label_line_height' ), array( 'form-label-letter-spacing', 'form_label_letter_spacing' ), array( 'form-label-focus-font-size', 'form_label_focus_font_size' ), array( 'form-label-focus-font-weight', 'form_label_focus_font_weight' ), array( 'form-label-focus-line-height', 'form_label_focus_line_height' ), array( 'form-label-focus-letter-spacing', 'form_label_focus_letter_spacing' ), array( 'form-label-color', 'form_label_text_color' ), array( 'form-label-margin', 'form_label_margin', 'box' ), array( 'form-placeholder-font-size', 'form_placeholder_font_size' ), array( 'form-placeholder-font-weight', 'form_placeholder_font_weight' ), array( 'form-placeholder-line-height', 'form_placeholder_line_height' ), array( 'form-placeholder-letter-spacing', 'form_placeholder_letter_spacing' ), array( 'form-placeholder-color', 'form_placeholder_text_color' ), array( 'form-input-font-size', 'form_input_font_size' ), array( 'form-input-font-weight', 'form_input_font_weight' ), array( 'form-input-line-height', 'form_input_line_height' ), array( 'form-input-letter-spacing', 'form_input_letter_spacing' ), array( 'form-input-color', 'form_input_text_color' ), array( 'input-padding', 'form_input_padding', 'box' ), array( 'input-background', 'form_input_background' ), array( 'input-border', 'form_input_border', 'border' ), array( 'input-shadow', 'form_input_outline', 'shadow' ), array( 'inline-error-font-size', 'inline_error_font_size' ), array( 'inline-error-font-weight', 'inline_error_font_weight' ), array( 'inline-error-label-font-size', 'inline_error_label_font_size' ), array( 'inline-error-label-font-weight', 'inline_error_label_font_weight' ), array( 'inline-error-margin', 'inline_error_margin', 'box' ), array( 'inline-error-padding', 'inline_error_padding', 'box' ), array( 'inline-error-border', 'inline_error_border', 'border' ), array( 'inline-error-input-background', 'inline_error_input_background' ), array( 'inline-error-input-shadow', 'inline_error_input_outline', 'shadow' ), array( 'resend-timer-font-size', 'resend_timer_font_size' ), array( 'resend-timer-font-weight', 'resend_timer_font_weight' ), array( 'notice-gap', 'notice_gap', 'dimension' ), array( 'notice-width', 'notice_width', 'dimension' ), array( 'otp-field-width', 'otp_field_width', 'dimension' ), array( 'otp-field-height', 'otp_field_height', 'dimension' ), array( 'secondary-box-shadow', 'secondary_box_shadow', 'shadow' ) );
		foreach ( $map as $item ) {
			self::add_var( $vars, $item[0], $attrs[ $item[1] ] ?? '', $item[2] ?? 'atom' );
		}
		if ( isset( $attrs['form_input_height'] ) && (string) $attrs['form_input_height'] !== '' && (float) $attrs['form_input_height'] !== 0.0 ) {
			self::add_var( $vars, 'input-height', $attrs['form_input_height'], 'dimension' );
		}
		self::add_border_vars( $vars, 'inline-error-input-border', $attrs['inline_error_input_border'] ?? array() );
		foreach ( array(
			array( 'information-border', 'information_border' ),
			array( 'input-border', 'form_input_border' ),
			array( 'inline-error-border', 'inline_error_border' ),
			array( 'secondary-border', 'secondary_border' ),
			array( 'resend-border', 'resend_border' ),
			array( 'notice-close-border', 'notice_close_border' ),
		) as $item ) {
			self::add_border_vars( $vars, $item[0], $attrs[ $item[1] ] ?? array() );
		}
		foreach ( array( array( 'stage-title-padding', 'stage_title_padding' ), array( 'stage-title-margin', 'stage_title_margin' ), array( 'stage-description-padding', 'stage_description_padding' ), array( 'stage-description-margin', 'stage_description_margin' ) ) as $item ) {
			self::add_box_sides( $vars, $item[0], $attrs[ $item[1] ] ?? array() );
		}
		foreach ( array(
			array( 'container-header-margin', 'container_header_margin' ),
			array( 'container-content-margin', 'container_content_margin' ),
			array( 'container-actions-margin', 'container_actions_margin' ),
			array( 'information-padding', 'information_padding' ),
			array( 'information-margin', 'information_margin' ),
			array( 'form-label-margin', 'form_label_margin' ),
			array( 'input-padding', 'form_input_padding' ),
			array( 'inline-error-margin', 'inline_error_margin' ),
			array( 'inline-error-padding', 'inline_error_padding' ),
		) as $item ) {
			self::add_box_sides( $vars, $item[0], $attrs[ $item[1] ] ?? array() );
		}
		foreach ( array( 'continue', 'verify', 'login', 'register', 'forgot', 'reset_password', 'secondary', 'resend' ) as $prefix ) {
			$css_prefix = str_replace( '_', '-', $prefix );
			foreach ( array( 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-align', 'color' ) as $name ) {
				$key = $name === 'color' ? $prefix . '_text_color' : $prefix . '_' . str_replace( '-', '_', $name );
				self::add_var( $vars, $css_prefix . '-' . $name, $attrs[ $key ] ?? '' ); }
			self::add_var( $vars, $css_prefix . '-background', $attrs[ $prefix . '_background' ] ?? '' );
			self::add_var( $vars, $css_prefix . '-padding', $attrs[ $prefix . '_padding' ] ?? '', 'box' );
			self::add_box_sides( $vars, $css_prefix . '-padding', $attrs[ $prefix . '_padding' ] ?? array() );
			self::add_var( $vars, $css_prefix . '-border', $attrs[ $prefix . '_border' ] ?? '', 'border' );
			self::add_border_vars( $vars, $css_prefix . '-border', $attrs[ $prefix . '_border' ] ?? array() );
			self::add_var( $vars, $css_prefix . '-icon-size', $attrs[ $prefix . '_icon_size' ] ?? '' );
			self::add_var( $vars, $css_prefix . '-icon-color', $attrs[ $prefix . '_icon_color' ] ?? '' );
			self::add_var( $vars, $css_prefix . '-icon-gap', $attrs[ $prefix . '_icon_gap' ] ?? '', 'dimension' );
		}
		foreach ( array( 'success', 'error', 'warning', 'info' ) as $type ) {
			self::add_var( $vars, 'notice-' . $type . '-background', $attrs[ 'notice_' . $type . '_background' ] ?? '' );
			self::add_var( $vars, 'notice-' . $type . '-border', $attrs[ 'notice_' . $type . '_border' ] ?? '', 'border' );
			self::add_border_vars( $vars, 'notice-' . $type . '-border', $attrs[ 'notice_' . $type . '_border' ] ?? array() );
			self::add_var( $vars, 'notice-' . $type . '-padding', $attrs[ 'notice_' . $type . '_padding' ] ?? '', 'box' );
			self::add_box_sides( $vars, 'notice-' . $type . '-padding', $attrs[ 'notice_' . $type . '_padding' ] ?? array() );
			self::add_var( $vars, 'notice-' . $type . '-shadow', $attrs[ 'notice_' . $type . '_shadow' ] ?? '', 'shadow' ); }
		self::add_var( $vars, 'notice-close-background', $attrs['notice_close_background'] ?? '' );
		self::add_var( $vars, 'notice-close-border', $attrs['notice_close_border'] ?? '', 'border' );
		self::add_border_vars( $vars, 'notice-close-border', $attrs['notice_close_border'] ?? array() );
		self::add_var( $vars, 'notice-close-padding', $attrs['notice_close_padding'] ?? '', 'box' );
		self::add_box_sides( $vars, 'notice-close-padding', $attrs['notice_close_padding'] ?? array() );
		return implode(
			';',
			array_map(
				static function ( $key, $value ) {
					return $key . ':' . $value;
				},
				array_keys( $vars ),
				array_values( $vars )
			)
		);
	}

	private static function action_style_vars( array $attrs, string $style_key, bool $primary = false ): string {
		$vars       = array();
		$css_prefix = str_replace( '_', '-', $style_key );
		foreach ( array( 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-align', 'color' ) as $name ) {
			$key = $style_key . '_' . str_replace( '-', '_', $name );
			self::add_var( $vars, $css_prefix . '-' . $name, $attrs[ $key ] ?? '' ); }
		self::add_var( $vars, $css_prefix . '-background', $attrs[ $style_key . '_background' ] ?? '' );
		self::add_var( $vars, $css_prefix . '-padding', $attrs[ $style_key . '_padding' ] ?? '', 'box' );
		self::add_var( $vars, $css_prefix . '-border', $attrs[ $style_key . '_border' ] ?? '', 'border' );
		self::add_var( $vars, $css_prefix . '-icon-gap', $attrs[ $style_key . '_icon_gap' ] ?? '', 'dimension' );
		self::add_var( $vars, $css_prefix . '-icon-size', $attrs[ $style_key . '_icon_size' ] ?? '' );
		self::add_var( $vars, $css_prefix . '-icon-color', $attrs[ $style_key . '_icon_color' ] ?? '' );
		return implode(
			';',
			array_map(
				static function ( $key, $value ) {
					return $key . ':' . $value;
				},
				array_keys( $vars ),
				array_values( $vars )
			)
		);
	}

	private static function back_button_style_vars( array $attrs ): string {
		$vars = array();
		foreach ( array( 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-align', 'color' ) as $name ) {
			self::add_var( $vars, 'back-button-' . $name, $attrs[ str_replace( '-', '_', $name ) ] ?? '' );
		}
		self::add_var( $vars, 'back-background', $attrs['background'] ?? '' );
		self::add_var( $vars, 'back-padding', $attrs['padding'] ?? '', 'box' );
		self::add_box_sides( $vars, 'back-padding', $attrs['padding'] ?? array() );
		self::add_var( $vars, 'back-border', $attrs['border'] ?? '', 'border' );
		self::add_border_vars( $vars, 'back-border', $attrs['border'] ?? array() );
		self::add_var( $vars, 'back-icon-gap', $attrs['icon_gap'] ?? '', 'dimension' );
		self::add_var( $vars, 'back-icon-size', $attrs['icon_size'] ?? '' );
		self::add_var( $vars, 'back-icon-color', $attrs['icon_color'] ?? '' );
		return implode(
			';',
			array_map(
				static function ( $key, $value ) {
					return $key . ':' . $value;
				},
				array_keys( $vars ),
				array_values( $vars )
			)
		);
	}

	private static function attr( array $attrs, string $key, string $fallback = '' ): string {
		return isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) && trim( $attrs[ $key ] ) !== '' ? trim( $attrs[ $key ] ) : $fallback; }
	private static function css_value( $value ): string {
		$value = trim( (string) $value );
		return preg_match( '/^[a-zA-Z0-9#%().,_+\-\s\/]+$/', $value ) ? $value : ''; }
	private static function bool_attr( array $attrs, string $key, bool $fallback = false ): bool {
		if ( ! array_key_exists( $key, $attrs ) ) {
			return $fallback;
		} return is_string( $attrs[ $key ] ) ? filter_var( $attrs[ $key ], FILTER_VALIDATE_BOOLEAN ) : (bool) $attrs[ $key ]; }
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
		return $map[ $key ] ?? ''; }

	private static function safe_icon( $icon ): string {
		return AuthState::render_icon( $icon );
	}

	private static function icon_value( array $attrs, string $key ) {
		return array_key_exists( $key, $attrs ) ? $attrs[ $key ] : ''; }
	private static function icon_markup( $icon ): string {
		return self::safe_icon( $icon ); }
	private static function notice_icon( $icon ): string {
		return self::safe_icon( $icon ); }

	private static function render_field( string $label, string $input_html, bool $required = false, string $description = '' ): string {
		if ( $required && substr( $input_html, -1 ) === '>' ) {
			$input_html = substr( $input_html, 0, -1 ) . ' required>';
		}
		preg_match( '/id="([^"]+)"/', $input_html, $m );
		$for      = $m[1] ?? '';
		$error_id = $for ? $for . '-error' : '';
		if ( $error_id !== '' ) {
			$input_html = preg_replace( '/(\s)aria-describedby="[^"]*"/i', '', $input_html );
		}
		return '<div class="peyvast-auth-field" data-peyvast-auth-field><div class="peyvast-auth-field__control">' . $input_html . '<label class="peyvast-auth-field__label" for="' . esc_attr( $for ) . '">' . esc_html( $label ) . ( $required ? '<span class="peyvast-auth-field__required" aria-hidden="true">*</span>' : '' ) . '</label></div>' . ( $description !== '' ? '<p class="peyvast-auth-field__description">' . esc_html( $description ) . '</p>' : '' ) . '<p class="peyvast-auth-field__error" id="' . esc_attr( $error_id ) . '" data-peyvast-auth-inline-error hidden aria-live="polite"></p></div>';
	}

	private static function stage( array $args ): string {
		$stage               = (string) ( $args['stage'] ?? '' );
		$title               = (string) ( $args['title'] ?? '' );
		$description         = (string) ( $args['description'] ?? '' );
		$description_enabled = ! empty( $args['description_enabled'] ) && $description !== '';
		$content             = (string) ( $args['content'] ?? '' );
		$actions             = (string) ( $args['actions'] ?? '' );
		$active              = $stage === (string) ( $args['active'] ?? 'phone' );
		$hidden              = $active ? '' : ' hidden';
		$inert               = $active ? '' : ' inert';
		return '<section class="peyvast-auth-stage ' . ( $active ? 'is-active' : '' ) . '" data-step="' . esc_attr( $stage ) . '" aria-hidden="' . ( $active ? 'false' : 'true' ) . '"' . $inert . $hidden . '><header class="peyvast-auth-stage__header"><h2 class="peyvast-auth__title" data-stage-heading>' . wp_kses_post( $title ) . '</h2>' . ( $description_enabled ? '<div class="peyvast-auth__description" data-stage-description>' . wp_kses_post( $description ) . '</div>' : '' ) . '<div class="peyvast-auth__information" data-peyvast-auth-information role="status" aria-live="polite" hidden></div></header><div class="peyvast-auth-stage__content">' . $content . '</div><div class="peyvast-auth-stage__actions">' . $actions . '</div></section>';
	}

	private static function render_action_button( string $action, string $text, string $icon = '', string $type = 'submit', string $class = 'peyvast-auth-button', array $attrs = array(), array $options = array() ): string {
		$action_key     = str_replace( '-', '_', $action );
		$style_key      = array(
			'continue-identifier' => 'continue',
			'verify-otp'          => 'verify',
			'password-login'      => 'login',
			'forgot-send'         => 'forgot',
			'reset-password'      => 'reset_password',
		)[ $action ] ?? $action_key;
		$position       = sanitize_key( (string) ( $options['icon_position'] ?? 'before' ) );
		$position       = in_array( $position, array( 'after', 'right' ), true ) ? 'after' : 'before';
		$semantic_class = ' peyvast-auth-button--' . sanitize_html_class( $action ) . ( $style_key !== $action_key ? ' peyvast-auth-button--' . sanitize_html_class( $style_key ) : '' );
		$extra_class    = $semantic_class;
		if ( ! empty( $options['native_class'] ) ) {
			$extra_class .= ' ' . trim( (string) $options['native_class'] );
		} if ( ! empty( $options['style_classes'] ) ) {
			$extra_class .= ' ' . trim( (string) $options['style_classes'] );
		} if ( ! empty( $options['primary_class'] ) ) {
			$extra_class .= ' peyvast-auth-button--primary';
		} if ( ! empty( $options['has_icon_space'] ) ) {
			$extra_class .= ' has-icon-space';
		}
		$gap   = self::css_value( $options['icon_gap'] ?? '' );
		$style = $gap !== '' ? ' style="--peyvast-auth-' . esc_attr( str_replace( '_', '-', $style_key ) ) . '-icon-gap:' . esc_attr( $gap ) . '"' : '';
		if ( ! empty( $options['inline_style'] ) ) {
			$inline_style = trim( (string) $options['inline_style'] );
			if ( $inline_style !== '' ) {
				$style = ' style="' . esc_attr( trim( ( $gap !== '' ? '--peyvast-auth-' . str_replace( '_', '-', $style_key ) . '-icon-gap:' . $gap . ';' : '' ) . $inline_style, ';' ) ) . '"';
			}
		}
		$icon_markup = '';
		if ( trim( $icon ) !== '' ) {
			$icon_markup = $icon;
		}
		$children = $position === 'after' ? wp_kses_post( $text ) . $icon_markup : $icon_markup . wp_kses_post( $text );
		return '<button type="' . esc_attr( $type ) . '" class="' . esc_attr( $class . $extra_class ) . '" data-action="' . esc_attr( $action ) . '" data-peyvast-auth-action-button' . $style . ' aria-busy="false">' . $children . '</button>';
	}

	private static function render_secondary_button( string $key, string $text, string $action, array $attrs = array(), array $options = array() ): string {
		$classes = 'peyvast-auth-link peyvast-auth-link--' . sanitize_html_class( str_replace( '_', '-', $key ) );
		if ( ! empty( $options['native_class'] ) ) {
			$classes .= ' ' . trim( (string) $options['native_class'] );
		} if ( ! empty( $options['style_classes'] ) ) {
			$classes .= ' ' . trim( (string) $options['style_classes'] );
		} $style = trim( (string) ( $options['inline_style'] ?? '' ) );
		return '<button type="button" class="' . esc_attr( $classes ) . '" data-action="' . esc_attr( $action ) . '"' . ( $style !== '' ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>' . esc_html( $text ) . '</button>'; }
	private static function identifier_attrs( bool $phone_enabled, bool $email_enabled, string $autocomplete ): array {
		if ( $phone_enabled && ! $email_enabled ) {
			return array(
				'type'         => 'tel',
				'inputmode'    => 'tel',
				'autocomplete' => $autocomplete,
			);
		} if ( $email_enabled && ! $phone_enabled ) {
			return array(
				'type'         => 'email',
				'inputmode'    => 'email',
				'autocomplete' => $autocomplete,
			);
		} return array(
			'type'         => 'text',
			'inputmode'    => 'text',
			'autocomplete' => '',
		); }
	private static function render_identifier_stage( string $id, string $label, string $placeholder, string $continue_text, string $continue_icon, bool $show_password, array $attrs, bool $google_enabled, string $google_text, array $button_options = array() ): array {
		$field         = AuthenticationCapabilities::field( 'otp_login' );
		$email_enabled = in_array( 'email', $field['identifiers'], true );
		$phone_enabled = in_array( 'phone', $field['identifiers'], true );
		$ia            = self::identifier_attrs( $phone_enabled, $email_enabled, 'tel' );
		$input         = '<input id="' . esc_attr( $id ) . '" class="peyvast-auth-input" type="' . esc_attr( $ia['type'] ) . '" name="identifier" data-identifier' . ( $ia['autocomplete'] !== '' ? ' autocomplete="' . esc_attr( $ia['autocomplete'] ) . '"' : '' ) . ' inputmode="' . esc_attr( $ia['inputmode'] ) . '" dir="rtl"' . ( $placeholder !== '' ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '' ) . ' aria-invalid="false">';
		$content       = self::render_field( $label, $input );
		$secondary     = '';
		if ( $show_password ) {
			$secondary .= self::render_secondary_button( 'password_link', self::attr( $attrs, 'password_link_text', self::t( 'password_method' ) ), 'show-password', $attrs, $button_options['password_link'] ?? array() );
		} $actions = self::render_action_button( 'continue-identifier', $continue_text, $continue_icon, 'submit', 'peyvast-auth-button', $attrs, $button_options['continue-identifier'] ?? array() );
		if ( $google_enabled ) {
			$actions .= '<div class="peyvast-auth-google-action" data-google-action><div data-google-login></div></div>';
		} if ( $secondary !== '' ) {
			$actions .= '<div class="peyvast-auth-stage__secondary-actions" data-peyvast-auth-secondary-actions>' . $secondary . '</div>';
		} return array(
			'content' => $content,
			'actions' => $actions,
		); }
	private static function render_password_stage( string $identifier_id, string $password_id, bool $email_enabled, string $identifier_placeholder, string $password_placeholder, string $login_text, string $login_icon, string $forgot_text, bool $reset_enabled, array $attrs, array $button_options = array() ): array {
		$field                     = AuthenticationCapabilities::field( 'password_login' );
		$email_enabled             = in_array( 'email', $field['identifiers'], true );
		$label                     = $field['label'];
		$password_identifier_label = AuthenticationCapabilities::apply_method_placeholders( self::attr( $attrs, 'password_identifier_label', $label ), 'password_login' );
		$password_label            = self::attr( $attrs, 'password_label', self::t( 'password' ) );
		$ia                        = self::identifier_attrs( in_array( 'phone', $field['identifiers'], true ), $email_enabled, 'username' );
		$content                   = self::render_field( $password_identifier_label, '<input id="' . esc_attr( $identifier_id ) . '" class="peyvast-auth-input" type="' . esc_attr( $ia['type'] ) . '" name="password_identifier" data-password-identifier' . ( $ia['autocomplete'] !== '' ? ' autocomplete="' . esc_attr( $ia['autocomplete'] ) . '"' : '' ) . ' inputmode="' . esc_attr( $ia['inputmode'] ) . '" dir="rtl" placeholder="' . esc_attr( $identifier_placeholder ) . '" aria-invalid="false">' );
		$content                  .= self::render_field( $password_label, '<input id="' . esc_attr( $password_id ) . '" class="peyvast-auth-input" type="password" name="login_password" data-login-password autocomplete="current-password" placeholder="' . esc_attr( $password_placeholder ) . '" aria-invalid="false">' );
		$actions                   = self::render_action_button( 'password-login', $login_text, $login_icon, 'submit', 'peyvast-auth-button', $attrs, $button_options['password-login'] ?? array() );
		if ( $reset_enabled ) {
			$actions .= '<div class="peyvast-auth-stage__secondary-actions" data-peyvast-auth-secondary-actions>' . self::render_secondary_button( 'forgot_link', $forgot_text, 'show-forgot', $attrs, $button_options['forgot_link'] ?? array() ) . '</div>';
		} return array(
			'content' => $content,
			'actions' => $actions,
		); }
	private static function render_otp_stage( int $length, string $resend_text, string $verify_text, string $verify_icon, string $id, array $attrs, array $button_options = array() ): array {
		$length   = max( 4, min( 8, $length ) );
		$input_id = 'peyvast-auth-otp-' . $id;
		$label    = self::t( 'otp' );
		$digits   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$digits .= '<input id="' . esc_attr( $input_id . '-' . ( $i + 1 ) ) . '" class="peyvast-auth-otp__input peyvast-auth-otp__digit peyvast-auth-input" data-otp-input' . ( 0 === $i ? ' data-otp-first' : '' ) . ' data-otp-digit="' . esc_attr( $i ) . '" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="1" dir="ltr" placeholder=" " aria-label="' . esc_attr( sprintf( $label . ' %d', $i + 1 ) ) . '" aria-invalid="false">';
		}
		$input    = '<div class="peyvast-auth-otp__fields" data-otp-fields role="group" aria-label="' . esc_attr( $label ) . '">' . $digits . '</div>';
		$input_error_id = $input_id . '-error';
		$content  = '<div class="peyvast-auth-otp" data-otp-wrap>'
			. '<div class="peyvast-auth-field" data-peyvast-auth-field>'
			. '<div class="peyvast-auth-field__control">' . $input . '</div>'
			. '<p class="peyvast-auth-field__error" id="' . esc_attr( $input_error_id ) . '" data-peyvast-auth-inline-error hidden aria-live="polite"></p>'
			. '</div>'
			. '<div class="peyvast-auth-otp__feedback" data-otp-feedback aria-live="polite"></div><div class="peyvast-auth-otp__resend" data-otp-resend-component><div class="peyvast-auth__resend-slot" data-resend-slot><span class="peyvast-auth__timer" data-otp-timer hidden></span>' . self::render_action_button( 'resend', $resend_text, '', 'button', 'peyvast-auth-link peyvast-auth-link--resend', $attrs, $button_options['resend'] ?? array() ) . '</div></div></div>';
		return array(
			'content' => $content,
			'actions' => self::render_action_button( 'verify-otp', $verify_text, $verify_icon, 'submit', 'peyvast-auth-button', $attrs, $button_options['verify-otp'] ?? array() ),
		); }
	private static function render_register_stage( array $fields, string $first_name_id, string $last_name_id, string $email_id, string $password_id, string $register_text, string $register_icon, array $attrs, string $first_name_placeholder, string $last_name_placeholder, string $email_placeholder, string $password_placeholder, array $button_options = array() ): array {
		$content = '';
		if ( ! empty( $fields['first_name']['enabled'] ) ) {
			$content .= self::render_field( self::attr( $attrs, 'first_name_label', self::t( 'first_name' ) ), '<input id="' . esc_attr( $first_name_id ) . '" class="peyvast-auth-input" type="text" data-first-name autocomplete="given-name" placeholder="' . esc_attr( $first_name_placeholder ) . '" aria-invalid="false">', ! empty( $fields['first_name']['required'] ) );
		}
		if ( ! empty( $fields['last_name']['enabled'] ) ) {
			$content .= self::render_field( self::attr( $attrs, 'last_name_label', self::t( 'last_name' ) ), '<input id="' . esc_attr( $last_name_id ) . '" class="peyvast-auth-input" type="text" data-last-name autocomplete="family-name" placeholder="' . esc_attr( $last_name_placeholder ) . '" aria-invalid="false">', ! empty( $fields['last_name']['required'] ) );
		} if ( ! empty( $fields['email']['enabled'] ) ) {
			$content .= self::render_field( self::attr( $attrs, 'email_label', self::t( 'email' ) ), '<input id="' . esc_attr( $email_id ) . '" class="peyvast-auth-input" type="email" data-register-email autocomplete="email" inputmode="email" dir="ltr" placeholder="' . esc_attr( $email_placeholder ) . '" aria-invalid="false">', ! empty( $fields['email']['required'] ) );
		} if ( ! empty( $fields['password']['enabled'] ) ) {
			$content .= self::render_field( self::attr( $attrs, 'password_label', self::t( 'password' ) ), '<input id="' . esc_attr( $password_id ) . '" class="peyvast-auth-input" type="password" data-register-password autocomplete="new-password" placeholder="' . esc_attr( $password_placeholder ) . '" aria-invalid="false">', ! empty( $fields['password']['required'] ) );
		} return array(
			'content' => $content,
			'actions' => self::render_action_button( 'register', $register_text, $register_icon, 'submit', 'peyvast-auth-button', $attrs, $button_options['register'] ?? array() ),
		); }
	private static function render_forgot_stage( string $id, bool $email_enabled, string $placeholder, string $continue_text, string $continue_icon, array $attrs, array $button_options = array() ): array {
		$field         = AuthenticationCapabilities::field( 'password_reset' );
		$email_enabled = in_array( 'email', $field['identifiers'], true );
		$label         = AuthenticationCapabilities::apply_method_placeholders( self::attr( $attrs, 'forgot_identifier_label', $field['label'] ), 'password_reset' );
		$ia            = self::identifier_attrs( in_array( 'phone', $field['identifiers'], true ), $email_enabled, 'username' );
		return array(
			'content' => self::render_field( $label, '<input id="' . esc_attr( $id ) . '" class="peyvast-auth-input" type="' . esc_attr( $ia['type'] ) . '" data-forgot-identifier' . ( $ia['autocomplete'] !== '' ? ' autocomplete="' . esc_attr( $ia['autocomplete'] ) . '"' : '' ) . ' inputmode="' . esc_attr( $ia['inputmode'] ) . '" dir="rtl" placeholder="' . esc_attr( $placeholder ) . '" aria-invalid="false">' ),
			'actions' => self::render_action_button( 'forgot-send', $continue_text, $continue_icon, 'submit', 'peyvast-auth-button', $attrs, $button_options['forgot-send'] ?? array() ),
		); }
	private static function render_reset_stage( string $id, string $placeholder, string $icon, array $attrs, array $button_options = array() ): array {
		$reset_text = self::attr( $attrs, 'reset_password_text', self::t( 'save_password' ) );
		return array(
			'content' => self::render_field( self::attr( $attrs, 'new_password_label', self::t( 'new_password' ) ), '<input id="' . esc_attr( $id ) . '" class="peyvast-auth-input" type="password" data-new-password autocomplete="new-password" placeholder="' . esc_attr( $placeholder ) . '" aria-invalid="false">' ),
			'actions' => self::render_action_button( 'reset-password', $reset_text, $icon, 'submit', 'peyvast-auth-button', $attrs, $button_options['reset-password'] ?? array() ),
		); }

	private static function button_options( array $attrs, string $action ): array {
		$key      = array(
			'continue-identifier' => 'continue',
			'verify-otp'          => 'verify',
			'password-login'      => 'login',
			'forgot-send'         => 'forgot',
			'reset-password'      => 'reset_password',
		)[ $action ] ?? str_replace( '-', '_', $action );
		$position = sanitize_key( (string) ( $attrs[ $key . '_icon_position' ] ?? 'before' ) );
		return array(
			'icon_position'  => in_array( $position, array( 'after', 'right' ), true ) ? 'after' : 'before',
			'native_class'   => '',
			'style_classes'  => '',
			'primary_class'  => in_array( $action, array( 'continue-identifier', 'verify-otp', 'password-login', 'register', 'forgot-send', 'reset-password' ), true ),
			'icon_gap'       => self::css_dimension( $attrs[ $key . '_icon_gap' ] ?? '' ),
			'has_icon_space' => ! empty( $attrs[ $key . '_icon_space' ] ),
			'inline_style'   => self::action_style_vars( $attrs, $key ),
		); }
	private static function secondary_options( array $attrs ): array {
		return array(
			'native_class'  => '',
			'style_classes' => '',
			'inline_style'  => self::action_style_vars( $attrs, 'secondary' ),
		); }

	private static function stages( array $state, array $attrs ): array {
		$options = array();
		foreach ( array( 'continue-identifier', 'verify-otp', 'password-login', 'register', 'forgot-send', 'reset-password', 'resend' ) as $action ) {
			$options[ $action ] = self::button_options( $attrs, $action );
		}
		$options['password_link'] = self::secondary_options( $attrs );
		$options['forgot_link']   = self::secondary_options( $attrs );
		$password_forgot_text     = trim( (string) ( $state['attrs']['password_reset_link_text'] ?? '' ) );
		if ( $password_forgot_text === '' ) {
			$password_forgot_text = trim( (string) ( $state['attrs']['forgot_text'] ?? '' ) );
		} if ( $password_forgot_text === '' ) {
			$password_forgot_text = $state['texts']['forgot'];
		}
		$stages             = array();
		$stages['phone']    = self::render_identifier_stage( $state['ids']['identifier'], $state['labels']['identifier'], $state['placeholders']['identifier'], $state['texts']['continue'], $state['icons']['continue'], $state['password_enabled'] && ( $state['password_phone'] || $state['password_email'] ), $attrs, $state['google_enabled'], $state['texts']['google'], $options );
		$stages['password'] = self::render_password_stage( $state['ids']['password_identifier'], $state['ids']['password_login'], $state['identifier_email_enabled'], $state['placeholders']['password_identifier'], $state['placeholders']['password'], $state['texts']['login'], $state['icons']['login'], $password_forgot_text, $state['reset_enabled'], $attrs, $options );
		$stages['otp']      = self::render_otp_stage( $state['otp_length'], $state['texts']['resend'], $state['texts']['verify'], $state['icons']['verify'], $state['ids']['instance'], $attrs, $options );
		$stages['register'] = self::render_register_stage( $state['fields'], $state['ids']['first_name'], $state['ids']['last_name'], $state['ids']['email'], $state['ids']['password'], $state['texts']['register'], $state['icons']['register'], $attrs, $state['placeholders']['first_name'], $state['placeholders']['last_name'], $state['placeholders']['email'], $state['placeholders']['password'], $options );
		$stages['forgot']   = self::render_forgot_stage( $state['ids']['forgot'], $state['identifier_email_enabled'], $state['placeholders']['forgot_identifier'], $state['texts']['forgot_send'], $state['icons']['forgot'], $attrs, $options );
		$stages['reset']    = self::render_reset_stage( $state['ids']['new_password'], $state['placeholders']['new_password'], $state['icons']['reset_password'], $attrs, $options );
		return $stages;
	}
}
