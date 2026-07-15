( function () {
	'use strict';

	function node( tag, attrs, text ) {
		const item = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( function ( pair ) {
			if ( pair[ 0 ] === 'className' ) item.className = pair[ 1 ];
			else if ( pair[ 0 ].startsWith( 'data-' ) ) item.setAttribute( pair[ 0 ], pair[ 1 ] );
			else if ( pair[ 0 ] in item ) item[ pair[ 0 ] ] = pair[ 1 ];
			else item.setAttribute( pair[ 0 ], pair[ 1 ] );
		} );
		if ( text !== undefined ) item.textContent = text;
		return item;
	}

	async function api( root, nonce, path, options ) {
		const response = await fetch( root + path, Object.assign( {
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce, Accept: 'application/json' }
		}, options || {} ) );
		const body = await response.text();
		let data = null;
		try { data = body ? JSON.parse( body ) : null; } catch ( error ) { /* A proxy/server can return HTML; preserve a useful status below. */ }
		if ( ! response.ok ) {
			if ( data && data.code === 'rest_cookie_invalid_nonce' ) {
				throw new Error( 'Your WordPress login session has expired or this page was opened on a different site address. Reload this page, then save again.' );
			}
			throw new Error( ( data && data.message ) || 'Save request failed (HTTP ' + response.status + '). Check the browser Network response or WordPress error log.' );
		}
		if ( ! data ) throw new Error( 'The server returned an invalid response. Check the web server or WordPress error log.' );
		return data;
	}

	function toast( root, message, error ) {
		const target = root.querySelector( '.jfb-toast' );
		if ( ! target ) return;
		target.textContent = message;
		target.className = 'jfb-toast is-visible' + ( error ? ' is-error' : '' );
		setTimeout( function () { target.classList.remove( 'is-visible' ); }, 3500 );
	}

	async function copyText( value ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			await navigator.clipboard.writeText( value );
			return;
		}
		const fallback = node( 'textarea', { value: value, readOnly: true } );
		fallback.style.position = 'fixed';
		fallback.style.opacity = '0';
		document.body.append( fallback );
		fallback.select();
		const copied = document.execCommand( 'copy' );
		fallback.remove();
		if ( ! copied ) throw new Error( 'Clipboard access is unavailable. Select and copy the value manually.' );
	}

	document.querySelectorAll( '[data-jfb-admin]' ).forEach( function ( root ) {
		root.querySelectorAll( '[data-template]' ).forEach( function ( button ) {
			button.addEventListener( 'click', async function () {
				button.disabled = true;
				try {
					const templates = await api( root.dataset.restRoot, root.dataset.restNonce, '/templates' );
					const selected = templates[ button.dataset.template ];
					const form = await api( root.dataset.restRoot, root.dataset.restNonce, '/forms', {
						method: 'POST', body: JSON.stringify( { name: selected.name, status: 'draft', fields: selected.fields, settings: {} } )
					} );
					const url = new URL( document.location.href );
					url.searchParams.set( 'form', form.uuid );
					document.location.assign( url.toString() );
				} catch ( error ) { toast( root, error.message, true ); button.disabled = false; }
			} );
		} );
	} );

	document.querySelectorAll( '[data-jfb-builder]' ).forEach( function ( root ) {
		const dataNode = document.getElementById( 'jfb-form-data' );
		if ( ! dataNode ) return;
		let form = JSON.parse( dataNode.textContent );
		let dirty = false;
		const fieldList = root.querySelector( '[data-field-list]' );
		const preview = root.querySelector( '[data-builder-preview]' );
		const saveState = root.querySelector( '[data-save-state]' );
		const nameInput = root.querySelector( '[data-form-name]' );
		const showEyebrowInput = root.querySelector( '[data-show-eyebrow]' );
		const eyebrowTextInput = root.querySelector( '[data-eyebrow-text]' );
		const statusInput = root.querySelector( '[data-form-status]' );
		const successInput = root.querySelector( '[data-success-message]' );
		const submitLabelInput = root.querySelector( '[data-submit-label]' );
		const emailInput = root.querySelector( '[data-notification-email]' );
		const retentionInput = root.querySelector( '[data-retention-days]' );
		const paletteInputs = Array.from( root.querySelectorAll( '[data-palette-key]' ) );
		form.settings.palette = form.settings.palette || {};
		root.querySelectorAll( '[data-copy-text]' ).forEach( function ( button ) {
			button.addEventListener( 'click', async function () {
				const original = button.textContent;
				try {
					await copyText( button.dataset.copyText );
					button.textContent = 'Copied';
					toast( root, ( button.dataset.copyLabel || 'Value' ) + ' copied to clipboard.', false );
					setTimeout( function () { button.textContent = original; }, 1800 );
				} catch ( error ) { toast( root, error.message, true ); }
			} );
		} );

		nameInput.value = form.name;
		showEyebrowInput.checked = form.settings.show_eyebrow !== false;
		eyebrowTextInput.value = form.settings.eyebrow_text || 'Secure form';
		statusInput.value = form.status;
		successInput.value = form.settings.success_message || '';
		submitLabelInput.value = form.settings.submit_label || '';
		emailInput.value = form.settings.notification_email || '';
		retentionInput.value = form.settings.retention_days || 0;

		function changed() { dirty = true; saveState.textContent = 'Unsaved changes'; saveState.classList.add( 'is-dirty' ); renderPreview(); }
		[ nameInput, eyebrowTextInput, statusInput, successInput, submitLabelInput, emailInput, retentionInput ].forEach( function ( input ) { input.addEventListener( 'input', changed ); } );
		showEyebrowInput.addEventListener( 'change', changed );
		paletteInputs.forEach( function ( input ) { input.addEventListener( 'input', function () { form.settings.palette[ input.dataset.paletteKey ] = input.value; changed(); updateContrast(); } ); } );

		function luminance( hex ) {
			const values = [ 1, 3, 5 ].map( function ( start ) { const value = parseInt( hex.slice( start, start + 2 ), 16 ) / 255; return value <= 0.03928 ? value / 12.92 : Math.pow( ( value + 0.055 ) / 1.055, 2.4 ); } );
			return 0.2126 * values[ 0 ] + 0.7152 * values[ 1 ] + 0.0722 * values[ 2 ];
		}
		function updateContrast() {
			const state = root.querySelector( '[data-contrast-state]' );
			const text = form.settings.palette.text || '#18332e'; const background = form.settings.palette.background || '#ffffff';
			const ratio = ( Math.max( luminance( text ), luminance( background ) ) + 0.05 ) / ( Math.min( luminance( text ), luminance( background ) ) + 0.05 );
			state.textContent = ratio >= 4.5 ? 'Text contrast ' + ratio.toFixed( 1 ) + ':1 · WCAG AA pass' : 'Text contrast ' + ratio.toFixed( 1 ) + ':1 · improve colors for WCAG AA';
			state.className = ratio >= 4.5 ? 'is-pass' : 'is-warning';
		}

		function fieldLabel( label, control ) {
			const wrapper = node( 'label', {} ); wrapper.append( node( 'span', {}, label ), control ); return wrapper;
		}

		function renderFields() {
			fieldList.replaceChildren();
			form.fields.forEach( function ( field, index ) {
				const card = node( 'article', { className: 'jfb-field-card', draggable: true, 'data-index': String( index ) } );
				const bar = node( 'div', { className: 'jfb-field-card-bar' } );
				bar.append( node( 'span', { className: 'dashicons dashicons-move' } ), node( 'strong', {}, field.type ) );
				const actions = node( 'div', { className: 'jfb-field-actions' } );
				[ [ '↑', -1, 'Move up' ], [ '↓', 1, 'Move down' ] ].forEach( function ( action ) {
					const button = node( 'button', { type: 'button', title: action[ 2 ], className: 'button button-small' }, action[ 0 ] );
					button.addEventListener( 'click', function () { const target = index + action[ 1 ]; if ( target < 0 || target >= form.fields.length ) return; const moved = form.fields.splice( index, 1 )[ 0 ]; form.fields.splice( target, 0, moved ); changed(); renderFields(); } );
					actions.append( button );
				} );
				const duplicate = node( 'button', { type: 'button', className: 'button button-small' }, 'Duplicate' );
				duplicate.addEventListener( 'click', function () { const copy = JSON.parse( JSON.stringify( field ) ); copy.id = crypto.randomUUID(); copy.key += '_copy'; form.fields.splice( index + 1, 0, copy ); changed(); renderFields(); } );
				const remove = node( 'button', { type: 'button', className: 'button button-small button-link-delete' }, 'Delete' );
				remove.addEventListener( 'click', function () { form.fields.splice( index, 1 ); changed(); renderFields(); } );
				actions.append( duplicate, remove ); bar.append( actions ); card.append( bar );

				const grid = node( 'div', { className: 'jfb-field-card-grid' } );
				const is_content_block = [ 'heading', 'paragraph' ].includes( field.type );
				const fieldSettings = is_content_block ? [ [ field.type === 'heading' ? 'Heading text' : 'Paragraph text', 'label', 'text' ] ] : [ [ 'Label', 'label', 'text' ], [ 'Field key', 'key', 'text' ], [ 'Help text', 'help', 'text' ], [ 'Placeholder', 'placeholder', 'text' ] ];
				fieldSettings.forEach( function ( spec ) {
					const input = node( 'input', { type: spec[ 2 ], value: field[ spec[ 1 ] ] || '' } );
					input.addEventListener( 'input', function () { field[ spec[ 1 ] ] = input.value; changed(); } );
					grid.append( fieldLabel( spec[ 0 ], input ) );
				} );
				if ( ! is_content_block ) {
					const width = node( 'select', {} );
					[ [ 'full', 'Full width' ], [ 'half', 'Half width' ] ].forEach( function ( option ) { width.append( node( 'option', { value: option[ 0 ], selected: ( field.width || 'full' ) === option[ 0 ] }, option[ 1 ] ) ); } );
					width.addEventListener( 'change', function () { field.width = width.value; changed(); } );
					grid.append( fieldLabel( 'Field width', width ) );
					const labelPosition = node( 'select', {} );
					[ [ 'top', 'Label above field' ], [ 'left', 'Label left of field' ] ].forEach( function ( option ) { labelPosition.append( node( 'option', { value: option[ 0 ], selected: ( field.label_position || 'top' ) === option[ 0 ] }, option[ 1 ] ) ); } );
					labelPosition.addEventListener( 'change', function () { field.label_position = labelPosition.value; changed(); } );
					grid.append( fieldLabel( 'Label layout', labelPosition ) );
				}
				if ( [ 'select', 'radio', 'checkbox' ].includes( field.type ) ) {
					const choices = node( 'textarea', { rows: 3, value: ( field.choices || [] ).join( '\n' ) } );
					choices.addEventListener( 'input', function () { field.choices = choices.value.split( '\n' ).map( function ( value ) { return value.trim(); } ).filter( Boolean ); changed(); } );
					grid.append( fieldLabel( 'Choices, one per line', choices ) );
					const allowOther = node( 'input', { type: 'checkbox', checked: Boolean( field.allow_other ) } );
					allowOther.addEventListener( 'change', function () { field.allow_other = allowOther.checked; changed(); } );
					grid.append( fieldLabel( 'Allow “Other (please specify)”', allowOther ) );
				}
				if ( field.type === 'heading' ) {
					const headingStyle = node( 'select', {} );
					[ [ 'line', 'Underline' ], [ 'band', 'Section band' ] ].forEach( function ( option ) { headingStyle.append( node( 'option', { value: option[ 0 ], selected: ( field.heading_style || 'line' ) === option[ 0 ] }, option[ 1 ] ) ); } );
					headingStyle.addEventListener( 'change', function () { field.heading_style = headingStyle.value; changed(); } );
					grid.append( fieldLabel( 'Heading style', headingStyle ) );
				}
				if ( field.type === 'file' ) {
					const max = node( 'input', { type: 'number', min: 1, max: 20, value: field.max_mb || 5 } );
					max.addEventListener( 'input', function () { field.max_mb = Number( max.value ); changed(); } );
					grid.append( fieldLabel( 'Maximum MB', max ) );
				}
				if ( ! is_content_block ) {
					const required = node( 'input', { type: 'checkbox', checked: Boolean( field.required ) } );
					required.addEventListener( 'change', function () { field.required = required.checked; changed(); } );
					grid.append( fieldLabel( 'Required', required ) );
				}
				card.append( grid ); fieldList.append( card );
				card.addEventListener( 'dragstart', function ( event ) { event.dataTransfer.setData( 'text/plain', String( index ) ); } );
				card.addEventListener( 'dragover', function ( event ) { event.preventDefault(); } );
				card.addEventListener( 'drop', function ( event ) { event.preventDefault(); const from = Number( event.dataTransfer.getData( 'text/plain' ) ); if ( from === index || Number.isNaN( from ) ) return; const moved = form.fields.splice( from, 1 )[ 0 ]; form.fields.splice( index, 0, moved ); changed(); renderFields(); } );
			} );
			renderPreview();
		}

		function renderPreview() {
			preview.replaceChildren(); preview.append( node( 'h3', {}, nameInput.value || 'Untitled form' ) );
			form.fields.forEach( function ( field ) { const row = node( 'div', { className: 'jfb-preview-field' } ); const isContentBlock = [ 'heading', 'paragraph' ].includes( field.type ); row.append( node( 'strong', {}, field.label || 'Untitled field' ), node( 'small', {}, field.type + ( field.required && ! isContentBlock ? ' · required' : '' ) ) ); preview.append( row ); } );
		}

		root.querySelector( '[data-add-field]' ).addEventListener( 'click', function () {
			const type = root.querySelector( '[data-new-field]' ).value;
			form.fields.push( { id: crypto.randomUUID(), type: type, key: 'field_' + ( form.fields.length + 1 ), label: 'Untitled field', help: '', placeholder: '', required: false, width: 'full', label_position: 'top', heading_style: 'line', allow_other: false, choices: [ 'Option 1', 'Option 2' ], max_mb: 5 } );
			changed(); renderFields();
		} );

		root.querySelector( '[data-save-form]' ).addEventListener( 'click', async function ( event ) {
			const button = event.currentTarget; button.disabled = true; saveState.textContent = 'Saving…';
			try {
				form = await api( root.dataset.restRoot, root.dataset.restNonce, '/forms/' + encodeURIComponent( form.uuid ), { method: 'PUT', body: JSON.stringify( {
					name: nameInput.value, status: statusInput.value, fields: form.fields,
					settings: { eyebrow_text: eyebrowTextInput.value, show_eyebrow: showEyebrowInput.checked, success_message: successInput.value, submit_label: submitLabelInput.value, notification_email: emailInput.value, retention_days: Number( retentionInput.value ), palette: form.settings.palette || {} }
				} ) } );
				dirty = false; saveState.textContent = 'Saved'; saveState.classList.remove( 'is-dirty' ); toast( root, 'Form saved.', false ); renderFields();
			} catch ( error ) { saveState.textContent = 'Save failed'; toast( root, error.message, true ); } finally { button.disabled = false; }
		} );

		root.querySelector( '[data-delete-form]' ).addEventListener( 'click', async function ( event ) {
			if ( ! window.confirm( 'Delete this form definition? Existing submissions will be retained.' ) ) return;
			event.currentTarget.disabled = true;
			try {
				await api( root.dataset.restRoot, root.dataset.restNonce, '/forms/' + encodeURIComponent( form.uuid ), { method: 'DELETE' } );
				dirty = false;
				const url = new URL( document.location.href ); url.searchParams.delete( 'form' ); document.location.assign( url.toString() );
			} catch ( error ) { toast( root, error.message, true ); event.currentTarget.disabled = false; }
		} );

		window.addEventListener( 'beforeunload', function ( event ) { if ( dirty ) { event.preventDefault(); event.returnValue = ''; } } );
		updateContrast(); renderFields();
	} );
} )();
