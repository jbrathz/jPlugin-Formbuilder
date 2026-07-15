( function () {
	'use strict';

	const revealFeedback = function ( feedback ) {
		if ( ! feedback ) {
			return;
		}

		const shell = feedback.closest( '[data-jfb-form]' );
		const header = shell ? shell.querySelector( '[data-jfb-form-header]' ) : null;

		if ( header ) {
			header.focus( { preventScroll: true } );
			header.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			return;
		}

		feedback.focus( { preventScroll: true } );
		feedback.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	};

	document.querySelectorAll( '[data-jfb-form] form[data-endpoint]' ).forEach( function ( form ) {
		form.querySelectorAll( '[data-jfb-other-input]' ).forEach( function ( input ) {
			const wrapper = input.closest( '.jfb-choice-other' );
			const choice = wrapper.querySelector( 'input[type="radio"], input[type="checkbox"]' );
			const sync = function () { input.disabled = ! choice.checked; if ( ! choice.checked ) input.value = ''; };
			wrapper.closest( 'fieldset' ).querySelectorAll( 'input[name="' + choice.name + '"]' ).forEach( function ( option ) { option.addEventListener( 'change', sync ); } );
			sync();
		} );
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
				revealFeedback( feedback );
			} catch ( error ) {
				feedback.textContent = error.message;
				feedback.className = 'jfb-form-feedback jfb-error';
				revealFeedback( feedback );
			} finally {
				button.disabled = false;
				form.removeAttribute( 'aria-busy' );
			}
		} );
	} );

	document.querySelectorAll( '[data-jfb-form-feedback].has-message' ).forEach( function ( feedback ) {
		revealFeedback( feedback );
	} );
} )();
