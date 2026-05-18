/**
 * Client Octopus — Client-facing entry point.
 *
 * Reads window.coClientData.pageType to decide which top-level component to
 * mount:
 *   'proposal' (default) → <ProposalClientView />
 *   'success'            → <PaymentSuccess />
 *   'cancel'             → <PaymentCancelled />
 *
 * All components are mounted into #co-client-root.
 */

import ProposalClientView from './components/ProposalClientView';
import PaymentSuccess     from './components/PaymentSuccess';
import PaymentCancelled   from './components/PaymentCancelled';

// Provide injectStyles as a global so client components (which use wp.element
// globals instead of ES imports) can call it — same pattern as portal/index.jsx.
window.injectStyles = function ( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
};

const { render } = wp.element;

const root       = document.getElementById( 'co-client-root' );
const coData     = window.coClientData || {};
const pageType   = coData.pageType   || 'proposal';
const token      = coData.token      || '';
const sessionId  = coData.sessionId  || '';

if ( root ) {
	if ( pageType === 'success' ) {
		render( <PaymentSuccess token={ token } sessionId={ sessionId } />, root );
	} else if ( pageType === 'cancel' ) {
		render( <PaymentCancelled token={ token } />, root );
	} else {
		render( <ProposalClientView />, root );
	}
}
