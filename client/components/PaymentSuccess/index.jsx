/**
 * PaymentSuccess
 *
 * Full-page celebratory view rendered at /proposals/{token}/success.
 * Shows an animated checkmark, payment details, next steps, and confetti.
 *
 * Props:
 *   token      {string}  Proposal token (for "Return to proposal" link)
 *   sessionId  {string}  Stripe session ID (cs_xxx) from URL query param
 */

const { useState, useEffect } = wp.element;

const injectStyles = ( id, css ) => {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id; s.textContent = css;
	document.head.appendChild( s );
};

/* Inject fonts if ProposalClientView hasn't already (standalone success page). */
injectStyles( 'co-global-s', `@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600;700&family=DM+Mono&display=swap');
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: 'DM Sans', sans-serif; -webkit-font-smoothing: antialiased; }` );

/* ── Confetti data ────────────────────────────────────────────────────────── */
const CONFETTI = [
	{ left:  3, delay: 0.0, dur: 3.2, color: '#10B981', size: 8,  round: false },
	{ left:  8, delay: 0.2, dur: 2.8, color: '#6366F1', size: 6,  round: true  },
	{ left: 14, delay: 0.5, dur: 3.5, color: '#F59E0B', size: 7,  round: false },
	{ left: 19, delay: 0.1, dur: 3.0, color: '#10B981', size: 5,  round: true  },
	{ left: 25, delay: 0.7, dur: 2.6, color: '#6366F1', size: 9,  round: false },
	{ left: 30, delay: 0.3, dur: 3.3, color: '#F59E0B', size: 6,  round: true  },
	{ left: 35, delay: 0.9, dur: 2.9, color: '#10B981', size: 8,  round: false },
	{ left: 40, delay: 0.4, dur: 3.1, color: '#6366F1', size: 5,  round: true  },
	{ left: 47, delay: 0.6, dur: 3.4, color: '#F59E0B', size: 7,  round: false },
	{ left: 52, delay: 0.2, dur: 2.7, color: '#10B981', size: 6,  round: true  },
	{ left: 58, delay: 0.8, dur: 3.0, color: '#6366F1', size: 8,  round: false },
	{ left: 63, delay: 0.1, dur: 3.2, color: '#F59E0B', size: 5,  round: true  },
	{ left: 69, delay: 0.5, dur: 2.8, color: '#10B981', size: 9,  round: false },
	{ left: 74, delay: 0.3, dur: 3.5, color: '#6366F1', size: 6,  round: true  },
	{ left: 80, delay: 0.7, dur: 3.1, color: '#F59E0B', size: 7,  round: false },
	{ left: 85, delay: 0.4, dur: 2.9, color: '#10B981', size: 5,  round: true  },
	{ left: 90, delay: 0.6, dur: 3.3, color: '#6366F1', size: 8,  round: false },
	{ left: 95, delay: 0.2, dur: 3.0, color: '#F59E0B', size: 6,  round: true  },
	{ left:  7, delay: 1.1, dur: 2.8, color: '#10B981', size: 7,  round: false },
	{ left: 16, delay: 1.3, dur: 3.4, color: '#6366F1', size: 5,  round: true  },
	{ left: 23, delay: 1.0, dur: 3.1, color: '#F59E0B', size: 8,  round: false },
	{ left: 32, delay: 1.5, dur: 2.7, color: '#10B981', size: 6,  round: true  },
	{ left: 43, delay: 1.2, dur: 3.2, color: '#6366F1', size: 9,  round: false },
	{ left: 55, delay: 1.4, dur: 2.9, color: '#F59E0B', size: 5,  round: true  },
	{ left: 66, delay: 1.0, dur: 3.5, color: '#10B981', size: 7,  round: false },
	{ left: 72, delay: 1.6, dur: 3.0, color: '#6366F1', size: 6,  round: true  },
	{ left: 78, delay: 1.1, dur: 2.8, color: '#F59E0B', size: 8,  round: false },
	{ left: 87, delay: 1.3, dur: 3.3, color: '#10B981', size: 5,  round: true  },
	{ left: 92, delay: 1.5, dur: 3.1, color: '#6366F1', size: 7,  round: false },
	{ left: 97, delay: 1.2, dur: 2.6, color: '#F59E0B', size: 6,  round: true  },
];

