/**
 * ClientFlow Admin — React Entry Point
 *
 * Mounts the App component into #co-proposals-root.
 * Uses @wordpress/element (React wrapper) for WP compatibility.
 */
import { render } from '@wordpress/element';
import App from './App';

const root = document.getElementById( 'co-proposals-root' );

if ( root ) {
	render( <App />, root );
}
