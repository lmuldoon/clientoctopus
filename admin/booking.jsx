import { render } from '@wordpress/element';
import BookingsApp from './components/BookingsApp';

const root = document.getElementById( 'co-bookings-root' );
if ( root ) {
	render( <BookingsApp />, root );
}
