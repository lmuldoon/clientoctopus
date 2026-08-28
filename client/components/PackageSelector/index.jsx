const { useState, useEffect } = wp.element;
import { injectStyles } from '../../../shared/injectStyles';
import { getReadableOnWhite } from '../../../shared/colors';
import { fmt } from '../../../shared/currency';

/**
 * PackageSelector
 *
 * Client-facing tier + add-on picker for Package Selector proposals.
 * Renders instead of ClientPricingTable while the proposal hasn't been
 * accepted yet. The client picks one tier and toggles any add-ons; the
 * total recalculates live. The actual price is always resolved and
 * validated server-side at accept time — this component only reflects
 * what will be charged, it never determines it.
 *
 * Props:
 *   packages     {object}  — { tiers: [...], addons: [...] }
 *   discountPct  {number}
 *   vatPct       {number}
 *   currency     {string}
 *   onChange     {fn}      — onChange({ selectedTierId, selectedAddonIds })
 */

const CSS = `
.cps-wrap { margin: 40px 0 88px; }

.cps-label {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 10.5px;
	font-weight: 700;
	letter-spacing: 0.14em;
	text-transform: uppercase;
	color: #9CA3AF;
	margin-bottom: 16px;
}

.cps-tiers {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 16px;
	margin-bottom: 28px;
}

.cps-tier {
	position: relative;
	border: 1.5px solid #EAECEF;
	border-radius: 12px;
	padding: 20px;
	cursor: pointer;
	transition: border-color .15s, box-shadow .15s, transform .12s;
	background: #fff;
}
.cps-tier:hover { border-color: #C7D2FE; }
.cps-tier.selected {
	border-color: var(--cps-accent, #6366F1);
	box-shadow: 0 0 0 3px var(--cps-accent-ring, rgba(99,102,241,.12));
}

.cps-tier-radio {
	position: absolute;
	top: 18px;
	right: 18px;
	width: 18px;
	height: 18px;
	border-radius: 50%;
	border: 2px solid #D1D5DB;
	transition: border-color .15s, background .15s;
	flex-shrink: 0;
}
.cps-tier.selected .cps-tier-radio {
	border-color: var(--cps-accent, #6366F1);
	background: var(--cps-accent, #6366F1);
	box-shadow: inset 0 0 0 3px #fff;
}

.cps-tier-name {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 15px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 28px 4px 0;
}

.cps-tier-price {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 24px;
	font-weight: 700;
	color: #1A1A2E;
	margin-bottom: 14px;
	letter-spacing: -.5px;
}

.cps-tier-items { display: flex; flex-direction: column; gap: 6px; }
.cps-tier-item {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 12.5px;
	color: #6B7280;
	line-height: 1.4;
}
.cps-tier-item span:last-child { flex-shrink: 0; color: #9CA3AF; }

/* Add-ons */
.cps-addons { margin-bottom: 28px; }
.cps-addon {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 13px 16px;
	border: 1.5px solid #EAECEF;
	border-radius: 10px;
	margin-bottom: 8px;
	cursor: pointer;
	transition: border-color .15s, background .15s;
}
.cps-addon:hover { border-color: #C7D2FE; }
.cps-addon.checked { border-color: var(--cps-accent, #6366F1); background: var(--cps-accent-bg, #EEF2FF); }
.cps-addon-check {
	width: 18px;
	height: 18px;
	border-radius: 5px;
	border: 2px solid #D1D5DB;
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: border-color .15s, background .15s;
}
.cps-addon.checked .cps-addon-check {
	border-color: var(--cps-accent, #6366F1);
	background: var(--cps-accent, #6366F1);
}
.cps-addon-check svg { width: 11px; height: 11px; stroke: #fff; stroke-width: 3; opacity: 0; transition: opacity .1s; }
.cps-addon.checked .cps-addon-check svg { opacity: 1; }
.cps-addon-desc {
	flex: 1;
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 13.5px;
	font-weight: 500;
	color: #1A1A2E;
}
.cps-addon-price {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 13.5px;
	font-weight: 600;
	color: #374151;
}

/* Totals — mirrors ClientPricingTable */
.cps-totals { display: flex; justify-content: flex-end; margin-top: 20px; }
.cps-totals-inner { width: 300px; display: flex; flex-direction: column; gap: 0; }
.cps-total-row { display: flex; justify-content: space-between; align-items: baseline; padding: 7px 0; }
.cps-total-lbl { font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif; font-size: 13px; color: #6B7280; }
.cps-total-val { font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif; font-size: 13.5px; font-weight: 600; color: #374151; }
.cps-total-row--disc .cps-total-lbl, .cps-total-row--disc .cps-total-val { color: #0F9D6E; }
.cps-grand {
	display: flex; justify-content: space-between; align-items: center;
	margin-top: 10px; padding-top: 14px; border-top: 2px solid #1A1A2E;
}
.cps-grand-lbl { font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif; font-size: 14px; font-weight: 700; color: #1A1A2E; letter-spacing: 0.02em; }
.cps-grand-val { font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif; font-size: 30px; font-weight: 700; color: #6366F1; letter-spacing: -0.5px; line-height: 1; }
.cps-recurring-note { margin-top: 8px; text-align: right; font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif; font-size: 12.5px; color: #9CA3AF; font-style: italic; }

@media (max-width: 600px) {
	.cps-tiers { grid-template-columns: 1fr; }
	.cps-totals-inner { width: 100%; }
}
`;

