/**
 * PaymentButton
 *
 * Emerald CTA shown in the sticky footer once a proposal is accepted
 * and payment is enabled. Replaces the "Accept Proposal" action.
 *
 * Props:
 *   onInitiatePayment  {fn}    Called when the button is clicked
 *   loading            {bool}  Show spinner while session is being created
 */

const { useState } = wp.element;

const injectStyles = ( id, css ) => {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id; s.textContent = css;
	document.head.appendChild( s );
};

injectStyles( 'co-payment-btn-s', `
/* ── Payment button wrapper ────────────────────────────── */
.cfpb-wrap {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 6px;
}

/* ── Button ────────────────────────────────────────────── */
.cfpb-btn {
	display: inline-flex;
	align-items: center;
	gap: 10px;
	padding: 13px 26px;
	background: #10B981;
	color: #fff;
	border: none;
	border-radius: 10px;
	font-family: 'DM Sans', sans-serif;
	font-size: 15px;
	font-weight: 600;
	cursor: pointer;
	letter-spacing: 0.01em;
	transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
	box-shadow: 0 3px 14px rgba(16,185,129,.35);
	animation: cfpb-pulse 2.8s ease-in-out infinite;
	position: relative;
	overflow: hidden;
}

@keyframes cfpb-pulse {
	0%, 100% {
		box-shadow: 0 3px 14px rgba(16,185,129,.35);
	}
	50% {
		box-shadow: 0 3px 22px rgba(16,185,129,.55), 0 0 0 5px rgba(16,185,129,.1);
	}
}

.cfpb-btn::before {
	content: '';
	position: absolute;
	inset: 0;
	background: linear-gradient(135deg, rgba(255,255,255,.12) 0%, transparent 60%);
	pointer-events: none;
}

.cfpb-btn:hover:not(:disabled) {
	background: #059669;
	transform: translateY(-1px);
	box-shadow: 0 5px 22px rgba(16,185,129,.45);
	animation: none;
}

.cfpb-btn:active:not(:disabled) {
	transform: translateY(0);
}

.cfpb-btn:disabled {
	opacity: 0.8;
	cursor: not-allowed;
	animation: none;
}

.cfpb-arrow {
	display: inline-flex;
	transition: transform 0.2s;
}

.cfpb-btn:hover:not(:disabled) .cfpb-arrow {
	transform: translateX(4px);
}

/* ── Spinner ────────────────────────────────────────────── */
.cfpb-spinner {
	width: 17px;
	height: 17px;
	border: 2px solid rgba(255,255,255,.35);
	border-top-color: #fff;
	border-radius: 50%;
	animation: cfpb-spin .7s linear infinite;
	flex-shrink: 0;
}

@keyframes cfpb-spin { to { transform: rotate(360deg); } }

/* ── Security badge ─────────────────────────────────────── */
.cfpb-secure {
	display: flex;
	align-items: center;
	gap: 5px;
	font-family: 'DM Sans', sans-serif;
	font-size: 11px;
	color: #9CA3AF;
	letter-spacing: 0.025em;
}
` );

export default function PaymentButton( { onInitiatePayment, loading } ) {
	return (
		<div className="cfpb-wrap">
			<button
				className="cfpb-btn"
				onClick={ onInitiatePayment }
				disabled={ loading }
				aria-label="Proceed to payment"
			>
				{ loading ? (
					<>
						<span className="cfpb-spinner" />
						Preparing payment…
					</>
				) : (
					<>
						Proceed to Payment
						<span className="cfpb-arrow">
							<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
								<line x1="5" y1="12" x2="19" y2="12"/>
								<polyline points="12 5 19 12 12 19"/>
							</svg>
						</span>
					</>
				) }
			</button>

			<div className="cfpb-secure">
				<svg width="10" height="12" viewBox="0 0 10 12" fill="none">
					<path
						d="M5 0C3.34 0 2 1.34 2 3v1H1a1 1 0 00-1 1v6a1 1 0 001 1h8a1 1 0 001-1V5a1 1 0 00-1-1H8V3c0-1.66-1.34-3-3-3zm0 1.5c.83 0 1.5.67 1.5 1.5v1h-3V3c0-.83.67-1.5 1.5-1.5zM5 7.5a1 1 0 110 2 1 1 0 010-2z"
						fill="#9CA3AF"
					/>
				</svg>
				Secured by Stripe
			</div>
		</div>
	);
}
