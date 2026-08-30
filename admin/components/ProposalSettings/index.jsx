/**
 * ProposalSettings
 *
 * Step 3 of the proposal wizard. Title, currency, expiry date,
 * and either one-off deposit configuration or a recurring billing schedule.
 *
 * Props:
 *   values   {object}  — { title, currency, expiry_date, deposit_pct, require_deposit,
 *                           recurring: { enabled, frequency, start_date, end_date, max_occurrences, payment_terms, notes } }
 *   onChange {fn}      — onChange(field, value)
 *   errors   {object}
 */
import { useState } from '@wordpress/element';
import { injectStyles } from '../../../shared/injectStyles';

const FREQUENCIES = [
	{ value: 'weekly',    label: 'Weekly' },
	{ value: 'monthly',   label: 'Monthly' },
	{ value: 'quarterly', label: 'Quarterly' },
	{ value: 'yearly',    label: 'Yearly' },
];

function today() {
	return new Date().toISOString().split( 'T' )[ 0 ];
}

const DEFAULT_RECURRING = {
	enabled: false,
	frequency: 'monthly',
	start_date: '',
	end_date: '',
	max_occurrences: '',
	payment_terms: '',
	notes: '',
	billing_mode: 'manual',
};

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
input.co-ps-input, select.co-ps-select {
  width: 100%;
  height: 44px;
  box-sizing: border-box;
  padding: 0 14px;
  border: var(--co-input-border);
  border-radius: var(--co-radius-sm);
  font-size: 14px;
  font-family: var(--co-font);
  color: var(--co-slate-800);
  background: var(--co-white);
  line-height: normal;
  transition: border-color .15s, box-shadow .15s;
  outline: none;
  -webkit-appearance: none;
  appearance: none;
}
input.co-ps-input::placeholder { color: var(--co-slate-300); }
input.co-ps-input:focus, select.co-ps-select:focus {
  border-color: var(--co-indigo);
  box-shadow: var(--co-input-focus);
}
input.co-ps-input.co-ps-lg { font-size: 16px; font-weight: 500; padding: 0 16px; }
input.co-ps-input.co-ps-error, select.co-ps-select.co-ps-error {
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
input.co-ps-slider-num {
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
input.co-ps-slider-num:focus {
  border-color: var(--co-indigo);
  box-shadow: var(--co-input-focus);
}
.co-ps-pct-label {
  font-size: 13px;
  color: var(--co-slate-500);
  font-weight: 500;
}

/* Billing type toggle */
.co-ps-billing-toggle {
  display: inline-flex;
  padding: 3px;
  background: var(--co-slate-100);
  border-radius: 9px;
  margin-bottom: 16px;
}
.co-ps-billing-btn {
  padding: 8px 18px;
  border: none;
  border-radius: 7px;
  background: transparent;
  font-size: 13px;
  font-weight: 600;
  font-family: var(--co-font);
  color: var(--co-slate-500);
  cursor: pointer;
  transition: background .15s, color .15s, box-shadow .15s;
}
.co-ps-billing-btn.active {
  background: var(--co-white);
  color: var(--co-slate-800);
  box-shadow: 0 1px 3px rgba(0,0,0,.08);
}

/* Recurring fields */
.co-ps-recurring { display: flex; flex-direction: column; gap: 16px; }
.co-ps-end-options {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.co-ps-end-option {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13.5px;
  color: var(--co-slate-700);
}
.co-ps-end-option input[type="radio"] { accent-color: var(--co-indigo); }
.co-ps-end-option input[type="number"],
.co-ps-end-option input[type="date"] {
  padding: 6px 10px;
  border: var(--co-input-border);
  border-radius: var(--co-radius-sm);
  font-size: 13px;
  font-family: var(--co-font);
  width: 140px;
}
.co-ps-autocharge-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13.5px;
  font-weight: 500;
  color: var(--co-slate-700);
  cursor: pointer;
  margin-top: 4px;
}
.co-ps-autocharge-row input[type="checkbox"] {
  width: 16px; height: 16px; min-width: 16px; min-height: 16px;
  margin: 0; accent-color: var(--co-indigo); cursor: pointer;
}
.co-ps-autocharge-help {
  font-size: 12px;
  color: var(--co-slate-400);
  margin: 4px 0 0;
}
textarea.co-ps-textarea {
  width: 100%;
  min-height: 70px;
  padding: 11px 14px;
  border: var(--co-input-border);
  border-radius: var(--co-radius-sm);
  font-size: 14px;
  font-family: var(--co-font);
  color: var(--co-slate-800);
  background: var(--co-white);
  resize: vertical;
  outline: none;
  transition: border-color .15s, box-shadow .15s;
  box-sizing: border-box;
}
textarea.co-ps-textarea:focus {
  border-color: var(--co-indigo);
  box-shadow: var(--co-input-focus);
}
`;

export default function ProposalSettings( { values = {}, onChange, errors = {} } ) {
	injectStyles( 'co-ps-styles', CSS );

	const depositPct      = parseInt( values.deposit_pct ?? 25, 10 );
	const requireDeposit  = !! values.require_deposit;
	const recurring       = { ...DEFAULT_RECURRING, ...( values.recurring || {} ) };
	const isRecurring     = !! recurring.enabled;

	const [ endMode, setEndMode ] = useState(
		recurring.max_occurrences ? 'count' : ( recurring.end_date ? 'date' : 'never' )
	);

	function handleDepositSlider( e ) {
		const v = parseInt( e.target.value, 10 );
		onChange( 'deposit_pct', v );
	}

	function handleDepositNum( e ) {
		const v = Math.max( 0, Math.min( 100, parseInt( e.target.value, 10 ) || 0 ) );
		onChange( 'deposit_pct', v );
	}

	function updateRecurring( patch ) {
		onChange( 'recurring', { ...recurring, ...patch } );
	}

	function setBillingType( type ) {
		if ( type === 'recurring' ) {
			updateRecurring( { enabled: true, start_date: recurring.start_date || today() } );
		} else {
			updateRecurring( { enabled: false } );
		}
	}

	function handleEndMode( mode ) {
		setEndMode( mode );
		if ( mode === 'never' ) updateRecurring( { max_occurrences: '', end_date: '' } );
		if ( mode === 'count' ) updateRecurring( { end_date: '' } );
		if ( mode === 'date' )  updateRecurring( { max_occurrences: '' } );
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
					<label className="co-ps-label" htmlFor="co-ps-expiry">
						Expiry Date <span className="co-ps-req">*</span>
					</label>
					<input
						id="co-ps-expiry"
						type="date"
						className={ `co-ps-input${ errors.expiry_date ? ' co-ps-error' : '' }` }
						value={ values.expiry_date || '' }
						min={ new Date().toISOString().split( 'T' )[ 0 ] }
						onChange={ ( e ) => onChange( 'expiry_date', e.target.value ) }
					/>
					{ errors.expiry_date && <div className="co-ps-err">{ errors.expiry_date }</div> }
				</div>
			</div>

			<div className="co-ps-divider" />

			{/* Billing type */ }
			<div>
				<label className="co-ps-label">Billing Type</label>
				<div className="co-ps-billing-toggle">
					<button
						type="button"
						className={ `co-ps-billing-btn${ ! isRecurring ? ' active' : '' }` }
						onClick={ () => setBillingType( 'one-off' ) }
					>
						One-off
					</button>
					<button
						type="button"
						className={ `co-ps-billing-btn${ isRecurring ? ' active' : '' }` }
						onClick={ () => setBillingType( 'recurring' ) }
					>
						Recurring
					</button>
				</div>
			</div>

			{ isRecurring ? (
				<div className="co-ps-recurring">
					<div className="co-ps-row">
						<div>
							<label className="co-ps-label" htmlFor="co-ps-frequency">Frequency</label>
							<div className="co-ps-select-wrap">
								<select
									id="co-ps-frequency"
									className="co-ps-select"
									value={ recurring.frequency }
									onChange={ e => updateRecurring( { frequency: e.target.value } ) }
								>
									{ FREQUENCIES.map( f => (
										<option key={ f.value } value={ f.value }>{ f.label }</option>
									) ) }
								</select>
							</div>
						</div>
						<div>
							<label className="co-ps-label" htmlFor="co-ps-start-date">Start Date</label>
							<input
								id="co-ps-start-date"
								type="date"
								className="co-ps-input"
								value={ recurring.start_date || today() }
								onChange={ e => updateRecurring( { start_date: e.target.value } ) }
							/>
						</div>
					</div>

					<div>
						<label className="co-ps-label">Ends</label>
						<div className="co-ps-end-options">
							<label className="co-ps-end-option">
								<input type="radio" checked={ endMode === 'never' } onChange={ () => handleEndMode( 'never' ) } />
								Never
							</label>
							<label className="co-ps-end-option">
								<input type="radio" checked={ endMode === 'count' } onChange={ () => handleEndMode( 'count' ) } />
								After
								<input
									type="number"
									min="1"
									placeholder="e.g. 12"
									disabled={ endMode !== 'count' }
									value={ recurring.max_occurrences }
									onChange={ e => updateRecurring( { max_occurrences: e.target.value } ) }
									onWheel={ e => e.currentTarget.blur() }
								/>
								invoices
							</label>
							<label className="co-ps-end-option">
								<input type="radio" checked={ endMode === 'date' } onChange={ () => handleEndMode( 'date' ) } />
								On a date
								<input
									type="date"
									disabled={ endMode !== 'date' }
									value={ recurring.end_date }
									onChange={ e => updateRecurring( { end_date: e.target.value } ) }
								/>
							</label>
						</div>
					</div>

					<label className="co-ps-autocharge-row">
						<input
							type="checkbox"
							checked={ recurring.billing_mode === 'auto_charge' }
							onChange={ e => updateRecurring( { billing_mode: e.target.checked ? 'auto_charge' : 'manual' } ) }
						/>
						Auto-charge saved card
					</label>
					{ recurring.billing_mode === 'auto_charge' && (
						<p className="co-ps-autocharge-help">
							The first invoice is paid manually, which saves the client's card — every invoice after that is charged automatically.
						</p>
					) }

					<div>
						<label className="co-ps-label" htmlFor="co-ps-payment-terms">Payment Terms</label>
						<input
							id="co-ps-payment-terms"
							type="text"
							className="co-ps-input"
							placeholder="e.g. Net 14, Due on receipt"
							value={ recurring.payment_terms }
							onChange={ e => updateRecurring( { payment_terms: e.target.value } ) }
						/>
					</div>

					<div>
						<label className="co-ps-label" htmlFor="co-ps-recurring-notes">Notes</label>
						<textarea
							id="co-ps-recurring-notes"
							className="co-ps-textarea"
							placeholder="Bank transfer details, notes, or any additional information shown on generated invoices…"
							value={ recurring.notes }
							onChange={ e => updateRecurring( { notes: e.target.value } ) }
						/>
					</div>
				</div>
			) : (
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
			) }
		</div>
	);
}
