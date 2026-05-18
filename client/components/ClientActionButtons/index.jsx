/**
 * ClientActionButtons
 *
 * Sticky frosted-glass footer bar with Accept / Decline / Payment actions.
 * Decline opens a modal asking for an optional reason before firing.
 *
 * Props:
 *   status         {string}  Current proposal status
 *   paymentEnabled {bool}    Whether payment is enabled for this proposal
 *   ownerEmail     {string}  Owner's email for the "Contact us" mailto link
 *   onAccept       {fn}      Called when client accepts
 *   onDecline      {fn(reason)}  Called when client declines, with optional reason string
 *   onPayment      {fn}      Called when client clicks "Proceed to Payment"
 *   loading        {bool}    True while an action is in-flight
 */

const { useState } = wp.element;

const injectStyles = ( id, css ) => {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
};

const CSS = `
/* ── Footer bar ─────────────────────────────────────────────── */
.cfa-bar {
	position: fixed;
	bottom: 0;
	left: 0;
	right: 0;
	z-index: 200;
	padding: 15px 40px;
	background: rgba(255, 255, 255, 0.92);
	backdrop-filter: blur(14px);
	-webkit-backdrop-filter: blur(14px);
	border-top: 1px solid rgba(0, 0, 0, 0.07);
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	animation: cfa-slide-up 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes cfa-slide-up {
	from { transform: translateY(100%); opacity: 0; }
	to   { transform: translateY(0);    opacity: 1; }
}

.cfa-contact {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	color: #9CA3AF;
	text-decoration: none;
	transition: color 0.15s;
}
.cfa-contact:hover { color: #6366F1; }

.cfa-buttons {
	display: flex;
	align-items: center;
	gap: 10px;
}

/* ── Shared button ──────────────────────────────────────────── */
.cfa-btn {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 11px 22px;
	border-radius: 9px;
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
	transition: all 0.15s;
	border: 1.5px solid transparent;
	text-decoration: none;
	white-space: nowrap;
}
.cfa-btn:disabled {
	opacity: 0.55;
	cursor: not-allowed;
	transform: none !important;
}

/* Decline: ghost red */
.cfa-btn--decline {
	background: transparent;
	color: #EF4444;
	border-color: #EF4444;
	padding: 10px 20px;
}
.cfa-btn--decline:hover:not(:disabled) { background: #FEF2F2; }

/* Accept: indigo fill */
.cfa-btn--accept {
	background: #6366F1;
	color: #fff;
	box-shadow: 0 2px 10px rgba(99,102,241,.35);
}
.cfa-btn--accept:hover:not(:disabled) {
	background: #4F46E5;
	box-shadow: 0 4px 18px rgba(99,102,241,.45);
	transform: translateY(-1px);
}

/* Payment: emerald */
.cfa-btn--payment {
	background: #10B981;
	color: #fff;
	box-shadow: 0 2px 10px rgba(16,185,129,.3);
}
.cfa-btn--payment:hover:not(:disabled) {
	background: #059669;
	transform: translateY(-1px);
	box-shadow: 0 4px 18px rgba(16,185,129,.4);
}

/* ── Spinner ────────────────────────────────────────────────── */
.cfa-spinner {
	width: 14px;
	height: 14px;
	border: 2px solid rgba(255,255,255,0.35);
	border-top-color: #fff;
	border-radius: 50%;
	animation: cfa-spin 0.65s linear infinite;
	flex-shrink: 0;
}
@keyframes cfa-spin { to { transform: rotate(360deg); } }

/* ── Static status label ────────────────────────────────────── */
.cfa-status {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 7px;
}
.cfa-status--accepted { color: #10B981; }
.cfa-status--declined { color: #94A3B8; }
.cfa-status--expired  { color: #9CA3AF; }

/* Request a change: amber ghost */
.cfa-btn--revision {
	background: transparent;
	color: #D97706;
	border-color: #D97706;
	padding: 10px 20px;
}
.cfa-btn--revision:hover:not(:disabled) { background: #FFFBEB; }

/* Revision modal accent */
.cfa-modal--revision {
	border-left-color: #F59E0B;
}
.cfa-modal--revision .cfa-modal-icon {
	background: #FFFBEB;
}
.cfa-modal--revision .cfa-modal-icon svg {
	stroke: #D97706;
}
.cfa-modal--revision .cfa-modal-textarea:focus {
	border-color: #F59E0B;
	box-shadow: 0 0 0 3px rgba(245,158,11,0.12);
}
.cfa-modal--revision .cfa-modal-btn--confirm {
	background: #F59E0B;
	box-shadow: 0 2px 8px rgba(245,158,11,.3);
}
.cfa-modal--revision .cfa-modal-btn--confirm:hover {
	background: #D97706;
	box-shadow: 0 4px 14px rgba(245,158,11,.4);
}

/* revision_requested status label */
.cfa-status--revision { color: #D97706; }

/* ── Mobile ─────────────────────────────────────────────────── */
@media (max-width: 600px) {
	.cfa-bar {
		padding: 12px 20px;
		flex-direction: column;
		gap: 8px;
	}
	.cfa-contact { font-size: 12px; }
	.cfa-buttons {
		width: 100%;
	}
	.cfa-btn {
		flex: 1;
		justify-content: center;
		font-size: 14px;
		padding: 12px 16px;
	}
}

@media print { .cfa-bar { display: none !important; } }

/* ── Decline modal ──────────────────────────────────────────── */
@keyframes co-modal-in {
	from { opacity: 0; transform: scale(0.95) translateY(8px); }
	to   { opacity: 1; transform: scale(1)    translateY(0);   }
}

.cfa-modal-overlay {
	position: fixed;
	inset: 0;
	z-index: 400;
	background: rgba(15, 23, 42, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 20px;
	backdrop-filter: blur(3px);
	-webkit-backdrop-filter: blur(3px);
	animation: cfa-fade-overlay 0.2s ease both;
}
@keyframes cfa-fade-overlay {
	from { opacity: 0; }
	to   { opacity: 1; }
}

.cfa-modal {
	background: #fff;
	border-radius: 14px;
	border-left: 3px solid #EF4444;
	padding: 32px;
	width: 100%;
	max-width: 480px;
	box-shadow:
		0 4px 6px rgba(15,23,42,.05),
		0 20px 60px rgba(15,23,42,.18);
	animation: co-modal-in 0.25s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.cfa-modal-icon {
	width: 44px;
	height: 44px;
	border-radius: 50%;
	background: #FEF2F2;
	display: flex;
	align-items: center;
	justify-content: center;
	margin-bottom: 18px;
}
.cfa-modal-icon svg {
	width: 20px;
	height: 20px;
	stroke: #EF4444;
	stroke-width: 2;
}

.cfa-modal-title {
	font-family: 'Playfair Display', Georgia, serif;
	font-size: 20px;
	font-weight: 600;
	color: #0F172A;
	margin: 0 0 8px;
	line-height: 1.3;
}

.cfa-modal-subtitle {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	color: #64748B;
	margin: 0 0 22px;
	line-height: 1.55;
}

.cfa-modal-label {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-weight: 600;
	color: #374151;
	margin-bottom: 8px;
	display: block;
}

.cfa-modal-optional {
	font-weight: 400;
	color: #9CA3AF;
	margin-left: 4px;
}

.cfa-modal-textarea {
	width: 100%;
	min-height: 96px;
	padding: 12px 14px;
	border: 1.5px solid #E2E8F0;
	border-radius: 8px;
	font-family: 'DM Sans', sans-serif;
	font-size: 13.5px;
	line-height: 1.6;
	color: #1E293B;
	background: #F8FAFC;
	resize: vertical;
	outline: none;
	transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
	box-sizing: border-box;
	margin-bottom: 24px;
}
.cfa-modal-textarea::placeholder {
	color: #CBD5E1;
	font-style: italic;
}
.cfa-modal-textarea:focus {
	border-color: #EF4444;
	box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
	background: #fff;
}

.cfa-modal-actions {
	display: flex;
	gap: 10px;
	justify-content: flex-end;
}

.cfa-modal-btn {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	padding: 10px 20px;
	border-radius: 8px;
	font-family: 'DM Sans', sans-serif;
	font-size: 13.5px;
	font-weight: 600;
	cursor: pointer;
	transition: all 0.15s;
	border: 1.5px solid transparent;
}

.cfa-modal-btn--cancel {
	background: transparent;
	color: #64748B;
	border-color: #E2E8F0;
}
.cfa-modal-btn--cancel:hover {
	background: #F8FAFC;
	border-color: #CBD5E1;
	color: #374151;
}

.cfa-modal-btn--confirm {
	background: #EF4444;
	color: #fff;
	box-shadow: 0 2px 8px rgba(239,68,68,.3);
}
.cfa-modal-btn--confirm:hover {
	background: #DC2626;
	box-shadow: 0 4px 14px rgba(239,68,68,.4);
	transform: translateY(-1px);
}

@media (max-width: 500px) {
	.cfa-modal { padding: 24px 20px; }
	.cfa-modal-actions { flex-direction: column-reverse; }
	.cfa-modal-btn { width: 100%; justify-content: center; }
}
`;

