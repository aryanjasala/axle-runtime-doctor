/**
 * Tools > Runtime Doctor: runs the checks over REST and copies support tickets.
 *
 * JS hooks (through wp.hooks, read on each use so later scripts can add them):
 *
 * - filter axleRuntimeDoctor.ticketText ( text, checkId, hostId ): the support
 *   ticket text that "Copy support ticket" puts on the clipboard.
 * - filter axleRuntimeDoctor.ticketText only runs for support tickets, not for the
 *   report text or code on the Compare tab.
 * - action axleRuntimeDoctor.checksFinished ( report ): the checks ran from the
 *   screen; fires before the page reloads with the new results.
 * - action axleRuntimeDoctor.pageCacheStarted ( text ): the page cache test was
 *   started from the Page cache tab; the page reloads when it is done. The text is
 *   what the screen tells assistive technology.
 */
( function () {
	'use strict';

	const { __ } = window.wp && window.wp.i18n ? window.wp.i18n : { __: ( text ) => text };

	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
	}

	function announce( message ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message );
			return;
		}
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
		if ( h && button.hasAttribute( 'data-check' ) ) {
			text = String( h.applyFilters( 'axleRuntimeDoctor.ticketText', text, button.getAttribute( 'data-check' ) || '', button.getAttribute( 'data-host' ) || '' ) );
		}
		const done = () => {
			button.setAttribute( 'data-copied', text );
			announce( button.hasAttribute( 'data-check' ) ? __( 'Support ticket copied.', 'axle-runtime-doctor' ) : __( 'Copied.', 'axle-runtime-doctor' ) );
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

	// In-place confirm: the Remove button opens the panel, Keep it closes it and gives focus back.
	function toggleConfirm( id, open ) {
		const panel = document.getElementById( id );
		const opener = document.querySelector( '[data-ard-confirm="' + id + '"]' );
		if ( ! panel ) {
			return;
		}
		panel.hidden = ! open;
		if ( opener ) {
			opener.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
		if ( open ) {
			const first = panel.querySelector( 'button[type="submit"]' );
			if ( first ) {
				first.focus();
			}
		} else if ( opener ) {
			opener.focus();
		}
	}

	document.addEventListener( 'click', ( event ) => {
		const opener = event.target.closest( '[data-ard-confirm]' );
		if ( opener ) {
			toggleConfirm( opener.getAttribute( 'data-ard-confirm' ), true );
			return;
		}
		const cancel = event.target.closest( '[data-ard-cancel]' );
		if ( cancel ) {
			toggleConfirm( cancel.getAttribute( 'data-ard-cancel' ), false );
		}
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' !== event.key ) {
			return;
		}
		const panel = event.target.closest && event.target.closest( '.axle-confirm' );
		if ( panel && panel.id ) {
			toggleConfirm( panel.id, false );
		}
	} );

	// A form that waits for another site: show it is busy, once.
	document.addEventListener( 'submit', ( event ) => {
		const form = event.target.closest( 'form[data-ard-busy]' );
		if ( ! form || form.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		form.setAttribute( 'aria-busy', 'true' );
		const spinner = form.querySelector( '.spinner' );
		if ( spinner ) {
			spinner.classList.add( 'is-active' );
		}
		const button = form.querySelector( 'button[type="submit"]' );
		if ( button ) {
			window.setTimeout( () => {
				button.disabled = true;
			}, 0 );
		}
		const busyText = form.getAttribute( 'data-ard-busy-text' ) || __( 'Fetching the report from the other site.', 'axle-runtime-doctor' );
		announce( busyText );
		const h = hooks();
		if ( h && form.classList.contains( 'ard-cache-form' ) ) {
			h.doAction( 'axleRuntimeDoctor.pageCacheStarted', busyText );
		}
	} );

	// Say what the last action did, for people who cannot see the notice.
	const notice = document.querySelector( '.ard-notice' );
	if ( notice ) {
		announce( notice.textContent.trim() );
	}

	// No report yet: run the checks right away.
	const auto = document.querySelector( '[data-ard-autorun] form[data-ard-run]' );
	if ( auto ) {
		run( auto );
	}
} )();