function tierTotal( tier ) {
	return ( tier.line_items || [] ).reduce(
		( s, r ) => s + ( parseFloat( r.qty ) || 0 ) * ( parseFloat( r.unit_price ) || 0 ), 0
	);
}

export default function PackageSelector( { packages = { tiers: [], addons: [] }, discountPct = 0, vatPct = 0, currency = 'GBP', recurring, onChange } ) {
	injectStyles( 'co-pkgsel-s', CSS );

	const tiers  = packages.tiers  || [];
	const addons = packages.addons || [];

	const [ selectedTierId, setSelectedTierId ] = useState( tiers[ 0 ]?.id || null );
	const [ selectedAddonIds, setSelectedAddonIds ] = useState( [] );

	useEffect( () => {
		onChange?.( { selectedTierId, selectedAddonIds } );
	}, [ selectedTierId, selectedAddonIds ] );

	if ( ! tiers.length ) return null;

	const brandColor = window.clientoctopusClientData?.brandColor;
	const accent     = getReadableOnWhite( brandColor, '#6366F1' );

	function toggleAddon( id ) {
		setSelectedAddonIds( ids => ids.includes( id ) ? ids.filter( a => a !== id ) : [ ...ids, id ] );
	}

	const selectedTier = tiers.find( t => t.id === selectedTierId ) || tiers[ 0 ];
	const tierSubtotal = tierTotal( selectedTier );
	const addonsSubtotal = addons
		.filter( a => selectedAddonIds.includes( a.id ) )
		.reduce( ( s, a ) => s + ( parseFloat( a.unit_price ) || 0 ), 0 );

	const subtotal    = tierSubtotal + addonsSubtotal;
	const discountAmt = subtotal * ( discountPct / 100 );
	const afterDisc   = subtotal - discountAmt;
	const vatAmt      = afterDisc * ( vatPct / 100 );
	const grandTotal  = afterDisc + vatAmt;

	return (
		<div className="cps-wrap" style={ { '--cps-accent': accent } }>
			<div className="cps-label">Choose Your Package</div>

			<div className="cps-tiers">
				{ tiers.map( tier => (
					<div
						key={ tier.id }
						className={ `cps-tier${ tier.id === selectedTierId ? ' selected' : '' }` }
						onClick={ () => setSelectedTierId( tier.id ) }
						role="radio"
						aria-checked={ tier.id === selectedTierId }
						tabIndex={ 0 }
						onKeyDown={ e => { if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); setSelectedTierId( tier.id ); } } }
					>
						<div className="cps-tier-radio" />
						<div className="cps-tier-name">{ tier.name || 'Untitled tier' }</div>
						<div className="cps-tier-price">{ fmt( tierTotal( tier ), currency ) }</div>
						<div className="cps-tier-items">
							{ ( tier.line_items || [] ).map( item => (
								<div key={ item.id } className="cps-tier-item">
									<span>{ item.description || '—' }</span>
									<span>{ fmt( ( parseFloat( item.qty ) || 0 ) * ( parseFloat( item.unit_price ) || 0 ), currency ) }</span>
								</div>
							) ) }
						</div>
					</div>
				) ) }
			</div>

			{ addons.length > 0 && (
				<div className="cps-addons">
					<div className="cps-label">Add-ons (optional)</div>
					{ addons.map( addon => {
						const checked = selectedAddonIds.includes( addon.id );
						return (
							<div
								key={ addon.id }
								className={ `cps-addon${ checked ? ' checked' : '' }` }
								onClick={ () => toggleAddon( addon.id ) }
								role="checkbox"
								aria-checked={ checked }
								tabIndex={ 0 }
								onKeyDown={ e => { if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); toggleAddon( addon.id ); } } }
							>
								<div className="cps-addon-check">
									<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
								</div>
								<div className="cps-addon-desc">{ addon.description || '—' }</div>
								<div className="cps-addon-price">{ fmt( parseFloat( addon.unit_price ) || 0, currency ) }</div>
							</div>
						);
					} ) }
				</div>
			) }

			<div className="cps-totals">
				<div className="cps-totals-inner">
					<div className="cps-total-row">
						<span className="cps-total-lbl">Subtotal</span>
						<span className="cps-total-val">{ fmt( subtotal, currency ) }</span>
					</div>

					{ discountPct > 0 && (
						<div className="cps-total-row cps-total-row--disc">
							<span className="cps-total-lbl">Discount ({ discountPct }%)</span>
							<span className="cps-total-val">−{ fmt( discountAmt, currency ) }</span>
						</div>
					) }

					{ vatPct > 0 && (
						<div className="cps-total-row">
							<span className="cps-total-lbl">VAT ({ vatPct }%)</span>
							<span className="cps-total-val">{ fmt( vatAmt, currency ) }</span>
						</div>
					) }

					<div className="cps-grand">
						<span className="cps-grand-lbl">{ recurring?.enabled ? 'Billed Per Cycle' : 'Total Due' }</span>
						<span className="cps-grand-val" style={ { color: accent } }>{ fmt( grandTotal, currency ) }</span>
					</div>

					{ recurring?.enabled && (
						<div className="cps-recurring-note">
							Billed { recurring.frequency || 'monthly' }
							{ recurring.start_date && `, starting ${ new Date( recurring.start_date ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' } ) }` }
						</div>
					) }
				</div>
			</div>
		</div>
	);
}