const ACTIONABLE = [ 'draft', 'sent', 'viewed' ];
const CURRENCY_FMT = ( amount, currency = 'GBP' ) =>
	new Intl.NumberFormat( 'en-GB', { style: 'currency', currency } ).format( amount );

function DeclineModal( { onConfirm, onCancel } ) {
	const [ reason, setReason ] = useState( '' );

	return (
		<div
			className="cfa-modal-overlay"
			onClick={ e => { if ( e.target === e.currentTarget ) onCancel(); } }
			role="dialog"
			aria-modal="true"
			aria-labelledby="cfa-modal-title"
		>
			<div className="cfa-modal">
				<div className="cfa-modal-icon">
					<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<circle cx="12" cy="12" r="10"/>
						<line x1="12" y1="8" x2="12" y2="12"/>
						<line x1="12" y1="16" x2="12.01" y2="16"/>
					</svg>
				</div>

				<h2 className="cfa-modal-title" id="cfa-modal-title">
					Are you sure you want to decline?
				</h2>
				<p className="cfa-modal-subtitle">
					This will notify the sender. You can always reach out to discuss further.
				</p>

				<label className="cfa-modal-label" htmlFor="cfa-decline-reason">
					Reason <span className="cfa-modal-optional">(optional)</span>
				</label>
				<textarea
					id="cfa-decline-reason"
					className="cfa-modal-textarea"
					value={ reason }
					onChange={ e => setReason( e.target.value ) }
					placeholder="e.g. The budget doesn't work for us right now…"
					rows={ 3 }
				/>

				<div className="cfa-modal-actions">
					<button
						type="button"
						className="cfa-modal-btn cfa-modal-btn--cancel"
						onClick={ onCancel }
					>
						Cancel
					</button>
					<button
						type="button"
						className="cfa-modal-btn cfa-modal-btn--confirm"
						onClick={ () => onConfirm( reason.trim() ) }
					>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
							<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
						</svg>
						Yes, decline
					</button>
				</div>
			</div>
		</div>
	);
}

