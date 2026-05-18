/**
 * PricingSetup
 *
 * Step 4 of the proposal wizard. Editable line items with
 * auto-calculating subtotal, discount, VAT, and grand total.
 *
 * Props:
 *   items      {array}   — [{ id, description, qty, unit_price }]
 *   currency   {string}  — 'GBP' | 'USD' | 'EUR'
 *   onUpdate   {fn}      — onUpdate({ items, discount_pct, vat_pct })
 *   discountPct {number}
 *   vatPct      {number}
 */
import { useState, useCallback } from '@wordpress/element';

const CURRENCY_SYMBOLS = { GBP: '£', USD: '$', EUR: '€', CAD: '$', AUD: '$' };

function uid() {
	return Math.random().toString( 36 ).slice( 2 );
}

const CSS = `
.co-pricing-wrap { display: flex; flex-direction: column; gap: 0; }

/* Headers */
.co-pricing-headers {
  display: grid;
  grid-template-columns: 32px 1fr 90px 120px 100px 36px;
  gap: 8px;
  padding: 0 4px 8px;
  border-bottom: 2px solid var(--co-slate-100);
}
.co-pricing-header-cell {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--co-slate-400);
}

/* Rows */
.co-pricing-rows { }
.co-pricing-row {
  display: grid;
  grid-template-columns: 32px 1fr 90px 120px 100px 36px;
  gap: 8px;
  align-items: center;
  padding: 8px 4px;
  border-bottom: 1px solid var(--co-slate-100);
  transition: background .12s;
  border-radius: 6px;
}
.co-pricing-row:hover { background: var(--co-slate-50); }

/* Drag handle */
.co-pricing-handle {
  display: flex; align-items: center; justify-content: center;
  cursor: grab;
  color: var(--co-slate-300);
  font-size: 14px;
  height: 36px;
  transition: color .12s;
}
.co-pricing-row:hover .co-pricing-handle { color: var(--co-slate-400); }

/* Cell inputs */
.co-pricing-input {
  width: 100%;
  padding: 8px 10px;
  border: 1.5px solid transparent;
  border-radius: 6px;
  font-size: 13.5px;
  font-family: var(--co-font);
  color: var(--co-slate-800);
  background: transparent;
  transition: border-color .12s, background .12s, box-shadow .12s;
  outline: none;
  -webkit-appearance: none;
}
.co-pricing-input:hover { border-color: var(--co-slate-200); background: var(--co-white); }
.co-pricing-input:focus {
  border-color: var(--co-indigo);
  background: var(--co-white);
  box-shadow: 0 0 0 3px rgba(99,102,241,.1);
}
.co-pricing-input.num { text-align: right; font-variant-numeric: tabular-nums; }

/* Row total */
.co-pricing-row-total {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--co-slate-700);
  text-align: right;
  font-variant-numeric: tabular-nums;
  padding-right: 4px;
}

/* Delete button */
.co-pricing-del {
  display: flex; align-items: center; justify-content: center;
  width: 28px; height: 28px;
  border-radius: 6px;
  background: transparent;
  border: none;
  cursor: pointer;
  color: var(--co-slate-300);
  transition: background .12s, color .12s;
}
.co-pricing-del:hover { background: var(--co-red-bg); color: var(--co-red); }
.co-pricing-del svg { width: 14px; height: 14px; stroke: currentColor; stroke-width: 2; }

/* Add row button */
.co-pricing-add {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  margin-top: 12px;
  padding: 8px 14px;
  background: transparent;
  border: 1.5px dashed var(--co-slate-300);
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  font-family: var(--co-font);
  color: var(--co-slate-500);
  cursor: pointer;
  transition: border-color .15s, color .15s, background .15s;
}
.co-pricing-add:hover {
  border-color: var(--co-indigo);
  color: var(--co-indigo);
  background: var(--co-indigo-bg);
}
.co-pricing-add svg { width: 14px; height: 14px; stroke: currentColor; stroke-width: 2; }

/* Totals panel */
.co-pricing-footer {
  display: flex;
  justify-content: flex-end;
  margin-top: 20px;
}
.co-pricing-totals {
  min-width: 280px;
  background: var(--co-slate-50);
  border: 1px solid var(--co-slate-200);
  border-radius: var(--co-radius);
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.co-pricing-total-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13.5px;
}
.co-pricing-total-row .label { color: var(--co-slate-500); }
.co-pricing-total-row .value {
  font-weight: 600;
  color: var(--co-slate-700);
  font-variant-numeric: tabular-nums;
}
.co-pricing-total-row.grand .label {
  font-size: 15px;
  font-weight: 700;
  color: var(--co-slate-800);
}
.co-pricing-total-row.grand .value {
  font-family: var(--co-font-display);
  font-size: 22px;
  color: var(--co-indigo);
}
.co-pricing-total-divider { height: 1px; background: var(--co-slate-200); margin: 2px 0; }

/* Modifier inputs */
.co-pricing-mod-input {
  width: 70px;
  padding: 5px 8px;
  border: var(--co-input-border);
  border-radius: 6px;
  font-size: 13px;
  font-family: var(--co-font);
  font-weight: 600;
  text-align: center;
  outline: none;
  background: var(--co-white);
  transition: border-color .12s, box-shadow .12s;
  -webkit-appearance: none;
}
.co-pricing-mod-input:focus {
  border-color: var(--co-indigo);
  box-shadow: 0 0 0 3px rgba(99,102,241,.1);
}

/* Empty state */
.co-pricing-empty {
  text-align: center;
  padding: 32px;
  color: var(--co-slate-400);
  font-size: 14px;
}
`;

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
}

