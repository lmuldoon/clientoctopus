/**
 * PortalSetPassword
 *
 * Forced password-setup screen shown after the first magic-link verification.
 * Client cannot proceed to the portal until they set a compliant password.
 */

const { useState, useEffect, useCallback } = wp.element;

const RULES = [
	{ key: 'min_length', label: 'At least 8 characters',    test: p => p.length >= 8 },
	{ key: 'uppercase',  label: 'One uppercase letter (A–Z)', test: p => /[A-Z]/.test( p ) },
	{ key: 'lowercase',  label: 'One lowercase letter (a–z)', test: p => /[a-z]/.test( p ) },
	{ key: 'number',     label: 'One number (0–9)',           test: p => /[0-9]/.test( p ) },
	{ key: 'special',    label: 'One special character',      test: p => /[^A-Za-z0-9]/.test( p ) },
];

injectStyles( 'co-global-s', `@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600;700&family=DM+Mono&display=swap');
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: 'DM Sans', sans-serif; -webkit-font-smoothing: antialiased; background: #F8F7F5; }` );

injectStyles( 'cpsp-s', `
/* ── Page ─────────────────────────────────────────────── */
.cpsp-page {
	min-height: 100vh;
	background: #F8F7F5;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 40px 20px;
}

/* ── Card ──────────────────────────────────────────────── */
.cpsp-card {
	background: #fff;
	border-radius: 20px;
	padding: 52px 48px 44px;
	width: 100%;
	max-width: 460px;
	box-shadow:
		0 2px 4px rgba(26,26,46,.04),
		0 12px 40px rgba(26,26,46,.08);
	animation: cpsp-rise .5s cubic-bezier(.22,1,.36,1) both;
}

@keyframes cpsp-rise {
	from { opacity: 0; transform: translateY(16px); }
	to   { opacity: 1; transform: translateY(0); }
}

/* ── Icon ──────────────────────────────────────────────── */
.cpsp-icon {
	width: 56px;
	height: 56px;
	background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%);
	border-radius: 14px;
	display: flex;
	align-items: center;
	justify-content: center;
	margin-bottom: 24px;
	box-shadow: 0 4px 16px rgba(99,102,241,.3);
}

/* ── Typography ─────────────────────────────────────────── */
.cpsp-heading {
	font-family: 'Playfair Display', serif;
	font-size: 30px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 10px;
	letter-spacing: -0.02em;
	line-height: 1.2;
}

.cpsp-sub {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	color: #6B7280;
	line-height: 1.65;
	margin: 0 0 32px;
}

/* ── Field wrapper ──────────────────────────────────────── */
.cpsp-field {
	margin-bottom: 20px;
}

.cpsp-label {
	display: block;
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-weight: 600;
	color: #374151;
	margin-bottom: 8px;
	letter-spacing: 0.02em;
}

.cpsp-input-wrap {
	position: relative;
}

.cpsp-input {
	display: block;
	width: 100%;
	height: 52px;
	padding: 0 48px 0 16px;
	background: #F8F7F5;
	border: 1.5px solid #E5E7EB;
	border-radius: 10px;
	font-family: 'DM Sans', sans-serif;
	font-size: 15px;
	color: #1A1A2E;
	outline: none;
	transition: border-color .15s, box-shadow .15s;
}

.cpsp-input:focus {
	border-color: #6366F1;
	box-shadow: 0 0 0 3px rgba(99,102,241,.12);
	background: #fff;
}

.cpsp-eye {
	position: absolute;
	right: 14px;
	top: 50%;
	transform: translateY(-50%);
	background: none;
	border: none;
	cursor: pointer;
	color: #9CA3AF;
	padding: 4px;
	display: flex;
	align-items: center;
	transition: color .15s;
}
.cpsp-eye:hover { color: #6366F1; }

/* ── Rules checklist ────────────────────────────────────── */
.cpsp-rules {
	background: #F8F7F5;
	border-radius: 10px;
	padding: 16px 18px;
	margin-bottom: 20px;
	display: flex;
	flex-direction: column;
	gap: 9px;
}

.cpsp-rule {
	display: flex;
	align-items: center;
	gap: 10px;
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	color: #9CA3AF;
	transition: color .2s;
}

.cpsp-rule--met {
	color: #059669;
}

.cpsp-rule-dot {
	width: 18px;
	height: 18px;
	border-radius: 50%;
	border: 1.5px solid #D1D5DB;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	transition: border-color .2s, background .2s;
}

.cpsp-rule--met .cpsp-rule-dot {
	border-color: #10B981;
	background: #10B981;
}

/* ── Mismatch error ──────────────────────────────────────── */
.cpsp-mismatch {
	font-family: 'DM Sans', sans-serif;
	font-size: 12px;
	color: #B91C1C;
	margin-top: 6px;
	animation: cpsp-shake .3s ease both;
}

@keyframes cpsp-shake {
	0%, 100% { transform: translateX(0); }
	25%       { transform: translateX(-4px); }
	75%       { transform: translateX(4px); }
}

/* ── Submit button ───────────────────────────────────────── */
.cpsp-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	width: 100%;
	height: 52px;
	margin-top: 8px;
	background: #6366F1;
	color: #fff;
	border: none;
	border-radius: 10px;
	font-family: 'DM Sans', sans-serif;
	font-size: 15px;
	font-weight: 600;
	cursor: pointer;
	transition: background .15s, transform .15s, box-shadow .15s;
	box-shadow: 0 3px 12px rgba(99,102,241,.3);
	letter-spacing: 0.01em;
}

.cpsp-btn:hover:not(:disabled) {
	background: #4F46E5;
	transform: translateY(-1px);
	box-shadow: 0 5px 18px rgba(99,102,241,.4);
}

.cpsp-btn:disabled {
	opacity: .5;
	cursor: not-allowed;
	transform: none;
	box-shadow: none;
}

/* ── Spinner ─────────────────────────────────────────────── */
.cpsp-spinner {
	width: 18px;
	height: 18px;
	border: 2.5px solid rgba(255,255,255,.4);
	border-top-color: #fff;
	border-radius: 50%;
	animation: cpsp-spin .7s linear infinite;
}
@keyframes cpsp-spin { to { transform: rotate(360deg); } }

/* ── Server error ────────────────────────────────────────── */
.cpsp-error {
	background: #FEF2F2;
	border: 1px solid #FECACA;
	border-radius: 8px;
	padding: 12px 14px;
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	color: #B91C1C;
	margin-top: 16px;
}

/* ── Mobile ──────────────────────────────────────────────── */
@media (max-width: 520px) {
	.cpsp-card    { padding: 36px 24px 32px; }
	.cpsp-heading { font-size: 24px; }
}
` );

