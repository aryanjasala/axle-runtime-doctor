/**
 * Tools > Runtime Doctor: runs the checks over REST and copies support tickets.
 *
 * JS hooks (through wp.hooks, read on each use so later scripts can add them):
 *
 * - filter axleRuntimeDoctor.ticketText ( text, checkId, hostId ): the support
 *   ticket text that "Copy support ticket" puts on the clipboard.
 * - action axleRuntimeDoctor.checksFinished ( report ): the checks ran from the
 *   screen; fires before the page reloads with the new results.
 */
( function () {
	'use strict';

	const { __ } = window.wp && window.wp.i18n ? window.wp.i18n : { __: ( text ) => text };

	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
	}

	function announce( message ) {
		const live = document.querySelector( '.ard-live' );
		if ( live ) {
			live.textContent = '';
			window.setTimeout( () => {
				live.textContent = message;
			}, 50 );
		}
	}

	function run( form ) {
		if ( ! window.wp || ! window.wp.apiFetch ) {
			return false;
		}
		const button = form.querySelector( 'button[type="submit"]' );
		const running = form.querySelector( '.ard-running' );
		button.disabled = true;
		form.setAttribute( 'aria-busy', 'true' );
		if ( running ) {
			running.hidden = false;
		}
		announce( __( 'Running the checks.', 'axle-runtime-doctor' ) );

		window.wp
			.apiFetch( { path: '/axle-runtime-doctor/v1/run', method: 'POST' } )
			.then( ( report ) => {
				const h = hooks();
				if ( h ) {
					h.doAction( 'axleRuntimeDoctor.checksFinished', report );
				}
				announce( __( 'The checks ran. Loading the results.', 'axle-runtime-doctor' ) );
				const url = new URL( window.location.href );
				url.searchParams.set( 'tab', 'checks' );
				url.searchParams.set( 'ard-message', 'ran' );
				window.location.assign( url.toString() );
			} )
			.catch( () => {
				// Fall back to the plain form, which runs the checks in admin-post.php.
				form.removeAttribute( 'data-ard-run' );
				form.submit();
			} );

		return true;
	}

	document.addEventListener( 'submit', ( event ) => {
		const form = event.target.closest( 'form[data-ard-run]' );
		if ( form && run( form ) ) {
			event.preventDefault();
		}
	} );

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-ard-copy]' );
		if ( ! button ) {
			return;
		}
		const field = document.getElementById( button.getAttribute( 'data-ard-copy' ) );
		if ( ! field ) {
			return;
		}
		let text = field.value;
		const h = hooks();
		if ( h ) {
			text = String( h.applyFilters( 'axleRuntimeDoctor.ticketText', text, button.getAttribute( 'data-check' ) || '', button.getAttribute( 'data-host' ) || '' ) );
		}
		const done = () => {
			button.setAttribute( 'data-copied', text );
			announce( __( 'Support ticket copied.', 'axle-runtime-doctor' ) );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done, () => {
				field.select();
				done();
			} );
		} else {
			field.value = text;
			field.select();
			try {
				document.execCommand( 'copy' );
			} catch ( error ) {
				// Selected for a manual copy.
			}
			done();
		}
	} );

	// No report yet: run the checks right away.
	const auto = document.querySelector( '[data-ard-autorun] form[data-ard-run]' );
	if ( auto ) {
		run( auto );
	}
} )();
