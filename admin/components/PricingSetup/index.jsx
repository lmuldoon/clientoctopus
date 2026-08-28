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
import { injectStyles } from '../../../shared/injectStyles';
import { SYMBOLS } from '../../../shared/currency';
import LineItemRows, { lineItemsTotal } from './LineItemRows';

export const CSS = `
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
input.co-pricing-input {
  width: 100%;
  padding: 8px 10px;
  border: 1.5px solid transparent;
  border-radius: var(--co-radius-sm);
  font-size: 13.5px;
  font-family: var(--co-font);
  color: var(--co-slate-800);
  background: transparent;
  transition: border-color .12s, background .12s, box-shadow .12s;
  outline: none;
  -webkit-appearance: none;
}
input.co-pricing-input:hover { border-color: var(--co-slate-200); background: var(--co-white); }
input.co-pricing-input:focus {
  border-color: var(--co-indigo);
  background: var(--co-white);
  box-shadow: 0 0 0 3px rgba(99,102,241,.1);
}
input.co-pricing-input.num { text-align: right; font-variant-numeric: tabular-nums; }

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
input.co-pricing-mod-input {
  width: 70px;
  padding: 5px 8px;
  border: var(--co-input-border);
  border-radius: var(--co-radius-sm);
  font-size: 13px;
  font-family: var(--co-font);
  font-weight: 600;
  text-align: center;
  outline: none;
  background: var(--co-white);
  transition: border-color .12s, box-shadow .12s;
  -webkit-appearance: none;
}
input.co-pricing-mod-input:focus {
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

	const symbol = SYMBOLS[ currency ] || '£';

	function updateItems( newItems ) {
		onUpdate( { items: newItems, discount_pct: discountPct, vat_pct: vatPct } );
	}

	// ── Calculations ─────────────────────────────────────────────────────────
	const subtotal = lineItemsTotal( items );

	const discountAmt = subtotal * ( ( parseFloat( discountPct ) || 0 ) / 100 );
	const afterDiscount = subtotal - discountAmt;
	const vatAmt  = afterDiscount * ( ( parseFloat( vatPct ) || 0 ) / 100 );
	const grand   = afterDiscount + vatAmt;

	return (
		<div className="co-pricing-wrap">

			<LineItemRows items={ items } currency={ currency } onChange={ updateItems } />

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
								onWheel={ e => e.currentTarget.blur() }
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
								onWheel={ e => e.currentTarget.blur() }
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
