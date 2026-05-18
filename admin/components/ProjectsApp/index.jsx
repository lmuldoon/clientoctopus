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

const CF_GLOBAL_CSS = `
@import url('https://fonts.googleapis.com/css2?family=Archivo:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400&display=swap');

:root {
  --co-navy:       #0F172A;
  --co-navy-mid:   #1E293B;
  --co-navy-dim:   #334155;
  --co-indigo:     #6366F1;
  --co-indigo-lt:  #818CF8;
  --co-indigo-bg:  #EEF2FF;
  --co-emerald:    #10B981;
  --co-emerald-bg: #ECFDF5;
  --co-amber:      #F59E0B;
  --co-amber-bg:   #FFFBEB;
  --co-red:        #EF4444;
  --co-red-bg:     #FEF2F2;
  --co-slate-50:   #F8FAFC;
  --co-slate-100:  #F1F5F9;
  --co-slate-200:  #E2E8F0;
  --co-slate-300:  #CBD5E1;
  --co-slate-400:  #94A3B8;
  --co-slate-500:  #64748B;
  --co-slate-600:  #475569;
  --co-slate-700:  #334155;
  --co-slate-800:  #1E293B;
  --co-white:      #FFFFFF;
  --co-radius:     12px;
  --co-radius-sm:  8px;
  --co-shadow:     0 1px 3px rgba(15,23,42,.06), 0 4px 16px rgba(15,23,42,.08);
  --co-shadow-lg:  0 4px 6px rgba(15,23,42,.05), 0 10px 40px rgba(15,23,42,.12);
  --co-font:         'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
  --co-font-display: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
  --co-input-border: 1.5px solid var(--co-slate-200);
  --co-input-focus: 0 0 0 3px rgba(99,102,241,.12);
}

#co-projects-root, #co-projects-root * {
  box-sizing: border-box;
  font-family: var(--co-font);
  -webkit-font-smoothing: antialiased;
}

#co-projects-root a { text-decoration: none; }
`;

function injectGlobalStyles() {
	if ( document.getElementById( 'co-projects-global-styles' ) ) return;
	const el = document.createElement( 'style' );
	el.id = 'co-projects-global-styles';
	el.textContent = CF_GLOBAL_CSS;
	document.head.appendChild( el );
}

export async function coFetch( path, options = {} ) {
	const { apiUrl, nonce } = window.coData || {};
	const url = ( apiUrl || '/wp-json/clientoctopus/v1/' ) + path;

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
	injectGlobalStyles();

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

	return (
		<div style={ { padding: '32px 28px 64px' } }>
			{ view === 'list' ? (
				<ProjectList onViewProject={ handleViewProject } />
			) : (
				<ProjectDetail
					projectId={ activeProjectId }
					onBack={ handleBack }
				/>
			) }
		</div>
	);
}
