/**
 * LeadsApp
 *
 * Admin UI for leads captured via the [clientoctopus_lead_form] shortcode.
 * Design matches InvoicesApp / ClientsApp: same shared tokens, same card/
 * table/badge/tab conventions, same field classes — intentionally not a new
 * visual style, since this extends an existing product rather than
 * introducing one.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { injectStyles } from '../../../shared/injectStyles';
import { CO_TOKENS_CSS } from '../../../shared/tokens';

// ── Fetch helper (identical pattern to InvoicesApp) ────────────────────────

async function coFetch( path, options = {} ) {
	const { apiUrl, nonce } = window.clientoctopusData || {};
	const [ base, qs ] = path.split( '?' );
	const url = ( apiUrl || '/wp-json/clientoctopus/v1/' ) + base.replace( /\/?$/, '/' ) + ( qs ? `?${ qs }` : '' );
	const res = await fetch( url, {
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce || '',
			...( options.headers || {} ),
		},
		...options,
	} );
	if ( ! res.ok ) {
		const err = await res.json().catch( () => ( {} ) );
		const e   = new Error( err.message || `HTTP ${ res.status }` );
		e.data    = err;
		throw e;
	}
	return res.json();
}

// ── Constants ───────────────────────────────────────────────────────────────

const STATUS_CONFIG = {
	new:       { bg: 'var(--co-indigo-bg)',  color: 'var(--co-indigo)',    label: 'New'       },
	contacted: { bg: '#FEF3C7',              color: '#92400E',             label: 'Contacted' },
	converted: { bg: 'var(--co-emerald-bg)', color: 'var(--co-emerald)',   label: 'Converted' },
	archived:  { bg: 'var(--co-slate-100)',  color: 'var(--co-slate-400)', label: 'Archived'  },
};

const TABS = [
	{ id: '',          label: 'All'       },
	{ id: 'new',       label: 'New'       },
	{ id: 'contacted', label: 'Contacted' },
	{ id: 'converted', label: 'Converted' },
	{ id: 'archived',  label: 'Archived'  },
];

// ── Styles ────────────────────────────────────────────────────────────────────

const CSS = `
${ CO_TOKENS_CSS }
.co-leads {
  font-family: 'Archivo', -apple-system, sans-serif;
  padding: 32px 28px 64px;
}
@keyframes co-leads-enter {
  from { opacity:0; transform:translateY(8px); }
  to   { opacity:1; transform:translateY(0); }
}
/* No fill-mode: a lingering "both"/"forwards" transform (even translateY(0))
   stays a set CSS property after the animation ends, which creates a new
   containing block for any position:fixed descendant (e.g. the lead detail
   modal overlay) — confining it to this element's box instead of the
   viewport. Letting the animation end naturally drops transform back to
   its default (none) once it completes. */
.co-leads { animation: co-leads-enter .2s ease; }

.co-leads-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; gap:16px; flex-wrap:wrap; }
.co-leads-title { font-family:'Archivo', -apple-system, sans-serif; font-size:28px; font-weight:800; color:var(--co-navy); margin:0; line-height: 1; }
.co-leads-sub { font-size:14px; color:var(--co-slate-400); margin:6px 0 0; }

.co-leads-header-actions { display:flex; align-items:center; gap:10px; }

/* Export button — matches AnalyticsApp's .co-an-export exactly */
.co-leads-export {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  font-size: 13px;
  font-weight: 600;
  background: #fff;
  border: 1px solid var(--co-slate-200);
  border-radius: 10px;
  cursor: pointer;
  color: var(--co-navy);
  text-decoration: none;
  transition: border-color .15s, box-shadow .15s;
}
.co-leads-export:hover { border-color: var(--co-indigo); box-shadow: 0 0 0 3px rgba(99,102,241,.1); }

