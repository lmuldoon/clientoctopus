/**
 * Portal bundle entry point.
 *
 * Reads cfPortalData.page and mounts the correct top-level component.
 *
 *   login     → PortalLogin   (unauthenticated)
 *   verify    → PortalVerify  (unauthenticated, auto-fires token verification)
 *   dashboard | proposals | payments → PortalApp (authenticated shell)
 */

// Must be first import so window.injectStyles is defined before any
// component module evaluates its top-level injectStyles() calls.
import './portal-globals';

import PortalLogin       from './components/PortalLogin';
import PortalVerify      from './components/PortalVerify';
import PortalSetPassword from './components/PortalSetPassword';
import PortalReceipt     from './components/PortalReceipt';
import PortalApp         from './components/PortalApp';

const { render } = wp.element;

const root = document.getElementById( 'co-portal-root' );
const page = ( window.cfPortalData || {} ).page || 'login';

if ( 'login' === page ) {
	render( <PortalLogin />, root );
} else if ( 'verify' === page ) {
	render( <PortalVerify />, root );
} else if ( 'set-password' === page ) {
	render( <PortalSetPassword />, root );
} else if ( 'receipt' === page ) {
	render( <PortalReceipt />, root );
} else {
	// dashboard | proposals | payments | projects
	render( <PortalApp page={ page } />, root );
}