function RevisionModal( { onConfirm, onCancel } ) {
	const [ note, setNote ] = useState( '' );

	return (
		<div
			className="cfa-modal-overlay"
			onClick={ e => { if ( e.target === e.currentTarget ) onCancel(); } }
			role="dialog"
			aria-modal="true"
			aria-labelledby="cfa-revision-title"
		>
			<div className="cfa-modal cfa-modal--revision">
				<div className="cfa-modal-icon">
					<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
						<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
					</svg>
				</div>

				<h2 className="cfa-modal-title" id="cfa-revision-title">
					Request a Change
				</h2>
				<p className="cfa-modal-subtitle">
					Let the sender know what you'd like amended. They'll be notified and can update the proposal before you decide.
				</p>

				<label className="cfa-modal-label" htmlFor="cfa-revision-note">
					Your note <span className="cfa-modal-optional">(optional)</span>
				</label>
				<textarea
					id="cfa-revision-note"
					className="cfa-modal-textarea"
					value={ note }
					onChange={ e => setNote( e.target.value ) }
					placeholder="e.g. Could you adjust the timeline on phase 2 and add a monthly retainer option?"
					rows={ 3 }
				/>

				<div className="cfa-modal-actions">
					<button
						type="button"
						className="cfa-modal-btn cfa-modal-btn--cancel"
						onClick={ onCancel }
					>
						Cancel
					</button>
					<button
						type="button"
						className="cfa-modal-btn cfa-modal-btn--confirm"
						onClick={ () => onConfirm( note.trim() ) }
					>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
							<polyline points="20 6 9 17 4 12"/>
						</svg>
						Submit Request
					</button>
				</div>
			</div>
		</div>
	);
}

