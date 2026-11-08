<?php
namespace Peyvast\Auth\Integrations\Bricks;

use Peyvast\Auth\Presentation\Frontend\Assets;
use Peyvast\Auth\Presentation\Frontend\AuthState;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Authentication\AuthenticationCapabilities;

defined( 'ABSPATH' ) || exit;

final class AuthElement extends \Bricks\Element {
	private static $icon_cache = array();
	public $category           = 'general';
	public $name               = 'peyvast-auth';
	public $icon               = 'ti-shield';
	public $nestable           = false;

	public function get_label() {
		return __( 'Peyvast Authentication', 'peyvast-auth' ); }
	public function get_keywords() {
		return array( 'peyvast', 'authentication', 'login', 'register', 'otp', 'password', 'phone', 'email', 'google' ); }

	public function set_control_groups() {
		$groups = array(
			'supported'         => __( 'Supported Variables', 'peyvast-auth' ),
			'preview'           => __( 'Preview Stages', 'peyvast-auth' ),
			'stages'            => __( 'Stages', 'peyvast-auth' ),
			'containers'        => __( 'Containers', 'peyvast-auth' ),
			'information'       => __( 'Information', 'peyvast-auth' ),
			'form'              => __( 'Form Fields', 'peyvast-auth' ),
			'primary_actions'   => __( 'Primary Actions', 'peyvast-auth' ),
			'secondary_actions' => __( 'Secondary Actions', 'peyvast-auth' ),
			'resend'            => __( 'Resend', 'peyvast-auth' ),
			'notice'            => __( 'Notice Queue', 'peyvast-auth' ),
		);
		foreach ( $groups as $key => $label ) {
			$this->control_groups[ $key ] = array(
				'title' => $label,
				'tab'   => 'content',
			);
		}
	}

	private function control( string $key, array $definition ): void {
		$definition['tab'] = $definition['tab'] ?? 'content';
		foreach ( array( 'label', 'description', 'content', 'placeholder' ) as $field ) {
			if ( isset( $definition[ $field ] ) && is_string( $definition[ $field ] ) && $definition[ $field ] !== '' ) {
				$definition[ $field ] = __( $definition[ $field ], 'peyvast-auth' );
			}
		}
		if ( isset( $definition['options'] ) && is_array( $definition['options'] ) ) {
			foreach ( $definition['options'] as $option => $label ) {
				if ( is_string( $label ) && $label !== '' ) {
					$definition['options'][ $option ] = __( $label, 'peyvast-auth' );
				}
			}
		}
		$this->controls[ $key ] = $definition;
	}

	private function text_control( string $key, string $label, string $group, string $help = '' ): void {
		$control = array(
			'type'          => 'text',
			'group'         => $group,
			'label'         => __( $label, 'peyvast-auth' ),
			'default'       => '',
			'inlineEditing' => true,
		);
		if ( $help !== '' ) {
			$control['description'] = __( $help, 'peyvast-auth' );
		}
		$this->control( $key, $control );
	}

	private function textarea_control( string $key, string $label, string $group, string $help = '' ): void {
		$control = array(
			'type'           => 'textarea',
			'group'          => $group,
			'label'          => __( $label, 'peyvast-auth' ),
			'default'        => '',
			'hasDynamicData' => true,
		);
		if ( $help !== '' ) {
			$control['description'] = __( $help, 'peyvast-auth' );
		}
		$this->control( $key, $control );
	}

	private function separator( string $key, string $label, string $group ): void {
		$this->control(
			$key,
			array(
				'type'  => 'separator',
				'group' => $group,
				'label' => $label,
			)
		);
	}

	private function icon_control( string $key, string $label, string $group ): void {
		$this->control(
			$key,
			array(
				'type'  => 'icon',
				'group' => $group,
				'label' => __( $label, 'peyvast-auth' ),
			)
		);
	}

	private function typography( string $key, string $label, string $group, string $selector ): void {
		$this->control(
			$key,
			array(
				'type'  => 'typography',
				'group' => $group,
				'label' => __( $label, 'peyvast-auth' ),
				'css'   => array(
					array(
						'property' => 'font',
						'selector' => $selector,
					),
				),
			)
		);
	}

	private function color( string $key, string $label, string $group, string $property, string $selector = '' ): void {
		$this->control(
			$key,
			array(
				'type'  => 'color',
				'group' => $group,
				'label' => __( $label, 'peyvast-auth' ),
				'css'   => array(
					array(
						'property' => $property,
						'selector' => $selector,
					),
				),
			)
		);
	}

	private function spacing( string $key, string $label, string $group, string $property, string $selector = '' ): void {
		$this->control(
			$key,
			array(
				'type'  => 'spacing',
				'group' => $group,
				'label' => __( $label, 'peyvast-auth' ),
				'css'   => array(
					array(
						'property' => $property,
						'selector' => $selector,
					),
				),
			)
		);
	}

	private function button_styles( string $prefix, string $group, string $selector, bool $include_text = true ): void {
		$sizes  = $this->control_options['buttonSizes'];
		$styles = $this->control_options['styles'];
		if ( $include_text ) {
			$this->text_control( $prefix . '_text', ucfirst( str_replace( '_', ' ', $prefix ) ) . ' text', $group );
		}
		$this->control(
			$prefix . '_size',
			array(
				'type'        => 'select',
				'group'       => $group,
				'label'       => 'Size',
				'options'     => $sizes,
				'inline'      => true,
				'reset'       => true,
				'placeholder' => 'Default',
			)
		);
		$this->control(
			$prefix . '_style',
			array(
				'type'        => 'select',
				'group'       => $group,
				'label'       => 'Style',
				'options'     => $styles,
				'inline'      => true,
				'reset'       => true,
				'placeholder' => 'Default',
			)
		);
		$this->control(
			$prefix . '_circle',
			array(
				'type'  => 'checkbox',
				'group' => $group,
				'label' => 'Circle',
				'reset' => true,
			)
		);
		$this->control(
			$prefix . '_outline',
			array(
				'type'  => 'checkbox',
				'group' => $group,
				'label' => 'Outline',
				'reset' => true,
			)
		);
		$this->spacing( $prefix . '_padding', 'Padding', $group, 'padding', $selector );
		$this->control(
			$prefix . '_border',
			array(
				'type'  => 'border',
				'group' => $group,
				'label' => 'Border',
				'css'   => array(
					array(
						'property' => 'border',
						'selector' => $selector,
					),
				),
			)
		);
		$this->color( $prefix . '_background', 'Background', $group, 'background-color', $selector );
		$this->typography( $prefix . '_typography', 'Typography', $group, $selector );
	}

