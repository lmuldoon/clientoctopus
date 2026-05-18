/**
 * PortalVerify
 *
 * Auto-fires the verify API on mount using the token from coPortalData.verifyToken.
 * Three phases: verifying → success → error.
 *
 * On success: redirects to /portal/dashboard after a 1.5 s delay.
 */

const { useState, useEffect } = wp.element;

const apiFetch = ( path, opts = {} ) =>
	fetch( window.coPortalData.apiUrl + path, {
		headers: {
			'X-WP-Nonce':   window.coPortalData.nonce,
			'Content-Type': 'application/json',
		},
		...opts,
	} ).then( r => r.json() );

injectStyles( 'cpv-s', `
/* ── Page ─────────────────────────────────────────────── */
.cpv-page {
	min-height: 100vh;
	background: #F8F7F5;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 40px 24px;
}

/* ── Card ─────────────────────────────────────────────── */
.cpv-card {
	background: #fff;
	border-radius: 20px;
	border-top: 4px solid #6366F1;
	padding: 52px 48px 44px;
	max-width: 440px;
	width: 100%;
	text-align: center;
	box-shadow:
		0 2px 4px rgba(26,26,46,.04),
		0 12px 40px rgba(26,26,46,.09);
	animation: cpv-rise .5s cubic-bezier(0.22,1,0.36,1) both;
}

@keyframes cpv-rise {
	from { opacity: 0; transform: translateY(14px); }
	to   { opacity: 1; transform: translateY(0); }
}

/* ── Indigo spinner ───────────────────────────────────── */
.cpv-spinner-wrap {
	width: 72px;
	height: 72px;
	margin: 0 auto 28px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.cpv-spinner {
	width: 48px;
	height: 48px;
	border: 3px solid #EEF2FF;
	border-top-color: #6366F1;
	border-radius: 50%;
	animation: cpv-spin .8s linear infinite;
}
@keyframes cpv-spin { to { transform: rotate(360deg); } }

/* ── Check animation ──────────────────────────────────── */
.cpv-check-wrap {
	width: 72px;
	height: 72px;
	margin: 0 auto 28px;
}

.cpv-check-circle {
	stroke-dasharray: 226;
	stroke-dashoffset: 226;
	animation: cpv-circle .5s ease .1s forwards;
}
@keyframes cpv-circle { to { stroke-dashoffset: 0; } }

.cpv-check-mark {
	stroke-dasharray: 60;
	stroke-dashoffset: 60;
	animation: cpv-check .35s ease .55s forwards;
}
@keyframes cpv-check { to { stroke-dashoffset: 0; } }

/* ── Amber icon ──────────────────────────────────────── */
.cpv-amber-wrap {
	width: 72px;
	height: 72px;
	background: #FEF3C7;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0 auto 28px;
	animation: cpv-pop .4s cubic-bezier(0.22,1,0.36,1) both;
}
@keyframes cpv-pop {
	from { opacity: 0; transform: scale(0.6); }
	to   { opacity: 1; transform: scale(1); }
}

/* ── Text ─────────────────────────────────────────────── */
.cpv-title {
	font-family: 'Playfair Display', serif;
	font-size: 24px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 10px;
	letter-spacing: -0.02em;
}

.cpv-msg {
	font-family: 'DM Sans', sans-serif;
	font-size: 15px;
	color: #6B7280;
	line-height: 1.65;
	margin: 0 0 28px;
}

/* ── Button ───────────────────────────────────────────── */
.cpv-btn {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 13px 28px;
	background: #6366F1;
	color: #fff;
	border: none;
	border-radius: 10px;
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	font-weight: 600;
	text-decoration: none;
	cursor: pointer;
	transition: background .15s, transform .15s;
	box-shadow: 0 3px 12px rgba(99,102,241,.3);
}

.cpv-btn:hover {
	background: #4F46E5;
	transform: translateY(-1px);
}

@media (max-width: 600px) {
	.cpv-card { padding: 36px 24px 32px; }
	.cpv-title { font-size: 20px; }
}
` );

export default function PortalVerify() {
	const token = ( window.coPortalData || {} ).verifyToken || '';

	const [ phase,  setPhase  ] = useState( 'verifying' ); // verifying | success | error
	const [ errMsg, setErrMsg ] = useState( '' );

	useEffect( () => {
		if ( ! token ) {
			setPhase( 'error' );
			setErrMsg( 'No login token found. Please request a new login link.' );
			return;
		}

		apiFetch( '/portal/verify', {
			method: 'POST',
			body:   JSON.stringify( { token } ),
		} )
			.then( res => {
				if ( res.success ) {
					setPhase( 'success' );
					setTimeout( () => {
						window.location.href = res.redirect_url || '/clientoctopus/dashboard';
					}, 1500 );
				} else {
					setPhase( 'error' );
					setErrMsg( res.message || 'This login link is invalid or has expired.' );
				}
			} )
			.catch( () => {
				setPhase( 'error' );
				setErrMsg( 'Network error. Please try again.' );
			} );
	}, [] );

	return (
		<div className="cpv-page">
			<div className="cpv-card">

				{ /* ── Verifying ── */ }
				{ 'verifying' === phase && (
					<>
						<div className="cpv-spinner-wrap">
							<div className="cpv-spinner" />
						</div>
						<h1 className="cpv-title">Verifying your link&hellip;</h1>
						<p className="cpv-msg">Just a moment while we log you in.</p>
					</>
				) }

				{ /* ── Success ── */ }
				{ 'success' === phase && (
					<>
						<div className="cpv-check-wrap">
							<svg width="72" height="72" viewBox="0 0 72 72" fill="none">
								<circle
									cx="36" cy="36" r="34"
									stroke="#10B981" strokeWidth="3" fill="none"
									className="cpv-check-circle"
								/>
								<path
									d="M22 37 L32 47 L52 27"
									stroke="#10B981" strokeWidth="3.5"
									strokeLinecap="round" strokeLinejoin="round" fill="none"
									className="cpv-check-mark"
								/>
							</svg>
						</div>
						<h1 className="cpv-title">You&rsquo;re in!</h1>
						<p className="cpv-msg">Redirecting to your dashboard&hellip;</p>
					</>
				) }

				{ /* ── Error ── */ }
				{ 'error' === phase && (
					<>
						<div className="cpv-amber-wrap">
							<svg width="32" height="32" viewBox="0 0 24 24" fill="none"
								stroke="#F59E0B" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
								<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
								<line x1="12" y1="9" x2="12" y2="13"/>
								<line x1="12" y1="17" x2="12.01" y2="17"/>
							</svg>
						</div>
						<h1 className="cpv-title">Link Expired</h1>
						<p className="cpv-msg">{ errMsg }</p>
						<a className="cpv-btn" href="/clientoctopus/login">
							Request a new login link
						</a>
					</>
				) }

			</div>
		</div>
	);
}
