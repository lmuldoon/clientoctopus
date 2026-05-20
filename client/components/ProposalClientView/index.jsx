/**
 * ProposalClientView
 *
 * Root orchestrator for the client-facing proposal page.
 * Fetches proposal by token, tracks the view, and wires up
 * accept / decline actions with toast feedback.
 *
 * Expects window.coClientData = { apiUrl, token, businessName, businessLogo }
 */

const { useState, useEffect, useCallback, useRef } = wp.element;

import ClientProposalHeader  from '../ClientProposalHeader';
import ClientProposalSection from '../ClientProposalSection';
import ClientPricingTable    from '../ClientPricingTable';
import ClientActionButtons   from '../ClientActionButtons';
import PaymentModal          from '../PaymentModal';

/* ── Style injection ──────────────────────────────────────────── */
const injectStyles = ( id, css ) => {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
};

/* Fonts + global reset — injected once at root level */
const GLOBAL_CSS = `

*, *::before, *::after { box-sizing: border-box; }

html { scroll-behavior: smooth; }

body {
	background: #F8F7F5;
	margin: 0;
	padding: 0;
	min-height: 100vh;
	font-family: 'DM Sans', sans-serif;
	-webkit-font-smoothing: antialiased;
}

#co-client-root { min-height: 100vh; }
`;

