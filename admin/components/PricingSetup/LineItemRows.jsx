/**
 * LineItemRows
 *
 * The reusable add/remove/reorder line-item row editor extracted from
 * PricingSetup, so it can be used both for flat pricing (one list) and
 * the Package Selector's per-tier line items (one list per tier) without
 * duplicating the row markup.
 *
 * Props:
 *   items    {array}  — [{ id, description, qty, unit_price }]
 *   currency {string} — 'GBP' | 'USD' | 'EUR'
 *   onChange {fn}     — onChange(newItems)
 */
import { SYMBOLS } from '../../../shared/currency';

function uid() {
	return Math.random().toString( 36 ).slice( 2 );
}

function fmt( amount, symbol ) {
	return `${ symbol }${ Number( amount ).toFixed( 2 ) }`;
}

export default function LineItemRows( { items = [], currency = 'GBP', onChange } ) {
	const symbol = SYMBOLS[ currency ] || '£';

	function updateRow( id, field, value ) {
		onChange( items.map( row => row.id === id ? { ...row, [ field ]: value } : row ) );
	}

	function addRow() {
		onChange( [ ...items, { id: uid(), description: '', qty: 1, unit_price: '' } ] );
	}

	function removeRow( id ) {
		onChange( items.filter( r => r.id !== id ) );
	}

	function moveRow( id, dir ) {
		const idx = items.findIndex( r => r.id === id );
		if ( idx === -1 ) return;
		const next = idx + dir;
		if ( next < 0 || next >= items.length ) return;
		const arr = [ ...items ];
		[ arr[ idx ], arr[ next ] ] = [ arr[ next ], arr[ idx ] ];
		onChange( arr );
	}

	return (
		<div className="co-pricing-wrap">
			<div className="co-pricing-headers">
				<div className="co-pricing-header-cell" />
				<div className="co-pricing-header-cell">Description</div>
				<div className="co-pricing-header-cell" style={ { textAlign: 'center' } }>Qty</div>
				<div className="co-pricing-header-cell" style={ { textAlign: 'right' } }>Unit Price</div>
				<div className="co-pricing-header-cell" style={ { textAlign: 'right' } }>Total</div>
				<div className="co-pricing-header-cell" />
			</div>

			<div className="co-pricing-rows">
				{ items.length === 0 && (
					<div className="co-pricing-empty">No line items yet — add one below.</div>
				) }
				{ items.map( ( row, idx ) => {
					const rowTotal = ( parseFloat( row.qty ) || 0 ) * ( parseFloat( row.unit_price ) || 0 );
					return (
						<div key={ row.id } className="co-pricing-row">
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

							<input
								type="text"
								className="co-pricing-input"
								placeholder="Service description"
								value={ row.description }
								onChange={ ( e ) => updateRow( row.id, 'description', e.target.value ) }
							/>

							<input
								type="number"
								className="co-pricing-input num"
								placeholder="1"
								min="0"
								step="0.5"
								value={ row.qty }
								onChange={ ( e ) => updateRow( row.id, 'qty', e.target.value ) }
								onWheel={ e => e.currentTarget.blur() }
							/>

							<input
								type="number"
								className="co-pricing-input num"
								placeholder={ `${ symbol }0.00` }
								min="0"
								step="0.01"
								value={ row.unit_price }
								onChange={ ( e ) => updateRow( row.id, 'unit_price', e.target.value ) }
								onWheel={ e => e.currentTarget.blur() }
							/>

							<div className="co-pricing-row-total">
								{ fmt( rowTotal, symbol ) }
							</div>

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

			<button type="button" className="co-pricing-add" onClick={ addRow }>
				<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
					<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
				</svg>
				Add Line Item
			</button>
		</div>
	);
}

export function lineItemsTotal( items ) {
	return items.reduce( ( sum, row ) => {
		const qty   = parseFloat( row.qty ) || 0;
		const price = parseFloat( row.unit_price ) || 0;
		return sum + qty * price;
	}, 0 );
}
