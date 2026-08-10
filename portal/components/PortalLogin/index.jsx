/**
 * PortalLogin
 *
 * Full-page magic link / password login. Centered card on a full branded
 * background with a subtle light-to-dark gradient. Business logo sits above
 * the card. No split panel.
 *
 * Reads: window.coPortalData.businessName, .businessLogo, .brandColor,
 *        .hideBusinessName
 */

const { useState } = wp.element;

const apiFetch = ( path, opts = {} ) =>
	fetch( window.coPortalData.apiUrl + path, {
		headers: {
			'X-WP-Nonce':    window.coPortalData.nonce,
			'Content-Type':  'application/json',
		},
		...opts,
	} ).then( r => r.json() );

injectStyles( 'co-global-s', `
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif; -webkit-font-smoothing: antialiased; }` );

injectStyles( 'cpl-s', `
/* ── Shell ─────────────────────────────────────────────── */
.cpl-shell {
	min-height: 100vh;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 48px 20px;
	position: relative;
}

.cpl-shell::before {
	content: '';
	position: absolute;
	inset: 0;
	background: linear-gradient(to bottom, rgba(255,255,255,0.15) 0%, rgba(0,0,0,0.20) 100%);
	pointer-events: none;
}

/* ── Content wrapper (logo + card stacked) ──────────────── */
.cpl-login-wrap {
	position: relative;
	z-index: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	width: 100%;
	max-width: 460px;
}

/* ── Logo above card ─────────────────────────────────────── */
.cpl-logo-above {
	display: flex;
	flex-direction: column;
	align-items: center;
	margin-bottom: 2rem;
}

.cpl-logo-wrap {
	width: 80px;
	height: 80px;
	background: rgba(255,255,255,.18);
	border-radius: 20px;
	display: flex;
	align-items: center;
	justify-content: center;
	backdrop-filter: blur(8px);
	border: 1px solid rgba(255,255,255,.25);
}

.cpl-logo-wrap.cpl-logo-wrap--image {
	width: auto;
	height: auto;
	background: transparent;
	border: none;
	border-radius: 0;
	backdrop-filter: none;
}

.cpl-logo-wrap.cpl-logo-wrap--image img {
	width: 80%;
	height: auto;
	max-height:200px;
	display: block;
}

.cpl-logo-initials {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 28px;
	font-weight: 700;
	color: #fff;
	letter-spacing: -0.02em;
}

.cpl-brand-name {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 18px;
	font-weight: 600;
	margin: 14px 0 0;
	letter-spacing: -0.01em;
}

/* ── Card ────────────────────────────────────────────────── */
.cpl-card {
	background: #fff;
	border-radius: 20px;
	padding: 48px 48px 40px;
	width: 100%;
	box-shadow:
		0 2px 4px rgba(26,26,46,.06),
		0 16px 48px rgba(26,26,46,.18);
}

/* ── Typography ─────────────────────────────────────────── */
.cpl-heading {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 32px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 10px;
	letter-spacing: -0.02em;
	line-height: 1.15;
	animation: cpl-fade-up .5s ease both;
}

.cpl-sub {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 15px;
	color: #6B7280;
	line-height: 1.65;
	margin: 0 0 28px;
	animation: cpl-fade-up .5s ease .08s both;
}

@keyframes cpl-fade-up {
	from { opacity: 0; transform: translateY(8px); }
	to   { opacity: 1; transform: translateY(0); }
}

/* ── Form ───────────────────────────────────────────────── */
.cpl-label {
	display: block;
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 13px;
	font-weight: 600;
	color: #374151;
	margin-bottom: 8px;
	letter-spacing: 0.02em;
	animation: cpl-fade-up .5s ease .14s both;
}

.cpl-input {
	display: block;
	width: 100%;
	height: 52px;
	padding: 0 16px;
	background: #F8F7F5;
	border: 1.5px solid #E5E7EB;
	border-radius: 10px;
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 15px;
	color: #1A1A2E;
	outline: none;
	transition: border-color .15s, box-shadow .15s;
	animation: cpl-fade-up .5s ease .18s both;
}

.cpl-input:focus {
	border-color: #6366F1;
	box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}

.cpl-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	width: 100%;
	height: 52px;
	margin-top: 20px;
	background: #6366F1;
	color: #fff;
	border: none;
	border-radius: 10px;
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 15px;
	font-weight: 600;
	cursor: pointer;
	transition: background .15s, transform .15s, box-shadow .15s;
	box-shadow: 0 3px 12px rgba(99,102,241,.3);
	letter-spacing: 0.01em;
	animation: cpl-fade-up .5s ease .24s both;
}

.cpl-btn:hover:not(:disabled) {
	background: #4F46E5;
	transform: translateY(-1px);
	box-shadow: 0 5px 18px rgba(99,102,241,.4);
}

.cpl-btn:disabled {
	opacity: .7;
	cursor: not-allowed;
}

/* ── Spinner ─────────────────────────────────────────────── */
.cpl-spinner {
	width: 18px;
	height: 18px;
	border: 2.5px solid rgba(255,255,255,.4);
	border-top-color: #fff;
	border-radius: 50%;
	animation: cpl-spin .7s linear infinite;
	flex-shrink: 0;
}
@keyframes cpl-spin { to { transform: rotate(360deg); } }

/* ── Success state ──────────────────────────────────────── */
.cpl-success {
	text-align: center;
	animation: cpl-fade-up .4s ease both;
}

.cpl-success-icon {
	width: 64px;
	height: 64px;
	background: #D1FAE5;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0 auto 20px;
}

.cpl-success-title {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 22px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 10px;
}

.cpl-success-msg {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 15px;
	color: #6B7280;
	line-height: 1.65;
	margin: 0 0 20px;
}

.cpl-retry-link {
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 13px;
	color: #9CA3AF;
	background: none;
	border: none;
	cursor: pointer;
	text-decoration: underline;
	padding: 0;
}

.cpl-retry-link:hover { color: #6366F1; }

/* ── Error notice ────────────────────────────────────────── */
.cpl-error {
	background: #FEF2F2;
	border: 1px solid #FECACA;
	border-radius: 8px;
	padding: 12px 14px;
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 13px;
	color: #B91C1C;
	margin-top: 16px;
	animation: cpl-fade-up .3s ease both;
}

/* ── Small print ─────────────────────────────────────────── */
.cpl-fine-print {
	margin-top: 28px;
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 12px;
	color: #C0C0C8;
	text-align: center;
	line-height: 1.6;
	animation: cpl-fade-up .5s ease .3s both;
}

/* ── Tabs ────────────────────────────────────────────────── */
.cpl-tabs {
	display: flex;
	gap: 6px;
	background: #F3F4F6;
	border-radius: 10px;
	padding: 4px;
	margin-bottom: 28px;
}

.cpl-tab {
	flex: 1;
	height: 36px;
	border: none;
	border-radius: 7px;
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
	transition: background .15s, color .15s, box-shadow .15s;
	background: transparent;
	color: #9CA3AF;
}

.cpl-tab--active {
	background: #6366F1;
	color: #fff;
	box-shadow: 0 2px 8px rgba(99,102,241,.3);
}

.cpl-tab:not(.cpl-tab--active):hover { color: #6366F1; }

/* ── Password input wrapper ──────────────────────────────── */
.cpl-input-wrap {
	position: relative;
}

.cpl-input-wrap .cpl-input {
	padding-right: 48px;
}

.cpl-eye {
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
.cpl-eye:hover { color: #6366F1; }

/* ── Forgot / switch link ────────────────────────────────── */
.cpl-switch-link {
	display: block;
	text-align: center;
	margin-top: 18px;
	font-family: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
	font-size: 13px;
	color: #9CA3AF;
	background: none;
	border: none;
	cursor: pointer;
	text-decoration: underline;
	padding: 0;
}
.cpl-switch-link:hover { color: #6366F1; }

/* ── Mobile ──────────────────────────────────────────────── */
@media (max-width: 520px) {
	.cpl-shell { padding: 32px 16px; }
	.cpl-card { padding: 36px 24px 32px; }
	.cpl-heading { font-size: 26px; }
	.cpl-logo-wrap { width: 60px; height: 60px; border-radius: 14px; }
	.cpl-logo-initials { font-size: 22px; }
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

function getContrastColor( hex ) {
	const c = ( hex || '#6366F1' ).replace( '#', '' );
	const r = parseInt( c.substring( 0, 2 ), 16 ) / 255;
	const g = parseInt( c.substring( 2, 4 ), 16 ) / 255;
	const b = parseInt( c.substring( 4, 6 ), 16 ) / 255;
	const lin = x => x <= 0.04045 ? x / 12.92 : Math.pow( ( x + 0.055 ) / 1.055, 2.4 );
	const L = 0.2126 * lin( r ) + 0.7152 * lin( g ) + 0.0722 * lin( b );
	return L > 0.35 ? '#1A1A2E' : '#ffffff';
}

export default function PortalLogin() {
	const { businessName, businessLogo, brandColor, hideBusinessName } = window.coPortalData || {};
	const brandBg        = brandColor || '#6366F1';
	const brandTextColor = getContrastColor( brandBg );

	const initials = ( businessName || 'CO' )
		.split( ' ' )
		.slice( 0, 2 )
		.map( w => w[0] )
		.join( '' )
		.toUpperCase();

	// Shared
	const [ tab,    setTab    ] = useState( 'magic' ); // 'magic' | 'password'

	// Magic link tab
	const [ mlEmail,  setMlEmail  ] = useState( '' );
	const [ mlPhase,  setMlPhase  ] = useState( 'idle' ); // idle | loading | success | error
	const [ mlErr,    setMlErr    ] = useState( '' );

	// Password tab
	const [ pwEmail,   setPwEmail   ] = useState( '' );
	const [ pwPass,    setPwPass    ] = useState( '' );
	const [ showPass,  setShowPass  ] = useState( false );
	const [ pwPhase,   setPwPhase   ] = useState( 'idle' ); // idle | loading | error
	const [ pwErr,     setPwErr     ] = useState( '' );

	function switchTab( t ) {
		setTab( t );
		setMlErr( '' ); setMlPhase( 'idle' );
		setPwErr( '' ); setPwPhase( 'idle' );
	}

	async function handleMagicSubmit( e ) {
		e.preventDefault();
		if ( ! mlEmail ) return;
		setMlPhase( 'loading' ); setMlErr( '' );
		try {
			const res = await apiFetch( '/portal/send-magic-link/', {
				method: 'POST',
				body:   JSON.stringify( { email: mlEmail } ),
			} );
			setMlPhase( res.success ? 'success' : 'error' );
			if ( ! res.success ) setMlErr( res.message || 'Something went wrong.' );
		} catch {
			setMlPhase( 'error' );
			setMlErr( 'Network error. Please try again.' );
		}
	}

	async function handlePasswordSubmit( e ) {
		e.preventDefault();
		if ( ! pwEmail || ! pwPass ) return;
		setPwPhase( 'loading' ); setPwErr( '' );
		try {
			const res = await fetch( ( window.coPortalData.apiUrl || '' ) + '/portal/login', {
				method:  'POST',
				headers: { 'Content-Type': 'application/json' },
				body:    JSON.stringify( { email: pwEmail, password: pwPass } ),
			} ).then( r => r.json() );
			if ( res.success ) {
				window.location.href = res.redirect_url || '/clientoctopus/dashboard';
			} else {
				setPwPhase( 'error' );
				setPwErr( res.message || 'Invalid email or password.' );
			}
		} catch {
			setPwPhase( 'error' );
			setPwErr( 'Network error. Please try again.' );
		}
	}

	return (
		<div className="cpl-shell" style={ { background: brandBg } }>

			<div className="cpl-login-wrap">

				{ /* Logo above card */ }
				<div className="cpl-logo-above">
					<div className={ `cpl-logo-wrap${ businessLogo ? ' cpl-logo-wrap--image' : '' }` }>
						{ businessLogo
							? <img src={ businessLogo } alt={ businessName } />
							: <span className="cpl-logo-initials">{ initials }</span>
						}
					</div>
					{ businessName && ! hideBusinessName && (
						<p className="cpl-brand-name" style={ { color: brandTextColor } }>{ businessName }</p>
					) }
				</div>

				{ /* Login card */ }
				<div className="cpl-card">

					<h1 className="cpl-heading">Welcome back</h1>
					<p className="cpl-sub" style={ { marginBottom: 20 } }>
						Sign in to your client portal.
					</p>

					{ /* Tab switcher */ }
					<div className="cpl-tabs" role="tablist">
						<button
							role="tab"
							className={ `cpl-tab${ tab === 'magic' ? ' cpl-tab--active' : '' }` }
							onClick={ () => switchTab( 'magic' ) }
							aria-selected={ tab === 'magic' }
						>
							Magic link
						</button>
						<button
							role="tab"
							className={ `cpl-tab${ tab === 'password' ? ' cpl-tab--active' : '' }` }
							onClick={ () => switchTab( 'password' ) }
							aria-selected={ tab === 'password' }
						>
							Sign in
						</button>
					</div>

					{ /* ── Magic link tab ── */ }
					{ tab === 'magic' && (
						mlPhase === 'success' ? (
							<div className="cpl-success">
								<div className="cpl-success-icon">
									<svg width="28" height="28" viewBox="0 0 24 24" fill="none"
										stroke="#0F9D6E" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
										<polyline points="20 6 9 17 4 12"/>
									</svg>
								</div>
								<h2 className="cpl-success-title">Check your email!</h2>
								<p className="cpl-success-msg">
									A login link is on its way to <strong>{ mlEmail }</strong>.
									It&rsquo;ll arrive within a minute.
								</p>
								<button
									className="cpl-retry-link"
									onClick={ () => { setMlPhase( 'idle' ); setMlEmail( '' ); } }
								>
									Didn&rsquo;t receive it? Try again
								</button>
							</div>
						) : (
							<form onSubmit={ handleMagicSubmit }>
								<label className="cpl-label" htmlFor="cpl-ml-email">Email address</label>
								<input
									id="cpl-ml-email"
									className="cpl-input"
									type="email"
									placeholder="you@example.com"
									value={ mlEmail }
									onChange={ e => setMlEmail( e.target.value ) }
									required
									autoFocus={ tab === 'magic' }
								/>
								{ mlPhase === 'error' && <div className="cpl-error">{ mlErr }</div> }
								<button className="cpl-btn" type="submit" disabled={ mlPhase === 'loading' }>
									{ mlPhase === 'loading'
										? <><span className="cpl-spinner" />Sending your link&hellip;</>
										: 'Send Login Link'
									}
								</button>
								<p className="cpl-fine-print">Links expire in 24 hours and can only be used once.</p>
							</form>
						)
					) }

					{ /* ── Password tab ── */ }
					{ tab === 'password' && (
						<form onSubmit={ handlePasswordSubmit }>
							<label className="cpl-label" htmlFor="cpl-pw-email">Email address</label>
							<input
								id="cpl-pw-email"
								className="cpl-input"
								type="email"
								placeholder="you@example.com"
								value={ pwEmail }
								onChange={ e => setPwEmail( e.target.value ) }
								required
								autoFocus={ tab === 'password' }
								style={ { marginBottom: 16 } }
							/>

							<label className="cpl-label" htmlFor="cpl-pw-pass">Password</label>
							<div className="cpl-input-wrap">
								<input
									id="cpl-pw-pass"
									className="cpl-input"
									type={ showPass ? 'text' : 'password' }
									placeholder="Your password"
									value={ pwPass }
									onChange={ e => setPwPass( e.target.value ) }
									required
									autoComplete="current-password"
								/>
								<button
									type="button"
									className="cpl-eye"
									onClick={ () => setShowPass( v => ! v ) }
									aria-label={ showPass ? 'Hide password' : 'Show password' }
								>
									<EyeIcon open={ showPass } />
								</button>
							</div>

							{ pwPhase === 'error' && <div className="cpl-error">{ pwErr }</div> }

							<button className="cpl-btn" type="submit" disabled={ pwPhase === 'loading' } style={ { marginTop: 20 } }>
								{ pwPhase === 'loading'
									? <><span className="cpl-spinner" />Signing in&hellip;</>
									: 'Sign in →'
								}
							</button>

							<button
								type="button"
								className="cpl-switch-link"
								onClick={ () => switchTab( 'magic' ) }
							>
								No password yet? Send a magic link instead
							</button>
						</form>
					) }

				</div>
			</div>

		</div>
	);
}
