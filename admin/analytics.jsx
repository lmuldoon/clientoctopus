/**
 * Client Octopus Analytics — React Entry Point
 *
 * Mounts the AnalyticsApp component into #co-analytics-root.
 */
import { render } from '@wordpress/element';
import AnalyticsApp from './components/AnalyticsApp';

const root = document.getElementById( 'co-analytics-root' );

if ( root ) {
	render( <AnalyticsApp />, root );
}
