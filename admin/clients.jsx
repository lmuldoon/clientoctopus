import { render } from '@wordpress/element';
import ClientsApp from './components/ClientsApp';

const root = document.getElementById( 'co-clients-root' );
if ( root ) {
	render( <ClientsApp />, root );
}
