/**
 * PaymentModal
 *
 * Fullscreen overlay that fires on mount to create a Stripe Checkout Session,
 * then immediately redirects the client to Stripe's hosted checkout page.
 *
 * States: loading → (redirect) | error → (retry)
 *
 * Props:
 *   proposal          {object}  Proposal data (used for amount/currency display)
 *   onClose           {fn}      Close the modal (user dismissed before redirect)
 */

const { useState, useEffect } = wp.element;

const injectStyles = ( id, css ) => {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id; s.textContent = css;
	document.head.appendChild( s );
};

injectStyles( 'co-payment-modal-s', `
/* ── Overlay ───────────────────────────────────────────── */
.cfpm-overlay {
	position: fixed;
	inset: 0;
	z-index: 900;
	background: rgba(10, 10, 20, 0.62);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 24px;
	backdrop-filter: blur(4px);
	-webkit-backdrop-filter: blur(4px);
	animation: cfpm-bg-in 0.3s ease both;
}

@keyframes cfpm-bg-in {
	from { opacity: 0; }
	to   { opacity: 1; }
}

/* ── Card ──────────────────────────────────────────────── */
.cfpm-card {
	position: relative;
	background: #fff;
	border-radius: 20px;
	padding: 44px 40px 40px;
	max-width: 440px;
	width: 100%;
	text-align: center;
	box-shadow:
		0 20px 60px rgba(0, 0, 0, .22),
		0 2px 8px rgba(0, 0, 0, .08);
	animation: cfpm-card-in 0.38s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes cfpm-card-in {
	from { opacity: 0; transform: translateY(24px) scale(0.97); }
	to   { opacity: 1; transform: translateY(0)    scale(1);    }
}

/* ── Close button ──────────────────────────────────────── */
.cfpm-close {
	position: absolute;
	top: 16px;
	right: 16px;
	width: 32px;
	height: 32px;
	border: none;
	background: #F3F4F6;
	border-radius: 50%;
	font-size: 18px;
	line-height: 1;
	color: #9CA3AF;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: background .15s, color .15s;
}
.cfpm-close:hover { background: #E5E7EB; color: #374151; }

/* ── Icon circle ───────────────────────────────────────── */
.cfpm-icon {
	width: 72px;
	height: 72px;
	background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%);
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0 auto 24px;
	box-shadow: 0 8px 24px rgba(99,102,241,.3);
}

/* ── Title ─────────────────────────────────────────────── */
.cfpm-title {
	font-family: 'Playfair Display', serif;
	font-size: 28px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 10px;
	letter-spacing: -0.01em;
}

/* ── Amount ─────────────────────────────────────────────── */
.cfpm-amount {
	font-family: 'DM Mono', monospace;
	font-size: 22px;
	font-weight: 500;
	color: #6366F1;
	margin: 0 0 28px;
	letter-spacing: -0.02em;
}

/* ── Spinner ────────────────────────────────────────────── */
.cfpm-spinner {
	width: 36px;
	height: 36px;
	border: 3px solid #EDE9FE;
	border-top-color: #6366F1;
	border-radius: 50%;
	animation: cfpm-spin 0.8s linear infinite;
	margin: 0 auto 20px;
}

@keyframes cfpm-spin { to { transform: rotate(360deg); } }

/* ── Hint text ──────────────────────────────────────────── */
.cfpm-hint {
	font-family: 'DM Sans', sans-serif;
	font-size: 15px;
	color: #374151;
	margin: 0 0 8px;
	font-weight: 500;
}

.cfpm-sub {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	color: #9CA3AF;
	margin: 0 0 24px;
	line-height: 1.5;
}

/* ── Stripe badge ───────────────────────────────────────── */
.cfpm-stripe {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-family: 'DM Sans', sans-serif;
	font-size: 12px;
	color: #9CA3AF;
}

.cfpm-stripe strong {
	font-weight: 700;
	color: #6772E5; /* Stripe brand blue */
	font-size: 13px;
	letter-spacing: -0.01em;
}

/* ── Error state ────────────────────────────────────────── */
.cfpm-err-icon {
	width: 56px;
	height: 56px;
	background: #FEF2F2;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0 auto 20px;
	color: #EF4444;
}

.cfpm-err-msg {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	color: #6B7280;
	line-height: 1.6;
	margin: 0 0 24px;
}

.cfpm-retry {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 11px 24px;
	background: #6366F1;
	color: #fff;
	border: none;
	border-radius: 9px;
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
	transition: background .15s;
}
.cfpm-retry:hover { background: #4F46E5; }

/* ── Mobile ─────────────────────────────────────────────── */
@media (max-width: 480px) {
	.cfpm-card { padding: 36px 24px 32px; }
	.cfpm-title { font-size: 24px; }
}
` );