const PAGE_CSS = `
/* ── Page shell ───────────────────────────────────────────────── */
.cfv-page {
	min-height: 100vh;
	background: #F8F7F5;
	padding-bottom: 50px;
}

/* ── Document card ────────────────────────────────────────────── */
.cfv-doc {
	max-width: 780px;
	margin: 0 auto;
	background: #fff;
	box-shadow:
		0 1px 3px rgba(26,26,46,.04),
		0 8px 32px rgba(26,26,46,.07);
	min-height: calc(100vh - 100px);
	animation: cfv-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes cfv-rise {
	from { opacity: 0; transform: translateY(14px); }
	to   { opacity: 1; transform: translateY(0); }
}

.cfv-body {
	padding: 52px 56px 72px;
}

/* Section stagger */
.cfv-body > * {
	animation: cfv-fade 0.4s ease both;
}
.cfv-body > *:nth-child(1) { animation-delay: 0.1s; }
.cfv-body > *:nth-child(2) { animation-delay: 0.17s; }
.cfv-body > *:nth-child(3) { animation-delay: 0.24s; }
.cfv-body > *:nth-child(4) { animation-delay: 0.31s; }
.cfv-body > *:nth-child(5) { animation-delay: 0.38s; }
.cfv-body > *:nth-child(n+6) { animation-delay: 0.44s; }

@keyframes cfv-fade {
	from { opacity: 0; transform: translateY(8px); }
	to   { opacity: 1; transform: translateY(0); }
}

/* ── Expiry banners ─────────────────────────────────────────── */
.cfv-expiry-banner {
	max-width: 780px;
	margin: 0 auto 0;
	padding: 0 0 12px;
}

.cfv-expiry-banner__inner {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 16px 20px;
	border-radius: 10px;
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	line-height: 1.55;
}

.cfv-expiry-banner--expired .cfv-expiry-banner__inner {
	background: #FEF2F2;
	border: 1px solid #FECACA;
	color: #991B1B;
}

.cfv-expiry-banner--warning .cfv-expiry-banner__inner {
	background: #FFFBEB;
	border: 1px solid #FDE68A;
	color: #92400E;
}

.cfv-expiry-banner__icon {
	flex-shrink: 0;
	margin-top: 1px;
}

.cfv-expiry-banner__text strong {
	display: block;
	font-weight: 600;
	margin-bottom: 2px;
}

/* ── Divider ─────────────────────────────────────────────────── */
.cfv-divider {
	height: 1px;
	background: linear-gradient(to right, transparent, #E5E7EB 15%, #E5E7EB 85%, transparent);
	margin: 44px 0;
}

/* ── Loading skeleton ────────────────────────────────────────── */
@keyframes cfv-shimmer {
	0%   { background-position: -700px 0; }
	100% { background-position: 700px 0; }
}

.cfv-skel {
	border-radius: 6px;
	background: linear-gradient(90deg, #EFEFEF 25%, #E4E4E4 50%, #EFEFEF 75%);
	background-size: 700px 100%;
	animation: cfv-shimmer 1.5s infinite;
}

.cfv-loading {
	max-width: 780px;
	margin: 0 auto;
	background: #fff;
	min-height: 100vh;
}

.cfv-loading__head {
	padding: 44px 56px 32px;
	border-bottom: 1px solid #F3F4F6;
	display: flex;
	gap: 28px;
	align-items: flex-start;
}

.cfv-loading__body {
	padding: 52px 56px;
}

.cfv-loading__block {
	margin-bottom: 36px;
}

/* ── Error state ─────────────────────────────────────────────── */
.cfv-error {
	max-width: 460px;
	margin: 80px auto;
	padding: 48px 36px;
	background: #fff;
	border-radius: 16px;
	box-shadow: 0 4px 28px rgba(26,26,46,.08);
	text-align: center;
}

.cfv-error__icon {
	width: 68px;
	height: 68px;
	background: #FEF2F2;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0 auto 24px;
	color: #EF4444;
}

.cfv-error__title {
	font-family: 'Playfair Display', serif;
	font-size: 26px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0 0 12px;
}

.cfv-error__msg {
	font-family: 'DM Sans', sans-serif;
	font-size: 15px;
	color: #6B7280;
	line-height: 1.65;
	margin: 0;
}

/* ── Toast ───────────────────────────────────────────────────── */
.cfv-toasts {
	position: fixed;
	top: 22px;
	right: 22px;
	z-index: 999;
	display: flex;
	flex-direction: column;
	gap: 10px;
	pointer-events: none;
}

.cfv-toast {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 13px 18px;
	border-radius: 10px;
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	font-weight: 500;
	min-width: 260px;
	max-width: 360px;
	box-shadow: 0 4px 22px rgba(0,0,0,.12);
	animation: cfv-toast-in 0.35s cubic-bezier(0.22, 1, 0.36, 1) both;
	pointer-events: all;
	line-height: 1.4;
}

@keyframes cfv-toast-in {
	from { transform: translateX(40px); opacity: 0; }
	to   { transform: translateX(0);    opacity: 1; }
}

.cfv-toast--success { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }
.cfv-toast--error   { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 840px) {
	.cfv-doc, .cfv-loading { margin: 0; box-shadow: none; }
}

@media (max-width: 600px) {
	.cfv-body, .cfv-loading__body { padding: 32px 24px 60px; }
	.cfv-loading__head { padding: 28px 24px; }
}

/* ── Powered-by footer ───────────────────────────────────────── */
.cfv-powered {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 7px;
	padding: 28px 0 36px;
	font-family: 'DM Sans', sans-serif;
	font-size: 12px;
	color: #C4C9D4;
	letter-spacing: .01em;
	user-select: none;
}
.cfv-powered__logo {
	height: 22px;
	width: auto;
	opacity: 0.3;
	filter: grayscale(1);
	display: block;
}

/* ── Print ───────────────────────────────────────────────────── */
@media print {
	.cfv-page    { background: #fff; padding: 0; }
	.cfv-doc     { box-shadow: none; min-height: auto; }
	.cfv-body    { padding: 24px 32px 40px; }
	.cfv-toasts  { display: none; }
	.cfv-powered { display: none; }
	.cfv-expiry-banner { display: none !important; }
}
`;

/* ── Preview banner ───────────────────────────────────────────── */

const PREVIEW_BANNER_CSS = `
.cfv-preview-banner {
	position: sticky;
	top: 0;
	z-index: 300;
	background: #0F172A;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 24px;
	gap: 16px;
	font-family: 'DM Sans', sans-serif;
}
.cfv-preview-banner__left {
	display: flex;
	align-items: center;
	gap: 10px;
}
.cfv-preview-banner__icon {
	flex-shrink: 0;
	color: rgba(255,255,255,0.75);
	display: flex;
	align-items: center;
}
.cfv-preview-banner__label {
	display: flex;
	flex-direction: column;
	gap: 1px;
}
.cfv-preview-banner__label strong {
	font-size: 13px;
	font-weight: 700;
	color: rgba(255,255,255,0.9);
	letter-spacing: .02em;
}
.cfv-preview-banner__label span {
	font-size: 11.5px;
	color: rgba(255,255,255,0.7);
}
.cfv-preview-banner__copy {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 14px;
	border-radius: 6px;
	background: #6666F1;
	color: #FFFFFF;
	font-size: 12px;
	font-weight: 600;
	font-family: 'DM Sans', sans-serif;
	cursor: pointer;
	transition: background .15s, border-color .15s;
	white-space: nowrap;
	flex-shrink: 0;
	border:none;
}
.cfv-preview-banner__copy:hover:not(:disabled) {
	background:#4F46E5;
}
.cfv-preview-banner__copy.copied {
	background: #4F46E5;
	color: #FFFFFF;
}
@media print { .cfv-preview-banner { display: none; } }
`;

