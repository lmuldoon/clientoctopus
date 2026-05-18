/**
 * ProposalSettings
 *
 * Step 3 of the proposal wizard. Title, currency, expiry date,
 * and optional deposit configuration.
 *
 * Props:
 *   values   {object}  — { title, currency, expiry_date, deposit_pct, require_deposit }
 *   onChange {fn}      — onChange(field, value)
 *   errors   {object}
 */
import { useState } from '@wordpress/element';

const CURRENCIES = [
	{ value: 'GBP', label: '£ GBP — British Pound' },
	{ value: 'USD', label: '$ USD — US Dollar' },
	{ value: 'EUR', label: '€ EUR — Euro' },
	{ value: 'CAD', label: '$ CAD — Canadian Dollar' },
	{ value: 'AUD', label: '$ AUD — Australian Dollar' },
];

const CSS = `
.co-ps-wrap { display: flex; flex-direction: column; gap: 20px; }

/* Label */
.co-ps-label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: var(--co-slate-500);
  margin-bottom: 6px;
  letter-spacing: .03em;
  text-transform: uppercase;
}
.co-ps-req { color: var(--co-indigo); margin-left: 2px; }

/* Input shared */
.co-ps-input, .co-ps-select {
  width: 100%;
  padding: 11px 14px;
  border: var(--co-input-border);
  border-radius: var(--co-radius-sm);
  font-size: 14px;
  font-family: var(--co-font);
  color: var(--co-slate-800);
  background: var(--co-white);
  transition: border-color .15s, box-shadow .15s;
  outline: none;
  -webkit-appearance: none;
  appearance: none;
}
.co-ps-input::placeholder { color: var(--co-slate-300); }
.co-ps-input:focus, .co-ps-select:focus {
  border-color: var(--co-indigo);
  box-shadow: var(--co-input-focus);
}
.co-ps-input.co-ps-lg { font-size: 16px; font-weight: 500; padding: 13px 16px; }
.co-ps-input.co-ps-error, .co-ps-select.co-ps-error {
  border-color: var(--co-red);
  box-shadow: 0 0 0 3px rgba(239,68,68,.1);
}
.co-ps-err { font-size: 12px; color: var(--co-red); margin-top: 5px; font-weight: 500; }

/* Select wrapper */
.co-ps-select-wrap {
  position: relative;
}
.co-ps-select-wrap select {
max-width:unset;
}
// .co-ps-select-wrap::after {
//   content: '';
//   position: absolute;
//   right: 14px; top: 50%;
//   transform: translateY(-50%);
//   width: 0; height: 0;
//   border-left: 5px solid transparent;
//   border-right: 5px solid transparent;
//   border-top: 5px solid var(--co-slate-400);
//   pointer-events: none;
// }

/* 2-col row */
.co-ps-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
@media (max-width: 640px) { .co-ps-row { grid-template-columns: 1fr; } }

/* Divider */
.co-ps-divider {
  height: 1px;
  background: var(--co-slate-100);
  margin: 4px 0;
}

/* Section header */
.co-ps-section {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.co-ps-section-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--co-slate-700);
}
.co-ps-section-sub {
  font-size: 12px;
  color: var(--co-slate-400);
  margin-top: 2px;
}

/* Toggle */
.co-ps-toggle {
  position: relative;
  width: 42px; height: 24px;
  flex-shrink: 0;
  cursor: pointer;
}
.co-ps-toggle input {
  opacity: 0; width: 0; height: 0; position: absolute;
}
.co-ps-toggle-track {
  position: absolute; inset: 0;
  border-radius: 999px;
  background: var(--co-slate-200);
  transition: background .2s;
}
.co-ps-toggle input:checked + .co-ps-toggle-track {
  background: var(--co-indigo);
}
.co-ps-toggle-thumb {
  position: absolute;
  top: 3px; left: 3px;
  width: 18px; height: 18px;
  border-radius: 50%;
  background: white;
  box-shadow: 0 1px 4px rgba(0,0,0,.2);
  transition: transform .2s cubic-bezier(.34,1.56,.64,1);
}
.co-ps-toggle input:checked ~ .co-ps-toggle-thumb {
  transform: translateX(18px);
}
.co-ps-toggle:focus-within .co-ps-toggle-track {
  box-shadow: 0 0 0 3px rgba(99,102,241,.2);
}

/* Deposit reveal */
.co-ps-deposit-section {
  overflow: hidden;
  transition: max-height .3s ease, opacity .3s ease;
}
.co-ps-deposit-section.hidden {
  max-height: 0;
  opacity: 0;
}
.co-ps-deposit-section.visible {
  max-height: 120px;
  opacity: 1;
}

/* Slider */
.co-ps-slider-row {
  display: flex;
  align-items: center;
  gap: 14px;
}
.co-ps-slider {
  flex: 1;
  -webkit-appearance: none;
  appearance: none;
  height: 6px;
  border-radius: 999px;
  background: var(--co-slate-200);
  outline: none;
  cursor: pointer;
}
.co-ps-slider::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 20px; height: 20px;
  border-radius: 50%;
  background: var(--co-indigo);
  box-shadow: 0 0 0 3px rgba(99,102,241,.2);
  cursor: grab;
  transition: box-shadow .15s;
}
.co-ps-slider::-webkit-slider-thumb:active { cursor: grabbing; }
.co-ps-slider:focus::-webkit-slider-thumb { box-shadow: 0 0 0 5px rgba(99,102,241,.3); }
.co-ps-slider-num {
  width: 70px;
  padding: 8px 12px;
  border: var(--co-input-border);
  border-radius: var(--co-radius-sm);
  font-size: 14px;
  font-weight: 600;
  text-align: center;
  font-family: var(--co-font);
  color: var(--co-slate-800);
  outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.co-ps-slider-num:focus {
  border-color: var(--co-indigo);
  box-shadow: var(--co-input-focus);
}
.co-ps-pct-label {
  font-size: 13px;
  color: var(--co-slate-500);
  font-weight: 500;
}
`;

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
}

