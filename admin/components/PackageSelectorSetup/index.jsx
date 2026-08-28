/**
 * PackageSelectorSetup
 *
 * Step 4 alternative to PricingSetup — lets the sender define unlimited
 * pricing tiers (each with its own independent line-item list) plus
 * unlimited add-ons (a single description + price each). The client
 * picks a tier and toggles add-ons on the client-facing proposal;
 * discount/VAT stay global modifiers applied on top of that selection.
 *
 * Props:
 *   tiers        {array}  — [{ id, name, line_items }]
 *   addons       {array}  — [{ id, description, unit_price }]
 *   currency     {string} — 'GBP' | 'USD' | 'EUR'
 *   discountPct  {number}
 *   vatPct       {number}
 *   onUpdate     {fn}     — onUpdate({ tiers, addons, discount_pct, vat_pct })
 */
import { injectStyles } from '../../../shared/injectStyles';
import { SYMBOLS } from '../../../shared/currency';
import LineItemRows, { lineItemsTotal } from '../PricingSetup/LineItemRows';
import { CSS as PRICING_CSS } from '../PricingSetup/index';

function uid( prefix ) {
	return `${ prefix }_${ Math.random().toString( 36 ).slice( 2 ) }`;
}

function fmt( amount, symbol ) {
	return `${ symbol }${ Number( amount ).toFixed( 2 ) }`;
}

const CSS = `
.co-pkg-wrap { display: flex; flex-direction: column; gap: 28px; }

.co-pkg-section-label {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--co-slate-400);
  margin-bottom: 10px;
}

/* Tiers */
.co-pkg-tiers { display: flex; flex-direction: column; gap: 14px; }
.co-pkg-tier {
  border: 1.5px solid var(--co-slate-200);
  border-radius: var(--co-radius);
  padding: 16px 18px;
  background: var(--co-white);
}
.co-pkg-tier-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}
input.co-pkg-tier-name {
  flex: 1;
  padding: 8px 10px;
  border: 1.5px solid transparent;
  border-radius: var(--co-radius-sm);
  font-size: 14px;
  font-weight: 600;
  font-family: var(--co-font-display);
  color: var(--co-slate-800);
  background: transparent;
  outline: none;
  transition: border-color .12s, background .12s;
}
input.co-pkg-tier-name:hover { border-color: var(--co-slate-200); background: var(--co-slate-50); }
input.co-pkg-tier-name:focus {
  border-color: var(--co-indigo);
  background: var(--co-white);
  box-shadow: 0 0 0 3px rgba(99,102,241,.1);
}
.co-pkg-tier-total {
  font-size: 13px;
  font-weight: 600;
  color: var(--co-slate-500);
  white-space: nowrap;
}
.co-pkg-tier-del {
  display: flex; align-items: center; justify-content: center;
  width: 28px; height: 28px;
  border-radius: 6px;
  background: transparent;
  border: none;
  cursor: pointer;
  color: var(--co-slate-300);
  transition: background .12s, color .12s;
}
.co-pkg-tier-del:hover { background: var(--co-red-bg); color: var(--co-red); }
.co-pkg-tier-del svg { width: 14px; height: 14px; stroke: currentColor; stroke-width: 2; }

.co-pkg-add-tier {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 10px 16px;
  background: transparent;
  border: 1.5px dashed var(--co-slate-300);
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  font-family: var(--co-font);
  color: var(--co-slate-500);
  cursor: pointer;
  transition: border-color .15s, color .15s, background .15s;
  align-self: flex-start;
}
.co-pkg-add-tier:hover {
  border-color: var(--co-indigo);
  color: var(--co-indigo);
  background: var(--co-indigo-bg);
}
.co-pkg-add-tier svg { width: 14px; height: 14px; stroke: currentColor; stroke-width: 2; }

/* Add-ons */
.co-pkg-addons { display: flex; flex-direction: column; }
.co-pkg-addon-row {
  display: grid;
  grid-template-columns: 1fr 140px 36px;
  gap: 8px;
  align-items: center;
  padding: 8px 4px;
  border-bottom: 1px solid var(--co-slate-100);
  border-radius: 6px;
  transition: background .12s;
}
.co-pkg-addon-row:hover { background: var(--co-slate-50); }
.co-pkg-empty {
  text-align: center;
  padding: 24px;
  color: var(--co-slate-400);
  font-size: 14px;
  border: 1.5px dashed var(--co-slate-200);
  border-radius: var(--co-radius);
}

/* Totals footer (mirrors PricingSetup) */
.co-pkg-footer { display: flex; justify-content: flex-end; }
.co-pkg-totals {
  min-width: 280px;
  background: var(--co-slate-50);
  border: 1px solid var(--co-slate-200);
  border-radius: var(--co-radius);
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.co-pkg-total-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13.5px;
}
.co-pkg-total-row .label { color: var(--co-slate-500); }
.co-pkg-total-row .value {
  font-weight: 600;
  color: var(--co-slate-700);
  font-variant-numeric: tabular-nums;
}
.co-pkg-total-row.grand .label {
  font-size: 15px;
  font-weight: 700;
  color: var(--co-slate-800);
}
.co-pkg-total-row.grand .value {
  font-family: var(--co-font-display);
  font-size: 22px;
  color: var(--co-indigo);
}
.co-pkg-total-divider { height: 1px; background: var(--co-slate-200); margin: 2px 0; }
input.co-pkg-mod-input {
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
input.co-pkg-mod-input:focus {
  border-color: var(--co-indigo);
  box-shadow: 0 0 0 3px rgba(99,102,241,.1);
}
.co-pkg-hint {
  font-size: 12.5px;
  color: var(--co-slate-400);
  margin-top: -4px;
}
`;