	private function icon_button_controls( string $prefix, string $group, string $label ): void {
		$this->icon_control( $prefix . '_icon', $label . ' icon', $group );
		$this->control(
			$prefix . '_iconTypography',
			array(
				'type'     => 'typography',
				'group'    => $group,
				'label'    => 'Font icon typography',
				'description' => 'Applies to font icons. Use the SVG controls for SVG icons.',
				'css'      => array(
					array(
						'property' => 'font',
						'selector' => '.peyvast-auth-button--' . str_replace( '_', '-', $prefix ) . ' i',
					),
				),
				'required' => array( $prefix . '_icon.icon', '!=', '' ),
			)
		);
		$this->control(
			$prefix . '_iconSvgSize',
			array(
				'type'     => 'number',
				'group'    => $group,
				'label'    => 'SVG icon size',
				'units'    => true,
				'css'      => array(
					array(
						'property' => 'width',
						'selector' => '.peyvast-auth-button--' . str_replace( '_', '-', $prefix ) . ' svg',
					),
					array(
						'property' => 'height',
						'selector' => '.peyvast-auth-button--' . str_replace( '_', '-', $prefix ) . ' svg',
					),
				),
				'required' => array( $prefix . '_icon.icon', '!=', '' ),
			)
		);
		$this->control(
			$prefix . '_iconSvgColor',
			array(
				'type'     => 'color',
				'group'    => $group,
				'label'    => 'SVG icon color',
				'css'      => array(
					array(
						'property' => 'color',
						'selector' => '.peyvast-auth-button--' . str_replace( '_', '-', $prefix ) . ' svg',
					),
				),
				'required' => array( $prefix . '_icon.icon', '!=', '' ),
			)
		);
		$this->control(
			$prefix . '_iconPosition',
			array(
				'type'        => 'select',
				'group'       => $group,
				'label'       => 'Icon position',
				'options'     => $this->control_options['iconPosition'],
				'inline'      => true,
				'placeholder' => 'Right',
				'required'    => array( $prefix . '_icon.icon', '!=', '' ),
			)
		);
		$selector = '.peyvast-auth-button--' . str_replace( '_', '-', $prefix );
		$this->control(
			$prefix . '_iconGap',
			array(
				'type'     => 'number',
				'group'    => $group,
				'label'    => 'Icon gap',
				'units'    => true,
				'css'      => array(
					array(
						'property' => 'gap',
						'selector' => $selector,
					),
				),
				'required' => array( $prefix . '_icon.icon', '!=', '' ),
			)
		);
		$this->control(
			$prefix . '_iconSpace',
			array(
				'type'     => 'checkbox',
				'group'    => $group,
				'label'    => 'Space between',
				'css'      => array(
					array(
						'property' => 'justify-content',
						'value'    => 'space-between',
						'selector' => $selector,
					),
				),
				'required' => array( $prefix . '_icon.icon', '!=', '' ),
			)
		);
	}

