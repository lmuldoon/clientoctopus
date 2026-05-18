/**
 * ClientDetailsForm
 *
 * Step 2 of the proposal wizard. Collects client name, email,
 * company, and phone with floating labels and inline validation.
 *
 * Props:
 *   values   {object}  — { name, email, company, phone }
 *   onChange {fn}      — onChange(field, value)
 *   errors   {object}  — { name?: string, email?: string, ... }
 */
import { useState } from '@wordpress/element';

const CSS = `
.co-cdf-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}
@media (max-width: 640px) { .co-cdf-grid { grid-template-columns: 1fr; } }

.co-cdf-field {
  position: relative;
}
.co-cdf-label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: var(--co-slate-500);
  margin-bottom: 6px;
  letter-spacing: .03em;
  text-transform: uppercase;
}
.co-cdf-req {
  color: var(--co-indigo);
  margin-left: 2px;
}
.co-cdf-input {
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
}
.co-cdf-input::placeholder { color: var(--co-slate-300); }
.co-cdf-input:focus {
  border-color: var(--co-indigo);
  box-shadow: var(--co-input-focus);
}
.co-cdf-input.co-cdf-error {
  border-color: var(--co-red);
  box-shadow: 0 0 0 3px rgba(239,68,68,.1);
}
.co-cdf-err-msg {
  display: flex;
  align-items: center;
  gap: 5px;
  margin-top: 5px;
  font-size: 12px;
  color: var(--co-red);
  font-weight: 500;
}
.co-cdf-err-msg svg {
  width: 13px; height: 13px;
  stroke: currentColor;
  flex-shrink: 0;
}
.co-cdf-hint {
  margin-top: 5px;
  font-size: 12px;
  color: var(--co-slate-400);
}
`;

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
}

function Field( { label, name, type = 'text', required, placeholder, value, onChange, error, hint } ) {
	const [ focused, setFocused ] = useState( false );
	const hasError = !! error;

	return (
		<div className="co-cdf-field">
			<label className="co-cdf-label" htmlFor={ `co-field-${ name }` }>
				{ label }
				{ required && <span className="co-cdf-req" aria-hidden="true"> *</span> }
			</label>
			<input
				id={ `co-field-${ name }` }
				type={ type }
				className={ [ 'co-cdf-input', hasError ? 'co-cdf-error' : '' ].join( ' ' ) }
				placeholder={ focused ? placeholder || '' : placeholder || `Enter ${ label.toLowerCase() }` }
				value={ value || '' }
				onChange={ ( e ) => onChange( name, e.target.value ) }
				onFocus={ () => setFocused( true ) }
				onBlur={ () => setFocused( false ) }
				aria-required={ required }
				aria-describedby={ hasError ? `co-err-${ name }` : undefined }
				aria-invalid={ hasError }
			/>
			{ hasError && (
				<div className="co-cdf-err-msg" id={ `co-err-${ name }` } role="alert">
					<svg viewBox="0 0 24 24" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
						<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
					</svg>
					{ error }
				</div>
			) }
			{ hint && ! hasError && <div className="co-cdf-hint">{ hint }</div> }
		</div>
	);
}

export default function ClientDetailsForm( { values = {}, onChange, errors = {} } ) {
	injectStyles( 'co-cdf-styles', CSS );

	return (
		<div className="co-cdf-grid">
			<Field
				label="Client Name"
				name="name"
				required
				value={ values.name }
				onChange={ onChange }
				error={ errors.name }
				placeholder="Jane Smith"
			/>
			<Field
				label="Email Address"
				name="email"
				type="email"
				required
				value={ values.email }
				onChange={ onChange }
				error={ errors.email }
				placeholder="jane@company.com"
			/>
			<Field
				label="Company"
				name="company"
				value={ values.company }
				onChange={ onChange }
				error={ errors.company }
				placeholder="Acme Ltd"
			/>
			<Field
				label="Phone"
				name="phone"
				type="tel"
				value={ values.phone }
				onChange={ onChange }
				error={ errors.phone }
				placeholder="+44 7700 900000"
				hint="Optional — used for proposal cover"
			/>
		</div>
	);
}