function fmt( amount, symbol ) {
	return `${ symbol }${ Number( amount ).toFixed( 2 ) }`;
}

export default function PricingSetup( {
	items = [],
	currency = 'GBP',
	discountPct = 0,
	vatPct = 0,
	onUpdate,
} ) {
	injectStyles( 'co-pricing-styles', CSS );

	const symbol = CURRENCY_SYMBOLS[ currency ] || '£';

	function updateItems( newItems ) {
		onUpdate( { items: newItems, discount_pct: discountPct, vat_pct: vatPct } );
	}

	function updateRow( id, field, value ) {
		onUpdate( {
			items: items.map( row =>
				row.id === id ? { ...row, [ field ]: value } : row
			),
			discount_pct: discountPct,
			vat_pct: vatPct,
		} );
	}

	function addRow() {
		updateItems( [
			...items,
			{ id: uid(), description: '', qty: 1, unit_price: '' },
		] );
	}

	function removeRow( id ) {
		updateItems( items.filter( r => r.id !== id ) );
	}

	function moveRow( id, dir ) {
		const idx = items.findIndex( r => r.id === id );
		if ( idx === -1 ) return;
		const next = idx + dir;
		if ( next < 0 || next >= items.length ) return;
		const arr = [ ...items ];
		[ arr[ idx ], arr[ next ] ] = [ arr[ next ], arr[ idx ] ];
		updateItems( arr );
	}

	// ── Calculations ─────────────────────────────────────────────────────────
	const subtotal = items.reduce( ( sum, row ) => {
		const qty   = parseFloat( row.qty ) || 0;
		const price = parseFloat( row.unit_price ) || 0;
		return sum + qty * price;
	}, 0 );

	const discountAmt = subtotal * ( ( parseFloat( discountPct ) || 0 ) / 100 );
	const afterDiscount = subtotal - discountAmt;
	const vatAmt  = afterDiscount * ( ( parseFloat( vatPct ) || 0 ) / 100 );
	const grand   = afterDiscount + vatAmt;

	return (
		<div className="co-pricing-wrap">

			{/* Column headers */ }
			<div className="co-pricing-headers">
				<div className="co-pricing-header-cell" />
				<div className="co-pricing-header-cell">Description</div>
				<div className="co-pricing-header-cell" style={ { textAlign: 'center' } }>Qty</div>
				<div className="co-pricing-header-cell" style={ { textAlign: 'right' } }>Unit Price</div>
				<div className="co-pricing-header-cell" style={ { textAlign: 'right' } }>Total</div>
				<div className="co-pricing-header-cell" />
			</div>

			{/* Rows */ }
			<div className="co-pricing-rows">
				{ items.length === 0 && (
					<div className="co-pricing-empty">No line items yet — add one below.</div>
				) }
				{ items.map( ( row, idx ) => {
					const rowTotal = ( parseFloat( row.qty ) || 0 ) * ( parseFloat( row.unit_price ) || 0 );
					return (
						<div key={ row.id } className="co-pricing-row">
							{/* Handle (up/down) */ }
							<div className="co-pricing-handle" title="Reorder">
								<span style={ { display: 'flex', flexDirection: 'column', gap: 1 } }>
									<button
										type="button"
										onClick={ () => moveRow( row.id, -1 ) }
										disabled={ idx === 0 }
										style={ { background: 'none', border: 'none', cursor: idx === 0 ? 'default' : 'pointer', padding: '1px 3px', color: 'inherit', opacity: idx === 0 ? .3 : 1 } }
										aria-label="Move up"
									>
										▴
									</button>
									<button
										type="button"
										onClick={ () => moveRow( row.id, 1 ) }
										disabled={ idx === items.length - 1 }
										style={ { background: 'none', border: 'none', cursor: idx === items.length - 1 ? 'default' : 'pointer', padding: '1px 3px', color: 'inherit', opacity: idx === items.length - 1 ? .3 : 1 } }
										aria-label="Move down"
									>
										▾
									</button>
								</span>
							</div>

							{/* Description */ }
							<input
								type="text"
								className="co-pricing-input"
								placeholder="Service description"
								value={ row.description }
								onChange={ ( e ) => updateRow( row.id, 'description', e.target.value ) }
							/>

							{/* Qty */ }
							<input
								type="number"
								className="co-pricing-input num"
								placeholder="1"
								min="0"
								step="0.5"
								value={ row.qty }
								onChange={ ( e ) => updateRow( row.id, 'qty', e.target.value ) }
							/>

							{/* Unit price */ }
							<input
								type="number"
								className="co-pricing-input num"
								placeholder={ `${ symbol }0.00` }
								min="0"
								step="0.01"
								value={ row.unit_price }
								onChange={ ( e ) => updateRow( row.id, 'unit_price', e.target.value ) }
							/>

							{/* Row total */ }
							<div className="co-pricing-row-total">
								{ fmt( rowTotal, symbol ) }
							</div>

							{/* Delete */ }
							<button
								type="button"
								className="co-pricing-del"
								onClick={ () => removeRow( row.id ) }
								aria-label="Remove row"
							>
								<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
									<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
								</svg>
							</button>
						</div>
					);
				} ) }
			</div>

			{/* Add row */ }
			<button type="button" className="co-pricing-add" onClick={ addRow }>
				<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
					<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
				</svg>
				Add Line Item
			</button>

			{/* Totals */ }
			<div className="co-pricing-footer">
				<div className="co-pricing-totals">
					<div className="co-pricing-total-row">
						<span className="label">Subtotal</span>
						<span className="value">{ fmt( subtotal, symbol ) }</span>
					</div>

					<div className="co-pricing-total-row">
						<span className="label">Discount</span>
						<span style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
							<input
								type="number"
								className="co-pricing-mod-input"
								min="0" max="100" step="1"
								value={ discountPct }
								onChange={ e => onUpdate( { items, discount_pct: parseFloat( e.target.value ) || 0, vat_pct: vatPct } ) }
							/>
							<span style={ { fontSize: 13, color: 'var(--co-slate-500)' } }>%</span>
						</span>
					</div>

					<div className="co-pricing-total-row">
						<span className="label">VAT</span>
						<span style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
							<input
								type="number"
								className="co-pricing-mod-input"
								min="0" max="100" step="1"
								value={ vatPct }
								onChange={ e => onUpdate( { items, discount_pct: discountPct, vat_pct: parseFloat( e.target.value ) || 0 } ) }
							/>
							<span style={ { fontSize: 13, color: 'var(--co-slate-500)' } }>%</span>
						</span>
					</div>

					<div className="co-pricing-total-divider" />

					<div className="co-pricing-total-row grand">
						<span className="label">Total</span>
						<span className="value">{ fmt( grand, symbol ) }</span>
					</div>
				</div>
			</div>
		</div>
	);
}