function PreviewBanner() {
	const { useState: useLocalState } = wp.element;
	const [ copied, setCopied ]   = useLocalState( false );
	const [ copying, setCopying ] = useLocalState( false );

	async function handleCopy() {
		setCopying( true );
		try {
			await navigator.clipboard.writeText( window.location.href );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2500 );
		} catch {
			// Fallback: select a temporary input
			const inp = document.createElement( 'input' );
			inp.value = window.location.href;
			document.body.appendChild( inp );
			inp.select();
			document.execCommand( 'copy' );
			document.body.removeChild( inp );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2500 );
		} finally {
			setCopying( false );
		}
	}

	injectStyles( 'cfv-preview-banner-s', PREVIEW_BANNER_CSS );

	return (
		<div className="cfv-preview-banner" role="alert" aria-live="polite">
			<div className="cfv-preview-banner__left">
				<span className="cfv-preview-banner__icon">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
						<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
						<circle cx="12" cy="12" r="3"/>
					</svg>
				</span>
				<div className="cfv-preview-banner__label">
					<strong>Internal Preview</strong>
					<span>This link is for internal review only — the client cannot see it</span>
				</div>
			</div>
			<button
				type="button"
				className={ `cfv-preview-banner__copy${ copied ? ' copied' : '' }` }
				onClick={ handleCopy }
				disabled={ copying }
				aria-label="Copy preview link to clipboard"
			>
				{ copied ? (
					<>
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
							<polyline points="20 6 9 17 4 12"/>
						</svg>
						Copied!
					</>
				) : (
					<>
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
							<rect x="9" y="9" width="13" height="13" rx="2"/>
							<path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
						</svg>
						Copy Link
					</>
				) }
			</button>
		</div>
	);
}

/* ── Sub-components ───────────────────────────────────────────── */

function LoadingSkeleton() {
	return (
		<div className="cfv-loading">
			<div className="cfv-loading__head">
				<div className="cfv-skel" style={ { width: 58, height: 58, borderRadius: 13, flexShrink: 0 } } />
				<div style={ { flex: 1 } }>
					<div className="cfv-skel" style={ { height: 12, width: '22%', marginBottom: 14 } } />
					<div className="cfv-skel" style={ { height: 36, width: '65%', marginBottom: 14 } } />
					<div className="cfv-skel" style={ { height: 16, width: '42%', marginBottom: 22 } } />
					<div style={ { display: 'flex', gap: 10 } }>
						<div className="cfv-skel" style={ { height: 26, width: 80, borderRadius: 100 } } />
						<div className="cfv-skel" style={ { height: 26, width: 130 } } />
					</div>
				</div>
			</div>
			<div className="cfv-loading__body">
				{ [ 0.9, 0.7, 0.85 ].map( ( w, i ) => (
					<div key={ i } className="cfv-loading__block">
						<div className="cfv-skel" style={ { height: 24, width: '35%', marginBottom: 14 } } />
						<div className="cfv-skel" style={ { height: 14, marginBottom: 8 } } />
						<div className="cfv-skel" style={ { height: 14, width: `${ w * 100 }%`, marginBottom: 8 } } />
						<div className="cfv-skel" style={ { height: 14, width: '72%' } } />
					</div>
				) ) }
			</div>
		</div>
	);
}

function Toasts( { toasts } ) {
	return (
		<div className="cfv-toasts">
			{ toasts.map( t => (
				<div key={ t.id } className={ `cfv-toast cfv-toast--${ t.type }` }>
					{ t.type === 'success'
						? <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><polyline points="20 6 9 17 4 12"/></svg>
						: <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
					}
					{ t.message }
				</div>
			) ) }
		</div>
	);
}

/* ── API helper ───────────────────────────────────────────────── */
const BASE = ( window.coClientData || {} ).apiUrl || '/wp-json/clientoctopus/v1/';

