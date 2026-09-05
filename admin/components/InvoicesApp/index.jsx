/**
 * InvoicesApp
 *
 * Admin UI for standalone invoices, plus recurring invoices as a second tab
 * within the same screen (RecurringApp is a fully self-contained sub-app —
 * its own list/editor/data-fetching — so it's just mounted here, not merged
 * into this file's own state/logic).
 *
 * "Invoices" tab, two views:
 *   1. Invoice list — sortable by status, quick actions
 *   2. Invoice editor — create / edit form
 *
 * Design matches ProposalList / ClientsApp: same CSS vars, same card layout,
 * same status badge pattern, same field classes.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { injectStyles } from '../../../shared/injectStyles';
import { fmt, SYMBOLS } from '../../../shared/currency';
import { CO_TOKENS_CSS } from '../../../shared/tokens';
import RecurringApp from '../RecurringApp';

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

const STATUS_CONFIG = {
	draft:     { bg: 'var(--co-slate-100)', color: 'var(--co-slate-600)',  label: 'Draft'     },
	sent:      { bg: 'var(--co-indigo-bg)', color: 'var(--co-indigo)',     label: 'Sent'      },
	paid:      { bg: 'var(--co-emerald-bg)', color: 'var(--co-emerald)',   label: 'Paid'      },
	overdue:   { bg: '#FEF3C7',             color: '#92400E',              label: 'Overdue'   },
	cancelled: { bg: 'var(--co-slate-100)', color: 'var(--co-slate-400)',  label: 'Cancelled' },
};

const TABS = [
	{ id: 'all',       label: 'All'       },
	{ id: 'draft',     label: 'Draft'     },
	{ id: 'sent',      label: 'Sent'      },
	{ id: 'paid',      label: 'Paid'      },
	{ id: 'overdue',   label: 'Overdue'   },
	{ id: 'cancelled', label: 'Cancelled' },
];

// ── Styles ────────────────────────────────────────────────────────────────────

const CSS = `
${ CO_TOKENS_CSS }
.co-inv {
  font-family: 'Archivo', -apple-system, sans-serif;
  padding: 32px 28px 64px;
}
@keyframes co-inv-enter {
  from { opacity:0; transform:translateY(8px); }
  to   { opacity:1; transform:translateY(0); }
}
.co-inv { animation: co-inv-enter .2s ease both; }

/* Header */
.co-inv-header {
  display:flex; align-items: flex-start; justify-content:space-between;
  flex-wrap:wrap; gap:16px; margin-bottom:24px;
}
.co-inv-title {
  font-size:28px; font-weight:800; color:var(--co-navy);
  letter-spacing:-.5px; margin:0; line-height:1;
}
.co-inv-subtitle { font-size:14px; color:var(--co-slate-400); margin:6px 0 0; line-height:1.5; }
.co-inv-btn-primary {
  display:inline-flex; align-items:center; gap:7px;
  background:var(--co-indigo); color:#fff; border:none;
  padding:10px 18px; border-radius:8px; font-size:14px;
  font-weight:600; cursor:pointer; transition:opacity .15s;
}
.co-inv-btn-primary:hover { opacity:.88; }
.co-inv-btn-ghost {
  background:none; border:1.5px solid var(--co-slate-200);
  color:var(--co-slate-600); padding:10px 18px; border-radius:8px;
  font-size:13px; font-weight:500; cursor:pointer; transition:border-color .15s;
}
.co-inv-btn-ghost:hover { border-color:var(--co-indigo); color:var(--co-indigo); }

/* Section tabs (Invoices / Recurring Invoices) — underline style, distinct from the pill-style status tabs below */
.co-inv-section-tabs {
  display:flex; gap:24px; border-bottom:1.5px solid var(--co-slate-200); margin-bottom:24px;
}
.co-inv-section-tab {
  background:none; border:none; padding:0 0 12px; font-size:15px; font-weight:600; color:var(--co-slate-400);
  cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1.5px; transition:color .15s, border-color .15s;
}
.co-inv-section-tab:hover { color:var(--co-navy); }
.co-inv-section-tab.active { color:var(--co-indigo); border-color:var(--co-indigo); }