	public function set_controls() {
		/* Global placeholder reference (shown above the tabbed control groups) */
		$this->control(
			'supported_variables',
			array(
				'type'    => 'info',
				'group'   => 'supported',
				'content' => 'Placeholders: {identifier} (the destination the code was sent to, from the server response), {channel} (accepted delivery channels from the server response), {site_name}, {otp_length} and {valid_minutes} (data from the server), {seconds} (resend countdown), {method} (the stage sign-in method). Static variables are resolved by the server; {identifier} and {channel} are resolved from the server response.',
			)
		);

		/* Preview stages (builder only) */
		$this->control(
			'preview_stage',
			array(
				'type'        => 'select',
				'group'       => 'preview',
				'label'       => 'Preview stage',
				'options'     => array(
					'phone'    => 'Phone',
					'password' => 'Password',
					'otp'      => 'OTP',
					'register' => 'Register',
					'forgot'   => 'Forgot',
					'reset'    => 'Reset',
				),
				'inline'      => true,
				'reset'       => true,
				'default'     => 'phone',
				'placeholder' => 'Phone',
			)
		);
		$this->control(
			'preview_stage_info',
			array(
				'type'    => 'info',
				'group'   => 'preview',
				'content' => 'Choose which authentication stage to show inside the builder. This control has no effect on the published page.',
			)
		);

		/* Stages */
		foreach ( array(
			'phone'    => 'Phone stage',
			'password' => 'Password stage',
			'otp'      => 'OTP stage',
			'register' => 'Registration stage',
			'forgot'   => 'Password reset request stage',
			'reset'    => 'New password stage',
		) as $key => $label ) {
			if ( $key !== 'phone' ) {
				$this->separator( 'separator_' . $key, $label, 'stages' );
			}
			$this->text_control( $key . '_title', $label . ' title', 'stages', 'Supported variable: {method}.' );
			$this->control(
				'show_' . $key . '_description',
				array(
					'type'    => 'checkbox',
					'group'   => 'stages',
					'label'   => 'Show description',
					'default' => false,
					'inline'  => true,
				)
			);
			$this->textarea_control( $key . '_description', $label . ' description', 'stages', 'Supported variable: {method}. HTML is allowed and is sanitized before output. Shown only when the description toggle is enabled.' );
			$this->controls[ $key . '_description' ]['required'] = array( 'show_' . $key . '_description', '=', true );
		}
		$this->separator( 'separator_stages_global', 'Global styles', 'stages' );
		$this->typography( 'stage_title_typography', 'Title typography', 'stages', '.peyvast-auth__title' );
		$this->typography( 'stage_description_typography', 'Description typography', 'stages', '.peyvast-auth__description' );
		$this->color( 'stage_description_background', 'Description background', 'stages', 'background-color', '.peyvast-auth__description' );
		$this->spacing( 'stage_description_padding', 'Description padding', 'stages', 'padding', '.peyvast-auth__description' );
		$this->spacing( 'stage_description_margin', 'Description margin', 'stages', 'margin', '.peyvast-auth__description' );

		/* Containers */
		foreach ( array(
			'header'  => 'Stage header',
			'content' => 'Stage content',
			'actions' => 'Stage actions',
		) as $key => $label ) {
			if ( $key !== 'header' ) {
				$this->separator( 'separator_container_' . $key, $label, 'containers' );
			}
			$selector = '.peyvast-auth-stage__' . $key;
			$this->control(
				'container_' . $key . '_gap',
				array(
					'type'  => 'number',
					'group' => 'containers',
					'label' => $label . ' gap',
					'units' => true,
					'reset' => true,
					'css'   => array(
						array(
							'property' => 'gap',
							'selector' => $selector,
						),
					),
				)
			);
			$this->spacing( 'container_' . $key . '_margin', $label . ' margin', 'containers', 'margin', $selector );
		}

		/* Information */
		$information_help = 'Supported placeholders: {identifier}, {channel}, {site_name}, {otp_length}, {valid_minutes}. HTML is allowed and is sanitized before output.';
		$this->textarea_control( 'information_otp_sent', 'OTP sent', 'information', $information_help );
		$this->textarea_control( 'information_registration', 'Registration information', 'information', $information_help );
		$this->separator( 'separator_information_global', 'Global styles', 'information' );
		$this->spacing( 'information_padding', 'Padding', 'information', 'padding', '.peyvast-auth__information-item' );
		$this->spacing( 'information_margin', 'Margin', 'information', 'margin', '.peyvast-auth__information-item' );
		$this->color( 'information_background', 'Background', 'information', 'background-color', '.peyvast-auth__information-item' );
		$this->control(
			'information_border',
			array(
				'type'  => 'border',
				'group' => 'information',
				'label' => 'Border',
				'css'   => array(
					array(
						'property' => 'border',
						'selector' => '.peyvast-auth__information-item',
					),
				),
			)
		);
		$this->typography( 'information_typography', 'Typography', 'information', '.peyvast-auth__information-item' );

		/* Form */
		$fields = array(
			'identifier'          => 'Identifier',
			'first_name'          => 'First name',
			'last_name'           => 'Last name',
			'email'               => 'Email',
			'password'            => 'Password',
			'password_identifier' => 'Password sign-in identifier',
			'forgot_identifier'   => 'Password reset identifier',
			'new_password'        => 'New password',
		);
		foreach ( $fields as $key => $label ) {
			if ( $key !== 'identifier' ) {
				$this->separator( 'separator_form_' . $key, $label, 'form' );
			}
			$this->text_control( $key . '_label', $label . ' label', 'form', 'Supported variable: {method}. It shows the enabled sign-in method for that flow.' );
			$this->text_control( $key . '_placeholder', $label . ' placeholder', 'form', 'Supported variable: {method}. It shows the enabled sign-in method for that flow.' );
		}
		$this->separator( 'separator_form_global', 'Global styles', 'form' );
		$this->typography( 'form_label_typography', 'Label typography', 'form', '.peyvast-auth-field__label' );
		$this->typography( 'form_label_focus_typography', 'Focused label typography', 'form', '.peyvast-auth-input:focus ~ .peyvast-auth-field__label, .peyvast-auth-input:not(:placeholder-shown) ~ .peyvast-auth-field__label' );
		$this->spacing( 'form_label_margin', 'Label margin', 'form', 'margin', '.peyvast-auth-field__label' );
		$this->typography( 'form_placeholder_typography', 'Placeholder typography', 'form', '.peyvast-auth-input::placeholder' );
		$this->typography( 'form_input_typography', 'Input typography', 'form', '.peyvast-auth-input' );
		$this->control(
			'form_input_height',
			array(
				'type'  => 'number',
				'group' => 'form',
				'label' => 'Input height',
				'units' => true,
				'css'   => array(
					array(
						'property' => 'height',
						'selector' => '.peyvast-auth-input',
					),
				),
			)
		);
		$this->spacing( 'form_input_padding', 'Input padding', 'form', 'padding', '.peyvast-auth-input' );
		$this->color( 'form_input_background', 'Input background', 'form', 'background-color', '.peyvast-auth-input' );
		$this->control(
			'form_input_border',
			array(
				'type'  => 'border',
				'group' => 'form',
				'label' => 'Input border',
				'css'   => array(
					array(
						'property' => 'border',
						'selector' => '.peyvast-auth-input',
					),
				),
			)
		);
		$this->control(
			'form_input_outline',
			array(
				'type'  => 'box-shadow',
				'group' => 'form',
				'label' => 'Input focus box shadow',
				'css'   => array(
					array(
						'property' => 'box-shadow',
						'selector' => '.peyvast-auth-input:focus',
					),
				),
			)
		);

		/* Separated OTP fields */
		$this->separator( 'separator_form_otp', 'OTP fields', 'form' );
		$this->control(
			'otp_field_width',
			array(
				'type'  => 'number', 'group' => 'form', 'label' => 'OTP field width', 'units' => true,
				'css'   => array( array( 'property' => 'width', 'selector' => '.peyvast-auth-otp__digit' ) ),
			)
		);
		$this->control(
			'otp_field_height',
			array(
				'type'  => 'number', 'group' => 'form', 'label' => 'OTP field height', 'units' => true,
				'css'   => array( array( 'property' => 'height', 'selector' => '.peyvast-auth-otp__digit' ) ),
			)
		);
		$this->typography( 'otp_field_typography', 'OTP field typography', 'form', '.peyvast-auth-otp__digit' );
		$this->control(
			'otp_field_border',
			array(
				'type' => 'border', 'group' => 'form', 'label' => 'OTP field border',
				'css' => array( array( 'property' => 'border', 'selector' => '.peyvast-auth-otp__digit' ) ),
			)
		);
		$this->control(
			'otp_field_outline',
			array(
				'type' => 'box-shadow', 'group' => 'form', 'label' => 'OTP field box shadow',
				'css' => array( array( 'property' => 'box-shadow', 'selector' => '.peyvast-auth-otp__digit:focus' ) ),
			)
		);
		$this->separator( 'separator_form_inline_error', 'Global inline error styles', 'form' );
		$this->typography( 'inline_error_label_typography', 'Error label typography', 'form', '.peyvast-auth-field.is-error .peyvast-auth-field__label' );
		$this->typography( 'inline_error_label_focus_typography', 'Error focused label typography', 'form', '.peyvast-auth-field.is-error .peyvast-auth-input:focus ~ .peyvast-auth-field__label, .peyvast-auth-field.is-error .peyvast-auth-input:not(:placeholder-shown) ~ .peyvast-auth-field__label' );
		$this->typography( 'inline_error_typography', 'Inline error typography', 'form', '.peyvast-auth-field__error' );
		$this->spacing( 'inline_error_margin', 'Inline error margin', 'form', 'margin', '.peyvast-auth-field__error' );
		$this->spacing( 'inline_error_padding', 'Inline error padding', 'form', 'padding', '.peyvast-auth-field__error' );
		$this->control(
			'inline_error_border',
			array(
				'type'  => 'border',
				'group' => 'form',
				'label' => 'Inline error border',
				'css'   => array(
					array(
						'property' => 'border',
						'selector' => '.peyvast-auth-field__error',
					),
				),
			)
		);
		$this->color( 'inline_error_input_background', 'Error input background', 'form', 'background-color', '.peyvast-auth-field.is-error .peyvast-auth-input' );
		$this->control(
			'inline_error_input_border',
			array(
				'type'  => 'border',
				'group' => 'form',
				'label' => 'Error input border',
				'css'   => array(
					array(
						'property' => 'border',
						'selector' => '.peyvast-auth-field.is-error .peyvast-auth-input',
					),
				),
			)
		);
		$this->control(
			'inline_error_input_outline',
			array(
				'type'  => 'box-shadow',
				'group' => 'form',
				'label' => 'Error input box shadow',
				'css'   => array(
					array(
						'property' => 'box-shadow',
						'selector' => '.peyvast-auth-field.is-error .peyvast-auth-input:focus',
					),
				),
			)
		);

		/* Primary actions */
		foreach ( array(
			'continue'       => 'Continue',
			'verify'         => 'Verify',
			'login'          => 'Sign in',
			'register'       => 'Register',
			'forgot'         => 'Reset request',
			'reset_password' => 'Save password',
		) as $key => $label ) {
			if ( $key !== 'continue' ) {
				$this->separator( 'separator_primary_' . $key, $label, 'primary_actions' );
			}
			$text_key = 'forgot' === $key ? 'forgot_send_text' : $key . '_text';
			$this->text_control( $text_key, $label . ' text', 'primary_actions' );
			$this->icon_button_controls( $key, 'primary_actions', $label );
		}
		$this->separator( 'separator_primary_global', 'Global styles', 'primary_actions' );
		$this->control(
			'primary_size',
			array(
				'type'        => 'select',
				'group'       => 'primary_actions',
				'label'       => 'Size',
				'options'     => $this->control_options['buttonSizes'],
				'inline'      => true,
				'reset'       => true,
				'placeholder' => 'Default',
			)
		);
		$this->control(
			'primary_style',
			array(
				'type'        => 'select',
				'group'       => 'primary_actions',
				'label'       => 'Style',
				'options'     => $this->control_options['styles'],
				'inline'      => true,
				'reset'       => true,
				'placeholder' => 'Default',
			)
		);
		$this->control(
			'primary_circle',
			array(
				'type'  => 'checkbox',
				'group' => 'primary_actions',
				'label' => 'Circle',
				'reset' => true,
			)
		);
		$this->control(
			'primary_outline',
			array(
				'type'  => 'checkbox',
				'group' => 'primary_actions',
				'label' => 'Outline',
				'reset' => true,
			)
		);
		$this->spacing( 'primary_padding', 'Padding', 'primary_actions', 'padding', '.peyvast-auth-stage__actions > .peyvast-auth-button' );
		$this->control(
			'primary_border',
			array(
				'type'  => 'border',
				'group' => 'primary_actions',
				'label' => 'Border',
				'css'   => array(
					array(
						'property' => 'border',
						'selector' => '.peyvast-auth-stage__actions > .peyvast-auth-button',
					),
				),
			)
		);
		$this->color( 'primary_background', 'Background', 'primary_actions', 'background-color', '.peyvast-auth-stage__actions > .peyvast-auth-button' );
		$this->typography( 'primary_typography', 'Typography', 'primary_actions', '.peyvast-auth-stage__actions > .peyvast-auth-button' );

		/* Secondary */
		$this->text_control( 'password_link_text', 'Password sign-in link text', 'secondary_actions' );
		$this->text_control( 'password_reset_link_text', 'Password reset link text', 'secondary_actions' );
		$this->separator( 'separator_secondary_global', 'Global styles', 'secondary_actions' );
		$this->button_styles( 'secondary', 'secondary_actions', '.peyvast-auth-link--password-link, .peyvast-auth-link--forgot-link', false );
		$this->control(
			'secondary_box_shadow',
			array(
				'type'  => 'box-shadow',
				'group' => 'secondary_actions',
				'label' => 'Box shadow',
				'css'   => array(
					array(
						'property' => 'box-shadow',
						'selector' => '.peyvast-auth-link--password-link, .peyvast-auth-link--forgot-link',
					),
				),
			)
		);

		/* Resend */
		$this->text_control( 'resend_text', 'Resend text', 'resend' );
		$this->button_styles( 'resend', 'resend', '.peyvast-auth-link--resend', false );
		$this->separator( 'separator_resend_timer', 'Resend timer', 'resend' );
		$this->text_control( 'resend_timer_template', 'Resend timer text', 'resend', 'Supported placeholder: {seconds}.' );
		$this->typography( 'resend_timer_typography', 'Timer typography', 'resend', '.peyvast-auth__timer' );

		/* Notices */
		$this->control(
			'notice_gap',
			array(
				'type'  => 'number',
				'group' => 'notice',
				'label' => 'Gap',
				'units' => true,
				'css'   => array(
					array(
						'property' => 'gap',
						'selector' => '.peyvast-auth__notices',
					),
				),
			)
		);
		$this->control(
			'notice_width',
			array(
				'type'  => 'number',
				'group' => 'notice',
				'label' => 'Width',
				'units' => true,
				'css'   => array(
					array(
						'property' => 'width',
						'selector' => '.peyvast-auth__notices',
					),
				),
			)
		);
		$this->control(
			'notice_position',
			array(
				'type'    => 'select',
				'group'   => 'notice',
				'label'   => 'Position',
				'options' => array(
					'top'    => 'Top',
					'left'   => 'Left',
					'bottom' => 'Bottom',
					'right'  => 'Right',
				),
				'inline'  => true,
				'reset'   => true,
			)
		);
		foreach ( array(
			'success' => 'Success',
			'error'   => 'Error',
			'warning' => 'Warning',
			'info'    => 'Info',
		) as $key => $label ) {
			if ( $key !== 'success' ) {
				$this->separator( 'separator_notice_' . $key, $label, 'notice' );
			}
			$this->color( 'notice_' . $key . '_background', 'Background', 'notice', 'background-color', '.peyvast-auth-notice--' . $key );
			$this->control(
				'notice_' . $key . '_border',
				array(
					'type'  => 'border',
					'group' => 'notice',
					'label' => 'Border',
					'css'   => array(
						array(
							'property' => 'border',
							'selector' => '.peyvast-auth-notice--' . $key,
						),
					),
				)
			);
			$this->spacing( 'notice_' . $key . '_padding', 'Padding', 'notice', 'padding', '.peyvast-auth-notice--' . $key );
			$this->control(
				'notice_' . $key . '_shadow',
				array(
					'type'  => 'box-shadow',
					'group' => 'notice',
					'label' => 'Box shadow',
					'css'   => array(
						array(
							'property' => 'box-shadow',
							'selector' => '.peyvast-auth-notice--' . $key,
						),
					),
				)
			);

		}
		$this->separator( 'separator_notice_close', 'Close icon', 'notice' );
		$this->color( 'notice_close_background', 'Background', 'notice', 'background-color', '.peyvast-auth-notice__close' );
		$this->control(
			'notice_close_border',
			array(
				'type'  => 'border',
				'group' => 'notice',
				'label' => 'Border',
				'css'   => array(
					array(
						'property' => 'border',
						'selector' => '.peyvast-auth-notice__close',
					),
				),
			)
		);
		$this->spacing( 'notice_close_padding', 'Padding', 'notice', 'padding', '.peyvast-auth-notice__close' );
	}

