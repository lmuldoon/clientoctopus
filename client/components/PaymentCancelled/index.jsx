/**
 * PaymentCancelled
 *
 * Full-page reassurance view rendered at /proposals/{token}/cancel.
 * The client chose to cancel — no alarm, just a clear path back.
 *
 * Props:
 *   token      {string}  Proposal token (for "Return to proposal" link)
 *   proposal   {object}  Proposal data for expiry date display
 */

const { useState } = wp.element;

const injectStyles = ( id, css ) => {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id; s.textContent = css;
	document.head.appendChild( s );
};

/* Inject fonts if ProposalClientView hasn't already (standalone cancel page). */
injectStyles( 'co-global-s', `@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600;700&family=DM+Mono&display=swap');
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: 'DM Sans', sans-serif; -webkit-font-smoothing: antialiased; background: #F8F7F5; }` );

injectStyles( 'co-pay-cancel-s', `
/* ── Page ──────────────────────────────────────────────── */
.cfpc-page {
	min-height: 100vh;
	background: #F8F7F5;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: flex-start;
	padding: 80px 24px 80px;
}

/* ── Card ──────────────────────────────────────────────── */
.cfpc-card {
	background: #fff;
	border-radius: 24px;
	padding: 52px 48px 44px;
	max-width: 520px;
	width: 100%;
	text-align: center;
	box-shadow:
		0 2px 4px rgba(26,26,46,.04),
		0 12px 40px rgba(26,26,46,.09);
	animation: cfpc-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes cfpc-rise {
	from { opacity: 0; transform: translateY(16px); }
	to   { opacity: 1; transform: translateY(0); }
}

/* ── Icon ───────────────────────────────────────────────── */
.cfpc-icon {
	width: 80px;
	height: 80px;
	background: #FEF3C7;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0 auto 28px;
	animation: cfpc-icon-in 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.15s both;
}

@keyframes cfpc-icon-in {
	from { opacity: 0; transform: scale(0.7); }
	to   { opacity: 1; transform: scale(1); }
}

/* ── Title ─────────────────────────────────────────────── */
.cfpc-title {
	font-family: 'Playfair Display', serif;
	font-size: 34px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 14px;
	letter-spacing: -0.02em;
	line-height: 1.2;
	animation: cfpc-fade 0.5s ease 0.3s both;
}

.cfpc-sub {
	font-family: 'DM Sans', sans-serif;
	font-size: 16px;
	color: #6B7280;
	line-height: 1.65;
	margin: 0 0 36px;
	animation: cfpc-fade 0.5s ease 0.4s both;
}

@keyframes cfpc-fade {
	from { opacity: 0; transform: translateY(6px); }
	to   { opacity: 1; transform: translateY(0); }
}

/* ── CTA button ─────────────────────────────────────────── */
.cfpc-cta {
	display: inline-flex;
	align-items: center;
	gap: 9px;
	padding: 14px 30px;
	background: #6366F1;
	color: #fff;
	border-radius: 11px;
	font-family: 'DM Sans', sans-serif;
	font-size: 15px;
	font-weight: 600;
	text-decoration: none;
	transition: background .15s, transform .15s, box-shadow .15s;
	box-shadow: 0 3px 12px rgba(99,102,241,.3);
	animation: cfpc-fade 0.5s ease 0.5s both;
	letter-spacing: 0.01em;
}

.cfpc-cta:hover {
	background: #4F46E5;
	transform: translateY(-1px);
	box-shadow: 0 5px 18px rgba(99,102,241,.4);
}

/* ── Divider ────────────────────────────────────────────── */
.cfpc-sep {
	height: 1px;
	background: #F3F4F6;
	margin: 32px 0;
	animation: cfpc-fade 0.5s ease 0.6s both;
}

/* ── Contact row ────────────────────────────────────────── */
.cfpc-contact {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	color: #9CA3AF;
	animation: cfpc-fade 0.5s ease 0.65s both;
}

.cfpc-contact a {
	color: #6366F1;
	text-decoration: none;
	font-weight: 500;
}

.cfpc-contact a:hover { text-decoration: underline; }

/* ── Small print ────────────────────────────────────────── */
.cfpc-small {
	margin-top: 16px;
	font-family: 'DM Sans', sans-serif;
	font-size: 12px;
	color: #C0C0C8;
	line-height: 1.5;
	animation: cfpc-fade 0.5s ease 0.7s both;
}

/* ── Mobile ─────────────────────────────────────────────── */
@media (max-width: 600px) {
	.cfpc-page { padding: 48px 16px 60px; }
	.cfpc-card { padding: 36px 24px 32px; }
	.cfpc-title { font-size: 26px; }
}
` );

export default function PaymentCancelled( { token, proposal } ) {
	const businessEmail  = ( window.coClientData || {} ).businessEmail || '';
	const businessName   = ( window.coClientData || {} ).businessName  || '';

	const expiryDisplay = proposal?.expiry_date
		? new Intl.DateTimeFormat( 'en-GB', { day: '2-digit', month: 'long', year: 'numeric' } )
			.format( new Date( proposal.expiry_date ) )
		: null;

	return (
		<div className="cfpc-page">
			<div className="cfpc-card">
				{ /* Amber icon */ }
				<div className="cfpc-icon" aria-hidden="true">
					<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
						<circle cx="12" cy="12" r="10"/>
						<line x1="15" y1="9" x2="9" y2="15"/>
						<line x1="9" y1="9" x2="15" y2="15"/>
					</svg>
				</div>

				<h1 className="cfpc-title">Payment Cancelled</h1>

				<p className="cfpc-sub">
					No worries — your proposal is still open.
					You can complete payment whenever you&rsquo;re ready.
				</p>

				{ token && (
					<a
						className="cfpc-cta"
						href={ `/proposals/${ token }` }
					>
						<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
							<line x1="19" y1="12" x2="5" y2="12"/>
							<polyline points="12 19 5 12 12 5"/>
						</svg>
						Return to Proposal
					</a>
				) }

				<div className="cfpc-sep" />

				<p className="cfpc-contact">
					{ businessEmail ? (
						<>
							Questions?{ ' ' }
							<a href={ `mailto:${ businessEmail }` }>Contact us</a>
						</>
					) : (
						'Questions? Please contact us.'
					) }
				</p>

				{ expiryDisplay && (
					<p className="cfpc-small">
						Your proposal remains valid until { expiryDisplay }.
					</p>
				) }
			</div>
		</div>
	);
}
