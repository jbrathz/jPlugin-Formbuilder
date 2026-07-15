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
		const data = await response.json();
		if ( ! response.ok ) throw new Error( data.message || 'Request failed.' );
		return data;
	}

	function toast( root, message, error ) {
		const target = root.querySelector( '.jfb-toast' );
		if ( ! target ) return;
		target.textContent = message;
		target.className = 'jfb-toast is-visible' + ( error ? ' is-error' : '' );
		setTimeout( function () { target.classList.remove( 'is-visible' ); }, 3500 );
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
		const statusInput = root.querySelector( '[data-form-status]' );
		const successInput = root.querySelector( '[data-success-message]' );
		const emailInput = root.querySelector( '[data-notification-email]' );
		const retentionInput = root.querySelector( '[data-retention-days]' );
		const paletteInputs = Array.from( root.querySelectorAll( '[data-palette-key]' ) );
		form.settings.palette = form.settings.palette || {};

		nameInput.value = form.name;
		statusInput.value = form.status;
		successInput.value = form.settings.success_message || '';
		emailInput.value = form.settings.notification_email || '';
		retentionInput.value = form.settings.retention_days || 0;

		function changed() { dirty = true; saveState.textContent = 'Unsaved changes'; saveState.classList.add( 'is-dirty' ); renderPreview(); }
		[ nameInput, statusInput, successInput, emailInput, retentionInput ].forEach( function ( input ) { input.addEventListener( 'input', changed ); } );
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
				[ [ 'Label', 'label', 'text' ], [ 'Field key', 'key', 'text' ], [ 'Help text', 'help', 'text' ], [ 'Placeholder', 'placeholder', 'text' ] ].forEach( function ( spec ) {
					const input = node( 'input', { type: spec[ 2 ], value: field[ spec[ 1 ] ] || '' } );
					input.addEventListener( 'input', function () { field[ spec[ 1 ] ] = input.value; changed(); } );
					grid.append( fieldLabel( spec[ 0 ], input ) );
				} );
				if ( [ 'select', 'radio', 'checkbox' ].includes( field.type ) ) {
					const choices = node( 'textarea', { rows: 3, value: ( field.choices || [] ).join( '\n' ) } );
					choices.addEventListener( 'input', function () { field.choices = choices.value.split( '\n' ).map( function ( value ) { return value.trim(); } ).filter( Boolean ); changed(); } );
					grid.append( fieldLabel( 'Choices, one per line', choices ) );
				}
				if ( field.type === 'file' ) {
					const max = node( 'input', { type: 'number', min: 1, max: 20, value: field.max_mb || 5 } );
					max.addEventListener( 'input', function () { field.max_mb = Number( max.value ); changed(); } );
					grid.append( fieldLabel( 'Maximum MB', max ) );
				}
				const required = node( 'input', { type: 'checkbox', checked: Boolean( field.required ) } );
				required.addEventListener( 'change', function () { field.required = required.checked; changed(); } );
				grid.append( fieldLabel( 'Required', required ) ); card.append( grid ); fieldList.append( card );
				card.addEventListener( 'dragstart', function ( event ) { event.dataTransfer.setData( 'text/plain', String( index ) ); } );
				card.addEventListener( 'dragover', function ( event ) { event.preventDefault(); } );
				card.addEventListener( 'drop', function ( event ) { event.preventDefault(); const from = Number( event.dataTransfer.getData( 'text/plain' ) ); if ( from === index || Number.isNaN( from ) ) return; const moved = form.fields.splice( from, 1 )[ 0 ]; form.fields.splice( index, 0, moved ); changed(); renderFields(); } );
			} );
			renderPreview();
		}

		function renderPreview() {
			preview.replaceChildren(); preview.append( node( 'h3', {}, nameInput.value || 'Untitled form' ) );
			form.fields.forEach( function ( field ) { const row = node( 'div', { className: 'jfb-preview-field' } ); row.append( node( 'strong', {}, field.label || 'Untitled field' ), node( 'small', {}, field.type + ( field.required ? ' · required' : '' ) ) ); preview.append( row ); } );
		}

		root.querySelector( '[data-add-field]' ).addEventListener( 'click', function () {
			const type = root.querySelector( '[data-new-field]' ).value;
			form.fields.push( { id: crypto.randomUUID(), type: type, key: 'field_' + ( form.fields.length + 1 ), label: 'Untitled field', help: '', placeholder: '', required: false, choices: [ 'Option 1', 'Option 2' ], max_mb: 5 } );
			changed(); renderFields();
		} );

		root.querySelector( '[data-save-form]' ).addEventListener( 'click', async function ( event ) {
			const button = event.currentTarget; button.disabled = true; saveState.textContent = 'Saving…';
			try {
				form = await api( root.dataset.restRoot, root.dataset.restNonce, '/forms/' + encodeURIComponent( form.uuid ), { method: 'PUT', body: JSON.stringify( {
					name: nameInput.value, status: statusInput.value, fields: form.fields,
					settings: { success_message: successInput.value, notification_email: emailInput.value, retention_days: Number( retentionInput.value ), palette: form.settings.palette || {} }
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