async function apiFetch( path, opts = {} ) {
	const res  = await fetch( BASE + path, { headers: { 'Content-Type': 'application/json' }, ...opts } );
	const json = await res.json().catch( () => ( {} ) );
	if ( ! res.ok ) throw new Error( json.message || `HTTP ${ res.status }` );
	return json;
}

/* ── Main component ───────────────────────────────────────────── */
export default function ProposalClientView( { isPreview = false } = {} ) {
	injectStyles( 'co-global-s',  GLOBAL_CSS );
	injectStyles( 'co-page-s',    PAGE_CSS );

	const coData       = window.coClientData || {};
	const token        = coData.token        || '';
	const businessName      = coData.businessName      || '';
	const businessLogo      = coData.businessLogo      || '';
	const hideBusinessName  = coData.hideBusinessName  || false;
	const pluginLogoUrl = coData.pluginLogoUrl || '';

	const [ loadState,      setLoadState     ] = useState( 'loading' ); // 'loading' | 'loaded' | 'error'
	const [ proposal,       setProposal      ] = useState( null );
	const [ errorMsg,       setErrorMsg      ] = useState( '' );
	const [ actionLoading,  setActionLoading ] = useState( false );
	const [ toasts,         setToasts        ] = useState( [] );
	const [ showPayment,    setShowPayment   ] = useState( false );
	const viewTracked = useRef( false );

	/* Toast helper */
	const toast = useCallback( ( message, type = 'success' ) => {
		const id = Date.now();
		setToasts( ts => [ ...ts, { id, message, type } ] );
		setTimeout( () => setToasts( ts => ts.filter( t => t.id !== id ) ), 4500 );
	}, [] );

	/* Fetch proposal */
	useEffect( () => {
		if ( ! token ) {
			setLoadState( 'error' );
			setErrorMsg( 'Invalid proposal link.' );
			return;
		}
		const path = isPreview
			? `client/proposals/preview/${ token }`
			: `client/proposals/${ token }`;
		apiFetch( path )
			.then( data => { setProposal( data.proposal ); setLoadState( 'loaded' ); } )
			.catch( err => { setLoadState( 'error' ); setErrorMsg( err.message ); } );
	}, [ token ] );

	/* Track view — fires once after proposal loads; skipped in preview mode */
	useEffect( () => {
		if ( isPreview || loadState !== 'loaded' || viewTracked.current ) return;
		viewTracked.current = true;
		apiFetch( `client/proposals/${ token }/view`, { method: 'POST' } ).catch( () => {} );
	}, [ loadState ] );

	/* Accept */
	const handleAccept = useCallback( async () => {
		setActionLoading( true );
		try {
			await apiFetch( `client/proposals/${ token }/accept`, { method: 'POST' } );
			setProposal( p => ( { ...p, status: 'accepted', accepted_at: new Date().toISOString() } ) );
			toast( 'Proposal accepted! We\'ll be in touch shortly.' );
		} catch ( err ) {
			toast( err.message || 'Could not accept the proposal. Please try again.', 'error' );
		} finally {
			setActionLoading( false );
		}
	}, [ token ] );

	/* Decline */
	const handleDecline = useCallback( async ( reason = '' ) => {
		setActionLoading( true );
		try {
			const body = reason ? JSON.stringify( { reason } ) : undefined;
			await apiFetch( `client/proposals/${ token }/decline`, { method: 'POST', body } );
			setProposal( p => ( { ...p, status: 'declined' } ) );
			toast( 'Proposal declined. Thank you for letting us know.' );
		} catch ( err ) {
			toast( err.message || 'Could not decline the proposal. Please try again.', 'error' );
		} finally {
			setActionLoading( false );
		}
	}, [ token ] );

	/* Request a change */
	const handleRequestChange = useCallback( async ( note = '' ) => {
		setActionLoading( true );
		try {
			const body = JSON.stringify( { note } );
			const data = await apiFetch( `client/proposals/${ token }/request-change`, { method: 'POST', body } );
			setProposal( data.proposal );
			toast( 'Your request has been sent. The sender will be in touch shortly.' );
		} catch ( err ) {
			toast( err.message || 'Could not send your request. Please try again.', 'error' );
		} finally {
			setActionLoading( false );
		}
	}, [ token ] );

	/* ── Render states ────────────────────────────────────────── */
	if ( loadState === 'loading' ) {
		return (
			<div className="cfv-page">
				<LoadingSkeleton />
			</div>
		);
	}

	if ( loadState === 'error' ) {
		return (
			<div className="cfv-page">
				<div className="cfv-error">
					<div className="cfv-error__icon">
						<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
							<circle cx="12" cy="12" r="10"/>
							<line x1="15" y1="9" x2="9" y2="15"/>
							<line x1="9" y1="9" x2="15" y2="15"/>
						</svg>
					</div>
					<h1 className="cfv-error__title">Proposal Not Found</h1>
					<p className="cfv-error__msg">
						{ errorMsg || 'This proposal link may be invalid or has been removed. Please contact us for assistance.' }
					</p>
				</div>
			</div>
		);
	}

	/* ── Full document ────────────────────────────────────────── */
	const content   = proposal.content   || {};
	const sections  = content.sections   || [];
	const lineItems = content.line_items || [];

	const isExpired    = proposal.status === 'expired';
	const daysUntilExpiry = proposal.expiry_date
		? Math.ceil( ( new Date( proposal.expiry_date ) - Date.now() ) / 86400000 )
		: null;
	const showWarning = ! isExpired && daysUntilExpiry !== null && daysUntilExpiry <= 7 && daysUntilExpiry >= 0;

	return (
		<div className="cfv-page">
			{ isPreview && <PreviewBanner /> }
			<div className="cfv-doc">
				<ClientProposalHeader
					proposal={ proposal }
					businessName={ businessName }
					businessLogo={ businessLogo }
					hideBusinessName={ hideBusinessName }
					showDownloadBtn={ true }
				/>

				<div className="cfv-body">
					{ sections.map( ( section, i ) => (
						<ClientProposalSection key={ i } section={ section } />
					) ) }

					{ lineItems.length > 0 && (
						<>
							<div className="cfv-divider" />
							<ClientPricingTable
								items={ lineItems }
								discountPct={ content.discount_pct || 0 }
								vatPct={ content.vat_pct || 0 }
								currency={ proposal.currency || 'GBP' }
								totalAmount={ proposal.total_amount }
							/>
						</>
					) }
				</div>
			</div>

			{ isExpired && (
				<div className="cfv-expiry-banner cfv-expiry-banner--expired">
					<div className="cfv-expiry-banner__inner">
						<span className="cfv-expiry-banner__icon">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
								<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
							</svg>
						</span>
						<div className="cfv-expiry-banner__text">
							<strong>This proposal has expired</strong>
							Please contact us to discuss next steps.
						</div>
					</div>
				</div>
			) }

			{ showWarning && (
				<div className="cfv-expiry-banner cfv-expiry-banner--warning">
					<div className="cfv-expiry-banner__inner">
						<span className="cfv-expiry-banner__icon">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
								<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
							</svg>
						</span>
						<div className="cfv-expiry-banner__text">
							<strong>This proposal expires { daysUntilExpiry === 0 ? 'today' : `in ${ daysUntilExpiry } day${ daysUntilExpiry === 1 ? '' : 's' }` }</strong>
							{ proposal.expiry_date && `Expires on ${ new Date( proposal.expiry_date ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' } ) }.` }
						</div>
					</div>
				</div>
			) }

			{ ! isExpired && ! isPreview && (
				<ClientActionButtons
					status={ proposal.status }
					paymentEnabled={ proposal.payment_enabled }
					hasPaid={ !! proposal.has_paid }
					remainingBalance={ parseFloat( proposal.remaining_balance || 0 ) }
					ownerEmail={ proposal.owner_email }
					onAccept={ handleAccept }
					onDecline={ handleDecline }
					onRequestChange={ handleRequestChange }
					onPayment={ () => setShowPayment( true ) }
					loading={ actionLoading }
				/>
			) }

			{ showPayment && (
				<PaymentModal
					proposal={ proposal }
					onClose={ () => setShowPayment( false ) }
				/>
			) }

			<div className="cfv-powered" aria-hidden="true">
				Powered by
				{ pluginLogoUrl && (
					<img
						src={ pluginLogoUrl }
						alt="Client Octopus"
						className="cfv-powered__logo"
					/>
				) }
			</div>

			<Toasts toasts={ toasts } />
		</div>
	);
}
