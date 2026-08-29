/**
 * ProjectsApp
 *
 * Root component for the Projects admin page.
 * Manages list ↔ detail view state.
 *
 * Injects global CSS variables (same as admin App.jsx) once on mount.
 */
import { useState } from '@wordpress/element';
import ProjectList   from '../ProjectList';
import ProjectDetail from '../ProjectDetail';
import { injectStyles } from '../../../shared/injectStyles';
import { CO_TOKENS_CSS } from '../../../shared/tokens';

const CF_GLOBAL_CSS = `
${ CO_TOKENS_CSS }

#co-projects-root, #co-projects-root * {
  box-sizing: border-box;
  font-family: var(--co-font);
  -webkit-font-smoothing: antialiased;
}

#co-projects-root a { text-decoration: none; }
`;

export async function coFetch( path, options = {} ) {
	const { apiUrl, nonce } = window.clientoctopusData || {};
	// Ensure the resource path (not the query string) ends in a trailing slash —
	// some hosts 301-redirect a request missing it, which can drop the method/body.
	const [ base, qs ] = path.split( '?' );
	const url = ( apiUrl || '/wp-json/clientoctopus/v1/' ) + base.replace( /\/?$/, '/' ) + ( qs ? `?${ qs }` : '' );

	const res = await fetch( url, {
		...options,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce || '',
			...( options.headers || {} ),
		},
	} );

	if ( ! res.ok ) {
		const err = await res.json().catch( () => ( {} ) );
		throw new Error( err.message || `Request failed: ${ res.status }` );
	}

	return res.json();
}

export default function ProjectsApp() {
	injectStyles( 'co-projects-global-styles', CF_GLOBAL_CSS );

	const [ view, setView ]               = useState( 'list' );
	const [ activeProjectId, setActiveProjectId ] = useState( null );

	function handleViewProject( id ) {
		setActiveProjectId( id );
		setView( 'detail' );
	}

	function handleBack() {
		setActiveProjectId( null );
		setView( 'list' );
	}

	return view === 'list' ? (
		<ProjectList onViewProject={ handleViewProject } />
	) : (
		<ProjectDetail
			projectId={ activeProjectId }
			onBack={ handleBack }
		/>
	);
}