	private function dynamic_text( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( $value !== '' && method_exists( $this, 'render_dynamic_data' ) ) {
			return (string) $this->render_dynamic_data( $value );
		}
		return $value;
	}

	private function builder_icon( $value ): string {
		if ( empty( $value ) || ! is_array( $value ) || ! is_callable( array( '\\Bricks\\Element', 'render_icon' ) ) ) {
			return '';
		}
		// Use Bricks' native renderer for font/SVG icons and dynamic icon data.
		$markup = self::render_icon( $value );
		return is_string( $markup ) ? trim( $markup ) : '';
	}

	public function enqueue_scripts() {
		Assets::enqueue_front(); }

	public function render() {
		Assets::enqueue_front();
		$settings = is_array( $this->settings ) ? $this->settings : array();
		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			if ( preg_match( '/(?:_title|_description|_label|_placeholder|_text)$/', $key ) || in_array( $key, array( 'information_otp_sent', 'information_registration', 'resend_timer_template' ), true ) ) {
				$settings[ $key ] = $this->dynamic_text( $value );
			}
		}
		$is_builder = function_exists( 'bricks_is_builder' ) && (
			bricks_is_builder()
			|| ( function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call() )
		);
		if ( $is_builder ) {
			$preview = sanitize_key( (string) ( $this->settings['preview_stage'] ?? '' ) );
			if ( in_array( $preview, array( 'phone', 'password', 'otp', 'register', 'forgot', 'reset' ), true ) ) {
				$settings['__peyvast_preview_stage'] = $preview;
			}
		}

		$builder_icons = array();
		foreach ( array( 'continue', 'verify', 'login', 'register', 'forgot', 'resend', 'reset_password' ) as $key ) {
			$builder_icons[ $key ] = $this->builder_icon( $this->settings[ $key . '_icon' ] ?? array() );
			// Keep the Core state contract scalar-safe; native Bricks output is restored after.
			$settings[ $key . '_icon' ] = '';
		}

		$state = AuthState::prepare( $settings );
		foreach ( $builder_icons as $key => $markup ) {
			$state['icons'][ $key ] = $markup;
		}
		$stages = self::stages( $state, $settings );
		$html   = '<script type="application/json" data-peyvast-auth-config>' . wp_json_encode( $state['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>';
		$html  .= '<form class="peyvast-auth__form" id="' . esc_attr( $state['ids']['form'] ) . '" data-peyvast-auth-form novalidate>';
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
		$inner    = $html . '<div class="peyvast-auth__notices' . $position . '" data-peyvast-auth-notice-queue aria-live="polite" aria-atomic="false"></div></form>';
		$this->set_attribute( '_root', 'class', array( 'peyvast-auth', 'peyvast-auth--bricks' ) );
		$this->set_attribute( '_root', 'data-peyvast-auth', '' );
		echo '<div ' . $this->render_attributes( '_root' ) . '>' . $inner . '</div>';
	}

	private static function attr( array $attrs, string $key, string $fallback = '' ): string {
		return isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) && trim( $attrs[ $key ] ) !== '' ? trim( $attrs[ $key ] ) : $fallback;
	}

