( function () {
	'use strict';

	document.querySelectorAll( '[data-jfb-form] form[data-endpoint]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', async function ( event ) {
			if ( ! window.fetch || ! form.reportValidity() ) {
				return;
			}
			event.preventDefault();
			const shell = form.closest( '[data-jfb-form]' );
			const feedback = shell.querySelector( '.jfb-form-feedback' );
			const button = form.querySelector( 'button[type="submit"]' );
			button.disabled = true;
			form.setAttribute( 'aria-busy', 'true' );
			feedback.textContent = 'Sending…';
			feedback.className = 'jfb-form-feedback';

			try {
				const response = await fetch( form.dataset.endpoint, {
					method: 'POST',
					body: new FormData( form ),
					credentials: 'same-origin',
					headers: { Accept: 'application/json' }
				} );
				const data = await response.json();
				if ( ! response.ok ) {
					throw new Error( data.message || 'The response could not be submitted.' );
				}
				feedback.textContent = data.message;
				feedback.className = 'jfb-form-feedback jfb-success';
				form.reset();
				const started = form.querySelector( '[name="jfb_started_at"]' );
				if ( started ) started.value = Math.floor( Date.now() / 1000 );
				feedback.focus();
			} catch ( error ) {
				feedback.textContent = error.message;
				feedback.className = 'jfb-form-feedback jfb-error';
			} finally {
				button.disabled = false;
				form.removeAttribute( 'aria-busy' );
			}
		} );
	} );
} )();