// Get today + 30 days as default expiry
function defaultExpiry() {
	const d = new Date();
	d.setDate( d.getDate() + 30 );
	return d.toISOString().split( 'T' )[ 0 ];
}

export default function ProposalSettings( { values = {}, onChange, errors = {} } ) {
	injectStyles( 'co-ps-styles', CSS );

	const depositPct      = parseInt( values.deposit_pct ?? 25, 10 );
	const requireDeposit  = !! values.require_deposit;

	function handleDepositSlider( e ) {
		const v = parseInt( e.target.value, 10 );
		onChange( 'deposit_pct', v );
	}

	function handleDepositNum( e ) {
		const v = Math.max( 0, Math.min( 100, parseInt( e.target.value, 10 ) || 0 ) );
		onChange( 'deposit_pct', v );
	}

	return (
		<div className="co-ps-wrap">
			{/* Title */ }
			<div>
				<label className="co-ps-label" htmlFor="co-ps-title">
					Proposal Title <span className="co-ps-req">*</span>
				</label>
				<input
					id="co-ps-title"
					type="text"
					className={ `co-ps-input co-ps-lg${ errors.title ? ' co-ps-error' : '' }` }
					placeholder="e.g. Website Redesign for Acme Ltd"
					value={ values.title || '' }
					onChange={ ( e ) => onChange( 'title', e.target.value ) }
				/>
				{ errors.title && <div className="co-ps-err">{ errors.title }</div> }
			</div>

			{/* Currency + Expiry row */ }
			<div className="co-ps-row">
				<div>
					<label className="co-ps-label" htmlFor="co-ps-currency">Currency</label>
					<div className="co-ps-select-wrap">
						<select
							id="co-ps-currency"
							className="co-ps-select"
							value={ values.currency || 'GBP' }
							onChange={ ( e ) => onChange( 'currency', e.target.value ) }
							style={ { paddingRight: 36 } }
						>
							{ CURRENCIES.map( c => (
								<option key={ c.value } value={ c.value }>{ c.label }</option>
							) ) }
						</select>
					</div>
				</div>
				<div>
					<label className="co-ps-label" htmlFor="co-ps-expiry">Expiry Date</label>
					<input
						id="co-ps-expiry"
						type="date"
						className={ `co-ps-input${ errors.expiry_date ? ' co-ps-error' : '' }` }
						value={ values.expiry_date || defaultExpiry() }
						min={ new Date().toISOString().split( 'T' )[ 0 ] }
						onChange={ ( e ) => onChange( 'expiry_date', e.target.value ) }
					/>
					{ errors.expiry_date && <div className="co-ps-err">{ errors.expiry_date }</div> }
				</div>
			</div>

			<div className="co-ps-divider" />

			{/* Payment options */ }
			<div>
				<div className="co-ps-section">
					<div>
						<div className="co-ps-section-title">Require Deposit</div>
						<div className="co-ps-section-sub">Client must pay a deposit before work begins</div>
					</div>
					<label className="co-ps-toggle" aria-label="Require deposit">
						<input
							type="checkbox"
							checked={ requireDeposit }
							onChange={ ( e ) => onChange( 'require_deposit', e.target.checked ) }
						/>
						<div className="co-ps-toggle-track" />
						<div className="co-ps-toggle-thumb" />
					</label>
				</div>

				<div className={ `co-ps-deposit-section ${ requireDeposit ? 'visible' : 'hidden' }` }
					style={ { marginTop: requireDeposit ? 16 : 0 } }>
					<label className="co-ps-label">Deposit Percentage</label>
					<div className="co-ps-slider-row">
						<input
							type="range"
							className="co-ps-slider"
							min="5" max="100" step="5"
							value={ depositPct }
							onChange={ handleDepositSlider }
						/>
						<input
							type="number"
							className="co-ps-slider-num"
							min="0" max="100"
							value={ depositPct }
							onChange={ handleDepositNum }
						/>
						<span className="co-ps-pct-label">%</span>
					</div>
				</div>
			</div>
		</div>
	);
}