	private static function css_value( $value ): string {
		if ( is_array( $value ) ) {
			foreach ( array( 'value', 'size', 'unit', 'raw' ) as $key ) {
				if ( array_key_exists( $key, $value ) && $value[ $key ] !== '' && $value[ $key ] !== null ) {
					return self::css_value( $value[ $key ] );
				}
			}
			return '';
		}
		if ( is_object( $value ) ) {
			foreach ( array( 'value', 'size', 'unit', 'raw' ) as $key ) {
				if ( isset( $value->{$key} ) && $value->{$key} !== '' ) {
					return self::css_value( $value->{$key} );
				}
			}
			return '';
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
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

	private static function raw_icon( $icon ): string {
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
				return $value;
			}
			if ( ctype_digit( (string) $value ) ) {
				$id = absint( $value );
			} else {
				$key = sanitize_key( $value );
			}
		}

		// Only trusted icon markup is emitted; attachment SVGs are not inlined.

		$allowed = array( 'check', 'close', 'error', 'info', 'warning' );
		if ( $key !== '' && in_array( $key, $allowed, true ) ) {
			$file = PEYVAST_AUTH_DIR . 'assets/img/' . $key . '.svg';
			if ( is_readable( $file ) ) {
				$raw                            = file_get_contents( $file );
				self::$icon_cache[ $cache_key ] = $raw === false ? '' : trim( $raw );
				return self::$icon_cache[ $cache_key ];
			}
		}
		self::$icon_cache[ $cache_key ] = '';
		return self::$icon_cache[ $cache_key ];
	}

	private static function icon_value( array $attrs, string $key ) {
		return array_key_exists( $key, $attrs ) ? $attrs[ $key ] : '';
	}

