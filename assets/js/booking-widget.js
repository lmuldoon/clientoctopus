/**
 * Call booking widget.
 *
 * Plain JS, no build step — mirrors assets/js/lead-form.js's dependency-free
 * approach, since this also loads on arbitrary third-party pages via the
 * [clientoctopus_booking_form] shortcode.
 */
( function () {
	'use strict';

	function pad( n ) {
		return String( n ).padStart( 2, '0' );
	}

	// The calendar grid is built entirely from UTC-based Date objects whose
	// UTC y/m/d fields represent the SITE's calendar day (not the visitor's
	// browser-local day) — see siteToday() below. Using .getUTC*()/Date.UTC()
	// throughout keeps this consistent and avoids any DST/local-timezone
	// interference in the day-of-week and month-length arithmetic.
	function dateKey( date ) {
		return date.getUTCFullYear() + '-' + pad( date.getUTCMonth() + 1 ) + '-' + pad( date.getUTCDate() );
	}

	var WEEKDAY_KEYS = [ 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ]; // Date#getUTCDay() order (0 = Sunday)

	// Returns a UTC-midnight Date object for "today" as the SITE sees it,
	// derived from the site's UTC offset (localized from PHP), not the
	// visitor's browser timezone/clock.
	function siteToday() {
		var offsetMs = ( window.coBookingData.siteUtcOffsetMinutes || 0 ) * 60000;
		var shifted  = new Date( Date.now() + offsetMs );
		return new Date( Date.UTC( shifted.getUTCFullYear(), shifted.getUTCMonth(), shifted.getUTCDate() ) );
	}

	function initWidget( root ) {
		var apiUrl        = window.coBookingData.apiUrl;
		var maxDaysAhead  = parseInt( root.getAttribute( 'data-max-days-ahead' ), 10 ) || 14;
		var enabledDays   = ( root.getAttribute( 'data-enabled-days' ) || '' ).split( ',' ).filter( Boolean );
		var viewMonthOffset = 0; // 0 = current month; preserved across back-navigation so returning to the dates step keeps the month the visitor was looking at.
		var datesStep     = root.querySelector( '.co-booking__step--dates' );
		var slotsStep     = root.querySelector( '.co-booking__step--slots' );
		var detailsStep   = root.querySelector( '.co-booking__step--details' );
		var confirmedStep = root.querySelector( '.co-booking__step--confirmed' );
		var cancelledStep = root.querySelector( '.co-booking__step--cancelled' );
		var datesEl       = root.querySelector( '.co-booking__dates' );
		var slotsEl       = root.querySelector( '.co-booking__slots' );
		var selectedSlotEl = root.querySelector( '.co-booking__selected-slot' );
		var messageEl     = root.querySelector( '.co-booking__message' );
		var selectedSlotIso = null;

		function showStep( step ) {
			[ datesStep, slotsStep, detailsStep, confirmedStep, cancelledStep ].forEach( function ( s ) {
				s.hidden = s !== step;
			} );
		}

		function setMessage( text, isError ) {
			messageEl.textContent = text || '';
			messageEl.className = 'co-booking__message' + ( isError ? ' co-booking__message--error' : '' );
		}

		// Cancellation entry point — the confirmation/reminder emails link back
		// here with ?cancel=TOKEN rather than a separate page template.
		var cancelToken = new URLSearchParams( window.location.search ).get( 'cancel' );
		if ( cancelToken ) {
			fetch( apiUrl + 'booking/cancel/' + encodeURIComponent( cancelToken ) + '/' )
				.then( function () { showStep( cancelledStep ); } )
				.catch( function () { setMessage( 'Something went wrong. Please try again.', true ); } );
			return;
		}

		function renderCalendar( monthOffset ) {
			viewMonthOffset = monthOffset;
			datesEl.innerHTML = '';

			var today      = siteToday();
			var lastValid  = new Date( Date.UTC( today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate() + maxDaysAhead - 1 ) );

			var monthStart = new Date( Date.UTC( today.getUTCFullYear(), today.getUTCMonth() + monthOffset, 1 ) );
			var daysInMonth = new Date( Date.UTC( monthStart.getUTCFullYear(), monthStart.getUTCMonth() + 1, 0 ) ).getUTCDate();
			var nextMonthStart = new Date( Date.UTC( monthStart.getUTCFullYear(), monthStart.getUTCMonth() + 1, 1 ) );

			var nav = document.createElement( 'div' );
			nav.className = 'co-booking__cal-nav';

			var prevBtn = document.createElement( 'button' );
			prevBtn.type = 'button';
			prevBtn.className = 'co-booking__cal-nav-btn';
			prevBtn.textContent = '‹';
			prevBtn.setAttribute( 'aria-label', 'Previous month' );
			prevBtn.disabled = monthOffset <= 0;
			prevBtn.addEventListener( 'click', function () { renderCalendar( monthOffset - 1 ); } );

			var label = document.createElement( 'span' );
			label.className = 'co-booking__cal-label';
			label.textContent = monthStart.toLocaleDateString( undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' } );

			var nextBtn = document.createElement( 'button' );
			nextBtn.type = 'button';
			nextBtn.className = 'co-booking__cal-nav-btn';
			nextBtn.textContent = '›';
			nextBtn.setAttribute( 'aria-label', 'Next month' );
			nextBtn.disabled = nextMonthStart > lastValid;
			nextBtn.addEventListener( 'click', function () { renderCalendar( monthOffset + 1 ); } );

			nav.appendChild( prevBtn );
			nav.appendChild( label );
			nav.appendChild( nextBtn );
			datesEl.appendChild( nav );

			var grid = document.createElement( 'div' );
			grid.className = 'co-booking__cal-grid';

			WEEKDAY_KEYS.forEach( function ( key ) {
				var head = document.createElement( 'div' );
				head.className = 'co-booking__cal-weekday';
				head.textContent = key.charAt( 0 ).toUpperCase() + key.slice( 1, 3 );
				grid.appendChild( head );
			} );

			for ( var i = 0; i < monthStart.getUTCDay(); i++ ) {
				grid.appendChild( document.createElement( 'div' ) );
			}

			for ( var day = 1; day <= daysInMonth; day++ ) {
				var cellDate = new Date( Date.UTC( monthStart.getUTCFullYear(), monthStart.getUTCMonth(), day ) );
				var isEnabledWeekday = enabledDays.length === 0 || enabledDays.indexOf( WEEKDAY_KEYS[ cellDate.getUTCDay() ] ) !== -1;
				var isInWindow = cellDate >= today && cellDate <= lastValid;
				var isToday = cellDate.getTime() === today.getTime();

				var cell = document.createElement( 'button' );
				cell.type = 'button';
				cell.className = 'co-booking__cal-day' + ( isToday ? ' co-booking__cal-day--today' : '' );
				cell.textContent = String( day );

				if ( isInWindow && isEnabledWeekday ) {
					( function ( d ) {
						cell.addEventListener( 'click', function () { loadSlots( d ); } );
					} )( cellDate );
				} else {
					cell.disabled = true;
					cell.className += ' co-booking__cal-day--disabled';
				}

				grid.appendChild( cell );
			}

			datesEl.appendChild( grid );
		}

		function loadSlots( day ) {
			setMessage( '' );
			slotsEl.innerHTML = '<p class="co-booking__loading">Loading…</p>';
			showStep( slotsStep );

			fetch( apiUrl + 'booking/availability/?date=' + dateKey( day ) )
				.then( function ( res ) { return res.json().then( function ( body ) { return { ok: res.ok, body: body }; } ); } )
				.then( function ( result ) {
					if ( ! result.ok ) {
						slotsEl.innerHTML = '';
						setMessage( result.body.message || 'Booking is not available.', true );
						return;
					}
					var slots = result.body.slots || [];
					slotsEl.innerHTML = '';
					if ( 0 === slots.length ) {
						slotsEl.innerHTML = '<p class="co-booking__empty">No times available that day.</p>';
						return;
					}
					slots.forEach( function ( iso ) {
						var slotDate = new Date( iso );
						var btn = document.createElement( 'button' );
						btn.type = 'button';
						btn.className = 'co-booking__slot-btn';
						btn.textContent = slotDate.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
						btn.addEventListener( 'click', function () {
							selectedSlotIso = iso;
							selectedSlotEl.textContent = slotDate.toLocaleString( undefined, {
								weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit',
							} );
							showStep( detailsStep );
						} );
						slotsEl.appendChild( btn );
					} );
				} )
				.catch( function () {
					setMessage( 'Something went wrong. Please try again.', true );
				} );
		}

		[ slotsStep, detailsStep ].forEach( function ( step ) {
			var back = step.querySelector( '.co-booking__back' );
			if ( back ) {
				back.addEventListener( 'click', function () { showStep( step === slotsStep ? datesStep : slotsStep ); } );
			}
		} );

		detailsStep.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( ! selectedSlotIso ) {
				return;
			}

			var submitBtn = detailsStep.querySelector( '.co-booking__submit' );
			submitBtn.disabled = true;
			setMessage( '' );

			var data = { scheduled_at: selectedSlotIso };
			Array.prototype.forEach.call( detailsStep.elements, function ( el ) {
				if ( el.name ) {
					data[ el.name ] = el.value;
				}
			} );

			fetch( apiUrl + 'booking/submit/', {
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
					confirmedStep.querySelector( '.co-booking__confirmed-message' ).textContent =
						'You’re booked! A confirmation email is on its way to you.';
					showStep( confirmedStep );
				} )
				.catch( function ( err ) {
					setMessage( err.message, true );
				} )
				.finally( function () {
					submitBtn.disabled = false;
				} );
		} );

		renderCalendar( viewMonthOffset );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var widgets = document.querySelectorAll( '.co-booking' );
		Array.prototype.forEach.call( widgets, initWidget );
	} );
} )();
