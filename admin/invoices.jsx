import { render } from '@wordpress/element';
import InvoicesApp from './components/InvoicesApp';

const root = document.getElementById( 'co-invoices-root' );
if ( root ) {
	render( <InvoicesApp />, root );
}
