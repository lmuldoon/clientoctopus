/**
 * RecurringApp
 *
 * Admin UI for recurring invoice profiles — templates that spawn a fresh
 * one-off invoice on a schedule (client still pays each one manually via
 * the existing Stripe/PayPal flow; no auto-charge in this version).
 *
 * Two views:
 *   1. Recurring list — status, next invoice date, quick actions
 *   2. Recurring editor — create / edit form
 *
 * Design matches InvoicesApp: same CSS vars, same card layout, same status
 * badge pattern, same field classes (kept as its own copy rather than a
 * shared import, matching how every other admin screen in this plugin does
 * its own inline styles/empty-state).
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { injectStyles } from '../../../shared/injectStyles';
import { fmt, SYMBOLS } from '../../../shared/currency';

// ── Fetch helper ──────────────────────────────────────────────────────────────

async function coFetch( path, options = {} ) {
	const { apiUrl, nonce } = window.clientoctopusData || {};
	// Ensure the resource path (not the query string) ends in a trailing slash —
	// some hosts 301-redirect a request missing it, which can drop the method/body.
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

// ── Constants ─────────────────────────────────────────────────────────────────

const CURRENCIES = [
	{ value: 'GBP', label: '£ GBP — British Pound' },
	{ value: 'USD', label: '$ USD — US Dollar' },
	{ value: 'EUR', label: '€ EUR — Euro' },
	{ value: 'CAD', label: '$ CAD — Canadian Dollar' },
	{ value: 'AUD', label: '$ AUD — Australian Dollar' },
];

const FREQUENCIES = [
	{ value: 'weekly',    label: 'Weekly' },
	{ value: 'monthly',   label: 'Monthly' },
	{ value: 'quarterly', label: 'Quarterly' },
	{ value: 'yearly',    label: 'Yearly' },
];

const STATUS_CONFIG = {
	active:    { bg: 'var(--co-emerald-bg)', color: 'var(--co-emerald)',  label: 'Active'    },
	paused:    { bg: '#FEF3C7',              color: '#92400E',            label: 'Paused'    },
	cancelled: { bg: 'var(--co-slate-100)',  color: 'var(--co-slate-400)', label: 'Cancelled' },
};

const TABS = [
	{ id: 'all',       label: 'All'       },
	{ id: 'active',    label: 'Active'    },
	{ id: 'paused',    label: 'Paused'    },
	{ id: 'cancelled', label: 'Cancelled' },
];

// ── Styles (reuses the .co-inv-* class names/vars already loaded by the
// Invoices screen where possible via the same injectStyles key namespace,
// but injected under its own key so this screen works standalone too) ──────────

const CSS = `
.co-rec {
  font-family: 'Archivo', -apple-system, sans-serif;
  --co-indigo: #6366F1;
  --co-navy:   #0F172A;
  --co-emerald:  #10B981;
  --co-amber:  #F59E0B;
  --co-red:    #EF4444;
  --co-slate-50:     #F8FAFC;
  --co-slate-200:     #E2E8F0;
  --co-slate-300:     #CBD5E1;
  --co-slate-400:     #94A3B8;
  --co-slate-500:     #64748B;
  --co-slate-600:     #475569;
}
@keyframes co-rec-enter {
  from { opacity:0; transform:translateY(8px); }
  to   { opacity:1; transform:translateY(0); }
}
.co-rec { animation: co-rec-enter .2s ease both; }

.co-rec-header { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:16px; margin-bottom:24px; }
.co-rec-title { font-size:28px; font-weight:800; color:var(--co-navy); letter-spacing:-.5px; margin:0; line-height:1; }
.co-rec-subtitle { font-size:14px; color:var(--co-slate-400); margin:6px 0 0; line-height:1.5; }
.co-rec-btn-primary { display:inline-flex; align-items:center; gap:7px; background:var(--co-indigo); color:#fff; border:none; padding:10px 18px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:opacity .15s; }
.co-rec-btn-primary:hover { opacity:.88; }
.co-rec-btn-ghost { background:none; border:1.5px solid var(--co-slate-200); color:var(--co-slate-600); padding:10px 18px; border-radius:8px; font-size:13px; font-weight:500; cursor:pointer; transition:border-color .15s; }
.co-rec-btn-ghost:hover { border-color:var(--co-indigo); color:var(--co-indigo); }

.co-rec-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:20px; }
.co-rec-tab { display:flex; align-items:center; gap:7px; padding:7px 16px; border-radius:20px; font-size:13px; font-weight:500; color:var(--co-slate-400); border:1.5px solid var(--co-slate-200); background:#fff; cursor:pointer; transition:all .12s; }
.co-rec-tab:hover { border-color:var(--co-indigo); color:var(--co-indigo); }
.co-rec-tab.active { background:var(--co-indigo); border-color:var(--co-indigo); color:#fff; font-weight:600; }
.co-rec-tab-count { font-size:11px; font-weight:700; background:var(--co-slate-50); color:var(--co-slate-400); border-radius:999px; padding:1px 7px; min-width:20px; text-align:center; }
.co-rec-tab.active .co-rec-tab-count { background:rgba(255,255,255,.22); color:#fff; }

.co-rec-pager { display:flex; align-items:center; justify-content:center; gap:6px; margin-top:20px; }
.co-rec-page-btn {
  min-width:34px; height:34px; display:flex; align-items:center; justify-content:center;
  border-radius:8px; border:1.5px solid var(--co-slate-200); background:#fff;
  font-size:13px; font-weight:600; color:var(--co-slate-600); cursor:pointer;
  transition:border-color .12s, background .12s, color .12s;
}
.co-rec-page-btn:hover:not(:disabled) { border-color:var(--co-indigo); color:var(--co-indigo); background:#EEF2FF; }
.co-rec-page-btn.active { background:var(--co-indigo); color:#fff; border-color:var(--co-indigo); }
.co-rec-page-btn:disabled { opacity:.4; cursor:not-allowed; }
.co-rec-page-btn svg { width:14px; height:14px; stroke:currentColor; stroke-width:2; }

.co-rec-table-wrap { background:#fff; border-radius:12px; border:1px solid var(--co-slate-200); overflow-x:auto; overflow-y:hidden; box-shadow: 0 1px 3px rgba(26,26,46,.04), 0 6px 24px rgba(26,26,46,.06); }
.co-rec-table { width:100%; min-width:680px; border-collapse:collapse; }
.co-rec-table th { text-align:left; padding:11px 16px; font-size:11px; font-weight:700; color:var(--co-slate-400); text-transform:uppercase; letter-spacing:.5px; border-bottom:1px solid var(--co-slate-200); background:var(--co-slate-50); }
.co-rec-table td { padding:14px 16px; border-bottom:1px solid var(--co-slate-200); font-size:14px; color:var(--co-slate-600); }
.co-rec-table tr:last-child td { border-bottom:none; }
.co-rec-table tr:hover td { background:var(--co-slate-50); }
.co-rec-client { font-weight:500; color:var(--co-navy); }
.co-rec-amount { font-weight:700; color:var(--co-navy); }
.co-rec-next { color:var(--co-slate-500); font-size:13px; }

.co-rec-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }

.co-rec-actions { display:flex; gap:6px; }
.co-rec-act { padding:5px 10px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; border:none; transition:opacity .15s; }
.co-rec-act:hover { opacity:.78; }
.co-rec-act-edit { background:var(--co-slate-50); color:var(--co-slate-500); }
.co-rec-act-pause { background:#FEF3C7; color:#92400E; }
.co-rec-act-resume { background:#D1FAE5; color:#065F46; }
.co-rec-act-cancel { background:#FEF2F2; color:var(--co-red); }

.co-rec-empty { background:#fff; border:1.5px solid var(--co-slate-200); border-radius:12px; padding:56px 32px; text-align:center; }
.co-rec-empty-icon { color:var(--co-slate-300); margin:0 auto 16px; display:block; }
.co-rec-empty-title { font-family:'Archivo', -apple-system, BlinkMacSystemFont, sans-serif; font-size:20px; color:var(--co-navy); margin:0 0 8px; }
.co-rec-empty-sub { font-size:14px; color:var(--co-slate-500); max-width:380px; margin:0 auto; line-height:1.6; }

.co-rec-loading { text-align:center; padding:48px; color:var(--co-slate-400); }
.co-rec-error { background:#FEF2F2; border:1px solid #FECACA; color:#991B1B; border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:14px; }

.co-rec-editor { max-width:760px; }
.co-rec-back { display:inline-flex; align-items:center; gap:6px; color:var(--co-slate-400); font-size:13px; font-weight:500; cursor:pointer; border:none; background:none; padding:0; margin-bottom:20px; transition:color .15s; }
.co-rec-back:hover { color:var(--co-indigo); }
.co-rec-editor-title { font-size:24px; font-weight:800; color:var(--co-navy); margin:0 0 24px; }
.co-rec-card { background:#fff; border:1px solid var(--co-slate-200); border-radius:12px; padding:24px; margin-bottom:16px; }
.co-rec-card-title { font-size:13px; font-weight:700; color:var(--co-slate-500); text-transform:uppercase; letter-spacing:.5px; margin:0 0 16px; padding-bottom:12px; border-bottom:1px solid var(--co-slate-200); }
.co-rec-field { margin-bottom:16px; }
.co-rec-field label { display:block; font-size:13px; font-weight:600; color:var(--co-slate-600); margin-bottom:6px; }
.co-rec-field input:not([type="radio"]):not([type="checkbox"]), .co-rec-field select, .co-rec-field textarea { width:100%; padding:9px 12px; border:1.5px solid var(--co-slate-200); border-radius:8px; font-size:14px; line-height:20px; color:var(--co-navy); background:#fff; box-sizing:border-box; transition:border-color .15s; }
.co-rec-field input:not([type="radio"]):not([type="checkbox"]), .co-rec-field select { height:41px; }
.co-rec-field textarea { min-height:80px; resize:vertical; }
.co-rec-end-toggle input[type="radio"] { width:16px; height:16px; min-width:16px; min-height:16px; margin:0; flex:none; accent-color:var(--co-indigo); cursor:pointer; }
.co-rec-field select { appearance:none; -webkit-appearance:none; -moz-appearance:none; display:flex; align-items:center; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2364748B' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:36px; }
.co-rec-field input:not([type="radio"]):not([type="checkbox"]):focus, .co-rec-field select:focus, .co-rec-field textarea:focus { outline:none; border-color:var(--co-indigo); box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.co-rec-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.co-rec-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; }
.co-rec-help { font-size:12px; color:var(--co-slate-400); margin:6px 0 0; line-height:1.5; }

.co-rec-items { display:flex; flex-direction:column; gap:10px; margin-bottom:12px; }
.co-rec-item { display:grid; grid-template-columns:1fr 130px 36px; gap:8px; align-items:center; }
.co-rec-item input {
  margin:0; width:100%; height:41px; padding:9px 12px; border:1.5px solid var(--co-slate-200); border-radius:8px;
  font-size:14px; color:var(--co-navy); background:#fff; box-sizing:border-box; transition:border-color .15s;
}
.co-rec-item input:focus { outline:none; border-color:var(--co-indigo); box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.co-rec-item-del { background:none; border:none; color:var(--co-slate-300); cursor:pointer; font-size:18px; line-height:1; padding:4px; border-radius:6px; transition:color .15s; }
.co-rec-item-del:hover { color:var(--co-red); }
.co-rec-add-item { background:none; border:1.5px dashed var(--co-slate-300); color:var(--co-slate-500); border-radius:8px; padding:8px 12px; font-size:13px; font-weight:600; cursor:pointer; width:100%; text-align:center; transition:border-color .15s; }
.co-rec-add-item:hover { border-color:var(--co-indigo); color:var(--co-indigo); }

.co-rec-totals { background:var(--co-slate-50); border-radius:8px; padding:14px 16px; font-size:13px; display:flex; flex-direction:column; gap:6px; align-items:flex-end; }
.co-rec-totals-row { display:flex; gap:32px; width:240px; justify-content:space-between; }
.co-rec-totals-label { color:var(--co-slate-500); }
.co-rec-totals-value { font-weight:600; color:var(--co-navy); }
.co-rec-totals-total .co-rec-totals-label { font-weight:700; color:var(--co-navy); font-size:15px; }
.co-rec-totals-total .co-rec-totals-value { font-size:17px; }

.co-rec-client-picker { position:relative; }
.co-rec-client-search-row { display:flex; gap:8px; align-items:center; }
.co-rec-client-search-row input {
  flex:1; height:41px; padding:9px 12px; border:1.5px solid var(--co-slate-200); border-radius:8px;
  font-size:14px; color:var(--co-navy); background:#fff; box-sizing:border-box; transition:border-color .15s;
}
.co-rec-client-search-row input:focus { outline:none; border-color:var(--co-indigo); box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.co-rec-client-results { position:absolute; top:100%; left:0; right:0; z-index:20; background:#fff; border:1.5px solid var(--co-slate-200); border-top:none; border-radius:0 0 8px 8px; max-height:200px; overflow-y:auto; box-shadow:0 4px 16px rgba(15,23,42,.1); }
.co-rec-client-opt { padding:10px 14px; font-size:14px; cursor:pointer; border-bottom:1px solid var(--co-slate-200); }
.co-rec-client-opt:last-child { border-bottom:none; }
.co-rec-client-opt:hover { background:var(--co-slate-50); }
.co-rec-client-selected { display:flex; align-items:center; justify-content:space-between; padding:9px 12px; border:1.5px solid var(--co-slate-200); border-radius:8px; background:#fff; }
.co-rec-client-clear { background:none; border:none; color:var(--co-slate-300); cursor:pointer; font-size:16px; padding:0; }
.co-rec-new-client { border:1.5px solid var(--co-indigo); border-radius:8px; padding:14px; margin-top:8px; background:#EEF2FF15; }
.co-rec-new-client-title { font-size:12px; font-weight:700; color:var(--co-indigo); text-transform:uppercase; letter-spacing:.5px; margin:0 0 12px; }

.co-rec-editor-footer { display:flex; gap:10px; justify-content:flex-end; padding-top:8px; }

.co-rec-end-toggle { display:flex; gap:16px; margin-bottom:12px; }
.co-rec-end-toggle label { display:flex; align-items:center; gap:6px; font-size:13px; font-weight:500; color:var(--co-slate-600); cursor:pointer; }
`;

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatDate( iso ) {
	if ( ! iso ) return '—';
	return new Date( iso ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' } );
}

function computeTotals( lineItems, discountType, discountValue, vatPct ) {
	const subtotal = lineItems.reduce( ( s, i ) => s + parseFloat( i.amount || 0 ), 0 );
	const discAmt  = discountType === 'percentage'
		? subtotal * ( Math.min( 100, parseFloat( discountValue || 0 ) ) / 100 )
		: Math.min( parseFloat( discountValue || 0 ), subtotal );
	const afterDisc = Math.max( 0, subtotal - discAmt );
	const vatAmt    = afterDisc * ( parseFloat( vatPct || 0 ) / 100 );
	const total     = afterDisc + vatAmt;
	return { subtotal, discAmt, vatAmt, total };
}

function today() {
	return new Date().toISOString().slice( 0, 10 );
}

// ── Status badge ──────────────────────────────────────────────────────────────

function StatusBadge( { profile } ) {
	const { status } = profile;
	const completedNaturally = status === 'cancelled'
		&& profile.occurrences_sent > 0
		&& !! profile.max_occurrences
		&& profile.occurrences_sent >= profile.max_occurrences;
	const cfg = completedNaturally
		? { bg: 'var(--co-emerald-bg)', color: 'var(--co-emerald)', label: 'Completed' }
		: ( STATUS_CONFIG[ status ] || { bg: '#F1F5F9', color: '#64748B', label: status } );
	return <span className="co-rec-badge" style={ { background: cfg.bg, color: cfg.color } }>{ cfg.label }</span>;
}

// ── Client picker (own copy, mirrors InvoicesApp's ClientPicker) ──────────────

function ClientPicker( { value, label, onChange } ) {
	const [ query, setQuery ]     = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ open, setOpen ]       = useState( false );
	const [ creating, setCreating ] = useState( false );
	const [ newClient, setNewClient ] = useState( { name: '', email: '', company: '' } );

	const search = useCallback( async ( q ) => {
		if ( q.length < 1 ) { setResults( [] ); return; }
		try {
			const data = await coFetch( `clients/?search=${ encodeURIComponent( q ) }&per_page=10` );
			setResults( data.clients || [] );
		} catch {
			setResults( [] );
		}
	}, [] );

	useEffect( () => {
		const t = setTimeout( () => search( query ), 250 );
		return () => clearTimeout( t );
	}, [ query, search ] );

	async function createInline() {
		try {
			const data = await coFetch( 'clients/', {
				method: 'POST',
				body: JSON.stringify( newClient ),
			} );
			const client = data.client || data;
			onChange( client.id, client.name );
			setCreating( false );
			setOpen( false );
			setQuery( '' );
			setNewClient( { name: '', email: '', company: '' } );
		} catch( e ) {
			alert( e.message );
		}
	}

	if ( value ) {
		return (
			<div className="co-rec-client-selected">
				<span style={ { fontSize: 14, color: '#0F172A', fontWeight: 600 } }>{ label }</span>
				<button className="co-rec-client-clear" onClick={ () => onChange( null, '' ) } type="button">×</button>
			</div>
		);
	}

	return (
		<div className="co-rec-client-picker">
			<div className="co-rec-client-search-row">
				<input
					type="text"
					placeholder="Search existing client…"
					value={ query }
					onChange={ e => { setQuery( e.target.value ); setOpen( true ); } }
					onFocus={ () => setOpen( true ) }
					onBlur={ () => setTimeout( () => setOpen( false ), 200 ) }
				/>
				<button className="co-rec-btn-ghost" onClick={ () => setCreating( true ) } type="button">+ Add New Client</button>
			</div>
			{ open && results.length > 0 && (
				<div className="co-rec-client-results">
					{ results.map( c => (
						<div
							key={ c.id }
							className="co-rec-client-opt"
							onMouseDown={ () => { onChange( c.id, c.name ); setOpen( false ); setQuery( '' ); } }
						>
							{ c.name }{ c.company ? ` — ${ c.company }` : '' }
						</div>
					) ) }
				</div>
			) }
			{ creating && (
				<div className="co-rec-new-client">
					<p className="co-rec-new-client-title">New Client</p>
					<div className="co-rec-row-3">
						<div className="co-rec-field">
							<label>Name *</label>
							<input value={ newClient.name } onChange={ e => setNewClient( p => ( { ...p, name: e.target.value } ) ) } />
						</div>
						<div className="co-rec-field">
							<label>Email *</label>
							<input type="email" value={ newClient.email } onChange={ e => setNewClient( p => ( { ...p, email: e.target.value } ) ) } />
						</div>
						<div className="co-rec-field">
							<label>Company</label>
							<input value={ newClient.company } onChange={ e => setNewClient( p => ( { ...p, company: e.target.value } ) ) } />
						</div>
					</div>
					<div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end' } }>
						<button className="co-rec-btn-ghost" onClick={ () => setCreating( false ) } type="button">Cancel</button>
						<button className="co-rec-btn-primary" onClick={ createInline } type="button" disabled={ ! newClient.name || ! newClient.email }>Save Client</button>
					</div>
				</div>
			) }
		</div>
	);
}

// ── Line items editor (own copy, mirrors InvoicesApp's LineItems) ─────────────

function LineItems( { items, currency, onChange } ) {
	function update( i, field, val ) {
		onChange( items.map( ( it, idx ) => idx === i ? { ...it, [ field ]: val } : it ) );
	}
	function addRow() {
		onChange( [ ...items, { description: '', amount: '' } ] );
	}
	function removeRow( i ) {
		onChange( items.filter( ( _, idx ) => idx !== i ) );
	}

	const sym = SYMBOLS[ currency ] || '';

	return (
		<>
			<div className="co-rec-items">
				{ items.map( ( item, i ) => (
					<div key={ i } className="co-rec-item">
						<input type="text" placeholder="Description" value={ item.description } onChange={ e => update( i, 'description', e.target.value ) } />
						<input type="number" placeholder={ `${ sym }0.00` } value={ item.amount } min="0" step="0.01" onChange={ e => update( i, 'amount', e.target.value ) } onWheel={ e => e.currentTarget.blur() } />
						<button className="co-rec-item-del" onClick={ () => removeRow( i ) } type="button">×</button>
					</div>
				) ) }
			</div>
			<button className="co-rec-add-item" onClick={ addRow } type="button">+ Add line item</button>
		</>
	);
}

function TotalsBlock( { lineItems, discountType, discountValue, vatPct, currency } ) {
	const { subtotal, discAmt, vatAmt, total } = computeTotals( lineItems, discountType, discountValue, vatPct );
	const showDiscount = parseFloat( discountValue || 0 ) > 0;
	const showVat      = parseFloat( vatPct || 0 ) > 0;

	return (
		<div className="co-rec-totals">
			<div className="co-rec-totals-row">
				<span className="co-rec-totals-label">Subtotal</span>
				<span className="co-rec-totals-value">{ fmt( subtotal, currency ) }</span>
			</div>
			{ showDiscount && (
				<div className="co-rec-totals-row">
					<span className="co-rec-totals-label">Discount</span>
					<span className="co-rec-totals-value" style={ { color: '#10B981' } }>−{ fmt( discAmt, currency ) }</span>
				</div>
			) }
			{ showVat && (
				<div className="co-rec-totals-row">
					<span className="co-rec-totals-label">VAT ({ parseFloat( vatPct ).toFixed( 0 ) }%)</span>
					<span className="co-rec-totals-value">{ fmt( vatAmt, currency ) }</span>
				</div>
			) }
			<div className="co-rec-totals-row co-rec-totals-total" style={ { borderTop: '1.5px solid #E2E8F0', paddingTop: 8, marginTop: 4 } }>
				<span className="co-rec-totals-label">Per-invoice total</span>
				<span className="co-rec-totals-value">{ fmt( total, currency ) }</span>
			</div>
		</div>
	);
}

// ── Recurring editor ──────────────────────────────────────────────────────────

const BLANK_FORM = {
	client_id: null, client_label: '',
	title: '', po_number: '', currency: 'GBP',
	payment_terms: '', notes: '',
	line_items: [ { description: '', amount: '' } ],
	discount_type: 'percentage', discount_value: 0,
	vat_pct: 0, vat_number: '',
	frequency: 'monthly',
	start_date: today(),
	end_mode: 'never', // 'never' | 'count' | 'date'
	end_date: '', max_occurrences: '',
};

function RecurringEditor( { profile, onSaved, onBack } ) {
	const isEdit = !! profile?.id;

	const [ form, setForm ] = useState( () => {
		if ( ! profile ) return { ...BLANK_FORM };
		return {
			client_id:       profile.client_id || null,
			client_label:    profile._client_name || '',
			title:           profile.title || '',
			po_number:       profile.po_number || '',
			payment_terms:   profile.payment_terms || '',
			notes:           profile.notes || '',
			currency:        profile.currency || 'GBP',
			line_items:      profile.line_items?.length ? profile.line_items : [ { description: '', amount: '' } ],
			discount_type:   profile.discount_type || 'percentage',
			discount_value:  profile.discount_value || 0,
			vat_pct:         profile.vat_pct || 0,
			vat_number:      profile.vat_number || '',
			frequency:       profile.frequency || 'monthly',
			start_date:      profile.start_date || today(),
			end_mode:        profile.max_occurrences ? 'count' : ( profile.end_date ? 'date' : 'never' ),
			end_date:        profile.end_date || '',
			max_occurrences: profile.max_occurrences || '',
		};
	} );

	const [ saving, setSaving ] = useState( false );
	const [ error, setError ]   = useState( null );

	function set( field, val ) {
		setForm( p => ( { ...p, [ field ]: val } ) );
	}

	async function save() {
		setSaving( true );
		setError( null );
		try {
			const payload = { ...form };
			delete payload.client_label;
			delete payload.end_mode;
			if ( form.end_mode !== 'count' ) payload.max_occurrences = null;
			if ( form.end_mode !== 'date' )  payload.end_date = '';

			if ( isEdit ) {
				const data = await coFetch( `recurring-profiles/${ profile.id }/update/`, { method: 'POST', body: JSON.stringify( payload ) } );
				onSaved( data.profile );
			} else {
				const data = await coFetch( 'recurring-profiles/create/', { method: 'POST', body: JSON.stringify( payload ) } );
				onSaved( data.profile );
			}
		} catch( e ) {
			setError( e.message );
			setSaving( false );
		}
	}

	return (
		<div className="co-rec-editor">
			<button className="co-rec-back" onClick={ onBack } type="button">← Back to recurring invoices</button>
			<h1 className="co-rec-editor-title">{ isEdit ? `Edit ${ form.title || 'recurring invoice' }` : 'New Recurring Invoice' }</h1>

			{ error && <div className="co-rec-error">{ error }</div> }

			<div className="co-rec-card">
				<p className="co-rec-card-title">Client</p>
				<ClientPicker
					value={ form.client_id }
					label={ form.client_label }
					onChange={ ( id, label ) => setForm( p => ( { ...p, client_id: id, client_label: label } ) ) }
				/>
			</div>

			<div className="co-rec-card">
				<p className="co-rec-card-title">Recurring Invoice Details</p>
				<div className="co-rec-field">
					<label>Title / Description</label>
					<input type="text" placeholder="e.g. Monthly retainer" value={ form.title } onChange={ e => set( 'title', e.target.value ) } />
				</div>
				<div className="co-rec-row-3">
					<div className="co-rec-field">
						<label>Currency</label>
						<select value={ form.currency } onChange={ e => set( 'currency', e.target.value ) }>
							{ CURRENCIES.map( c => <option key={ c.value } value={ c.value }>{ c.label }</option> ) }
						</select>
					</div>
					<div className="co-rec-field">
						<label>Frequency</label>
						<select value={ form.frequency } onChange={ e => set( 'frequency', e.target.value ) }>
							{ FREQUENCIES.map( f => <option key={ f.value } value={ f.value }>{ f.label }</option> ) }
						</select>
					</div>
					<div className="co-rec-field">
						<label>Start Date</label>
						<input type="date" value={ form.start_date } onChange={ e => set( 'start_date', e.target.value ) } />
					</div>
				</div>
				<div className="co-rec-field">
					<label>Ends</label>
					<div className="co-rec-end-toggle">
						<label><input type="radio" checked={ form.end_mode === 'never' } onChange={ () => set( 'end_mode', 'never' ) } /> Never</label>
						<label><input type="radio" checked={ form.end_mode === 'count' } onChange={ () => set( 'end_mode', 'count' ) } /> After N invoices</label>
						<label><input type="radio" checked={ form.end_mode === 'date' } onChange={ () => set( 'end_mode', 'date' ) } /> On a date</label>
					</div>
					{ form.end_mode === 'count' && (
						<input type="number" min="1" placeholder="e.g. 12" value={ form.max_occurrences } onChange={ e => set( 'max_occurrences', e.target.value ) } onWheel={ e => e.currentTarget.blur() } />
					) }
					{ form.end_mode === 'date' && (
						<input type="date" value={ form.end_date } onChange={ e => set( 'end_date', e.target.value ) } />
					) }
					<p className="co-rec-help">The client will receive a new invoice by email each cycle, with the usual Pay Now link — nothing is charged automatically.</p>
				</div>
				<div className="co-rec-row-2">
					<div className="co-rec-field">
						<label>Payment Terms</label>
						<input type="text" placeholder="e.g. Net 14, Due on receipt" value={ form.payment_terms } onChange={ e => set( 'payment_terms', e.target.value ) } />
					</div>
					<div className="co-rec-field">
						<label>PO Number</label>
						<input type="text" placeholder="Client purchase order ref" value={ form.po_number } onChange={ e => set( 'po_number', e.target.value ) } />
					</div>
				</div>
			</div>

			<div className="co-rec-card">
				<p className="co-rec-card-title">Line Items (per invoice)</p>
				<LineItems items={ form.line_items } currency={ form.currency } onChange={ items => set( 'line_items', items ) } />
				<div className="co-rec-row-3" style={ { marginTop: 16 } }>
					<div className="co-rec-field">
						<label>Discount type</label>
						<select value={ form.discount_type } onChange={ e => set( 'discount_type', e.target.value ) }>
							<option value="percentage">Percentage (%)</option>
							<option value="fixed">Fixed amount</option>
						</select>
					</div>
					<div className="co-rec-field">
						<label>Discount value</label>
						<input type="number" min="0" step="0.01" value={ form.discount_value } onChange={ e => set( 'discount_value', e.target.value ) } onWheel={ e => e.currentTarget.blur() } />
					</div>
					<div className="co-rec-field">
						<label>VAT %</label>
						<input type="number" min="0" max="100" step="0.01" value={ form.vat_pct } onChange={ e => set( 'vat_pct', e.target.value ) } onWheel={ e => e.currentTarget.blur() } />
					</div>
				</div>
				{ parseFloat( form.vat_pct || 0 ) > 0 && (
					<div className="co-rec-field">
						<label>VAT Registration Number</label>
						<input type="text" placeholder="e.g. GB123456789" value={ form.vat_number } onChange={ e => set( 'vat_number', e.target.value ) } />
					</div>
				) }
				<TotalsBlock lineItems={ form.line_items } discountType={ form.discount_type } discountValue={ form.discount_value } vatPct={ form.vat_pct } currency={ form.currency } />
			</div>

			<div className="co-rec-card">
				<p className="co-rec-card-title">Notes & Payment Instructions</p>
				<div className="co-rec-field">
					<textarea placeholder="Bank transfer details, notes, or any additional information shown on the invoice…"
						value={ form.notes }
						onChange={ e => set( 'notes', e.target.value ) }
						style={ { minHeight: 100 } } />
				</div>
			</div>

			<div className="co-rec-editor-footer">
				<button className="co-rec-btn-ghost" onClick={ onBack } type="button">Cancel</button>
				<button className="co-rec-btn-primary" onClick={ save } disabled={ saving || ! form.client_id } type="button">
					{ saving ? 'Saving…' : 'Save Recurring Invoice' }
				</button>
			</div>
		</div>
	);
}

// ── Recurring list ─────────────────────────────────────────────────────────────

function RecurringList( { profiles, pages = 1, counts = {}, query, onQueryChange, loading, onNew, onEdit, onAction, actionLoading } ) {
	const tab      = query.status || 'all';
	const filtered = profiles;
	const page     = query.page || 1;

	function setTab( id ) {
		onQueryChange( q => ( { ...q, status: id === 'all' ? '' : id, page: 1 } ) );
	}

	function setPage( updater ) {
		onQueryChange( q => ( { ...q, page: typeof updater === 'function' ? updater( q.page ) : updater } ) );
	}

	if ( loading ) return <div className="co-rec-loading">Loading recurring invoices…</div>;

	return (
		<>
			<div className="co-rec-header">
				<div>
					<h1 className="co-rec-title">Recurring Invoices</h1>
					<p className="co-rec-subtitle">Auto-generate and send a fresh invoice on a schedule — clients pay each one manually.</p>
				</div>
				<button className="co-rec-btn-primary" onClick={ onNew } type="button">+ New Recurring Invoice</button>
			</div>

			<div className="co-rec-tabs">
				{ TABS.map( t => (
					<button key={ t.id } className={ `co-rec-tab${ tab === t.id ? ' active' : '' }` } onClick={ () => setTab( t.id ) } type="button">
						{ t.label }
						<span className="co-rec-tab-count">{ counts[ t.id ] || 0 }</span>
					</button>
				) ) }
			</div>

			{ filtered.length === 0 ? (
				<div className="co-rec-empty">
					<svg className="co-rec-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
						<polyline points="23 4 23 10 17 10"/>
						<polyline points="1 20 1 14 7 14"/>
						<path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
					</svg>
					<p className="co-rec-empty-title">No recurring invoices yet</p>
					<p className="co-rec-empty-sub">
						{ tab !== 'all'
							? `No ${ tab } recurring invoices found.`
							: 'Set up a recurring invoice to automatically invoice a client on a schedule — weekly, monthly, quarterly, or yearly.' }
					</p>
				</div>
			) : (
				<div className="co-rec-table-wrap">
					<table className="co-rec-table">
						<thead>
							<tr>
								<th>Recurring Invoice</th>
								<th>Client</th>
								<th>Amount / invoice</th>
								<th>Frequency</th>
								<th>Next Invoice</th>
								<th>Status</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							{ filtered.map( p => {
								const busy  = actionLoading === p.id;
								const total = computeTotals( p.line_items || [], p.discount_type, p.discount_value, p.vat_pct ).total;
								return (
									<tr key={ p.id }>
										<td>{ p.title || '—' }</td>
										<td className="co-rec-client">{ p._client_name || '—' }</td>
										<td className="co-rec-amount">{ fmt( total, p.currency ) }</td>
										<td style={ { textTransform: 'capitalize' } }>{ p.frequency }</td>
										<td><span className="co-rec-next">{ 'cancelled' === p.status ? '—' : formatDate( p.next_run_date ) }</span></td>
										<td><StatusBadge profile={ p } /></td>
										<td>
											<div className="co-rec-actions">
												{ 'cancelled' !== p.status && (
													<button className="co-rec-act co-rec-act-edit" onClick={ () => onEdit( p ) } disabled={ busy } type="button">Edit</button>
												) }
												{ p.status === 'active' && (
													<button className="co-rec-act co-rec-act-pause" onClick={ () => onAction( 'pause', p ) } disabled={ busy } type="button">{ busy ? '…' : 'Pause' }</button>
												) }
												{ p.status === 'paused' && (
													<button className="co-rec-act co-rec-act-resume" onClick={ () => onAction( 'resume', p ) } disabled={ busy } type="button">{ busy ? '…' : 'Resume' }</button>
												) }
												{ 'cancelled' !== p.status && (
													<button className="co-rec-act co-rec-act-cancel" onClick={ () => onAction( 'cancel', p ) } disabled={ busy } type="button">{ busy ? '…' : 'Cancel' }</button>
												) }
											</div>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }

			{ pages > 1 && (
				<div className="co-rec-pager">
					<button
						type="button"
						className="co-rec-page-btn"
						disabled={ page === 1 }
						onClick={ () => setPage( p => p - 1 ) }
						aria-label="Previous page"
					>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
						</svg>
					</button>

					{ Array.from( { length: pages }, ( _, i ) => i + 1 )
						.filter( p => p === 1 || p === pages || Math.abs( p - page ) <= 1 )
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
									className={ `co-rec-page-btn${ page === p ? ' active' : '' }` }
									onClick={ () => setPage( p ) }
								>
									{ p }
								</button>
							)
						)
					}

					<button
						type="button"
						className="co-rec-page-btn"
						disabled={ page === pages }
						onClick={ () => setPage( p => p + 1 ) }
						aria-label="Next page"
					>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
						</svg>
					</button>
				</div>
			) }
		</>
	);
}

// ── Root component ────────────────────────────────────────────────────────────

export default function RecurringApp() {
	injectStyles( 'co-rec-styles', CSS );

	const [ view, setView ]            = useState( 'list' );
	const [ query, setQuery ]          = useState( { status: '', page: 1 } );
	const [ list, setList ]            = useState( { profiles: [], total: 0, pages: 1, counts: {} } );
	const [ loading, setLoading ]      = useState( true );
	const [ editProfile, setEdit ]     = useState( null );
	const [ error, setError ]          = useState( null );
	const [ actionLoading, setActing ] = useState( null );

	async function fetchProfiles() {
		setLoading( true );
		try {
			const params = new URLSearchParams();
			if ( query.status ) params.set( 'status', query.status );
			params.set( 'page', String( query.page ) );
			params.set( 'per_page', '20' );
			const data = await coFetch( `recurring-profiles/?${ params.toString() }` );
			setList( {
				profiles: data.profiles || [],
				total:    data.total    || 0,
				pages:    data.pages    || 1,
				counts:   data.counts   || {},
			} );
		} catch( e ) {
			setError( e.message );
		} finally {
			setLoading( false );
		}
	}

	useEffect( () => { fetchProfiles(); }, [ query ] ); // eslint-disable-line react-hooks/exhaustive-deps

	function refetch() {
		setQuery( q => ( { ...q } ) );
	}

	function openNew() {
		setEdit( null );
		setView( 'editor' );
	}

	function openEdit( p ) {
		setEdit( p );
		setView( 'editor' );
	}

	function onSaved( p ) {
		refetch();
		setView( 'list' );
	}

	async function handleAction( action, p ) {
		if ( action === 'cancel' && ! confirm( `Cancel the recurring invoice "${ p.title || p.id }"? No further invoices will be generated.` ) ) return;
		setActing( p.id );
		try {
			await coFetch( `recurring-profiles/${ p.id }/${ action }/`, { method: 'POST', body: '{}' } );
			refetch();
		} catch( e ) {
			alert( e.message );
		} finally {
			setActing( null );
		}
	}

	return (
		<div className="co-rec">
			{ error && <div className="co-rec-error">{ error }</div> }
			{ view === 'list' && (
				<RecurringList
					profiles={ list.profiles }
					pages={ list.pages }
					counts={ list.counts }
					query={ query }
					onQueryChange={ setQuery }
					loading={ loading }
					onNew={ openNew }
					onEdit={ openEdit }
					onAction={ handleAction }
					actionLoading={ actionLoading }
				/>
			) }
			{ view === 'editor' && (
				<RecurringEditor
					profile={ editProfile }
					onSaved={ onSaved }
					onBack={ () => setView( 'list' ) }
				/>
			) }
		</div>
	);
}
