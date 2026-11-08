(function (wp) {
    'use strict';

    const { registerBlockType } = wp.blocks;
    const { InspectorControls, MediaUpload, MediaUploadCheck, useBlockProps } = wp.blockEditor;
    const { PanelBody, TextControl, TextareaControl, ToggleControl, SelectControl, Notice, RangeControl, ColorPalette, BoxControl, FontSizePicker, UnitControl, Button } = wp.components;
    const BorderControl = wp.blockEditor?.BorderControl || wp.components?.BorderControl;
    const BoxShadowControl = wp.components?.__experimentalBoxShadowControl || wp.blockEditor?.__experimentalBoxShadowControl;
    const { __ } = wp.i18n;
    const { createElement: el, Fragment, useState } = wp.element;
    const t = value => __(value, 'peyvast-auth');
    const ServerSideRender = wp.serverSideRender?.ServerSideRender || wp.serverSideRender?.default || wp.serverSideRender;
    const metadata = window.PEYVAST_AUTH_BLOCK_METADATA || {};

    const stages = [
        ['phone', t('Phone stage')], ['password', t('Password stage')], ['otp', t('OTP stage')],
        ['register', t('Registration stage')], ['forgot', t('Password reset request stage')], ['reset', t('New password stage')]
    ];
    const fields = [
        ['identifier', t('Identifier')], ['first_name', t('First name')], ['last_name', t('Last name')], ['email', t('Email')], ['password', t('Password')],
        ['password_identifier', t('Password sign-in identifier')], ['forgot_identifier', t('Password reset identifier')], ['new_password', t('New password')]
    ];
    const actions = [
        ['continue', t('Continue')], ['verify', t('Verify')], ['login', t('Sign in')], ['register', t('Register')],
        ['forgot', t('Password reset request')], ['reset_password', t('Save password')]
    ];
    const information = [
        ['information_otp_sent', t('OTP sent')],
        ['information_registration', t('Registration information')]
    ];
    const notices = [
        ['success', t('Success')], ['error', t('Error')], ['warning', t('Warning')], ['info', t('Info')]
    ];

        const UNIT_OPTIONS = [
        { value: 'px', label: 'px', default: 0 },
        { value: 'em', label: 'em', default: 0 },
        { value: 'rem', label: 'rem', default: 0 },
        { value: '%', label: '%', default: 0 },
        { value: 'vw', label: 'vw', default: 0 },
        { value: 'vh', label: 'vh', default: 0 }
    ];

    const noticePositionOptions = [
        { label: t('Top'), value: 'top' }, { label: t('Bottom'), value: 'bottom' },
        { label: t('Left'), value: 'left' }, { label: t('Right'), value: 'right' }
    ];

    function iconControl(key, attributes, setAttributes) {
        const id = Number(attributes[key]) || 0;
        return el(MediaUploadCheck, {},
            el('div', { className: 'peyvast-auth-block-icon-control' },
                el(MediaUpload, {
                    onSelect: media => setAttributes({ [key]: Number(media?.id) || 0 }),
                    allowedTypes: ['image/svg+xml'],
                    value: id,
                    render: ({ open }) => el('button', {
                        type: 'button',
                        className: 'components-button is-secondary',
                        onClick: open
                    }, id ? t('Change SVG icon') : t('Select SVG icon'))
                }),
                id ? el('span', { className: 'peyvast-auth-block-icon-control__selected' }, `${t('SVG selected')} #${id}`) : null
            ),
            id ? el(Button, { variant: 'link', isDestructive: true, onClick: () => setAttributes({ [key]: 0 }) }, t('Remove icon')) : null
        );
    }

    function cssValue(value) {
        if (value == null || value === '') return '';
        if (typeof value === 'string' || typeof value === 'number') return String(value);
        if (typeof value === 'object') {
            for (const key of ['value', 'size', 'fontSize', 'slug']) {
                if (value[key] != null && value[key] !== '') return cssValue(value[key]);
            }
        }
        return '';
    }

    function ColorField({ label, value, onChange, disabled = false }) {
        if (!ColorPalette) return null;
        return el('div', { className: 'peyvast-auth-block-control' },
            el('p', { className: 'components-base-control__label' }, label),
            el(ColorPalette, { value: value || undefined, onChange, disabled })
        );
    }

    function BoxField({ label, value, onChange }) {
        if (!BoxControl) return null;
        return el(BoxControl, {
            label,
            values: value || {},
            units: UNIT_OPTIONS,
            allowReset: true,
            onChange
        });
    }

    function BorderField({ label, value, onChange }) {
        if (!BorderControl) return null;
        return el(BorderControl, {
            label,
            value: value || {},
            onChange
        });
    }

    function BoxShadowField({ label, value, onChange }) {
        if (!BoxShadowControl) return null;
        return el(BoxShadowControl, {
            label,
            value: value || '',
            onChange: next => onChange(next || '')
        });
    }

    function FontField({ label, value, onChange, disabled = false }) {
        if (!FontSizePicker) return null;
        return el(FontSizePicker, {
            label,
            value: cssValue(value) || undefined,
            units: [ 'px', 'em', 'rem', 'vw', 'vh', 'clamp' ],
            onChange: next => onChange(cssValue(next)),
            __nextHasNoMarginBottom: true,
            fallbackFontSize: 16,
            withReset: true,
            disabled
        });
    }

    function Preview({ name, attributes, stage }) {
        if (!ServerSideRender) return el(Notice, { status: 'error', isDismissible: false }, 'The WordPress server renderer is unavailable.');
        return el(ServerSideRender, {
            block: name,
            attributes,
            httpMethod: 'POST',
            urlQueryArgs: { peyvast_preview_stage: stage },
            skipBlockSupportAttributes: false,
            EmptyResponsePlaceholder: () => el(Notice, { status: 'warning', isDismissible: false }, 'The block returned no preview output.'),
            ErrorResponsePlaceholder: ({ message }) => el(Notice, { status: 'error', isDismissible: false }, message || 'The block preview could not be loaded.')
        });
    }

    function StageContentControls({ attributes, setAttributes }) {
        return el(PanelBody, { title: t('Stage Content'), initialOpen: false },
            ...stages.flatMap(([key, label]) => [
                el(TextControl, { key: key + '-title', label: label + ' title', help: t('Supported variable: {method}.'), value: attributes[key + '_title'] || '', onChange: value => setAttributes({ [key + '_title']: value }) }),
                el(ToggleControl, { key: key + '-toggle', label: t('Show description'), checked: !!attributes['show_' + key + '_description'], onChange: value => setAttributes({ ['show_' + key + '_description']: value }) }),
                el(TextareaControl, { key: key + '-description', label: label + ' description', help: t('Supported variable: {method}. HTML is allowed and sanitized before output.'), disabled: !attributes['show_' + key + '_description'], value: attributes[key + '_description'] || '', onChange: value => setAttributes({ [key + '_description']: value }) })
            ])
        );
    }

    function StageStyleControls({ attributes, setAttributes }) {
        return el(PanelBody, { title: t('Stage Typography and Spacing'), initialOpen: false },
            el(FontField, { label: 'Title font size', value: attributes.stage_title_font_size, onChange: value => setAttributes({ stage_title_font_size: value || '' }) }),
            el(SelectControl, { label: 'Title font weight', value: attributes.stage_title_font_weight || '', options: [{ label: t('Default'), value: '' }, { label: '400', value: '400' }, { label: '500', value: '500' }, { label: '600', value: '600' }, { label: '700', value: '700' }, { label: '800', value: '800' }], onChange: value => setAttributes({ stage_title_font_weight: value }) }),
            el(SelectControl, { label: 'Title alignment', value: attributes.stage_title_text_align || '', options: [{ label: t('Default'), value: '' }, { label: 'Left', value: 'left' }, { label: 'Center', value: 'center' }, { label: 'Right', value: 'right' }, { label: 'Justify', value: 'justify' }], onChange: value => setAttributes({ stage_title_text_align: value }) }),
            el(UnitValueField, { label: 'Title line height', value: attributes.stage_title_line_height, onChange: value => setAttributes({ stage_title_line_height: value || '' }), min: 0, max: 10, step: 0.05 }),
            el(UnitValueField, { label: 'Title letter spacing', value: attributes.stage_title_letter_spacing, onChange: value => setAttributes({ stage_title_letter_spacing: value || '' }), min: -10, max: 20, step: 0.1 }),
            el(ColorField, { label: 'Title text color', value: attributes.stage_title_text_color, onChange: value => setAttributes({ stage_title_text_color: value || '' }) }),
            el(BoxField, { label: 'Title padding', value: attributes.stage_title_padding, onChange: value => setAttributes({ stage_title_padding: value || {} }) }),
            el(BoxField, { label: 'Title margin', value: attributes.stage_title_margin, onChange: value => setAttributes({ stage_title_margin: value || {} }) }),
            el(FontField, { label: 'Description font size', value: attributes.stage_description_font_size, onChange: value => setAttributes({ stage_description_font_size: value || '' }) }),
            el(SelectControl, { label: 'Description font weight', value: attributes.stage_description_font_weight || '', options: [{ label: t('Default'), value: '' }, { label: '400', value: '400' }, { label: '500', value: '500' }, { label: '600', value: '600' }, { label: '700', value: '700' }], onChange: value => setAttributes({ stage_description_font_weight: value }) }),
            el(SelectControl, { label: 'Description alignment', value: attributes.stage_description_text_align || '', options: [{ label: t('Default'), value: '' }, { label: 'Left', value: 'left' }, { label: 'Center', value: 'center' }, { label: 'Right', value: 'right' }, { label: 'Justify', value: 'justify' }], onChange: value => setAttributes({ stage_description_text_align: value }) }),
            el(UnitValueField, { label: 'Description line height', value: attributes.stage_description_line_height, onChange: value => setAttributes({ stage_description_line_height: value || '' }), min: 0, max: 10, step: 0.05 }),
            el(UnitValueField, { label: 'Description letter spacing', value: attributes.stage_description_letter_spacing, onChange: value => setAttributes({ stage_description_letter_spacing: value || '' }), min: -10, max: 20, step: 0.1 }),
            el(ColorField, { label: 'Description text color', value: attributes.stage_description_text_color, onChange: value => setAttributes({ stage_description_text_color: value || '' }) }),
            el(ColorField, { label: 'Description background', value: attributes.stage_description_background, onChange: value => setAttributes({ stage_description_background: value || '' }) }),
            el(BoxField, { label: 'Description padding', value: attributes.stage_description_padding, onChange: value => setAttributes({ stage_description_padding: value || {} }) }),
            el(BoxField, { label: 'Description margin', value: attributes.stage_description_margin, onChange: value => setAttributes({ stage_description_margin: value || {} }) })
        );
    }

    function ContainerControls({ attributes, setAttributes }) {
        const groups = [['header', 'Stage header'], ['content', 'Stage content'], ['actions', 'Stage actions']];
        return el(PanelBody, { title: 'Containers', initialOpen: false },
            ...groups.flatMap(([key, label]) => [
                el(UnitValueField, { key: key + '-gap', label: label + ' gap', value: attributes['container_' + key + '_gap'], onChange: value => setAttributes({ ['container_' + key + '_gap']: value || '' }), min: 0, max: 200, step: 1 }),
                el(BoxField, { key: key + '-margin', label: label + ' margin', value: attributes['container_' + key + '_margin'], onChange: value => setAttributes({ ['container_' + key + '_margin']: value || {} }) })
            ])
        );
    }

    const informationHelp = (key) =>
        ['information_otp_sent', 'information_registration'].includes(key)
            ? t('Supported placeholders: {identifier}, {channel}, {site_name}, {otp_length}, {valid_minutes}.')
            : '';
    function InformationControls({ attributes, setAttributes }) {
        return el(PanelBody, { title: t('Information'), initialOpen: false },
            ...information.map(([key, label]) => el(TextareaControl, { key, label, help: informationHelp(key), value: attributes[key] || '', onChange: value => setAttributes({ [key]: value }) })),
            el(BoxField, { label: 'Padding', value: attributes.information_padding, onChange: value => setAttributes({ information_padding: value || {} }) }),
            el(BoxField, { label: t('Margin'), value: attributes.information_margin, onChange: value => setAttributes({ information_margin: value || {} }) }),
            el(ColorField, { label: 'Background', value: attributes.information_background, onChange: value => setAttributes({ information_background: value || '' }) }),
            el(BorderField, { label: 'Border', value: attributes.information_border, onChange: value => setAttributes({ information_border: value || {} }) }),
            el(FontField, { label: 'Typography size', value: attributes.information_font_size, onChange: value => setAttributes({ information_font_size: value || '' }) }),
            el(SelectControl, { label: 'Typography weight', value: attributes.information_font_weight || '', options: [{ label: t('Default'), value: '' }, { label: '400', value: '400' }, { label: '500', value: '500' }, { label: '600', value: '600' }, { label: '700', value: '700' }], onChange: value => setAttributes({ information_font_weight: value }) }),
            el(UnitValueField, { label: 'Typography line height', value: attributes.information_line_height, onChange: value => setAttributes({ information_line_height: value || '' }), min: 0, max: 10, step: 0.05 }),
            el(UnitValueField, { label: 'Typography letter spacing', value: attributes.information_letter_spacing, onChange: value => setAttributes({ information_letter_spacing: value || '' }), min: -10, max: 20, step: 0.1 }),
            el(ColorField, { label: 'Typography text color', value: attributes.information_text_color, onChange: value => setAttributes({ information_text_color: value || '' }) })
        );
    }

    function FormControls({ attributes, setAttributes }) {
        return el(Fragment, {},
            el(PanelBody, { title: t('Field Text'), initialOpen: false },
                ...fields.flatMap(([key, label]) => [
                    el(TextControl, { key: key + '-label', label: label + ' label', help: t('Supported variable: {method}. Shows the enabled sign-in method for that flow.'), value: attributes[key + '_label'] || '', onChange: value => setAttributes({ [key + '_label']: value }) }),
                    el(TextControl, { key: key + '-placeholder', label: label + ' placeholder', help: t('Supported variable: {method}. Shows the enabled sign-in method for that flow.'), value: attributes[key + '_placeholder'] || '', onChange: value => setAttributes({ [key + '_placeholder']: value }) })
                ])
            ),
            el(PanelBody, { title: t('Labels and Placeholders'), initialOpen: false },
                el(FontField, { label: 'Label font size', value: attributes.form_label_font_size, onChange: value => setAttributes({ form_label_font_size: value || '' }) }),
                el(SelectControl, { label: 'Label font weight', value: attributes.form_label_font_weight || '', options: [{ label: t('Default'), value: '' }, { label: '400', value: '400' }, { label: '500', value: '500' }, { label: '600', value: '600' }, { label: '700', value: '700' }], onChange: value => setAttributes({ form_label_font_weight: value }) }),
                el(UnitValueField, { label: 'Label line height', value: attributes.form_label_line_height, onChange: value => setAttributes({ form_label_line_height: value || '' }), min: 0, max: 10, step: 0.05 }),
                el(UnitValueField, { label: 'Label letter spacing', value: attributes.form_label_letter_spacing, onChange: value => setAttributes({ form_label_letter_spacing: value || '' }), min: -10, max: 20, step: 0.1 }),
                el(FontField, { label: 'Focused label font size', value: attributes.form_label_focus_font_size, onChange: value => setAttributes({ form_label_focus_font_size: value || '' }) }),
                el(SelectControl, { label: 'Focused label font weight', value: attributes.form_label_focus_font_weight || '', options: [{ label: t('Default'), value: '' }, { label: '400', value: '400' }, { label: '500', value: '500' }, { label: '600', value: '600' }, { label: '700', value: '700' }], onChange: value => setAttributes({ form_label_focus_font_weight: value }) }),
                el(UnitValueField, { label: 'Focused label line height', value: attributes.form_label_focus_line_height, onChange: value => setAttributes({ form_label_focus_line_height: value || '' }), min: 0, max: 10, step: 0.05 }),
                el(UnitValueField, { label: 'Focused label letter spacing', value: attributes.form_label_focus_letter_spacing, onChange: value => setAttributes({ form_label_focus_letter_spacing: value || '' }), min: -10, max: 20, step: 0.1 }),
                el(ColorField, { label: 'Label text color', value: attributes.form_label_text_color, onChange: value => setAttributes({ form_label_text_color: value || '' }) }),
                el(BoxField, { label: t('Label margin'), value: attributes.form_label_margin, onChange: value => setAttributes({ form_label_margin: value || {} }) }),
                el(FontField, { label: 'Placeholder font size', value: attributes.form_placeholder_font_size, onChange: value => setAttributes({ form_placeholder_font_size: value || '' }) }),
                el(SelectControl, { label: 'Placeholder font weight', value: attributes.form_placeholder_font_weight || '', options: [{ label: t('Default'), value: '' }, { label: '400', value: '400' }, { label: '500', value: '500' }, { label: '600', value: '600' }, { label: '700', value: '700' }], onChange: value => setAttributes({ form_placeholder_font_weight: value }) }),
                el(UnitValueField, { label: 'Placeholder line height', value: attributes.form_placeholder_line_height, onChange: value => setAttributes({ form_placeholder_line_height: value || '' }), min: 0, max: 10, step: 0.05 }),
                el(UnitValueField, { label: 'Placeholder letter spacing', value: attributes.form_placeholder_letter_spacing, onChange: value => setAttributes({ form_placeholder_letter_spacing: value || '' }), min: -10, max: 20, step: 0.1 }),
                el(ColorField, { label: 'Placeholder text color', value: attributes.form_placeholder_text_color, onChange: value => setAttributes({ form_placeholder_text_color: value || '' }) })
            ),
            el(PanelBody, { title: t('Input Appearance'), initialOpen: false },
                el(FontField, { label: 'Input font size', value: attributes.form_input_font_size, onChange: value => setAttributes({ form_input_font_size: value || '' }) }),
                el(SelectControl, { label: 'Input font weight', value: attributes.form_input_font_weight || '', options: [{ label: t('Default'), value: '' }, { label: '400', value: '400' }, { label: '500', value: '500' }, { label: '600', value: '600' }, { label: '700', value: '700' }], onChange: value => setAttributes({ form_input_font_weight: value }) }),
                el(UnitValueField, { label: 'Input line height', value: attributes.form_input_line_height, onChange: value => setAttributes({ form_input_line_height: value || '' }), min: 0, max: 10, step: 0.05 }),
                el(UnitValueField, { label: 'Input letter spacing', value: attributes.form_input_letter_spacing, onChange: value => setAttributes({ form_input_letter_spacing: value || '' }), min: -10, max: 20, step: 0.1 }),
                el(ColorField, { label: t('Input text color'), value: attributes.form_input_text_color, onChange: value => setAttributes({ form_input_text_color: value || '' }) }),
                el(RangeControl, { label: t('Input height'), min: 28, max: 96, value: attributes.form_input_height || undefined, onChange: value => setAttributes({ form_input_height: value || 0 }) }),
                el(BoxField, { label: t('Input padding'), value: attributes.form_input_padding, onChange: value => setAttributes({ form_input_padding: value || {} }) }),
                el(ColorField, { label: t('Input background'), value: attributes.form_input_background, onChange: value => setAttributes({ form_input_background: value || '' }) }),
                el(BorderField, { label: t('Input border'), value: attributes.form_input_border, onChange: value => setAttributes({ form_input_border: value || {} }) }),
                el(BoxShadowField, { label: t('Input focus box shadow'), value: attributes.form_input_outline, onChange: value => setAttributes({ form_input_outline: value || '' }) })
            ),
            el(PanelBody, { title: t('OTP Fields'), initialOpen: false },
                el(UnitValueField, { label: t('OTP field width'), value: attributes.otp_field_width, onChange: value => setAttributes({ otp_field_width: value || '' }), min: 16, max: 200, step: 1 }),
                el(UnitValueField, { label: t('OTP field height'), value: attributes.otp_field_height, onChange: value => setAttributes({ otp_field_height: value || '' }), min: 24, max: 160, step: 1 })
            ),
            el(PanelBody, { title: t('Validation and Errors'), initialOpen: false },
                el(FontField, { label: 'Inline error font size', value: attributes.inline_error_font_size, onChange: value => setAttributes({ inline_error_font_size: value || '' }) }),
                el(SelectControl, { label: 'Inline error font weight', value: attributes.inline_error_font_weight || '', options: [{ label: t('Default'), value: '' }, { label: '400', value: '400' }, { label: '500', value: '500' }, { label: '600', value: '600' }, { label: '700', value: '700' }], onChange: value => setAttributes({ inline_error_font_weight: value }) }),
                el(FontField, { label: 'Error label font size', value: attributes.inline_error_label_font_size, onChange: value => setAttributes({ inline_error_label_font_size: value || '' }) }),
                el(SelectControl, { label: 'Error label font weight', value: attributes.inline_error_label_font_weight || '', options: [{ label: t('Default'), value: '' }, { label: '400', value: '400' }, { label: '500', value: '500' }, { label: '600', value: '600' }, { label: '700', value: '700' }], onChange: value => setAttributes({ inline_error_label_font_weight: value }) }),
                el(BoxField, { label: t('Inline error margin'), value: attributes.inline_error_margin, onChange: value => setAttributes({ inline_error_margin: value || {} }) }),
                el(BoxField, { label: t('Inline error padding'), value: attributes.inline_error_padding, onChange: value => setAttributes({ inline_error_padding: value || {} }) }),
                el(BorderField, { label: t('Inline error border'), value: attributes.inline_error_border, onChange: value => setAttributes({ inline_error_border: value || {} }) }),
                el(ColorField, { label: t('Error input background'), value: attributes.inline_error_input_background, onChange: value => setAttributes({ inline_error_input_background: value || '' }) }),
                el(BorderField, { label: 'Error input border', value: attributes.inline_error_input_border, onChange: value => setAttributes({ inline_error_input_border: value || {} }) }),
                el(BoxShadowField, { label: t('Error input box shadow'), value: attributes.inline_error_input_outline, onChange: value => setAttributes({ inline_error_input_outline: value || '' }) })
            )
        );
    }

    function UnitValueField({ label, value, onChange, min = 0, max = 200, step = 1, disabled = false }) {
        if (!UnitControl) {
            return el(TextControl, { label, value: cssValue(value), onChange: next => onChange(cssValue(next)), disabled });
        }
        return el(UnitControl, {
            label,
            value: cssValue(value),
            onChange: next => onChange(cssValue(next)),
            min,
            max,
            step,
            disabled,
            units: UNIT_OPTIONS,
            __next40pxDefaultSize: true
        });
    }

    function TypographyControls({ attributes, setAttributes, prefix, label }) {
        return el('div', { className: 'peyvast-auth-editor-action-group__section' },
            el('h4', { className: 'peyvast-auth-editor-action-group__subtitle' }, label + ' Typography'),
            el(FontField, {
                label: 'Font size',
                value: attributes[prefix + '_font_size'],
                onChange: value => setAttributes({ [prefix + '_font_size']: value || '' })
            }),
            el(SelectControl, {
                label: t('Font weight'),
                value: attributes[prefix + '_font_weight'] || '',
                options: [
                    { label: t('Default'), value: '' },
                    { label: '400', value: '400' },
                    { label: '500', value: '500' },
                    { label: '600', value: '600' },
                    { label: '700', value: '700' },
                    { label: '800', value: '800' }
                ],
                onChange: value => setAttributes({ [prefix + '_font_weight']: value })
            }),
            el(UnitValueField, {
                label: 'Line height',
                value: attributes[prefix + '_line_height'],
                onChange: value => setAttributes({ [prefix + '_line_height']: value || '' }),
                min: 0,
                max: 10,
                step: 0.05
            }),
            el(UnitValueField, {
                label: 'Letter spacing',
                value: attributes[prefix + '_letter_spacing'],
                onChange: value => setAttributes({ [prefix + '_letter_spacing']: value || '' }),
                min: -10,
                max: 20,
                step: 0.1
            }),
            el(SelectControl, {
                label: 'Text align',
                value: attributes[prefix + '_text_align'] || '',
                options: [
                    { label: t('Default'), value: '' },
                    { label: 'Left', value: 'left' },
                    { label: 'Center', value: 'center' },
                    { label: 'Right', value: 'right' },
                    { label: 'Justify', value: 'justify' }
                ],
                onChange: value => setAttributes({ [prefix + '_text_align']: value })
            }),
            el(ColorField, {
                label: 'Text color',
                value: attributes[prefix + '_text_color'],
                onChange: value => setAttributes({ [prefix + '_text_color']: value || '' })
            })
        );
    }

    function ActionVisualControls({ attributes, setAttributes, prefix, label }) {
        return el('div', { className: 'peyvast-auth-editor-action-group__section' },
            el('h4', { className: 'peyvast-auth-editor-action-group__subtitle' }, label + ' Appearance'),
            el(ColorField, {
                label: 'Background',
                value: attributes[prefix + '_background'],
                onChange: value => setAttributes({ [prefix + '_background']: value || '' })
            }),
            el(BoxField, {
                label: 'Padding',
                value: attributes[prefix + '_padding'],
                onChange: value => setAttributes({ [prefix + '_padding']: value || {} })
            }),
            el(BorderField, {
                label: 'Border',
                value: attributes[prefix + '_border'],
                onChange: value => setAttributes({ [prefix + '_border']: value || {} })
            })
        );
    }

    function ActionIconControls({ attributes, setAttributes, keyPrefix, label }) {
        const hasIcon = !!Number(attributes[keyPrefix + '_icon']);
        return el(Fragment, {},
            el('div', { className: 'peyvast-auth-editor-action-group__control' }, iconControl(keyPrefix + '_icon', attributes, setAttributes)),
            el(SelectControl, {
                label: label + ' icon position',
                value: attributes[keyPrefix + '_icon_position'] || 'left',
                options: [{ label: 'Left', value: 'left' }, { label: 'Right', value: 'right' }],
                disabled: !hasIcon,
                onChange: value => setAttributes({ [keyPrefix + '_icon_position']: value })
            }),
            el(FontField, {
                label: label + ' icon size',
                value: attributes[keyPrefix + '_icon_size'],
                disabled: !hasIcon,
                onChange: value => setAttributes({ [keyPrefix + '_icon_size']: value || '' })
            }),
            el(ColorField, {
                label: label + ' icon color',
                value: attributes[keyPrefix + '_icon_color'],
                disabled: !hasIcon,
                onChange: value => setAttributes({ [keyPrefix + '_icon_color']: value || '' })
            }),
            el(UnitValueField, {
                label: label + ' icon gap',
                value: attributes[keyPrefix + '_icon_gap'],
                disabled: !hasIcon,
                onChange: value => setAttributes({ [keyPrefix + '_icon_gap']: value || '' }),
                min: 0,
                max: 100,
                step: 1
            }),
            el(ToggleControl, {
                label: label + ' space between',
                checked: !!attributes[keyPrefix + '_icon_space'],
                disabled: !hasIcon,
                onChange: value => setAttributes({ [keyPrefix + '_icon_space']: value })
            })
        );
    }

    function ActionStyleControls({ attributes, setAttributes, prefix, label, showIcon = true, showText = true, textPrefix = prefix }) {
        return el('div', { className: 'peyvast-auth-editor-action-group' },
            el('h3', { className: 'peyvast-auth-editor-action-group__title' }, label),
            showText ? el(TextControl, { label: 'Text', value: attributes[textPrefix + '_text'] || '', onChange: value => setAttributes({ [textPrefix + '_text']: value }) }) : null,
            showIcon ? el(ActionIconControls, { attributes, setAttributes, keyPrefix: prefix, label }) : null,
            el(TypographyControls, { attributes, setAttributes, prefix, label }),
            el(ActionVisualControls, { attributes, setAttributes, prefix, label })
        );
    }

    function PrimaryActionsControls({ attributes, setAttributes }) {
        return el(PanelBody, { title: t('Primary Actions'), initialOpen: false },
            ...actions.map(([action, label]) => el(ActionStyleControls, { key: action, prefix: action, textPrefix: action === 'forgot' ? 'forgot_send' : action, label, attributes, setAttributes }))
        );
    }

    function SecondaryActionsControls({ attributes, setAttributes }) {
        return el(PanelBody, { title: t('Secondary Actions'), initialOpen: false },
            el(ActionStyleControls, { prefix: 'secondary', label: t('Secondary actions'), attributes, setAttributes, showIcon: false, showText: false }),
            el(BoxShadowField, { label: t('Box shadow'), value: attributes.secondary_box_shadow, onChange: value => setAttributes({ secondary_box_shadow: value || '' }) }),
            el(TextControl, { label: t('Password sign-in link text'), value: attributes.password_link_text || '', onChange: value => setAttributes({ password_link_text: value }) }),
            el(TextControl, { label: t('Password reset link text'), value: attributes.password_reset_link_text || '', onChange: value => setAttributes({ password_reset_link_text: value }) })
        );
    }

    function ResendControls({ attributes, setAttributes }) {
        return el(PanelBody, { title: t('Resend'), initialOpen: false },
            el(ActionStyleControls, { prefix: 'resend', label: 'Resend button', attributes, setAttributes, showIcon: false }),
            el(TextControl, { label: t('Resend timer text'), help: t('Supported placeholder: {seconds}.'), value: attributes.resend_timer_template || '', onChange: value => setAttributes({ resend_timer_template: value }) }),
            el(FontField, { label: 'Timer font size', value: attributes.resend_timer_font_size, onChange: value => setAttributes({ resend_timer_font_size: value || '' }) }),
            el(SelectControl, { label: 'Timer font weight', value: attributes.resend_timer_font_weight || '', options: [{ label: t('Default'), value: '' }, { label: '400', value: '400' }, { label: '500', value: '500' }, { label: '600', value: '600' }, { label: '700', value: '700' }], onChange: value => setAttributes({ resend_timer_font_weight: value }) })
        );
    }

    function NoticeControls({ attributes, setAttributes }) {
        return el(PanelBody, { title: t('Notice Queue'), initialOpen: false },
            el(UnitValueField, { label: t('Gap'), value: attributes.notice_gap, onChange: value => setAttributes({ notice_gap: value || '' }), min: 0, max: 200, step: 1 }),
            el(UnitValueField, { label: t('Width'), value: attributes.notice_width, onChange: value => setAttributes({ notice_width: value || '' }), min: 0, max: 1200, step: 1 }),
            el(SelectControl, { label: t('Position'), value: attributes.notice_position || 'bottom', options: noticePositionOptions, onChange: value => setAttributes({ notice_position: value }) }),
            ...notices.flatMap(([key, label]) => [
                el('h3', { key: key + '-heading', className: 'peyvast-auth-editor-action-group__title' }, label),
                el(ColorField, { key: key + '-background', label: 'Background', value: attributes['notice_' + key + '_background'], onChange: value => setAttributes({ ['notice_' + key + '_background']: value || '' }) }),
                el(BorderField, { key: key + '-border', label: 'Border', value: attributes['notice_' + key + '_border'], onChange: value => setAttributes({ ['notice_' + key + '_border']: value || {} }) }),
                el(BoxField, { key: key + '-padding', label: 'Padding', value: attributes['notice_' + key + '_padding'], onChange: value => setAttributes({ ['notice_' + key + '_padding']: value || {} }) }),
                el(BoxShadowField, { key: key + '-shadow', label: 'Box shadow', value: attributes['notice_' + key + '_shadow'], onChange: value => setAttributes({ ['notice_' + key + '_shadow']: value || '' }) })
            ]),
            el('h3', { className: 'peyvast-auth-editor-action-group__title' }, 'Close control'),
            el(ColorField, { label: 'Background', value: attributes.notice_close_background, onChange: value => setAttributes({ notice_close_background: value || '' }) }),
            el(BorderField, { label: 'Border', value: attributes.notice_close_border, onChange: value => setAttributes({ notice_close_border: value || {} }) }),
            el(BoxField, { label: 'Padding', value: attributes.notice_close_padding, onChange: value => setAttributes({ notice_close_padding: value || {} }) })
        );
    }

    function AuthInspector({ attributes, setAttributes, previewStage, setPreviewStage }) {
        return el(InspectorControls, {},
            el(PanelBody, { title: t('Supported variables'), initialOpen: true },
                el(Notice, { status: 'info', isDismissible: false }, t('{identifier}: the destination(s) the code was sent to. {channel}: accepted delivery channels from the server response. {site_name}, {otp_length} and {valid_minutes}: data from the server. {seconds}: resend countdown. {method}: the stage sign-in method.'))
            ),
            el(PanelBody, { title: 'Preview Stages', initialOpen: true },
                el(SelectControl, { label: t('Preview stage'), value: previewStage, options: stages.map(([value, label]) => ({ value, label })), onChange: setPreviewStage }),
                el(Notice, { status: 'info', isDismissible: false }, 'Preview stage is editor-only and is never persisted as frontend state.')
            ),
            el(StageContentControls, { attributes, setAttributes }),
            el(StageStyleControls, { attributes, setAttributes }),
            el(ContainerControls, { attributes, setAttributes }),
            el(InformationControls, { attributes, setAttributes }),
            el(FormControls, { attributes, setAttributes }),
            el(PrimaryActionsControls, { attributes, setAttributes }),
            el(SecondaryActionsControls, { attributes, setAttributes }),
            el(ResendControls, { attributes, setAttributes }),
            el(NoticeControls, { attributes, setAttributes })
        );
    }

    function BackButtonInspector({ attributes, setAttributes }) {
        const hasIcon = !!attributes.show_icon && Number(attributes.icon) > 0;
        return el(InspectorControls, {},
            el(PanelBody, { title: t('Back Button'), initialOpen: true },
                el(TextControl, { label: t('Text'), value: attributes.text || '', onChange: value => setAttributes({ text: value }) }),
                iconControl('icon', attributes, setAttributes),
                el(SelectControl, { label: t('Icon position'), value: attributes.icon_position || 'before', disabled: !hasIcon, options: [{ label: t('Before text'), value: 'before' }, { label: t('After text'), value: 'after' }], onChange: value => setAttributes({ icon_position: value }) }),
                el(FontField, { label: t('Icon size'), value: attributes.icon_size, disabled: !hasIcon, onChange: value => setAttributes({ icon_size: value || '' }) }),
                el(ColorField, { label: t('Icon color'), value: attributes.icon_color, disabled: !hasIcon, onChange: value => setAttributes({ icon_color: value || '' }) }),
                el(UnitValueField, { label: t('Icon gap'), value: attributes.icon_gap, disabled: !hasIcon, onChange: value => setAttributes({ icon_gap: value || '' }), min: 0, max: 100, step: 1 }),
                el(ToggleControl, { label: t('Space between'), checked: !!attributes.icon_space, disabled: !hasIcon, onChange: value => setAttributes({ icon_space: value }) }),
                el(ToggleControl, { label: t('Show text'), checked: attributes.show_text !== false, onChange: value => setAttributes({ show_text: value }) }),
                el(ToggleControl, { label: t('Show icon'), checked: !!attributes.show_icon, onChange: value => setAttributes({ show_icon: value }) }),
                el(TextControl, { label: t('Accessibility label'), help: t('Used when visible text is hidden.'), value: attributes.aria_label || '', onChange: value => setAttributes({ aria_label: value }) }),
                el(TextControl, { label: t('Authentication target'), help: t('Optional CSS selector for a specific authentication instance.'), value: attributes.target || '', onChange: value => setAttributes({ target: value }) })
            )
        );
    }

    function AuthEdit(props) {
        const { attributes, setAttributes, clientId } = props;
        const [previewStage, setPreviewStage] = useState('phone');
        const blockProps = useBlockProps({ className: 'peyvast-auth-editor-preview', onClick: () => wp.data.dispatch('core/block-editor').selectBlock(clientId) });
        return el(Fragment, {},
            el('div', blockProps, el(Preview, { name: 'peyvast/auth', attributes, stage: previewStage })),
            el(AuthInspector, { attributes, setAttributes, previewStage, setPreviewStage })
        );
    }

    function BackButtonEdit(props) {
        const { attributes, setAttributes, clientId } = props;
        const blockProps = useBlockProps({ className: 'peyvast-auth-editor-preview', onClick: () => wp.data.dispatch('core/block-editor').selectBlock(clientId) });
        return el(Fragment, {},
            el('div', blockProps, el(Preview, { name: 'peyvast/back-button', attributes, stage: 'phone' })),
            el(BackButtonInspector, { attributes, setAttributes })
        );
    }

    registerBlockType('peyvast/auth', Object.assign({}, metadata['peyvast/auth'] || {}, { edit: AuthEdit, save: () => null }));
    registerBlockType('peyvast/back-button', Object.assign({}, metadata['peyvast/back-button'] || {}, { edit: BackButtonEdit, save: () => null }));
})(window.wp);