injectStyles( 'co-pay-success-s', `
/* ── Page ──────────────────────────────────────────────── */
.cfps-page {
	min-height: 100vh;
	background: #F8F7F5;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: flex-start;
	padding: 60px 24px 80px;
	position: relative;
	overflow: hidden;
}

/* ── Confetti ───────────────────────────────────────────── */
.cfps-confetti {
	position: fixed;
	inset: 0;
	pointer-events: none;
	overflow: hidden;
	z-index: 0;
}

.cfps-piece {
	position: absolute;
	top: -20px;
	animation: cfps-fall linear both;
}

@keyframes cfps-fall {
	0%   { transform: translateY(-20px) rotate(0deg);   opacity: 1; }
	80%  { opacity: 1; }
	100% { transform: translateY(110vh) rotate(540deg); opacity: 0; }
}

/* ── Card ──────────────────────────────────────────────── */
.cfps-card {
	position: relative;
	z-index: 1;
	background: #fff;
	border-radius: 24px;
	padding: 52px 48px 44px;
	max-width: 540px;
	width: 100%;
	text-align: center;
	box-shadow:
		0 2px 4px rgba(26,26,46,.04),
		0 12px 40px rgba(26,26,46,.1);
	animation: cfps-rise 0.6s cubic-bezier(0.22, 1, 0.36, 1) 0.1s both;
}

@keyframes cfps-rise {
	from { opacity: 0; transform: translateY(20px); }
	to   { opacity: 1; transform: translateY(0); }
}

/* ── Animated checkmark SVG ─────────────────────────────── */
.cfps-check-wrap {
	margin: 0 auto 28px;
	width: 88px;
	height: 88px;
}

.cfps-check-circle {
	stroke-dasharray: 283;
	stroke-dashoffset: 283;
	animation: cfps-circle-draw 0.8s ease-out 0.4s forwards;
}

.cfps-check-mark {
	stroke-dasharray: 75;
	stroke-dashoffset: 75;
	animation: cfps-check-draw 0.5s ease-out 1.2s forwards;
}

@keyframes cfps-circle-draw {
	to { stroke-dashoffset: 0; }
}

@keyframes cfps-check-draw {
	to { stroke-dashoffset: 0; }
}

/* ── Title ─────────────────────────────────────────────── */
.cfps-title {
	font-family: 'Playfair Display', serif;
	font-size: 38px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 12px;
	letter-spacing: -0.02em;
	line-height: 1.15;
	animation: cfps-fade-up 0.5s ease 1.4s both;
}

.cfps-subtitle {
	font-family: 'DM Sans', sans-serif;
	font-size: 16px;
	color: #6B7280;
	margin: 0 0 32px;
	line-height: 1.6;
	animation: cfps-fade-up 0.5s ease 1.55s both;
}

@keyframes cfps-fade-up {
	from { opacity: 0; transform: translateY(8px); }
	to   { opacity: 1; transform: translateY(0); }
}

/* ── Details card ───────────────────────────────────────── */
.cfps-details {
	background: #F8F7F5;
	border-radius: 14px;
	padding: 20px 24px;
	text-align: left;
	margin-bottom: 32px;
	animation: cfps-fade-up 0.5s ease 1.7s both;
}

.cfps-detail-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	padding: 5px 0;
}

.cfps-detail-row + .cfps-detail-row {
	border-top: 1px solid #E5E7EB;
	margin-top: 8px;
	padding-top: 13px;
}

.cfps-detail-label {
	font-family: 'DM Sans', sans-serif;
	font-size: 12px;
	font-weight: 600;
	color: #9CA3AF;
	text-transform: uppercase;
	letter-spacing: 0.06em;
}

.cfps-detail-value {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	color: #1A1A2E;
	font-weight: 500;
	text-align: right;
}

.cfps-detail-value--amount {
	font-family: 'DM Mono', monospace;
	font-size: 17px;
	color: #10B981;
	font-weight: 600;
}

/* ── Next steps ─────────────────────────────────────────── */
.cfps-next {
	text-align: left;
	margin-bottom: 36px;
	animation: cfps-fade-up 0.5s ease 1.85s both;
}

.cfps-next-title {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-weight: 700;
	color: #9CA3AF;
	text-transform: uppercase;
	letter-spacing: 0.07em;
	margin: 0 0 16px;
}

.cfps-step {
	display: flex;
	align-items: flex-start;
	gap: 14px;
	margin-bottom: 14px;
}

.cfps-step:last-child { margin-bottom: 0; }

.cfps-step-num {
	width: 24px;
	height: 24px;
	background: #EDE9FE;
	color: #6366F1;
	border-radius: 50%;
	font-family: 'DM Mono', monospace;
	font-size: 11px;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	margin-top: 1px;
}

.cfps-step-text {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	color: #374151;
	line-height: 1.55;
}

/* ── Actions ────────────────────────────────────────────── */
.cfps-actions {
	display: flex;
	flex-direction: column;
	align-items: stretch;
	gap: 10px;
	animation: cfps-fade-up 0.5s ease 2.0s both;
}

.cfps-portal-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 7px;
	padding: 13px 20px;
	background: #6366F1;
	color: #fff;
	border-radius: 10px;
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	font-weight: 600;
	text-decoration: none;
	transition: background .15s, transform .12s;
}
.cfps-portal-btn:hover { background: #4F46E5; transform: translateY(-1px); }

.cfps-secondary-actions {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 10px;
	flex-wrap: wrap;
}

.cfps-proposal-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 9px 16px;
	border: 1.5px solid #E5E7EB;
	border-radius: 9px;
	background: transparent;
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	color: #6B7280;
	font-weight: 500;
	text-decoration: none;
	transition: border-color .15s, color .15s;
}
.cfps-proposal-btn:hover { border-color: #9CA3AF; color: #374151; }

.cfps-print-btn {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	padding: 9px 16px;
	border: 1.5px solid #E5E7EB;
	border-radius: 9px;
	background: transparent;
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	color: #6B7280;
	font-weight: 500;
	cursor: pointer;
	transition: border-color .15s, color .15s;
}
.cfps-print-btn:hover { border-color: #9CA3AF; color: #374151; }

/* ── Footer ─────────────────────────────────────────────── */
.cfps-footer {
	margin-top: 32px;
	font-family: 'DM Sans', sans-serif;
	font-size: 12px;
	color: #C0C0C8;
	text-align: center;
	animation: cfps-fade-up 0.5s ease 2.1s both;
}

/* ── Mobile ─────────────────────────────────────────────── */
@media (max-width: 600px) {
	.cfps-page  { padding: 40px 16px 60px; }
	.cfps-card  { padding: 36px 24px 32px; }
	.cfps-title { font-size: 28px; }
}

/* ── Print ──────────────────────────────────────────────── */
@media print {
	.cfps-confetti { display: none; }
	.cfps-print-btn { display: none; }
	.cfps-page { background: #fff; padding: 20px; }
}
` );

