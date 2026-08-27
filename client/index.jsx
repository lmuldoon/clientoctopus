/**
 * Client Octopus — Client-facing entry point.
 *
 * Reads window.clientoctopusClientData.pageType to decide which top-level component to
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

const { render } = wp.element;

// Independent fallback read of the redirect URL itself — the PHP-templated
// window.clientoctopusClientData values above depend on client-routing.php's
// $_GET parsing surviving the rewrite chain intact; this degrades gracefully
// if that ever breaks, rather than silently stalling on "pending" forever.
const urlParams        = new URLSearchParams( window.location.search );
const urlPaypalToken   = urlParams.get( 'token' )    || '';
const urlPaypalPayerId = urlParams.get( 'PayerID' )  || urlParams.get( 'payerid' ) || '';
const urlStripeSession = urlParams.get( 'session_id' ) || '';

const root       = document.getElementById( 'co-client-root' );
const coData     = window.clientoctopusClientData || {};
const pageType   = coData.pageType   || 'proposal';
const token      = coData.token      || '';

let sessionId = coData.sessionId || '';
let provider  = coData.gatewayProvider || 'stripe';

if ( ! sessionId ) {
	if ( urlPaypalToken && urlPaypalPayerId ) {
		sessionId = urlPaypalToken;
		provider  = 'paypal';
	} else if ( urlStripeSession ) {
		sessionId = urlStripeSession;
		provider  = 'stripe';
	}
}

if ( root ) {
	if ( pageType === 'success' ) {
		render( <PaymentSuccess token={ token } sessionId={ sessionId } provider={ provider } />, root );
	} else if ( pageType === 'cancel' ) {
		render( <PaymentCancelled token={ token } />, root );
	} else {
		render( <ProposalClientView isPreview={ pageType === 'preview' } />, root );
	}
}
