( function ( blocks, element, components ) {
	'use strict';
	const el = element.createElement;
	blocks.registerBlockType( 'jplugin-formbuilder/form', {
		edit: function ( props ) {
			return el( 'div', { className: 'jfb-block-placeholder' },
				el( 'strong', null, 'jPlugin Formbuilder' ),
				el( components.TextControl, {
					label: 'Form UUID',
					value: props.attributes.formId || '',
					onChange: function ( value ) { props.setAttributes( { formId: value.trim() } ); }
				} )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components );

