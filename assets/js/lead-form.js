/**
 * Lead capture form submission handler.
 *
 * Plain JS, no build step — this loads on arbitrary third-party pages via
 * the [clientoctopus_lead_form] shortcode, so it stays dependency-free.
 */
( function () {
	'use strict';

	function onSubmit( form, event ) {
		event.preventDefault();

		var messageEl = form.querySelector( '.co-lead-form__message' );
		var submitBtn = form.querySelector( '.co-lead-form__submit' );
		var data = {};

		Array.prototype.forEach.call( form.elements, function ( el ) {
			if ( ! el.name ) {
				return;
			}
			if ( 'checkbox' === el.type ) {
				data[ el.name ] = el.checked;
			} else {
				data[ el.name ] = el.value;
			}
		} );

		var turnstileInput = form.querySelector( 'input[name="cf-turnstile-response"]' );
		if ( turnstileInput ) {
			data.turnstile_token = turnstileInput.value;
		}

		submitBtn.disabled = true;
		messageEl.textContent = '';
		messageEl.className = 'co-lead-form__message';

		fetch( window.coLeadFormData.apiUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( data ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( body ) {
					if ( ! res.ok ) {
						throw new Error( body.message || 'Something went wrong. Please try again.' );
					}
					return body;
				} );
			} )
			.then( function () {
				form.reset();
				messageEl.textContent = 'Thanks — your message has been sent.';
				messageEl.className = 'co-lead-form__message co-lead-form__message--success';
			} )
			.catch( function ( err ) {
				messageEl.textContent = err.message;
				messageEl.className = 'co-lead-form__message co-lead-form__message--error';
			} )
			.finally( function () {
				submitBtn.disabled = false;
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var forms = document.querySelectorAll( '.co-lead-form' );
		Array.prototype.forEach.call( forms, function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				onSubmit( form, event );
			} );
		} );
	} );
} )();
