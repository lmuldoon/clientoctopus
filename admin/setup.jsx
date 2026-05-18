import { render } from '@wordpress/element';
import SetupWizard from './components/SetupWizard';

const root = document.getElementById( 'co-setup-root' );
if ( root ) {
	render( <SetupWizard />, root );
}