/* Tabs */
.co-inv-tabs {
  display:flex; gap:6px; flex-wrap:wrap;
  margin-bottom:20px;
}
.co-inv-tab {
  display:flex; align-items:center; gap:7px;
  padding:7px 16px; border-radius:20px; font-size:13px; font-weight:500; color:var(--co-slate-400);
  border:1.5px solid var(--co-slate-200); background:#fff; cursor:pointer;
  transition:all .12s;
}
.co-inv-tab:hover { border-color:var(--co-indigo); color:var(--co-indigo); }
.co-inv-tab.active { background:var(--co-indigo); border-color:var(--co-indigo); color:#fff; font-weight:600; }
.co-inv-tab-count {
  font-size:11px; font-weight:700;
  background:var(--co-slate-50); color:var(--co-slate-400);
  border-radius:999px; padding:1px 7px; min-width:20px; text-align:center;
}
.co-inv-tab.active .co-inv-tab-count { background:rgba(255,255,255,.22); color:#fff; }

.co-inv-pager { display:flex; align-items:center; justify-content:center; gap:6px; margin-top:20px; }
.co-inv-page-btn {
  min-width:34px; height:34px; display:flex; align-items:center; justify-content:center;
  border-radius:8px; border:1.5px solid var(--co-slate-200); background:#fff;
  font-size:13px; font-weight:600; color:var(--co-slate-600); cursor:pointer;
  transition:border-color .12s, background .12s, color .12s;
}
.co-inv-page-btn:hover:not(:disabled) { border-color:var(--co-indigo); color:var(--co-indigo); background:#EEF2FF; }
.co-inv-page-btn.active { background:var(--co-indigo); color:#fff; border-color:var(--co-indigo); }
.co-inv-page-btn:disabled { opacity:.4; cursor:not-allowed; }
.co-inv-page-btn svg { width:14px; height:14px; stroke:currentColor; stroke-width:2; }

/* Table */
.co-inv-table-wrap { background:#fff; border-radius:12px; border:1px solid var(--co-slate-200); overflow-x:auto; overflow-y:hidden; box-shadow: 0 1px 3px rgba(26,26,46,.04), 0 6px 24px rgba(26,26,46,.06); }
.co-inv-table { width:100%; min-width:640px; border-collapse:collapse; }
.co-inv-table th {
  text-align:left; padding:11px 16px; font-size:11px; font-weight:700;
  color:var(--co-slate-400); text-transform:uppercase; letter-spacing:.5px;
  border-bottom:1px solid var(--co-slate-200); background:var(--co-slate-50);
}
.co-inv-table td { padding:14px 16px; border-bottom:1px solid var(--co-slate-200); font-size:14px; color:var(--co-slate-600); }
.co-inv-table tr:last-child td { border-bottom:none; }
.co-inv-table tr:hover td { background:var(--co-slate-50); }
.co-inv-ref { font-weight:600; color:var(--co-navy); font-family:monospace; font-size:14px; }
.co-inv-client { font-weight:500; color:var(--co-navy); }
.co-inv-amount { font-weight:700; color:var(--co-navy); }
.co-inv-due { color:var(--co-slate-400); font-size:13px; }
.co-inv-due.overdue { color:var(--co-red); font-weight:600; }

/* Status badge */
.co-inv-badge {
  display:inline-block; padding:3px 10px; border-radius:20px;
  font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px;
}

/* Row actions */
.co-inv-actions { display:flex; gap:6px; }
.co-inv-act {
  padding:5px 10px; border-radius:6px; font-size:12px; font-weight:600;
  cursor:pointer; border:none; transition:opacity .15s;
}
.co-inv-act:hover { opacity:.78; }
.co-inv-act-send { background:#EEF2FF; color:var(--co-indigo); }
.co-inv-act-paid { background:#D1FAE5; color:#065F46; }
.co-inv-act-cancel { background:#FEF2F2; color:var(--co-red); }
.co-inv-act-edit { background:var(--co-slate-50); color:var(--co-slate-500); }
.co-inv-act-del { background:#FEF2F2; color:var(--co-red); }

/* Empty state */
.co-inv-empty {
  background:#fff; border:1.5px solid var(--co-slate-200); border-radius:12px;
  padding:56px 32px; text-align:center;
}
.co-inv-empty-icon { color:var(--co-slate-300); margin:0 auto 16px; display:block; }
.co-inv-empty-title { font-family:'Archivo', -apple-system, BlinkMacSystemFont, sans-serif; font-size:20px; color:var(--co-navy); margin:0 0 8px; }
.co-inv-empty-sub { font-size:14px; color:var(--co-slate-500); max-width:380px; margin:0 auto; line-height:1.6; }

/* Loading / Error */
.co-inv-loading { text-align:center; padding:48px; color:var(--co-slate-400); }
.co-inv-error { background:#FEF2F2; border:1px solid #FECACA; color:#991B1B;
  border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:14px; }

/* ── Editor ── */
.co-inv-editor { max-width:760px; }
.co-inv-back {
  display:inline-flex; align-items:center; gap:6px; color:var(--co-slate-400);
  font-size:13px; font-weight:500; cursor:pointer; border:none; background:none;
  padding:0; margin-bottom:20px; transition:color .15s;
}
.co-inv-back:hover { color:var(--co-indigo); }
.co-inv-editor-title { font-size:24px; font-weight:800; color:var(--co-navy); margin:0 0 24px; }
.co-inv-card {
  background:#fff; border:1px solid var(--co-slate-200); border-radius:12px;
  padding:24px; margin-bottom:16px;
}
.co-inv-card-title {
  font-size:13px; font-weight:700; color:var(--co-slate-500); text-transform:uppercase;
  letter-spacing:.5px; margin:0 0 16px; padding-bottom:12px;
  border-bottom:1px solid var(--co-slate-200);
}
.co-inv-field { margin-bottom:16px; }
.co-inv-field label { display:block; font-size:13px; font-weight:600; color:var(--co-slate-600); margin-bottom:6px; }
.co-inv-field input,
.co-inv-field select,
.co-inv-field textarea {
  width:100%; padding:9px 12px; border:1.5px solid var(--co-slate-200); border-radius:8px;
  font-size:14px; color:var(--co-navy); background:#fff; box-sizing:border-box;
  transition:border-color .15s;
}
.co-inv-field input { height:41px; }
.co-inv-field select {
  height:41px; line-height:1;
  appearance:none; -webkit-appearance:none; -moz-appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2364748B' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat; background-position:right 12px center;
  padding-right:36px;
}
.co-inv-field input:focus,
.co-inv-field select:focus,
.co-inv-field textarea:focus {
  outline:none; border-color:var(--co-indigo); box-shadow:0 0 0 3px rgba(99,102,241,.1);
}
.co-inv-field textarea { min-height:80px; resize:vertical; }
.co-inv-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.co-inv-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; }

/* Line items */
.co-inv-items { display:flex; flex-direction:column; gap:10px; margin-bottom:12px; }
.co-inv-item { display:grid; grid-template-columns:1fr 130px 36px; gap:8px; align-items:center; }
.co-inv-item input {
  margin:0; width:100%; height:41px; padding:9px 12px; border:1.5px solid var(--co-slate-200); border-radius:8px;
  font-size:14px; color:var(--co-navy); background:#fff; box-sizing:border-box; transition:border-color .15s;
}
.co-inv-item input:focus { outline:none; border-color:var(--co-indigo); box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.co-inv-item-del {
  background:none; border:none; color:var(--co-slate-300); cursor:pointer; font-size:18px; line-height:1;
  padding:4px; border-radius:6px; transition:color .15s;
}
.co-inv-item-del:hover { color:var(--co-red); }
.co-inv-add-item {
  background:none; border:1.5px dashed var(--co-slate-300); color:var(--co-slate-500);
  border-radius:8px; padding:8px 12px; font-size:13px; font-weight:600;
  cursor:pointer; width:100%; text-align:center; transition:border-color .15s;
}
.co-inv-add-item:hover { border-color:var(--co-indigo); color:var(--co-indigo); }

/* Totals */
.co-inv-totals {
  background:var(--co-slate-50); border-radius:8px; padding:14px 16px;
  font-size:13px; display:flex; flex-direction:column; gap:6px; align-items:flex-end;
}
.co-inv-totals-row { display:flex; gap:32px; width:240px; justify-content:space-between; }
.co-inv-totals-label { color:var(--co-slate-500); }
.co-inv-totals-value { font-weight:600; color:var(--co-navy); }
.co-inv-totals-total .co-inv-totals-label { font-weight:700; color:var(--co-navy); font-size:15px; }
.co-inv-totals-total .co-inv-totals-value { font-size:17px; }

/* Client picker */
.co-inv-client-picker { position:relative; }
.co-inv-client-search-row { display:flex; gap:8px; align-items:center; }
.co-inv-client-search-row input {
  flex:1; height:41px; padding:9px 12px; border:1.5px solid var(--co-slate-200); border-radius:8px;
  font-size:14px; color:var(--co-navy); background:#fff; box-sizing:border-box; transition:border-color .15s;
}
.co-inv-client-search-row input:focus { outline:none; border-color:var(--co-indigo); box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.co-inv-client-results {
  position:absolute; top:100%; left:0; right:0; z-index:20;
  background:#fff; border:1.5px solid var(--co-slate-200); border-top:none;
  border-radius:0 0 8px 8px; max-height:200px; overflow-y:auto;
  box-shadow:0 4px 16px rgba(15,23,42,.1);
}
.co-inv-client-opt {
  padding:10px 14px; font-size:14px; cursor:pointer;
  border-bottom:1px solid var(--co-slate-200);
}
.co-inv-client-opt:last-child { border-bottom:none; }
.co-inv-client-opt:hover { background:var(--co-slate-50); }
.co-inv-client-opt.create { color:var(--co-indigo); font-weight:600; }
.co-inv-client-selected {
  display:flex; align-items:center; justify-content:space-between;
  padding:9px 12px; border:1.5px solid var(--co-slate-200); border-radius:8px;
  background:#fff;
}
.co-inv-client-clear {
  background:none; border:none; color:var(--co-slate-300); cursor:pointer; font-size:16px; padding:0;
}

/* Editor footer */
.co-inv-editor-footer {
  display:flex; gap:10px; justify-content:flex-end; padding-top:8px;
}

/* Inline new-client fields */
.co-inv-new-client { border:1.5px solid var(--co-indigo); border-radius:8px; padding:14px; margin-top:8px; background:#EEF2FF15; }
.co-inv-new-client-title { font-size:12px; font-weight:700; color:var(--co-indigo); text-transform:uppercase; letter-spacing:.5px; margin:0 0 12px; }
`;

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatDate( iso ) {
	if ( ! iso ) return '—';
	return new Date( iso ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' } );
}

function formatCurrency( amount, currency ) {
	return fmt( parseFloat( amount || 0 ), currency );
}

function computeTotals( lineItems, discountType, discountValue, vatPct ) {
	const subtotal = lineItems.reduce( ( s, i ) => s + parseFloat( i.amount || 0 ), 0 );
	const discAmt  = discountType === 'percentage'
		? subtotal * ( Math.min( 100, parseFloat( discountValue || 0 ) ) / 100 )
		: Math.min( parseFloat( discountValue || 0 ), subtotal );
	const afterDisc = Math.max( 0, subtotal - discAmt );
	const vatAmt    = afterDisc * ( parseFloat( vatPct || 0 ) / 100 );
	const total     = afterDisc + vatAmt;
	return { subtotal, discAmt, afterDisc, vatAmt, total };
}

function today() {
	return new Date().toISOString().slice( 0, 10 );
}

// ── Status badge ──────────────────────────────────────────────────────────────

function StatusBadge( { status } ) {
	const cfg = STATUS_CONFIG[ status ] || { bg: '#F1F5F9', color: '#64748B', label: status };
	return (
		<span
			className="co-inv-badge"
			style={ { background: cfg.bg, color: cfg.color } }
		>
			{ cfg.label }
		</span>
	);
}

// ── Client picker ─────────────────────────────────────────────────────────────

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
			<div className="co-inv-client-selected">
				<span style={ { fontSize: 14, color: '#0F172A', fontWeight: 600 } }>{ label }</span>
				<button className="co-inv-client-clear" onClick={ () => onChange( null, '' ) } type="button">×</button>
			</div>
		);
	}

	return (
		<div className="co-inv-client-picker">
			<div className="co-inv-client-search-row">
				<input
					type="text"
					placeholder="Search existing client…"
					value={ query }
					onChange={ e => { setQuery( e.target.value ); setOpen( true ); } }
					onFocus={ () => setOpen( true ) }
					onBlur={ () => setTimeout( () => setOpen( false ), 200 ) }
				/>
				<button className="co-inv-btn-ghost" onClick={ () => setCreating( true ) } type="button">+ Add New Client</button>
			</div>
			{ open && results.length > 0 && (
				<div className="co-inv-client-results">
					{ results.map( c => (
						<div
							key={ c.id }
							className="co-inv-client-opt"
							onMouseDown={ () => { onChange( c.id, c.name ); setOpen( false ); setQuery( '' ); } }
						>
							{ c.name }{ c.company ? ` — ${ c.company }` : '' }
						</div>
					) ) }
				</div>
			) }
			{ creating && (
				<div className="co-inv-new-client">
					<p className="co-inv-new-client-title">New Client</p>
					<div className="co-inv-row-3">
						<div className="co-inv-field">
							<label>Name *</label>
							<input value={ newClient.name } onChange={ e => setNewClient( p => ( { ...p, name: e.target.value } ) ) } />
						</div>
						<div className="co-inv-field">
							<label>Email *</label>
							<input type="email" value={ newClient.email } onChange={ e => setNewClient( p => ( { ...p, email: e.target.value } ) ) } />
						</div>
						<div className="co-inv-field">
							<label>Company</label>
							<input value={ newClient.company } onChange={ e => setNewClient( p => ( { ...p, company: e.target.value } ) ) } />
						</div>
					</div>
					<div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end' } }>
						<button className="co-inv-btn-ghost" onClick={ () => setCreating( false ) } type="button">Cancel</button>
						<button className="co-inv-btn-primary" onClick={ createInline } type="button" disabled={ ! newClient.name || ! newClient.email }>Save Client</button>
					</div>
				</div>
			) }
		</div>
	);
}

// ── Line items editor ─────────────────────────────────────────────────────────

function LineItems( { items, currency, onChange } ) {
	function update( i, field, val ) {
		const next = items.map( ( it, idx ) => idx === i ? { ...it, [ field ]: val } : it );
		onChange( next );
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
			<div className="co-inv-items">
				{ items.map( ( item, i ) => (
					<div key={ i } className="co-inv-item">
						<input
							type="text"
							placeholder="Description"
							value={ item.description }
							onChange={ e => update( i, 'description', e.target.value ) }
						/>
						<input
							type="number"
							placeholder={ `${ sym }0.00` }
							value={ item.amount }
							min="0"
							step="0.01"
							onChange={ e => update( i, 'amount', e.target.value ) }
						/>
						<button className="co-inv-item-del" onClick={ () => removeRow( i ) } type="button">×</button>
					</div>
				) ) }
			</div>
			<button className="co-inv-add-item" onClick={ addRow } type="button">+ Add line item</button>
		</>
	);
}

// ── Totals display ────────────────────────────────────────────────────────────

function TotalsBlock( { lineItems, discountType, discountValue, vatPct, currency } ) {
	const { subtotal, discAmt, vatAmt, total } = computeTotals( lineItems, discountType, discountValue, vatPct );
	const showDiscount = parseFloat( discountValue || 0 ) > 0;
	const showVat      = parseFloat( vatPct || 0 ) > 0;

	return (
		<div className="co-inv-totals">
			<div className="co-inv-totals-row">
				<span className="co-inv-totals-label">Subtotal</span>
				<span className="co-inv-totals-value">{ fmt( subtotal, currency ) }</span>
			</div>
			{ showDiscount && (
				<div className="co-inv-totals-row">
					<span className="co-inv-totals-label">Discount</span>
					<span className="co-inv-totals-value" style={ { color: '#10B981' } }>−{ fmt( discAmt, currency ) }</span>
				</div>
			) }
			{ showVat && (
				<div className="co-inv-totals-row">
					<span className="co-inv-totals-label">VAT ({ parseFloat( vatPct ).toFixed( 0 ) }%)</span>
					<span className="co-inv-totals-value">{ fmt( vatAmt, currency ) }</span>
				</div>
			) }
			<div className="co-inv-totals-row co-inv-totals-total" style={ { borderTop: '1.5px solid #E2E8F0', paddingTop: 8, marginTop: 4 } }>
				<span className="co-inv-totals-label">Total</span>
				<span className="co-inv-totals-value">{ fmt( total, currency ) }</span>
			</div>
		</div>
	);
}

// ── Invoice editor ────────────────────────────────────────────────────────────

const BLANK_FORM = {
	client_id: null, client_label: '',
	title: '', currency: 'GBP',
	line_items: [ { description: '', amount: '' } ],
	discount_type: 'percentage', discount_value: 0,
	vat_pct: 0, vat_number: '',
	notes: '',
	due_date: '', issue_date: today(),
	payment_terms: '', po_number: '',
};

function InvoiceEditor( { invoice, onSaved, onBack } ) {
	const isEdit = !! invoice?.id;

	const [ form, setForm ] = useState( () => {
		if ( ! invoice ) return { ...BLANK_FORM };
		return {
			client_id:      invoice.client_id || null,
			client_label:   invoice._client_name || '',
			title:          invoice.title || '',
			currency:       invoice.currency || 'GBP',
			line_items:     invoice.line_items?.length ? invoice.line_items : [ { description: '', amount: '' } ],
			discount_type:  invoice.discount_type || 'percentage',
			discount_value: invoice.discount_value || 0,
			vat_pct:        invoice.vat_pct || 0,
			vat_number:     invoice.vat_number || '',
			notes:          invoice.notes || '',
			due_date:       invoice.due_date || '',
			issue_date:     invoice.issue_date || today(),
			payment_terms:  invoice.payment_terms || '',
			po_number:      invoice.po_number || '',
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
			if ( isEdit ) {
				const data = await coFetch( `invoices/${ invoice.id }/update/`, { method: 'POST', body: JSON.stringify( payload ) } );
				onSaved( data.invoice );
			} else {
				const data = await coFetch( 'invoices/create/', { method: 'POST', body: JSON.stringify( payload ) } );
				onSaved( data.invoice );
			}
		} catch( e ) {
			setError( e.message );
			setSaving( false );
		}
	}

	const isSent = isEdit && ! [ 'draft' ].includes( invoice?.status );

	return (
		<div className="co-inv-editor">
			<button className="co-inv-back" onClick={ onBack } type="button">
				← Back to invoices
			</button>
			<h1 className="co-inv-editor-title">{ isEdit ? `Edit ${ invoice.invoice_ref }` : 'New Invoice' }</h1>

			{ error && <div className="co-inv-error">{ error }</div> }

			<div className="co-inv-card">
				<p className="co-inv-card-title">Client</p>
				<ClientPicker
					value={ form.client_id }
					label={ form.client_label }
					onChange={ ( id, label ) => setForm( p => ( { ...p, client_id: id, client_label: label } ) ) }
				/>
			</div>

			<div className="co-inv-card">
				<p className="co-inv-card-title">Invoice Details</p>
				<div className="co-inv-field">
					<label>Title / Description</label>
					<input
						type="text"
						placeholder="e.g. Website redesign — Phase 2"
						value={ form.title }
						onChange={ e => set( 'title', e.target.value ) }
						disabled={ isSent }
					/>
				</div>
				<div className="co-inv-row-3">
					<div className="co-inv-field">
						<label>Currency</label>
						<select value={ form.currency } onChange={ e => set( 'currency', e.target.value ) } disabled={ isSent }>
							{ CURRENCIES.map( c => <option key={ c.value } value={ c.value }>{ c.label }</option> ) }
						</select>
					</div>
					<div className="co-inv-field">
						<label>Issue Date</label>
						<input type="date" value={ form.issue_date } onChange={ e => set( 'issue_date', e.target.value ) } disabled={ isSent } />
					</div>
					<div className="co-inv-field">
						<label>Due Date</label>
						<input type="date" value={ form.due_date } onChange={ e => set( 'due_date', e.target.value ) } disabled={ isSent } />
					</div>
				</div>
				<div className="co-inv-row-2">
					<div className="co-inv-field">
						<label>Payment Terms</label>
						<input
							type="text"
							placeholder="e.g. Net 14, Due on receipt"
							value={ form.payment_terms }
							onChange={ e => set( 'payment_terms', e.target.value ) }
							disabled={ isSent }
						/>
					</div>
					<div className="co-inv-field">
						<label>PO Number</label>
						<input
							type="text"
							placeholder="Client purchase order ref"
							value={ form.po_number }
							onChange={ e => set( 'po_number', e.target.value ) }
							disabled={ isSent }
						/>
					</div>
				</div>
			</div>

			<div className="co-inv-card">
				<p className="co-inv-card-title">Line Items</p>
				<LineItems
					items={ form.line_items }
					currency={ form.currency }
					onChange={ items => set( 'line_items', items ) }
				/>
				{ ! isSent && (
					<div className="co-inv-row-3" style={ { marginTop: 16 } }>
						<div className="co-inv-field">
							<label>Discount type</label>
							<select value={ form.discount_type } onChange={ e => set( 'discount_type', e.target.value ) }>
								<option value="percentage">Percentage (%)</option>
								<option value="fixed">Fixed amount</option>
							</select>
						</div>
						<div className="co-inv-field">
							<label>Discount value</label>
							<input type="number" min="0" step="0.01" value={ form.discount_value } onChange={ e => set( 'discount_value', e.target.value ) } />
						</div>
						<div className="co-inv-field">
							<label>VAT %</label>
							<input type="number" min="0" max="100" step="0.01" value={ form.vat_pct } onChange={ e => set( 'vat_pct', e.target.value ) } />
						</div>
					</div>
				) }
				{ ! isSent && parseFloat( form.vat_pct || 0 ) > 0 && (
					<div className="co-inv-field">
						<label>VAT Registration Number</label>
						<input type="text" placeholder="e.g. GB123456789" value={ form.vat_number } onChange={ e => set( 'vat_number', e.target.value ) } />
					</div>
				) }
				<TotalsBlock
					lineItems={ form.line_items }
					discountType={ form.discount_type }
					discountValue={ form.discount_value }
					vatPct={ form.vat_pct }
					currency={ form.currency }
				/>
			</div>

			<div className="co-inv-card">
				<p className="co-inv-card-title">Notes &amp; Payment Instructions</p>
				<div className="co-inv-field">
					<textarea
						placeholder="Bank transfer details, notes, or any additional information shown on the invoice…"
						value={ form.notes }
						onChange={ e => set( 'notes', e.target.value ) }
						style={ { minHeight: 100 } }
					/>
				</div>
			</div>

			<div className="co-inv-editor-footer">
				<button className="co-inv-btn-ghost" onClick={ onBack } type="button">Cancel</button>
				<button className="co-inv-btn-primary" onClick={ save } disabled={ saving } type="button">
					{ saving ? 'Saving…' : 'Save Invoice' }
				</button>
			</div>
		</div>
	);
}

// ── Invoice list ──────────────────────────────────────────────────────────────

function InvoiceList( { invoices, pages = 1, counts = {}, query, onQueryChange, loading, onNew, onEdit, onAction, actionLoading } ) {
	const tab      = query.status || 'all';
	const filtered = invoices;
	const page     = query.page || 1;

	function setTab( id ) {
		onQueryChange( q => ( { ...q, status: id === 'all' ? '' : id, page: 1 } ) );
	}

	function setPage( updater ) {
		onQueryChange( q => ( { ...q, page: typeof updater === 'function' ? updater( q.page ) : updater } ) );
	}

	if ( loading ) return <div className="co-inv-loading">Loading invoices…</div>;

	return (
		<>
			<div className="co-inv-header">
				<div>
					<h1 className="co-inv-title">Invoices</h1>
					<p className="co-inv-subtitle">Create, send, and track invoices for your clients.</p>
				</div>
				<button className="co-inv-btn-primary" onClick={ onNew } type="button">+ New Invoice</button>
			</div>

			<div className="co-inv-tabs">
				{ TABS.map( t => (
					<button
						key={ t.id }
						className={ `co-inv-tab${ tab === t.id ? ' active' : '' }` }
						onClick={ () => setTab( t.id ) }
						type="button"
					>
						{ t.label }
						<span className="co-inv-tab-count">{ counts[ t.id ] || 0 }</span>
					</button>
				) ) }
			</div>

			{ filtered.length === 0 ? (
				<div className="co-inv-empty">
					<svg className="co-inv-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
						<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
						<polyline points="14 2 14 8 20 8"/>
						<line x1="12" y1="18" x2="12" y2="12"/>
						<line x1="9" y1="15" x2="15" y2="15"/>
					</svg>
					<p className="co-inv-empty-title">No invoices yet</p>
					<p className="co-inv-empty-sub">
						{ tab !== 'all'
							? `No ${ tab } invoices found.`
							: 'Create your first invoice to start billing clients.' }
					</p>
				</div>
			) : (
				<div className="co-inv-table-wrap">
					<table className="co-inv-table">
						<thead>
							<tr>
								<th>Invoice</th>
								<th>Client</th>
								<th>Amount</th>
								<th>Due Date</th>
								<th>Status</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							{ filtered.map( inv => {
								const isOverdue = inv.status === 'overdue';
								const busy = actionLoading === inv.id;
								return (
									<tr key={ inv.id }>
										<td>
											<span className="co-inv-ref">{ inv.invoice_ref }</span>
											{ inv.title && <div style={ { fontSize: 12, color: '#94A3B8', marginTop: 2 } }>{ inv.title }</div> }
										</td>
										<td className="co-inv-client">{ inv._client_name || '—' }</td>
										<td className="co-inv-amount">{ fmt( parseFloat( inv.total_amount ), inv.currency ) } <span style={ { color: '#94A3B8', fontWeight: 400 } }>{ inv.currency }</span></td>
										<td>
											<span className={ `co-inv-due${ isOverdue ? ' overdue' : '' }` }>
												{ formatDate( inv.due_date ) }
											</span>
										</td>
										<td><StatusBadge status={ inv.status } /></td>
										<td>
											<div className="co-inv-actions">
												{ inv.status === 'draft' && (
													<>
														<button className="co-inv-act co-inv-act-edit" onClick={ () => onEdit( inv ) } disabled={ busy } type="button">Edit</button>
														<button className="co-inv-act co-inv-act-send" onClick={ () => onAction( 'send', inv ) } disabled={ busy } type="button">{ busy ? '…' : 'Send' }</button>
													</>
												) }
												{ ( inv.status === 'sent' || inv.status === 'overdue' ) && (
													<>
														<button className="co-inv-act co-inv-act-send" onClick={ () => onAction( 'resend', inv ) } disabled={ busy } type="button">{ busy ? '…' : 'Re-send' }</button>
														<button className="co-inv-act co-inv-act-paid" onClick={ () => onAction( 'mark-paid', inv ) } disabled={ busy } type="button">{ busy ? '…' : 'Mark Paid' }</button>
														<button className="co-inv-act co-inv-act-cancel" onClick={ () => onAction( 'cancel', inv ) } disabled={ busy } type="button">{ busy ? '…' : 'Cancel' }</button>
													</>
												) }
												{ inv.status === 'draft' && (
													<button className="co-inv-act co-inv-act-del" onClick={ () => onAction( 'delete', inv ) } disabled={ busy } type="button">Delete</button>
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
				<div className="co-inv-pager">
					<button
						type="button"
						className="co-inv-page-btn"
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
								<span key={ `ellipsis-${ i }` } style={ { padding: '0 4px', color: '#94A3B8' } }>…</span>
							) : (
								<button
									key={ p }
									type="button"
									className={ `co-inv-page-btn${ page === p ? ' active' : '' }` }
									onClick={ () => setPage( p ) }
								>
									{ p }
								</button>
							)
						)
					}

					<button
						type="button"
						className="co-inv-page-btn"
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

export default function InvoicesApp() {
	injectStyles( 'co-inv-styles', CSS );

	const [ section, setSection ]     = useState(
		// Allows deep-linking straight to the Recurring Invoices tab, e.g. from
		// the "Recurring" indicator on the Proposals list.
		new URLSearchParams( window.location.search ).get( 'tab' ) === 'recurring' ? 'recurring' : 'invoices'
	); // 'invoices' | 'recurring'
	const [ view, setView ]           = useState( 'list' );
	const [ query, setQuery ]         = useState( { status: '', page: 1 } );
	const [ list, setList ]           = useState( { invoices: [], total: 0, pages: 1, counts: {} } );
	const [ loading, setLoading ]     = useState( true );
	const [ editInvoice, setEdit ]    = useState( null );
	const [ error, setError ]         = useState( null );
	const [ actionLoading, setActing ] = useState( null );

	async function fetchInvoices() {
		setLoading( true );
		try {
			const params = new URLSearchParams();
			if ( query.status ) params.set( 'status', query.status );
			params.set( 'page', String( query.page ) );
			params.set( 'per_page', '20' );
			const data = await coFetch( `invoices/?${ params.toString() }` );
			setList( {
				invoices: data.invoices || [],
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

	useEffect( () => { fetchInvoices(); }, [ query ] ); // eslint-disable-line react-hooks/exhaustive-deps

	function refetch() {
		setQuery( q => ( { ...q } ) );
	}

	function openNew() {
		setEdit( null );
		setView( 'editor' );
	}

	function openEdit( inv ) {
		setEdit( inv );
		setView( 'editor' );
	}

	function onSaved( inv ) {
		refetch();
		setView( 'list' );
	}

	async function handleAction( action, inv ) {
		if ( action === 'delete' && ! confirm( `Delete ${ inv.invoice_ref }? This cannot be undone.` ) ) return;
		setActing( inv.id );
		try {
			if ( action === 'delete' ) {
				await coFetch( `invoices/${ inv.id }/`, { method: 'DELETE' } );
			} else {
				const pathMap = { send: 'send', resend: 'send', 'mark-paid': 'mark-paid', cancel: 'cancel' };
				await coFetch( `invoices/${ inv.id }/${ pathMap[ action ] }/`, { method: 'POST', body: '{}' } );
			}
			refetch();
		} catch( e ) {
			alert( e.message );
		} finally {
			setActing( null );
		}
	}

	return (
		<div className="co-inv">
			<div className="co-inv-section-tabs">
				<button
					className={ `co-inv-section-tab${ section === 'invoices' ? ' active' : '' }` }
					onClick={ () => setSection( 'invoices' ) }
					type="button"
				>
					Invoices
				</button>
				<button
					className={ `co-inv-section-tab${ section === 'recurring' ? ' active' : '' }` }
					onClick={ () => setSection( 'recurring' ) }
					type="button"
				>
					Recurring Invoices
				</button>
			</div>

			{ section === 'recurring' ? (
				<RecurringApp />
			) : (
				<>
					{ error && <div className="co-inv-error">{ error }</div> }
					{ view === 'list' && (
						<InvoiceList
							invoices={ list.invoices }
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
						<InvoiceEditor
							invoice={ editInvoice }
							onSaved={ onSaved }
							onBack={ () => setView( 'list' ) }
						/>
					) }
				</>
			) }
		</div>
	);
}
