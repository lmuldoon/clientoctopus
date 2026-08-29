/**
 * Client Octopus App Root
 *
 * Manages top-level view state: list ↔ wizard.
 * Injects global CSS variables and font import once on mount.
 */
import { useState, useEffect } from '@wordpress/element';
import ProposalList    from './components/ProposalList';
import ProposalWizard  from './components/ProposalWizard';
import ContentEditor   from './components/ContentEditor';
import { CO_TOKENS_CSS } from '../shared/tokens';

// ─── Global styles (injected once) ────────────────────────────────────────────
const CF_GLOBAL_CSS = `
${ CO_TOKENS_CSS }

#co-app, #co-app * {
  box-sizing: border-box;
  font-family: var(--co-font);
  -webkit-font-smoothing: antialiased;
}

#co-app a { text-decoration: none; }

@keyframes co-fade-up {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: translateY(0); }
}
@keyframes co-slide-in-right {
  from { opacity: 0; transform: translateX(32px); }
  to   { opacity: 1; transform: translateX(0); }
}
@keyframes co-slide-in-left {
  from { opacity: 0; transform: translateX(-32px); }
  to   { opacity: 1; transform: translateX(0); }
}
@keyframes co-spin {
  to { transform: rotate(360deg); }
}
`;

function injectGlobalStyles() {
	if ( document.getElementById( 'co-global-styles' ) ) return;
	const el = document.createElement( 'style' );
	el.id = 'co-global-styles';
	el.textContent = CF_GLOBAL_CSS;
	document.head.appendChild( el );
}

// ─── API helper ───────────────────────────────────────────────────────────────
export async function coFetch( path, options = {} ) {
	const { apiUrl, nonce } = window.clientoctopusData || {};
	// Ensure the resource path (not the query string) ends in a trailing slash —
	// some hosts 301-redirect a request missing it, which can drop the method/body.
	const [ base, qs ] = path.split( '?' );
	const url = ( apiUrl || '/wp-json/clientoctopus/v1/' ) + base.replace( /\/?$/, '/' ) + ( qs ? `?${ qs }` : '' );

	const res = await fetch( url, {
		cache: 'no-store',
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

// ─── Root Component ───────────────────────────────────────────────────────────
export default function App() {
	const [ view, setView ]                             = useState( 'list' );
	const [ query, setQuery ]                           = useState( { status: '', search: '', page: 1 } );
	const [ list, setList ]                             = useState( { proposals: [], total: 0, pages: 1, counts: {} } );
	const [ loading, setLoading ]                       = useState( true );
	const [ error, setError ]                           = useState( null );
	const [ editingProposal, setEditingProposal ]       = useState( null );
	const [ editingContentProposal, setEditingContentProposal ] = useState( null );

	useEffect( () => {
		injectGlobalStyles();
	}, [] );

	useEffect( () => {
		let cancelled = false;
		( async () => {
			setLoading( true );
			setError( null );
			try {
				const params = new URLSearchParams();
				if ( query.status ) params.set( 'status', query.status );
				if ( query.search ) params.set( 'search', query.search );
				params.set( 'page', String( query.page ) );
				params.set( 'per_page', '20' );
				const data = await coFetch( `proposals?${ params.toString() }` );
				if ( ! cancelled ) {
					setList( {
						proposals: data.proposals || [],
						total:     data.total     || 0,
						pages:     data.pages     || 1,
						counts:    data.counts    || {},
					} );
				}
			} catch ( e ) {
				if ( ! cancelled ) setError( e.message );
			} finally {
				if ( ! cancelled ) setLoading( false );
			}
		} )();
		return () => { cancelled = true; };
	}, [ query ] );

	// Re-runs the current query — used after any action (delete/send/save) that
	// changes data server-side, since the list is no longer held fully in memory.
	function refetch() {
		setQuery( q => ( { ...q } ) );
	}

	async function handleEditProposal( id ) {
		try {
			const data = await coFetch( `proposals/${ id }` );
			setEditingProposal( data.proposal );
			setView( 'wizard' );
		} catch ( e ) {
			alert( e.message || 'Could not load proposal.' );
		}
	}

	function handleWizardComplete( savedProposal ) {
		refetch();
		setEditingProposal( null );
		setView( 'list' );
	}

	function handleWizardCancel() {
		setEditingProposal( null );
		setView( 'list' );
	}

	async function handleEditContent( id ) {
		try {
			const data = await coFetch( `proposals/${ id }` );
			setEditingContentProposal( data.proposal );
			setView( 'edit-content' );
		} catch ( e ) {
			alert( e.message || 'Could not load proposal.' );
		}
	}

	function handleContentSave( updatedProposal ) {
		refetch();
		setEditingContentProposal( null );
		setView( 'list' );
	}

	function handleContentCancel() {
		setEditingContentProposal( null );
		setView( 'list' );
	}

	function handleProposalDeleted( id ) {
		refetch();
	}

	function handleProposalSent( id ) {
		refetch();
	}

	return (
		<div id="co-app" style={ { padding: '32px 28px 64px' } }>
			{ view === 'list' && (
				<ProposalList
					proposals={ list.proposals }
					total={ list.total }
					pages={ list.pages }
					counts={ list.counts }
					query={ query }
					onQueryChange={ setQuery }
					loading={ loading }
					error={ error }
					onNewProposal={ () => setView( 'wizard' ) }
					onEditProposal={ handleEditProposal }
					onEditContent={ handleEditContent }
					onRefresh={ refetch }
					onProposalDeleted={ handleProposalDeleted }
					onProposalSent={ handleProposalSent }
				/>
			) }
			{ view === 'wizard' && (
				<ProposalWizard
					initialProposal={ editingProposal }
					onComplete={ handleWizardComplete }
					onCancel={ handleWizardCancel }
				/>
			) }
			{ view === 'edit-content' && editingContentProposal && (
				<ContentEditor
					proposal={ editingContentProposal }
					onSave={ handleContentSave }
					onCancel={ handleContentCancel }
				/>
			) }
		</div>
	);
}