const BASE = ( window.coClientData || {} ).apiUrl || '/wp-json/clientoctopus/v1/';

function fmt( amount, currency ) {
	return new Intl.NumberFormat( 'en-GB', { style: 'currency', currency: currency || 'GBP' } ).format( amount );
}

export default function PaymentModal( { proposal, onClose } ) {
	const [ phase,    setPhase    ] = useState( 'loading' ); // 'loading' | 'error'
	const [ errorMsg, setErrorMsg ] = useState( '' );

	const createSession = async () => {
		setPhase( 'loading' );
		setErrorMsg( '' );

		try {
			const res = await fetch( BASE + 'payments/create-session', {
				method:  'POST',
				headers: { 'Content-Type': 'application/json' },
				body:    JSON.stringify( { token: ( window.coClientData || {} ).token || '' } ),
			} );

			const data = await res.json().catch( () => ( {} ) );

			if ( ! res.ok ) {
				throw new Error( data.message || `Error ${ res.status }` );
			}

			// Redirect to Stripe Checkout — no state update needed.
			window.location.href = data.checkout_url;

		} catch ( err ) {
			setPhase( 'error' );
			setErrorMsg( err.message || 'Could not start checkout. Please try again.' );
		}
	};

	// Fire on mount.
	useEffect( () => { createSession(); }, [] );

	return (
		<div
			className="cfpm-overlay"
			role="dialog"
			aria-modal="true"
			aria-label="Payment"
			onClick={ ( e ) => e.target === e.currentTarget && onClose() }
		>
			<div className="cfpm-card">
				<button className="cfpm-close" onClick={ onClose } aria-label="Close">×</button>

				{ /* ── Lock icon ─────────────────────────────────────── */ }
				<div className="cfpm-icon">
					<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
						<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
						<path d="M7 11V7a5 5 0 0110 0v4"/>
					</svg>
				</div>

				<h2 className="cfpm-title">Secure Payment</h2>

				{ phase === 'loading' && (
					<>
						<p className="cfpm-amount">
							{ fmt( proposal?.total_amount, proposal?.currency ) }
						</p>
						<div className="cfpm-spinner" />
						<p className="cfpm-hint">Preparing your secure checkout…</p>
						<p className="cfpm-sub">You'll be redirected to Stripe's hosted checkout page.</p>
						<div className="cfpm-stripe">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polyline points="20 6 9 17 4 12"/></svg>
							Powered by <strong>Stripe</strong>
						</div>
					</>
				) }

				{ phase === 'error' && (
					<>
						<div className="cfpm-err-icon">
							<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
								<circle cx="12" cy="12" r="10"/>
								<line x1="12" y1="8" x2="12" y2="12"/>
								<line x1="12" y1="16" x2="12.01" y2="16"/>
							</svg>
						</div>
						<h2 className="cfpm-title" style={ { fontSize: '22px', marginBottom: '12px' } }>
							Something went wrong
						</h2>
						<p className="cfpm-err-msg">{ errorMsg }</p>
						<button className="cfpm-retry" onClick={ createSession }>
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
								<polyline points="1 4 1 10 7 10"/>
								<path d="M3.51 15a9 9 0 102.13-9.36L1 10"/>
							</svg>
							Try Again
						</button>
					</>
				) }
			</div>
		</div>
	);
}
