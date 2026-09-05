import { render } from '@wordpress/element';
import LeadsApp from './components/LeadsApp';

const root = document.getElementById( 'co-leads-root' );
if ( root ) {
	render( <LeadsApp />, root );
}
