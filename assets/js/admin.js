/* global sendfeatherAdmin */
( function () {
	'use strict';

	var config = window.sendfeatherAdmin || { messages: {} };

	/* --- Connection mode: show only the fields the selected mode uses. --- */
	var modeRadios = document.querySelectorAll( 'input[name="sendfeather_settings[mode]"]' );
	var modeFields = document.querySelectorAll( '[data-sendfeather-mode]' );

	function selectedMode() {
		var mode = 'api';

		modeRadios.forEach( function ( radio ) {
			if ( radio.checked ) {
				mode = radio.value;
			}
		} );

		return mode;
	}

	function syncModeFields() {
		var mode = selectedMode();

		modeFields.forEach( function ( field ) {
			var row = field.closest( 'tr' );
			( row || field ).hidden = field.getAttribute( 'data-sendfeather-mode' ) !== mode;
		} );
	}

	if ( modeRadios.length && modeFields.length ) {
		modeRadios.forEach( function ( radio ) {
			radio.addEventListener( 'change', syncModeFields );
		} );

		syncModeFields();
	}

	/* --- Show/hide password fields. --- */
	document.querySelectorAll( '[data-sendfeather-reveal]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var input = document.getElementById( button.getAttribute( 'data-sendfeather-reveal' ) );

			if ( ! input ) {
				return;
			}

			var reveal = input.type === 'password';

			input.type = reveal ? 'text' : 'password';
			button.textContent = reveal ? config.messages.hide : config.messages.show;
		} );
	} );

	/* --- Test email: send and render the inline result. --- */
	var testButton = document.getElementById( 'sendfeather-test-button' );
	var testInput = document.getElementById( 'sendfeather-test-email' );
	var testResult = document.getElementById( 'sendfeather-test-result' );
	var testSpinner = document.querySelector( '.sendfeather-test-spinner' );

	function showResult( message, state ) {
		if ( ! testResult ) {
			return;
		}

		testResult.textContent = message;
		testResult.hidden = false;
		testResult.className = 'sendfeather-test-result';

		if ( 'success' === state ) {
			testResult.className += ' is-success';
		} else if ( 'error' === state ) {
			testResult.className += ' is-error';
		}
	}

	function setBusy( busy ) {
		if ( testButton ) {
			testButton.disabled = busy;
		}

		if ( testSpinner ) {
			testSpinner.classList.toggle( 'is-active', busy );
		}
	}

	if ( testButton && testInput && testResult ) {
		testButton.addEventListener( 'click', function () {
			var email = testInput.value.trim();

			if ( ! email ) {
				showResult( config.messages.missingEmail, 'error' );

				return;
			}

			setBusy( true );
			showResult( config.messages.sending, 'pending' );

			var body = new URLSearchParams();

			body.append( 'action', 'sendfeather_send_test_email' );
			body.append( 'nonce', config.nonce );
			body.append( 'email', email );

			fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					var message = payload && payload.data && payload.data.message ? payload.data.message : config.messages.networkError;
					var succeeded = !!( payload && payload.success );

					showResult( message, succeeded ? 'success' : 'error' );
				} )
				.catch( function () {
					showResult( config.messages.networkError, 'error' );
				} )
				.finally( function () {
					setBusy( false );
				} );
		} );
	}
}() );