export default function ClientActionButtons( { status, paymentEnabled, hasPaid, remainingBalance, ownerEmail, onAccept, onDecline, onRequestChange, onPayment, loading } ) {
	injectStyles( 'co-actions-s', CSS );

	const [ showModal, setShowModal ] = useState( false );
	const [ showRevisionModal, setShowRevisionModal ] = useState( false );

	function handleDeclineConfirm( reason ) {
		setShowModal( false );
		onDecline( reason );
	}

	function handleRevisionConfirm( note ) {
		setShowRevisionModal( false );
		onRequestChange( note );
	}

	const hasRemainingBalance = remainingBalance > 0;
	const showPaymentButton = paymentEnabled && (
		( status === 'accepted' && ! hasPaid ) ||
		( status === 'completed' && hasRemainingBalance )
	);

	// Terminal non-payment state — minimal bar
	if ( ! ACTIONABLE.includes( status ) && ! showPaymentButton ) {
		return (
			<div className="cfa-bar">
				<a href={ `mailto:${ ownerEmail || '' }` } className="cfa-contact">Questions? Contact us</a>
				<div className="cfa-buttons">
					{ status === 'accepted' && (
						<span className="cfa-status cfa-status--accepted">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
								<polyline points="20 6 9 17 4 12"/>
							</svg>
							{ hasPaid ? 'Payment Received' : 'Proposal Accepted' }
						</span>
					) }
					{ status === 'declined' && (
						<span className="cfa-status cfa-status--declined">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
								<circle cx="12" cy="12" r="10"/>
								<line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
							</svg>
							Proposal Declined
						</span>
					) }
					{ status === 'expired' && (
						<span className="cfa-status cfa-status--expired">
							This proposal has expired
						</span>
					) }
					{ status === 'revision_requested' && (
						<span className="cfa-status cfa-status--revision">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
								<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
								<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
							</svg>
							Changes Requested
						</span>
					) }
					{ status === 'completed' && (
						<span className="cfa-status cfa-status--accepted">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
								<polyline points="20 6 9 17 4 12"/>
							</svg>
							Project Complete
						</span>
					) }
				</div>
			</div>
		);
	}

	return (
		<>
			{ showModal && (
				<DeclineModal
					onConfirm={ handleDeclineConfirm }
					onCancel={ () => setShowModal( false ) }
				/>
			) }
			{ showRevisionModal && (
				<RevisionModal
					onConfirm={ handleRevisionConfirm }
					onCancel={ () => setShowRevisionModal( false ) }
				/>
			) }

			<div className="cfa-bar">
				<a href={ `mailto:${ ownerEmail || '' }` } className="cfa-contact">Questions? Contact us</a>

				<div className="cfa-buttons">
					{ ACTIONABLE.includes( status ) && (
						<>
							<button
								className="cfa-btn cfa-btn--decline"
								onClick={ () => setShowModal( true ) }
								disabled={ loading }
								aria-label="Decline this proposal"
							>
								Decline
							</button>

							{ ( status === 'sent' || status === 'viewed' ) && (
								<button
									className="cfa-btn cfa-btn--revision"
									onClick={ () => setShowRevisionModal( true ) }
									disabled={ loading }
									aria-label="Request a change to this proposal"
								>
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
										<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
										<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
									</svg>
									Request a Change
								</button>
							) }

							<button
								className="cfa-btn cfa-btn--accept"
								onClick={ onAccept }
								disabled={ loading }
								aria-label="Accept this proposal"
							>
								{ loading ? (
									<><div className="cfa-spinner" />Processing…</>
								) : (
									<>
										<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
											<polyline points="20 6 9 17 4 12"/>
										</svg>
										Accept Proposal
									</>
								) }
							</button>
						</>
					) }

					{ showPaymentButton && (
						<button
							className="cfa-btn cfa-btn--payment"
							onClick={ onPayment }
							disabled={ loading }
						>
							{ loading ? (
								<><div className="cfa-spinner" />Preparing…</>
							) : status === 'completed' && hasRemainingBalance ? (
								`Pay remaining balance →`
							) : (
								'Proceed to Payment →'
							) }
						</button>
					) }
				</div>
			</div>
		</>
	);
}
