import { render } from '@wordpress/element';
import WebhooksApp from './components/WebhooksApp';

const root = document.getElementById( 'co-webhooks-root' );
if ( root ) {
	render( <WebhooksApp />, root );
}
