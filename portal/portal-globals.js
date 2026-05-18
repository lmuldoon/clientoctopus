/**
 * Portal globals
 *
 * Imported first in portal/index.jsx so that window.injectStyles is defined
 * before any component module evaluates its top-level injectStyles() calls.
 */

window.injectStyles = function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const el = document.createElement( 'style' );
	el.id          = id;
	el.textContent = css;
	document.head.appendChild( el );
};