export default function PackageSelectorSetup( {
	tiers = [],
	addons = [],
	currency = 'GBP',
	discountPct = 0,
	vatPct = 0,
	onUpdate,
} ) {
	injectStyles( 'co-pricing-styles', PRICING_CSS );
	injectStyles( 'co-pkg-styles', CSS );

	const symbol = SYMBOLS[ currency ] || '£';

	function emit( patch ) {
		onUpdate( {
			tiers,
			addons,
			discount_pct: discountPct,
			vat_pct: vatPct,
			...patch,
		} );
	}

	// ── Tiers ────────────────────────────────────────────────────────────────
	function addTier() {
		emit( {
			tiers: [
				...tiers,
				{ id: uid( 'tier' ), name: `Tier ${ tiers.length + 1 }`, line_items: [] },
			],
		} );
	}

	function updateTier( id, patch ) {
		emit( { tiers: tiers.map( t => t.id === id ? { ...t, ...patch } : t ) } );
	}

	function removeTier( id ) {
		emit( { tiers: tiers.filter( t => t.id !== id ) } );
	}

	// ── Add-ons ──────────────────────────────────────────────────────────────
	function addAddon() {
		emit( { addons: [ ...addons, { id: uid( 'addon' ), description: '', unit_price: '' } ] } );
	}

	function updateAddon( id, field, value ) {
		emit( { addons: addons.map( a => a.id === id ? { ...a, [ field ]: value } : a ) } );
	}

	function removeAddon( id ) {
		emit( { addons: addons.filter( a => a.id !== id ) } );
	}

	// ── Calculations (addons-total range shown as a hint, not a fixed total) ──
	const addonsTotal = addons.reduce( ( sum, a ) => sum + ( parseFloat( a.unit_price ) || 0 ), 0 );
	const tierTotals  = tiers.map( t => lineItemsTotal( t.line_items || [] ) );
	const cheapest    = tierTotals.length ? Math.min( ...tierTotals ) : 0;
	const priciest    = tierTotals.length ? Math.max( ...tierTotals ) : 0;

	const discountAmt = cheapest * ( ( parseFloat( discountPct ) || 0 ) / 100 );
	const startingAt  = ( cheapest - discountAmt ) * ( 1 + ( ( parseFloat( vatPct ) || 0 ) / 100 ) );

	return (
		<div className="co-pkg-wrap">

			{/* Tiers */ }
			<div>
				<div className="co-pkg-section-label">Pricing Tiers</div>
				<div className="co-pkg-tiers">
					{ tiers.length === 0 && (
						<div className="co-pkg-empty">No tiers yet — add one to get started.</div>
					) }
					{ tiers.map( ( tier ) => (
						<div key={ tier.id } className="co-pkg-tier">
							<div className="co-pkg-tier-head">
								<input
									type="text"
									className="co-pkg-tier-name"
									placeholder="Tier name"
									value={ tier.name }
									onChange={ e => updateTier( tier.id, { name: e.target.value } ) }
								/>
								<div className="co-pkg-tier-total">
									{ fmt( lineItemsTotal( tier.line_items || [] ), symbol ) }
								</div>
								<button
									type="button"
									className="co-pkg-tier-del"
									onClick={ () => removeTier( tier.id ) }
									aria-label="Remove tier"
								>
									<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
										<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
									</svg>
								</button>
							</div>

							<LineItemRows
								items={ tier.line_items || [] }
								currency={ currency }
								onChange={ newItems => updateTier( tier.id, { line_items: newItems } ) }
							/>
						</div>
					) ) }
				</div>

				<button type="button" className="co-pkg-add-tier" onClick={ addTier } style={ { marginTop: 14 } }>
					<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
					</svg>
					Add Tier
				</button>
			</div>

			{/* Add-ons */ }
			<div>
				<div className="co-pkg-section-label">Add-ons (optional)</div>
				<div className="co-pkg-addons">
					{ addons.length === 0 && (
						<div className="co-pkg-empty">No add-ons yet — clients will only choose a tier.</div>
					) }
					{ addons.map( addon => (
						<div key={ addon.id } className="co-pkg-addon-row">
							<input
								type="text"
								className="co-pricing-input"
								placeholder="Add-on description"
								value={ addon.description }
								onChange={ e => updateAddon( addon.id, 'description', e.target.value ) }
							/>
							<input
								type="number"
								className="co-pricing-input num"
								placeholder={ `${ symbol }0.00` }
								min="0"
								step="0.01"
								value={ addon.unit_price }
								onChange={ e => updateAddon( addon.id, 'unit_price', e.target.value ) }
								onWheel={ e => e.currentTarget.blur() }
							/>
							<button
								type="button"
								className="co-pricing-del"
								onClick={ () => removeAddon( addon.id ) }
								aria-label="Remove add-on"
							>
								<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
									<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
								</svg>
							</button>
						</div>
					) ) }
				</div>

				<button type="button" className="co-pricing-add" onClick={ addAddon } style={ { marginTop: 12 } }>
					<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
					</svg>
					Add Add-on
				</button>

				{ addons.length > 0 && (
					<div className="co-pkg-hint">
						Add-ons total up to { fmt( addonsTotal, symbol ) } if a client selects every one.
					</div>
				) }
			</div>

			{/* Global modifiers + starting-at total */ }
			<div className="co-pkg-footer">
				<div className="co-pkg-totals">
					<div className="co-pkg-total-row">
						<span className="label">Cheapest tier</span>
						<span className="value">{ fmt( cheapest, symbol ) }</span>
					</div>
					{ priciest !== cheapest && (
						<div className="co-pkg-total-row">
							<span className="label">Priciest tier</span>
							<span className="value">{ fmt( priciest, symbol ) }</span>
						</div>
					) }

					<div className="co-pkg-total-row">
						<span className="label">Discount</span>
						<span style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
							<input
								type="number"
								className="co-pkg-mod-input"
								min="0" max="100" step="1"
								value={ discountPct }
								onChange={ e => emit( { discount_pct: parseFloat( e.target.value ) || 0 } ) }
								onWheel={ e => e.currentTarget.blur() }
							/>
							<span style={ { fontSize: 13, color: 'var(--co-slate-500)' } }>%</span>
						</span>
					</div>

					<div className="co-pkg-total-row">
						<span className="label">VAT</span>
						<span style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
							<input
								type="number"
								className="co-pkg-mod-input"
								min="0" max="100" step="1"
								value={ vatPct }
								onChange={ e => emit( { vat_pct: parseFloat( e.target.value ) || 0 } ) }
								onWheel={ e => e.currentTarget.blur() }
							/>
							<span style={ { fontSize: 13, color: 'var(--co-slate-500)' } }>%</span>
						</span>
					</div>

					<div className="co-pkg-total-divider" />

					<div className="co-pkg-total-row grand">
						<span className="label">Starting at</span>
						<span className="value">{ fmt( startingAt, symbol ) }</span>
					</div>
				</div>
			</div>
		</div>
	);
}