function fmt( amount, currency ) {
	return new Intl.NumberFormat( 'en-GB', { style: 'currency', currency: currency || 'GBP' } ).format( amount );
}

const BASE = ( window.coClientData || {} ).apiUrl || '/wp-json/clientoctopus/v1/';

export default function PaymentSuccess( { token, sessionId } ) {
	const [ payment, setPayment ] = useState( null );
	const { businessName = '', clientEmail = '' } = window.coClientData || {};

	// Poll payment status so we can show the confirmed amount.
	useEffect( () => {
		if ( ! sessionId ) return;
		fetch( `${ BASE }payments/status?session_id=${ encodeURIComponent( sessionId ) }` )
			.then( r => r.json() )
			.then( data => setPayment( data ) )
			.catch( () => {} );
	}, [ sessionId ] );

	const date = new Intl.DateTimeFormat( 'en-GB', {
		day: '2-digit', month: 'long', year: 'numeric'
	} ).format( new Date() );

	return (
		<div className="cfps-page">
			{ /* Confetti */ }
			<div className="cfps-confetti" aria-hidden="true">
				{ CONFETTI.map( ( c, i ) => (
					<div
						key={ i }
						className="cfps-piece"
						style={ {
							left:              `${ c.left }%`,
							width:             c.size,
							height:            c.round ? c.size : c.size * 1.4,
							background:        c.color,
							borderRadius:      c.round ? '50%' : '2px',
							animationDelay:    `${ c.delay }s`,
							animationDuration: `${ c.dur }s`,
						} }
					/>
				) ) }
			</div>

			<div className="cfps-card">
				{ /* Animated checkmark */ }
				<div className="cfps-check-wrap">
					<svg viewBox="0 0 100 100" fill="none">
						<circle
							className="cfps-check-circle"
							cx="50" cy="50" r="45"
							stroke="#10B981" strokeWidth="4"
						/>
						<path
							className="cfps-check-mark"
							d="M 28 52 L 43 67 L 72 33"
							stroke="#10B981" strokeWidth="5"
							strokeLinecap="round" strokeLinejoin="round"
						/>
					</svg>
				</div>

				<h1 className="cfps-title">Payment Confirmed</h1>
				<p className="cfps-subtitle">
					Thank you — your payment has been received.
				</p>

				{ /* Details */ }
				<div className="cfps-details">
					{ payment?.amount && (
						<div className="cfps-detail-row">
							<span className="cfps-detail-label">Amount paid</span>
							<span className="cfps-detail-value cfps-detail-value--amount">
								{ fmt( payment.amount, payment.currency ) }
							</span>
						</div>
					) }
					<div className="cfps-detail-row">
						<span className="cfps-detail-label">Date</span>
						<span className="cfps-detail-value">{ date }</span>
					</div>
					{ sessionId && (
						<div className="cfps-detail-row">
							<span className="cfps-detail-label">Reference</span>
							<span className="cfps-detail-value" style={ { fontFamily: "'DM Mono', monospace", fontSize: '12px', color: '#9CA3AF' } }>
								{ sessionId.slice( 0, 28 ) }…
							</span>
						</div>
					) }
				</div>

				{ /* Next steps */ }
				<div className="cfps-next">
					<p className="cfps-next-title">What happens next?</p>
					{ [
						'You\'ll receive a payment receipt from Stripe shortly.',
						clientEmail
							? `A confirmation has been sent to ${ clientEmail }.`
							: 'Check your inbox for a confirmation email.',
						'We\'ll be in touch to kick things off.',
					].map( ( step, i ) => (
						<div key={ i } className="cfps-step">
							<span className="cfps-step-num">{ i + 1 }</span>
							<span className="cfps-step-text">{ step }</span>
						</div>
					) ) }
				</div>

				{ /* Actions */ }
				<div className="cfps-actions">
					<a href="/clientoctopus/dashboard" className="cfps-portal-btn">
						Go to Client Portal
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
							<polyline points="9 18 15 12 9 6"/>
						</svg>
					</a>
					<div className="cfps-secondary-actions">
						{ token && (
							<a href={ `/proposals/${ token }` } className="cfps-proposal-btn">
								View Proposal
							</a>
						) }
						<button
							className="cfps-print-btn"
							onClick={ () => window.print() }
						>
							<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
								<polyline points="6 9 6 2 18 2 18 9"/>
								<path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/>
								<rect x="6" y="14" width="12" height="8"/>
							</svg>
							Print this page
						</button>
					</div>
				</div>
			</div>

			{ businessName && (
				<p className="cfps-footer">{ businessName }</p>
			) }
		</div>
	);
}