const EyeIcon = ( { open } ) => open ? (
	<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
		<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
		<circle cx="12" cy="12" r="3"/>
	</svg>
) : (
	<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
		<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
		<path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
		<line x1="1" y1="1" x2="23" y2="23"/>
	</svg>
);

const CheckIcon = () => (
	<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
		<polyline points="20 6 9 17 4 12"/>
	</svg>
);

export default function PortalSetPassword() {
	const isChange = !! ( window.coPortalData || {} ).hasPassword;

	const [ password,       setPassword      ] = useState( '' );
	const [ confirm,        setConfirm       ] = useState( '' );
	const [ showPass,       setShowPass      ] = useState( false );
	const [ showConfirm,    setShowConfirm   ] = useState( false );
	const [ confirmTouched, setConfirmTouched ] = useState( false );
	const [ submitting,     setSubmitting    ] = useState( false );
	const [ serverError,    setServerError   ] = useState( '' );
	const [ redirectUrl,    setRedirectUrl   ] = useState( '' );

	const ruleStates  = RULES.map( r => ( { ...r, met: r.test( password ) } ) );
	const allRulesMet = ruleStates.every( r => r.met );
	const matches     = password === confirm;
	const canSubmit   = allRulesMet && matches && confirm.length > 0 && ! submitting;

	const handleSubmit = useCallback( async ( e ) => {
		e.preventDefault();
		if ( ! canSubmit ) return;

		setSubmitting( true );
		setServerError( '' );

		try {
			const res = await fetch( window.coPortalData.apiUrl + '/portal/set-password', {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   window.coPortalData.nonce,
				},
				body: JSON.stringify( { password } ),
			} ).then( r => r.json() );

			if ( res.success ) {
				setRedirectUrl( res.redirect_url || '/clientoctopus/dashboard' );
			} else {
				setServerError( res.message || 'Something went wrong. Please try again.' );
				setSubmitting( false );
			}
		} catch {
			setServerError( 'Network error. Please check your connection and try again.' );
			setSubmitting( false );
		}
	}, [ password, canSubmit ] );

	const loginUrl = ( window.location.origin || '' ) + '/clientoctopus/login';

	useEffect( () => {
		if ( ! redirectUrl ) return;
		const t = setTimeout( () => { window.location.href = redirectUrl; }, 3000 );
		return () => clearTimeout( t );
	}, [ redirectUrl ] );

	if ( redirectUrl ) {
		return (
			<div className="cpsp-page">
				<div className="cpsp-card" style={ { textAlign: 'center' } }>
					<div className="cpsp-icon" style={ { background: '#10B981' } }>
						<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
							<polyline points="20 6 9 17 4 12"/>
						</svg>
					</div>
					{ isChange ? (
						<>
							<h1 className="cpsp-heading">Password updated!</h1>
							<p className="cpsp-sub">Your password has been changed successfully.</p>
							<p style={ { fontSize: 13, color: '#9CA3AF', margin: 0 } }>Taking you back to your dashboard…</p>
						</>
					) : (
						<>
							<h1 className="cpsp-heading">Password set!</h1>
							<p className="cpsp-sub">Your portal is ready. Bookmark your login page so you can sign in any time:</p>
							<a
								href={ loginUrl }
								style={ {
									display: 'inline-block',
									marginBottom: 28,
									padding: '10px 20px',
									background: '#EEF2FF',
									color: '#6366F1',
									borderRadius: 8,
									fontSize: 14,
									fontWeight: 600,
									textDecoration: 'none',
									wordBreak: 'break-all',
								} }
							>
								{ loginUrl }
							</a>
							<p style={ { fontSize: 13, color: '#9CA3AF', margin: 0 } }>Taking you to your dashboard…</p>
						</>
					) }
				</div>
			</div>
		);
	}

	return (
		<div className="cpsp-page">
			<div className="cpsp-card">
				<div className="cpsp-icon">
					<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
						<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
						<path d="M7 11V7a5 5 0 0110 0v4"/>
					</svg>
				</div>

				<h1 className="cpsp-heading">{ isChange ? 'Change password' : 'Set your password' }</h1>
				<p className="cpsp-sub">
					{ isChange
						? 'Enter your current password, then choose a new one.'
						: 'Create a password so you can sign in to your portal without needing an email link each time.'
					}
				</p>

				<form onSubmit={ handleSubmit } noValidate>
					{ /* New password field */ }
					<div className="cpsp-field">
						<label className="cpsp-label" htmlFor="cpsp-pass">{ isChange ? 'New password' : 'Password' }</label>
						<div className="cpsp-input-wrap">
							<input
								id="cpsp-pass"
								className="cpsp-input"
								type={ showPass ? 'text' : 'password' }
								value={ password }
								onChange={ e => setPassword( e.target.value ) }
								autoComplete="new-password"
								autoFocus
							/>
							<button
								type="button"
								className="cpsp-eye"
								onClick={ () => setShowPass( v => ! v ) }
								aria-label={ showPass ? 'Hide password' : 'Show password' }
							>
								<EyeIcon open={ showPass } />
							</button>
						</div>
					</div>

					{ /* Requirements checklist */ }
					<div className="cpsp-rules" aria-label="Password requirements">
						{ ruleStates.map( r => (
							<div key={ r.key } className={ `cpsp-rule${ r.met ? ' cpsp-rule--met' : '' }` }>
								<span className="cpsp-rule-dot">
									{ r.met && <CheckIcon /> }
								</span>
								{ r.label }
							</div>
						) ) }
					</div>

					{ /* Confirm field */ }
					<div className="cpsp-field">
						<label className="cpsp-label" htmlFor="cpsp-confirm">Confirm password</label>
						<div className="cpsp-input-wrap">
							<input
								id="cpsp-confirm"
								className="cpsp-input"
								type={ showConfirm ? 'text' : 'password' }
								value={ confirm }
								onChange={ e => { setConfirm( e.target.value ); setConfirmTouched( true ); } }
								autoComplete="new-password"
							/>
							<button
								type="button"
								className="cpsp-eye"
								onClick={ () => setShowConfirm( v => ! v ) }
								aria-label={ showConfirm ? 'Hide password' : 'Show password' }
							>
								<EyeIcon open={ showConfirm } />
							</button>
						</div>
						{ confirmTouched && confirm.length > 0 && ! matches && (
							<p className="cpsp-mismatch">Passwords don&rsquo;t match</p>
						) }
					</div>

					{ serverError && (
						<div className="cpsp-error">{ serverError }</div>
					) }

					<button
						type="submit"
						className="cpsp-btn"
						disabled={ ! canSubmit }
					>
						{ submitting ? (
							<><span className="cpsp-spinner" /> { isChange ? 'Updating…' : 'Setting password…' }</>
						) : (
							isChange ? <>Update password &rarr;</> : <>Set password &amp; continue &rarr;</>
						) }
					</button>
				</form>
			</div>
		</div>
	);
}