	private static function notice_icon( $icon ): string {
		return self::raw_icon( $icon );
	}

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
		return '<div class="peyvast-auth-field" data-peyvast-auth-field>'
			. '<div class="peyvast-auth-field__control">' . $input_html
			. '<label class="peyvast-auth-field__label" for="' . esc_attr( $for ) . '">' . esc_html( $label ) . ( $required ? '<span class="peyvast-auth-field__required" aria-hidden="true">*</span>' : '' ) . '</label>'
			. '</div>'
			. ( $description !== '' ? '<p class="peyvast-auth-field__description">' . esc_html( $description ) . '</p>' : '' )
			. '<p class="peyvast-auth-field__error" id="' . esc_attr( $error_id ) . '" data-peyvast-auth-inline-error hidden aria-live="polite"></p>'
			. '</div>';
	}

	private static function stage( array $args ): string {
		$stage               = (string) ( $args['stage'] ?? '' );
		$title               = (string) ( $args['title'] ?? '' );
		$description         = (string) ( $args['description'] ?? '' );
		$description_enabled = ! empty( $args['description_enabled'] ) && $description !== '';
		$information         = (string) ( $args['information'] ?? '' );
		$content             = (string) ( $args['content'] ?? '' );
		$actions             = (string) ( $args['actions'] ?? '' );
		$active              = $stage === (string) ( $args['active'] ?? 'phone' );
		$hidden              = $active ? '' : ' hidden';
		$inert               = $active ? '' : ' inert';
		return '<section class="peyvast-auth-stage ' . ( $active ? 'is-active' : '' ) . '" data-step="' . esc_attr( $stage ) . '" aria-hidden="' . ( $active ? 'false' : 'true' ) . '"' . $inert . $hidden . '>'
			. '<header class="peyvast-auth-stage__header">'
			. '<h2 class="peyvast-auth__title" data-stage-heading>' . wp_kses_post( $title ) . '</h2>'
			. ( $description_enabled ? '<div class="peyvast-auth__description" data-stage-description>' . wp_kses_post( $description ) . '</div>' : '' )
			. '<div class="peyvast-auth__information" data-peyvast-auth-information role="status" aria-live="polite" hidden>' . wp_kses_post( $information ) . '</div>'
			. '</header>'
			. '<div class="peyvast-auth-stage__content">' . $content . '</div>'
			. '<div class="peyvast-auth-stage__actions">' . $actions . '</div>'
			. '</section>';
	}

	private static function sanitize_icon_svg( string $svg ): string {
		if ( preg_match( '/<\/?(?:script|foreignObject|iframe|object|embed|style)\b/i', $svg )
			|| preg_match( '/\bon[a-z0-9_-]+\s*=/i', $svg )
			|| preg_match( '/(?:javascript|data|vbscript):/i', $svg )
			|| preg_match( '/\b(?:href|xlink:href)\s*=\s*["\']\s*(?:https?:)?\/\//i', $svg )
			|| preg_match( '/\bstyle\s*=/i', $svg )
			|| preg_match( '/url\s*\(/i', $svg ) ) return '';
		$allowed = array(
			'svg' => array( 'xmlns' => true, 'viewBox' => true, 'width' => true, 'height' => true, 'role' => true, 'aria-hidden' => true, 'focusable' => true, 'class' => true ),
			'g' => array( 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'class' => true ),
			'path' => array( 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'class' => true ),
			'circle' => array( 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ),
			'rect' => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ),
			'line' => array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ),
			'polyline' => array( 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ),
			'polygon' => array( 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ),
		);
		return wp_kses( $svg, $allowed );
	}

	private static function render_action_button( string $action, string $text, string $icon = '', string $type = 'submit', string $class = 'peyvast-auth-button', array $attrs = array(), array $options = array() ): string {
		$icon       = trim( $icon );
		$action_key = str_replace( '-', '_', $action );
		switch ( $action ) {
			case 'continue-identifier':
				$style_key = 'continue';
				break;
			case 'verify-otp':
				$style_key = 'verify';
				break;
			case 'password-login':
				$style_key = 'login';
				break;
			case 'forgot-send':
				$style_key = 'forgot';
				break;
			case 'reset-password':
				$style_key = 'reset_password';
				break;
			default:
				$style_key = $action_key;
				break;
		}
		$position = sanitize_key( (string) ( $options['icon_position'] ?? 'before' ) );
		switch ( $position ) {
			case 'left':
				$position = 'before';
				break;
			case 'right':
				$position = 'after';
				break;
			default:
				$position = in_array( $position, array( 'before', 'after' ), true ) ? $position : 'before';
				break;
		}
		$semantic_class = ' peyvast-auth-button--' . sanitize_html_class( $action );
		if ( $style_key !== $action_key ) {
			$semantic_class .= ' peyvast-auth-button--' . sanitize_html_class( $style_key );
		}
		$native_class  = trim( (string) ( $options['native_class'] ?? '' ) );
		$style_classes = trim( (string) ( $options['style_classes'] ?? '' ) );
		$extra_class   = $semantic_class;
		if ( $native_class !== '' ) {
			$extra_class .= ' ' . $native_class;
		}
		if ( $style_classes !== '' ) {
			$extra_class .= ' ' . $style_classes;
		}
		if ( ! empty( $options['primary_class'] ) ) {
			$extra_class .= ' peyvast-auth-button--primary';
		}
		if ( ! empty( $options['has_icon_space'] ) ) {
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
		if ( $icon !== '' ) {
			$icon = trim( $icon );
			if ( ! empty( $options['trusted_bricks_icon_markup'] ) ) {
				// Native markup produced directly by Bricks\Element::render_icon().
				$icon_markup = $icon;
			} elseif ( preg_match( '/^\s*<svg\b/i', $icon ) ) {
				$icon_markup = self::sanitize_icon_svg( $icon );
			} elseif ( preg_match( '#^\s*<i\s+class="[a-zA-Z0-9 _\-]+"\s*/?>\s*$#i', $icon ) || preg_match( '#^\s*<i\s+class="[a-zA-Z0-9 _\-]+"\s*></i>\s*$#i', $icon ) ) {
				$icon_markup = $icon;
			}
		}
		$label_markup = wp_kses_post( $text );
		if ( $position === 'after' ) {
			$children = $label_markup . $icon_markup;
		} else {
			$children = $icon_markup . $label_markup;
		}
		return '<button type="' . esc_attr( $type ) . '" class="' . esc_attr( $class . $extra_class ) . '" data-action="' . esc_attr( $action ) . '" data-peyvast-auth-action-button' . $style . ' aria-busy="false">' . $children . '</button>';
	}

	private static function render_secondary_button( string $key, string $text, string $action, array $attrs = array(), array $options = array() ): string {
		$classes       = 'peyvast-auth-link peyvast-auth-link--' . sanitize_html_class( str_replace( '_', '-', $key ) );
		$native_class  = trim( (string) ( $options['native_class'] ?? '' ) );
		$style_classes = trim( (string) ( $options['style_classes'] ?? '' ) );
		if ( $native_class !== '' ) {
			$classes .= ' ' . $native_class;
		}
		if ( $style_classes !== '' ) {
			$classes .= ' ' . $style_classes;
		}
		$style = trim( (string) ( $options['inline_style'] ?? '' ) );
		return '<button type="button" class="' . esc_attr( $classes ) . '" data-action="' . esc_attr( $action ) . '"' . ( $style !== '' ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>' . esc_html( $text ) . '</button>';
	}

	private static function identifier_attrs( bool $phone_enabled, bool $email_enabled, string $autocomplete ): array {
		if ( $phone_enabled && ! $email_enabled ) {
			return array(
				'type'         => 'tel',
				'inputmode'    => 'tel',
				'autocomplete' => $autocomplete,
			);
		}
		if ( $email_enabled && ! $phone_enabled ) {
			return array(
				'type'         => 'email',
				'inputmode'    => 'email',
				'autocomplete' => $autocomplete,
			);
		}
		// Mixed phone/email fields: no single autocomplete token is accurate.
		return array(
			'type'         => 'text',
			'inputmode'    => 'text',
			'autocomplete' => '',
		);
	}

	private static function render_identifier_stage( string $id, string $label, string $placeholder, string $continue_text, string $continue_icon, bool $show_password, array $attrs, bool $google_enabled, string $google_text, array $button_options = array() ): array {
		$field         = AuthenticationCapabilities::field( 'otp_login' );
		$email_enabled = in_array( 'email', $field['identifiers'], true );
		$ia            = array(
			'type'         => $field['type'],
			'inputmode'    => $field['inputmode'],
			'autocomplete' => $field['autocomplete'],
		);
		$input         = '<input id="' . esc_attr( $id ) . '" class="peyvast-auth-input" type="' . esc_attr( $ia['type'] ) . '" name="identifier" data-identifier' . ( $ia['autocomplete'] !== '' ? ' autocomplete="' . esc_attr( $ia['autocomplete'] ) . '"' : '' ) . ' inputmode="' . esc_attr( $ia['inputmode'] ) . '" dir="rtl"' . ( $placeholder !== '' ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '' ) . ' aria-invalid="false">';
		$content       = self::render_field( $label, $input );
		$secondary     = '';
		if ( $show_password ) {
			$secondary .= self::render_secondary_button( 'password_link', self::attr( $attrs, 'password_link_text', self::t( 'password_method' ) ), 'show-password', $attrs, $button_options['password_link'] ?? array() );
		}
		$actions = self::render_action_button( 'continue-identifier', $continue_text, $continue_icon, 'submit', 'peyvast-auth-button', $attrs, $button_options['continue-identifier'] ?? array() );
		if ( $google_enabled ) {
			$actions .= '<div class="peyvast-auth-google-action" data-google-action><div data-google-login></div></div>';
		}
		if ( $secondary !== '' ) {
			$actions .= '<div class="peyvast-auth-stage__secondary-actions" data-peyvast-auth-secondary-actions>' . $secondary . '</div>';
		}
		return array(
			'content' => $content,
			'actions' => $actions,
		);
	}

	private static function render_password_stage( string $identifier_id, string $password_id, bool $email_enabled, string $identifier_placeholder, string $password_placeholder, string $login_text, string $login_icon, string $forgot_text, bool $reset_enabled, array $attrs, array $button_options = array() ): array {
		$field                     = AuthenticationCapabilities::field( 'password_login' );
		$label                     = $field['label'];
		$password_identifier_label = AuthenticationCapabilities::apply_method_placeholders( self::attr( $attrs, 'password_identifier_label', $label ), 'password_login' );
		$password_label            = self::attr( $attrs, 'password_label', self::t( 'password' ) );
		$ia                        = array(
			'type'         => $field['type'],
			'inputmode'    => $field['inputmode'],
			'autocomplete' => $field['autocomplete'],
		);
		$content                   = self::render_field( $password_identifier_label, '<input id="' . esc_attr( $identifier_id ) . '" class="peyvast-auth-input" type="' . esc_attr( $ia['type'] ) . '" name="password_identifier" data-password-identifier' . ( $ia['autocomplete'] !== '' ? ' autocomplete="' . esc_attr( $ia['autocomplete'] ) . '"' : '' ) . ' inputmode="' . esc_attr( $ia['inputmode'] ) . '" dir="rtl" placeholder="' . esc_attr( $identifier_placeholder ) . '" aria-invalid="false">' );
		$content                  .= self::render_field( $password_label, '<input id="' . esc_attr( $password_id ) . '" class="peyvast-auth-input" type="password" name="login_password" data-login-password autocomplete="current-password" placeholder="' . esc_attr( $password_placeholder ) . '" aria-invalid="false">' );
		$actions                   = self::render_action_button( 'password-login', $login_text, $login_icon, 'submit', 'peyvast-auth-button', $attrs, $button_options['password-login'] ?? array() );
		if ( $reset_enabled ) {
			$actions .= '<div class="peyvast-auth-stage__secondary-actions" data-peyvast-auth-secondary-actions>' . self::render_secondary_button( 'forgot_link', $forgot_text, 'show-forgot', $attrs, $button_options['forgot_link'] ?? array() ) . '</div>';
		}
		return array(
			'content' => $content,
			'actions' => $actions,
		);
	}

	private static function render_otp_stage( int $length, string $resend_text, string $verify_text, string $verify_icon, string $id, array $attrs, array $button_options = array() ): array {
		$length   = max( 4, min( 8, $length ) );
		$input_id = 'peyvast-auth-otp-' . $id;
		$label    = self::t( 'otp' );
		$digits   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$digits .= '<input id="' . esc_attr( $input_id . '-' . ( $i + 1 ) ) . '" class="peyvast-auth-otp__input peyvast-auth-otp__digit peyvast-auth-input" data-otp-input' . ( 0 === $i ? ' data-otp-first' : '' ) . ' data-otp-digit="' . esc_attr( $i ) . '" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="1" dir="ltr" placeholder=" " aria-label="' . esc_attr( sprintf( self::t( 'otp' ) . ' %d', $i + 1 ) ) . '" aria-invalid="false">';
		}
		$input    = '<div class="peyvast-auth-otp__fields" data-otp-fields role="group" aria-label="' . esc_attr( $label ) . '">' . $digits . '</div>';
		// OTP is composite: keep the field/error wrapper instead of a single floating label.
		$input_error_id = $input_id . '-error';
		$content  = '<div class="peyvast-auth-otp" data-otp-wrap>'
			. '<div class="peyvast-auth-field" data-peyvast-auth-field>'
			. '<div class="peyvast-auth-field__control">' . $input . '</div>'
			. '<p class="peyvast-auth-field__error" id="' . esc_attr( $input_error_id ) . '" data-peyvast-auth-inline-error hidden aria-live="polite"></p>'
			. '</div>'
			. '<div class="peyvast-auth-otp__feedback" data-otp-feedback aria-live="polite"></div><div class="peyvast-auth-otp__resend" data-otp-resend-component><div class="peyvast-auth__resend-slot" data-resend-slot><span class="peyvast-auth__timer" data-otp-timer hidden></span>' . self::render_action_button( 'resend', $resend_text, '', 'button', 'peyvast-auth-link peyvast-auth-link--resend', $attrs, $button_options['resend'] ?? array() ) . '</div></div></div>';
		$actions  = self::render_action_button( 'verify-otp', $verify_text, $verify_icon, 'submit', 'peyvast-auth-button', $attrs, $button_options['verify-otp'] ?? array() );
		return array(
			'content' => $content,
			'actions' => $actions,
		);
	}

	private static function render_register_stage( array $fields, string $first_name_id, string $last_name_id, string $email_id, string $password_id, string $register_text, string $register_icon, array $attrs, string $first_name_placeholder, string $last_name_placeholder, string $email_placeholder, string $password_placeholder, array $button_options = array() ): array {
		$content = '';
		if ( ! empty( $fields['first_name']['enabled'] ) ) {
			$content .= self::render_field( self::attr( $attrs, 'first_name_label', self::t( 'first_name' ) ), '<input id="' . esc_attr( $first_name_id ) . '" class="peyvast-auth-input" type="text" data-first-name autocomplete="given-name" placeholder="' . esc_attr( $first_name_placeholder ) . '" aria-invalid="false">', ! empty( $fields['first_name']['required'] ) );
		}
		if ( ! empty( $fields['last_name']['enabled'] ) ) {
			$content .= self::render_field( self::attr( $attrs, 'last_name_label', self::t( 'last_name' ) ), '<input id="' . esc_attr( $last_name_id ) . '" class="peyvast-auth-input" type="text" data-last-name autocomplete="family-name" placeholder="' . esc_attr( $last_name_placeholder ) . '" aria-invalid="false">', ! empty( $fields['last_name']['required'] ) );
		}
		if ( ! empty( $fields['email']['enabled'] ) ) {
			$content .= self::render_field( self::attr( $attrs, 'email_label', self::t( 'email' ) ), '<input id="' . esc_attr( $email_id ) . '" class="peyvast-auth-input" type="email" data-register-email autocomplete="email" inputmode="email" dir="ltr" placeholder="' . esc_attr( $email_placeholder ) . '" aria-invalid="false">', ! empty( $fields['email']['required'] ) );
		}
		if ( ! empty( $fields['password']['enabled'] ) ) {
			$content .= self::render_field( self::attr( $attrs, 'password_label', self::t( 'password' ) ), '<input id="' . esc_attr( $password_id ) . '" class="peyvast-auth-input" type="password" data-register-password autocomplete="new-password" placeholder="' . esc_attr( $password_placeholder ) . '" aria-invalid="false">', ! empty( $fields['password']['required'] ) );
		}
		return array(
			'content' => $content,
			'actions' => self::render_action_button( 'register', $register_text, $register_icon, 'submit', 'peyvast-auth-button', $attrs, $button_options['register'] ?? array() ),
		);
	}

	private static function render_forgot_stage( string $id, bool $email_enabled, string $placeholder, string $continue_text, string $continue_icon, array $attrs, array $button_options = array() ): array {
		$field   = AuthenticationCapabilities::field( 'password_reset' );
		$label   = AuthenticationCapabilities::apply_method_placeholders( self::attr( $attrs, 'forgot_identifier_label', $field['label'] ), 'password_reset' );
		$ia      = array(
			'type'         => $field['type'],
			'inputmode'    => $field['inputmode'],
			'autocomplete' => $field['autocomplete'],
		);
		$content = self::render_field( $label, '<input id="' . esc_attr( $id ) . '" class="peyvast-auth-input" type="' . esc_attr( $ia['type'] ) . '" data-forgot-identifier' . ( $ia['autocomplete'] !== '' ? ' autocomplete="' . esc_attr( $ia['autocomplete'] ) . '"' : '' ) . ' inputmode="' . esc_attr( $ia['inputmode'] ) . '" dir="rtl" placeholder="' . esc_attr( $placeholder ) . '" aria-invalid="false">' );
		return array(
			'content' => $content,
			'actions' => self::render_action_button( 'forgot-send', $continue_text, $continue_icon, 'submit', 'peyvast-auth-button', $attrs, $button_options['forgot-send'] ?? array() ),
		);
	}

	private static function render_reset_stage( string $id, string $placeholder, string $icon, array $attrs, array $button_options = array() ): array {
		$new_password_label = self::attr( $attrs, 'new_password_label', self::t( 'new_password' ) );
		$reset_text         = self::attr( $attrs, 'reset_password_text', self::t( 'save_password' ) );
		$content            = self::render_field( $new_password_label, '<input id="' . esc_attr( $id ) . '" class="peyvast-auth-input" type="password" data-new-password autocomplete="new-password" placeholder="' . esc_attr( $placeholder ) . '" aria-invalid="false">' );
		return array(
			'content' => $content,
			'actions' => self::render_action_button( 'reset-password', $reset_text, $icon, 'submit', 'peyvast-auth-button', $attrs, $button_options['reset-password'] ?? array() ),
		);
	}

	private static function value( array $attrs, array $prefixes, string $part ) {
		foreach ( $prefixes as $prefix ) {
			$key = $prefix . '_' . $part;
			if ( array_key_exists( $key, $attrs ) && $attrs[ $key ] !== '' && $attrs[ $key ] !== null ) {
				return $attrs[ $key ];
			}
		}
		return '';
	}

	private static function action_options( array $attrs, string $action ): array {
		$action_key = str_replace( '-', '_', $action );
		switch ( $action ) {
			case 'continue-identifier':
				$style_key = 'continue';
				break;
			case 'verify-otp':
				$style_key = 'verify';
				break;
			case 'password-login':
				$style_key = 'login';
				break;
			case 'forgot-send':
				$style_key = 'forgot';
				break;
			case 'reset-password':
				$style_key = 'reset_password';
				break;
			default:
				$style_key = $action_key;
				break;
		}
		$primary  = in_array( $action, array( 'continue-identifier', 'verify-otp', 'password-login', 'register', 'forgot-send', 'reset-password' ), true );
		$prefixes = $primary ? array( 'primary' ) : array_values( array_unique( array( $style_key, $action_key ) ) );
		$size     = sanitize_html_class( (string) self::value( $attrs, $prefixes, 'size' ) );
		$variant  = sanitize_html_class( (string) self::value( $attrs, $prefixes, 'style' ) );
		$outline  = filter_var( self::value( $attrs, $prefixes, 'outline' ), FILTER_VALIDATE_BOOLEAN );
		$circle   = filter_var( self::value( $attrs, $prefixes, 'circle' ), FILTER_VALIDATE_BOOLEAN );
		$classes  = array();
		if ( $size !== '' ) {
			$classes[] = $size;
		}
		if ( $variant !== '' ) {
			$classes[] = ( $outline ? 'bricks-color-' : 'bricks-background-' ) . $variant;
		}
		if ( $circle ) {
			$classes[] = 'circle';
		}
		if ( $outline ) {
			$classes[] = 'outline';
		}
		$position = self::value( $attrs, array( $style_key, $action_key, $action ), 'iconPosition' );
		if ( $position === '' ) {
			$position = self::value( $attrs, array( $style_key, $action_key, $action ), 'icon_position' );
		}
		$gap = self::value( $attrs, array( $style_key, $action_key, $action ), 'iconGap' );
		if ( $gap === '' ) {
			$gap = self::value( $attrs, array( $style_key, $action_key, $action ), 'icon_gap' );
		}
		$space = self::value( $attrs, array( $style_key, $action_key, $action ), 'iconSpace' );
		if ( $space === '' ) {
			$space = self::value( $attrs, array( $style_key, $action_key, $action ), 'icon_space' );
		}
		switch ( $action ) {
			case 'continue-identifier':
				$icon_key = 'continue';
				break;
			case 'verify-otp':
				$icon_key = 'verify';
				break;
			case 'password-login':
				$icon_key = 'login';
				break;
			case 'register':
				$icon_key = 'register';
				break;
			case 'forgot-send':
				$icon_key = 'forgot';
				break;
			case 'reset-password':
				$icon_key = 'reset_password';
				break;
			case 'resend':
				$icon_key = 'resend';
				break;
			default:
				$icon_key = $action_key;
				break;
		}
		return array(
			'native_class'    => ( implode( ' ', $classes ) !== '' ? 'bricks-button' : '' ),
			'style_classes'   => implode( ' ', $classes ),
			'primary_class'   => $primary,
			'icon_position'   => sanitize_key( (string) ( $position !== '' ? $position : 'before' ) ),
			'icon_gap'        => self::css_value( $gap ),
			'has_icon_space'  => filter_var( $space, FILTER_VALIDATE_BOOLEAN ),
			'bricks_icon_key' => $icon_key,
		);
	}

	private static function secondary_options( array $attrs ): array {
		$value   = static function ( string $part ) use ( $attrs ) {
			foreach ( array( 'secondary_' . $part, 'password_link_' . $part, 'forgot_link_' . $part ) as $key ) {
				if ( array_key_exists( $key, $attrs ) && $attrs[ $key ] !== '' && $attrs[ $key ] !== null ) {
					return $attrs[ $key ];
				}
			}
			return '';
		};
		$size    = sanitize_html_class( (string) $value( 'size' ) );
		$variant = sanitize_html_class( (string) $value( 'style' ) );
		$outline = filter_var( $value( 'outline' ), FILTER_VALIDATE_BOOLEAN );
		$classes = array();
		if ( $size !== '' ) {
			$classes[] = $size;
		}
		if ( $variant !== '' ) {
			$classes[] = ( $outline ? 'bricks-color-' : 'bricks-background-' ) . $variant;
		}
		if ( filter_var( $value( 'circle' ), FILTER_VALIDATE_BOOLEAN ) ) {
			$classes[] = 'circle';
		}
		if ( $outline ) {
			$classes[] = 'outline';
		}
		$styles = array();
		foreach ( array( 'padding', 'background', 'border', 'font_size', 'font_weight', 'box_shadow' ) as $part ) {
			$css = self::css_value( $value( $part ) );
			if ( $css !== '' ) {
				$styles[] = '--peyvast-auth-secondary-' . str_replace( '_', '-', $part ) . ':' . $css;
			}
		}
		return array(
			'native_class'  => ( implode( ' ', $classes ) !== '' ? 'bricks-button' : '' ),
			'style_classes' => implode( ' ', $classes ),
			'inline_style'  => implode( ';', $styles ),
		);
	}

	private static function stages( array $state, array $attrs, array $button_options = array() ): array {
		if ( $button_options === array() ) {
			foreach ( array( 'continue-identifier', 'verify-otp', 'password-login', 'register', 'forgot-send', 'reset-password', 'resend' ) as $action ) {
				$button_options[ $action ] = self::action_options( $attrs, $action );
			}
			$button_options['password_link'] = self::secondary_options( $attrs );
			$button_options['forgot_link']   = self::secondary_options( $attrs );
		}
		$icon_by_action = array(
			'continue-identifier' => 'continue',
			'verify-otp'          => 'verify',
			'password-login'      => 'login',
			'register'            => 'register',
			'forgot-send'         => 'continue',
			'reset-password'      => 'reset_password',
			'resend'              => 'resend',
		);
		foreach ( $icon_by_action as $action => $icon_key ) {
			if ( ! empty( $state['icons'][ $icon_key ] ) ) {
				$button_options[ $action ]['trusted_bricks_icon_markup'] = true;
			}
		}

		$stages               = array();
		$password_forgot_text = trim( (string) ( $state['attrs']['password_reset_link_text'] ?? '' ) );
		if ( $password_forgot_text === '' ) {
			$password_forgot_text = trim( (string) ( $state['attrs']['forgot_text'] ?? '' ) );
		}
		if ( $password_forgot_text === '' ) {
			$password_forgot_text = $state['texts']['forgot'];
		}
		$stages['phone']    = self::render_identifier_stage(
			$state['ids']['identifier'],
			$state['labels']['identifier'],
			$state['placeholders']['identifier'],
			$state['texts']['continue'],
			$state['icons']['continue'],
			$state['password_enabled'] && ( $state['password_phone'] || $state['password_email'] ),
			$attrs,
			$state['google_enabled'],
			$state['texts']['google'],
			$button_options
		);
		$stages['password'] = self::render_password_stage(
			$state['ids']['password_identifier'],
			$state['ids']['password_login'],
			in_array( 'email', $state['identifier_field']['identifiers'] ?? array(), true ),
			$state['placeholders']['password_identifier'],
			$state['placeholders']['password'],
			$state['texts']['login'],
			$state['icons']['login'],
			$password_forgot_text,
			$state['reset_enabled'],
			$attrs,
			$button_options
		);
		$stages['otp']      = self::render_otp_stage( $state['otp_length'], $state['texts']['resend'], $state['texts']['verify'], $state['icons']['verify'], $state['ids']['instance'], $attrs, $button_options );
		$stages['register'] = self::render_register_stage(
			$state['fields'],
			$state['ids']['first_name'],
			$state['ids']['last_name'],
			$state['ids']['email'],
			$state['ids']['password'],
			$state['texts']['register'],
			$state['icons']['register'],
			$attrs,
			$state['placeholders']['first_name'],
			$state['placeholders']['last_name'],
			$state['placeholders']['email'],
			$state['placeholders']['password'],
			$button_options
		);
		$stages['forgot']   = self::render_forgot_stage( $state['ids']['forgot'], in_array( 'email', ( AuthenticationCapabilities::field( 'password_reset' )['identifiers'] ?? array() ), true ), $state['placeholders']['forgot_identifier'], $state['texts']['forgot_send'], $state['icons']['forgot'], $attrs, $button_options );
		$stages['reset']    = self::render_reset_stage( $state['ids']['new_password'], $state['placeholders']['new_password'], $state['icons']['reset_password'], $attrs, $button_options );
		return $stages;
	}
}