/* Tabs + search bar — matches ProposalList's .co-list-controls pattern exactly */
.co-leads-controls {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.co-leads-tabs { display:flex; gap:6px; flex-wrap:wrap; }
.co-leads-tab {
  display:flex; align-items:center; gap:7px;
  padding:7px 16px;
  border-radius:20px;
  border:1.5px solid var(--co-slate-200);
  background:#fff;
  font-size:13px; font-weight:500;
  font-family:var(--co-font);
  color:var(--co-slate-500);
  cursor:pointer;
  transition:all .12s;
  white-space:nowrap;
}
.co-leads-tab:hover { border-color:var(--co-indigo); color:var(--co-indigo); }
.co-leads-tab.active {
  background:var(--co-indigo);
  border-color:var(--co-indigo);
  color:#fff;
  font-weight:600;
}
.co-leads-tab-count {
  font-size:11px;
  font-weight:700;
  background:var(--co-slate-100);
  color:var(--co-slate-500);
  border-radius:999px;
  padding:1px 7px;
  min-width:20px;
  text-align:center;
}
.co-leads-tab.active .co-leads-tab-count { background:rgba(255,255,255,.22); color:#fff; }

.co-leads-search-wrap { position:relative; flex-shrink:0; }
.co-leads-search-icon {
  position:absolute; right:12px; top:50%; transform:translateY(-50%);
  width:15px; height:15px; stroke:var(--co-slate-400); stroke-width:2;
  pointer-events:none; display:none;
}
input.co-leads-search {
  padding:9px 14px 9px 14px;
  border:var(--co-input-border);
  border-radius:var(--co-radius-sm);
  font-size:13.5px;
  font-family:var(--co-font);
  color:var(--co-slate-800);
  background:var(--co-white);
  outline:none;
  width:220px;
  transition:border-color .15s, box-shadow .15s;
}
input.co-leads-search::placeholder { color:var(--co-slate-300); }
input.co-leads-search:focus { border-color:var(--co-indigo); box-shadow:var(--co-input-focus); }

.co-leads-table-wrap { background:var(--co-white); border-radius:var(--co-radius); border:1px solid var(--co-slate-200); overflow-x:auto; box-shadow: 0 1px 3px rgba(26,26,46,.04), 0 6px 24px rgba(26,26,46,.06); }
.co-leads-table { width:100%; min-width:720px; border-collapse:collapse; }
.co-leads-table th {
  text-align:left;
  padding:11px 16px;
  font-size:11px;
  font-weight:600;
  letter-spacing:.06em;
  text-transform:uppercase;
  color:var(--co-slate-400);
  border-bottom:1px solid var(--co-slate-100);
  background:var(--co-slate-50);
}
.co-leads-table td { padding:14px 16px; border-bottom:1px solid var(--co-slate-100); font-size:14px; color:var(--co-slate-600); vertical-align:middle; }
.co-leads-table tr:last-child td { border-bottom:none; }
.co-leads-table tr:hover td { background:var(--co-slate-50); }

.co-leads-name { font-weight:600; color:var(--co-navy); }
.co-leads-flag { font-size:12px; color:#B45309; margin-top:2px; }

.co-leads-badge { display:inline-flex; align-items:center; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600; }

.co-leads-actions { display:flex; gap:6px; flex-wrap:wrap; }
.co-leads-btn { padding:6px 10px; font-size:12px; font-weight:600; border-radius:6px; border:1px solid var(--co-slate-200); background:#fff; color:var(--co-slate-600); cursor:pointer; }
.co-leads-btn:hover { background:var(--co-slate-50); }
.co-leads-btn.primary { background:var(--co-indigo); border-color:var(--co-indigo); color:#fff; }
.co-leads-btn.primary:hover { background:var(--co-indigo-dark, #4F46E5); }
.co-leads-btn.danger { color:#B91C1C; border-color:#FECACA; }
.co-leads-btn.danger:hover { background:#FEF2F2; }

.co-leads-empty { text-align:center; padding:64px 24px; }
.co-leads-empty-icon { color:var(--co-slate-300); margin:0 auto 16px; display:block; }
.co-leads-empty-title { font-family:'Archivo', -apple-system, sans-serif; font-size:20px; color:var(--co-navy); margin:0 0 8px; }
.co-leads-empty-sub { font-size:14px; color:var(--co-slate-500); max-width:420px; margin:0 auto; line-height:1.6; }
.co-leads-empty-shortcode { display:inline-block; margin-top:16px; padding:8px 14px; background:var(--co-slate-100); border-radius:8px; font-family:monospace; font-size:13px; color:var(--co-navy); }

/* Pagination — matches ProposalList's .co-list-pager exactly */
.co-leads-pager { display:flex; align-items:center; justify-content:center; gap:6px; margin-top:20px; }
.co-leads-page-btn {
  min-width:34px; height:34px;
  display:flex; align-items:center; justify-content:center;
  border-radius:8px;
  border:1.5px solid var(--co-slate-200);
  background:var(--co-white);
  font-size:13px; font-weight:600; font-family:var(--co-font);
  color:var(--co-slate-600);
  cursor:pointer;
  transition:border-color .12s, background .12s, color .12s;
}
.co-leads-page-btn:hover:not(:disabled) { border-color:var(--co-indigo); color:var(--co-indigo); background:var(--co-indigo-bg); }
.co-leads-page-btn.active { background:var(--co-indigo); color:#fff; border-color:var(--co-indigo); }
.co-leads-page-btn:disabled { opacity:.4; cursor:not-allowed; }
.co-leads-page-btn svg { width:14px; height:14px; stroke:currentColor; stroke-width:2; }

.co-leads-name-btn { background:none; border:none; padding:0; font: inherit; text-align:left; cursor:pointer; color:var(--co-navy); }
.co-leads-name-btn:hover .co-leads-name { color:var(--co-indigo); text-decoration:underline; }

.co-leads-overlay { position:fixed; inset:0; background:rgba(26,26,46,.45); display:flex; align-items:flex-start; justify-content:center; padding:64px 20px; z-index:100000; }
.co-leads-modal { background:#fff; border-radius:14px; width:100%; max-width:560px; max-height:80vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.25); }
.co-leads-modal-header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; border-bottom:1px solid var(--co-slate-200); position:sticky; top:0; background:#fff; }
.co-leads-modal-title { font-family:'Archivo', -apple-system, sans-serif; font-size:18px; font-weight:700; color:var(--co-navy); margin:0; }
.co-leads-modal-close { background:none; border:none; cursor:pointer; color:var(--co-slate-400); font-size:20px; line-height:1; padding:4px; }
.co-leads-modal-close:hover { color:var(--co-slate-600); }
.co-leads-modal-body { padding:20px 24px 24px; }
.co-leads-detail-row { padding:12px 0; border-bottom:1px solid var(--co-slate-100); }
.co-leads-detail-row:last-child { border-bottom:none; }
.co-leads-detail-label { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--co-slate-400); margin:0 0 4px; }
.co-leads-detail-value { font-size:14px; color:var(--co-navy); white-space:pre-wrap; word-break:break-word; margin:0; }
.co-leads-detail-value.empty { color:var(--co-slate-300); font-style:italic; }

.co-leads-bulkbar { display:flex; align-items:center; gap:12px; padding:10px 16px; background:var(--co-indigo-bg); border:1px solid var(--co-indigo); border-radius:10px; margin-bottom:14px; font-size:13px; color:var(--co-navy); }
.co-leads-bulkbar strong { font-weight:700; }
.co-leads-checkbox { width:16px; height:16px; cursor:pointer; }
`;

// ── Sub-components ──────────────────────────────────────────────────────────

function StatusBadge( { status } ) {
	const cfg = STATUS_CONFIG[ status ] || STATUS_CONFIG.new;
	return (
		<span className="co-leads-badge" style={ { background: cfg.bg, color: cfg.color } }>
			{ cfg.label }
		</span>
	);
}

const DETAIL_FIELDS = [
	{ key: 'name', label: 'Name' },
	{ key: 'email', label: 'Email' },
	{ key: 'phone', label: 'Phone' },
	{ key: 'company', label: 'Company' },
	{ key: 'budget_range', label: 'Budget Range' },
	{ key: 'preferred_contact', label: 'Preferred Contact Method' },
	{ key: 'message', label: 'Message' },
	{ key: 'source_url', label: 'Submitted From' },
];

function LeadDetailModal( { lead, onClose } ) {
	return (
		<div className="co-leads-overlay" onClick={ onClose }>
			<div className="co-leads-modal" onClick={ ( e ) => e.stopPropagation() }>
				<div className="co-leads-modal-header">
					<h2 className="co-leads-modal-title">{ lead.name }</h2>
					<button className="co-leads-modal-close" onClick={ onClose } aria-label="Close">×</button>
				</div>
				<div className="co-leads-modal-body">
					<div className="co-leads-detail-row">
						<p className="co-leads-detail-label">Status</p>
						<StatusBadge status={ lead.status } />
					</div>
					{ DETAIL_FIELDS.map( ( f ) => (
						<div className="co-leads-detail-row" key={ f.key }>
							<p className="co-leads-detail-label">{ f.label }</p>
							{ lead[ f.key ] ? (
								<p className="co-leads-detail-value">{ lead[ f.key ] }</p>
							) : (
								<p className="co-leads-detail-value empty">Not provided</p>
							) }
						</div>
					) ) }
					{ lead.existing_client_id && (
						<div className="co-leads-detail-row">
							<p className="co-leads-detail-label">Note</p>
							<p className="co-leads-detail-value">This email address already matches an existing client.</p>
						</div>
					) }
					<div className="co-leads-detail-row">
						<p className="co-leads-detail-label">Received</p>
						<p className="co-leads-detail-value">{ new Date( lead.created_at ).toLocaleString() }</p>
					</div>
				</div>
			</div>
		</div>
	);
}

// ── Main component ────────────────────────────────────────────────────────────

export default function LeadsApp() {
	injectStyles( 'co-leads-styles', CSS );

	const [ leads, setLeads ]     = useState( [] );
	const [ total, setTotal ]     = useState( 0 );
	const [ counts, setCounts ]   = useState( {} );
	const [ tab, setTab ]         = useState( '' );
	const [ page, setPage ]       = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ]     = useState( '' );
	const [ detailLead, setDetailLead ] = useState( null );
	const [ search, setSearch ]             = useState( '' );
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	const [ selected, setSelected ]         = useState( () => new Set() );
	const perPage = 20;

	useEffect( () => {
		const t = setTimeout( () => {
			setDebouncedSearch( search );
			setPage( 1 );
		}, 300 );
		return () => clearTimeout( t );
	}, [ search ] );

	const load = useCallback( () => {
		setLoading( true );
		setError( '' );
		const qs = new URLSearchParams( {
			page, per_page: perPage,
			...( tab ? { status: tab } : {} ),
			...( debouncedSearch ? { search: debouncedSearch } : {} ),
		} ).toString();
		coFetch( `leads?${ qs }` )
			.then( ( data ) => {
				setLeads( data.leads || [] );
				setTotal( data.total || 0 );
				setCounts( data.counts || {} );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setLoading( false ) );
	}, [ tab, page, debouncedSearch ] );

	useEffect( () => { load(); }, [ load ] );
	useEffect( () => { setSelected( new Set() ); }, [ tab, page, debouncedSearch ] );

	const updateStatus = ( id, status ) => {
		coFetch( `leads/${ id }`, { method: 'PATCH', body: JSON.stringify( { status } ) } )
			.then( load )
			.catch( ( e ) => setError( e.message ) );
	};

	const convert = ( id ) => {
		coFetch( `leads/${ id }/convert`, { method: 'POST' } )
			.then( load )
			.catch( ( e ) => setError( e.message ) );
	};

	const remove = ( id ) => {
		if ( ! window.confirm( 'Delete this lead? This cannot be undone.' ) ) {
			return;
		}
		coFetch( `leads/${ id }`, { method: 'DELETE' } )
			.then( load )
			.catch( ( e ) => setError( e.message ) );
	};

	const viewDetail = ( id ) => {
		coFetch( `leads/${ id }` )
			.then( ( data ) => setDetailLead( data.lead ) )
			.catch( ( e ) => setError( e.message ) );
	};

	const toggleSelect = ( id ) => {
		setSelected( ( prev ) => {
			const next = new Set( prev );
			next.has( id ) ? next.delete( id ) : next.add( id );
			return next;
		} );
	};

	const toggleSelectAll = () => {
		setSelected( ( prev ) =>
			prev.size === leads.length ? new Set() : new Set( leads.map( ( l ) => l.id ) )
		);
	};

	const bulkAction = ( action ) => {
		if ( action === 'delete' && ! window.confirm( `Delete ${ selected.size } lead(s)? This cannot be undone.` ) ) {
			return;
		}
		coFetch( 'leads/bulk', { method: 'POST', body: JSON.stringify( { ids: [ ...selected ], action } ) } )
			.then( () => { setSelected( new Set() ); load(); } )
			.catch( ( e ) => setError( e.message ) );
	};

	const exportUrl = () => {
		const { apiUrl, nonce } = window.clientoctopusData || {};
		const qs = new URLSearchParams( {
			export: 'csv',
			...( tab ? { status: tab } : {} ),
			...( debouncedSearch ? { search: debouncedSearch } : {} ),
			_wpnonce: nonce || '',
		} ).toString();
		return `${ apiUrl || '/wp-json/clientoctopus/v1/' }leads/?${ qs }`;
	};

	const totalPages = Math.max( 1, Math.ceil( total / perPage ) );

	return (
		<div className="co-leads">
			<div className="co-leads-header">
				<div>
					<h1 className="co-leads-title">Leads</h1>
					<p className="co-leads-sub">Inquiries submitted through your lead capture form.</p>
				</div>
				<div className="co-leads-header-actions">
					<a className="co-leads-export" href={ exportUrl() }>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
							<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
							<polyline points="7 10 12 15 17 10" />
							<line x1="12" y1="15" x2="12" y2="3" />
						</svg>
						Export CSV
					</a>
				</div>
			</div>

			{/* Tabs + search — matches ProposalList's .co-list-controls pattern */ }
			<div className="co-leads-controls">
				<div className="co-leads-tabs">
					{ TABS.map( ( t ) => (
						<button
							key={ t.id || 'all' }
							type="button"
							className={ `co-leads-tab${ tab === t.id ? ' active' : '' }` }
							onClick={ () => { setTab( t.id ); setPage( 1 ); } }
						>
							{ t.label }
							<span className="co-leads-tab-count">{ counts[ t.id || 'all' ] || 0 }</span>
						</button>
					) ) }
				</div>

				<div className="co-leads-search-wrap">
					<svg className="co-leads-search-icon" viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
					</svg>
					<input
						type="search"
						className="co-leads-search"
						placeholder="Search leads…"
						value={ search }
						onChange={ ( e ) => setSearch( e.target.value ) }
					/>
				</div>
			</div>

			{ error && (
				<p style={ { color: '#B91C1C', fontSize: 14, marginBottom: 16 } }>{ error }</p>
			) }

			{ selected.size > 0 && (
				<div className="co-leads-bulkbar">
					<strong>{ selected.size } selected</strong>
					<button className="co-leads-btn" onClick={ () => bulkAction( 'archive' ) }>Archive</button>
					<button className="co-leads-btn danger" onClick={ () => bulkAction( 'delete' ) }>Delete</button>
				</div>
			) }

			{ loading ? (
				<p style={ { color: 'var(--co-slate-500)' } }>Loading…</p>
			) : leads.length === 0 ? (
				<div className="co-leads-empty">
					<svg className="co-leads-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
						<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
					</svg>
					<p className="co-leads-empty-title">No leads yet</p>
					<p className="co-leads-empty-sub">
						Add the lead capture form to any page on your site and new inquiries will show up here.
					</p>
					<span className="co-leads-empty-shortcode">[clientoctopus_lead_form]</span>
				</div>
			) : (
				<div className="co-leads-table-wrap">
					<table className="co-leads-table">
						<thead>
							<tr>
								<th>
									<input
										type="checkbox"
										className="co-leads-checkbox"
										checked={ leads.length > 0 && selected.size === leads.length }
										onChange={ toggleSelectAll }
										aria-label="Select all on this page"
									/>
								</th>
								<th>Name</th>
								<th>Email</th>
								<th>Company</th>
								<th>Status</th>
								<th>Received</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							{ leads.map( ( lead ) => (
								<tr key={ lead.id }>
									<td>
										<input
											type="checkbox"
											className="co-leads-checkbox"
											checked={ selected.has( lead.id ) }
											onChange={ () => toggleSelect( lead.id ) }
											aria-label={ `Select ${ lead.name }` }
										/>
									</td>
									<td>
										<button className="co-leads-name-btn" onClick={ () => viewDetail( lead.id ) }>
											<div className="co-leads-name">{ lead.name }</div>
										</button>
										{ lead.existing_client_id && (
											<div className="co-leads-flag">Already a client</div>
										) }
									</td>
									<td>{ lead.email || '—' }</td>
									<td>{ lead.company || '—' }</td>
									<td><StatusBadge status={ lead.status } /></td>
									<td>{ new Date( lead.created_at ).toLocaleDateString() }</td>
									<td>
										<div className="co-leads-actions">
											<button className="co-leads-btn" onClick={ () => viewDetail( lead.id ) }>
												View
											</button>
											{ lead.status === 'new' && (
												<button className="co-leads-btn" onClick={ () => updateStatus( lead.id, 'contacted' ) }>
													Mark Contacted
												</button>
											) }
											{ lead.status !== 'converted' && (
												<button className="co-leads-btn primary" onClick={ () => convert( lead.id ) }>
													Convert to Client
												</button>
											) }
											{ lead.status !== 'archived' && (
												<button className="co-leads-btn" onClick={ () => updateStatus( lead.id, 'archived' ) }>
													Archive
												</button>
											) }
											<button className="co-leads-btn danger" onClick={ () => remove( lead.id ) }>
												Delete
											</button>
										</div>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{/* Pagination — matches ProposalList's .co-list-pager exactly */ }
			{ totalPages > 1 && (
				<div className="co-leads-pager">
					<button
						type="button"
						className="co-leads-page-btn"
						disabled={ page === 1 }
						onClick={ () => setPage( ( p ) => p - 1 ) }
						aria-label="Previous page"
					>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
						</svg>
					</button>

					{ Array.from( { length: totalPages }, ( _, i ) => i + 1 )
						.filter( ( p ) => p === 1 || p === totalPages || Math.abs( p - page ) <= 1 )
						.reduce( ( acc, p, idx, arr ) => {
							if ( idx > 0 && p - arr[ idx - 1 ] > 1 ) acc.push( '…' );
							acc.push( p );
							return acc;
						}, [] )
						.map( ( p, i ) =>
							p === '…' ? (
								<span key={ `ellipsis-${ i }` } style={ { padding: '0 4px', color: 'var(--co-slate-400)' } }>…</span>
							) : (
								<button
									key={ p }
									type="button"
									className={ `co-leads-page-btn${ page === p ? ' active' : '' }` }
									onClick={ () => setPage( p ) }
								>
									{ p }
								</button>
							)
						)
					}

					<button
						type="button"
						className="co-leads-page-btn"
						disabled={ page === totalPages }
						onClick={ () => setPage( ( p ) => p + 1 ) }
						aria-label="Next page"
					>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
						</svg>
					</button>
				</div>
			) }

			{ detailLead && (
				<LeadDetailModal lead={ detailLead } onClose={ () => setDetailLead( null ) } />
			) }
		</div>
	);
}
