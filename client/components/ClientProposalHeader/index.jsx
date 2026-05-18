/**
 * ClientProposalHeader
 *
 * Full-width proposal header showing business branding, proposal title,
 * client name, status badge, and expiry info. Shows a top banner for
 * terminal states (accepted / declined / expired).
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
.cfh-header {
	background: #fff;
	border-bottom: 1px solid rgba(26,26,46,.07);
	box-shadow: 0 2px 20px rgba(26,26,46,.05);
}

/* ── Portal back link ──────────────────────────────────────── */
.cfh-portal-back {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 9px 48px;
	font-family: 'DM Sans', sans-serif;
	font-size: 12px;
	font-weight: 500;
	color: #9CA3AF;
	text-decoration: none;
	background: #FAFAF8;
	border-bottom: 1px solid rgba(26,26,46,.06);
	transition: color .12s, background .12s;
}
.cfh-portal-back:hover {
	color: #6366F1;
	background: #F5F6FF;
}
.cfh-portal-back svg {
	flex-shrink: 0;
	transition: transform .12s;
}
.cfh-portal-back:hover svg {
	transform: translateX(-2px);
}

@media (max-width: 680px) {
	.cfh-portal-back { padding: 9px 24px; }
}

/* ── State banners ─────────────────────────────────────────── */
.cfh-banner {
	padding: 13px 48px;
	font-family: 'DM Sans', sans-serif;
	font-size: 13.5px;
	font-weight: 500;
	display: flex;
	align-items: center;
	gap: 9px;
	letter-spacing: 0.01em;
}
.cfh-banner--expired  { background: #FFFBEB; color: #92400E; border-bottom: 1px solid #FDE68A; }
.cfh-banner--accepted { background: #ECFDF5; color: #065F46; border-bottom: 1px solid #A7F3D0; }
.cfh-banner--declined { background: #F8FAFC; color: #475569; border-bottom: 1px solid #E2E8F0; }

/* ── Main layout ───────────────────────────────────────────── */
.cfh-main {
    max-width: 780px;
    margin: 0 auto;
    padding: 44px 56px 36px;
    display: flex;
    /* grid-template-columns: 1fr 5fr; */
    gap: 28px;
    align-items: flex-start;
    flex-direction: column;
}

/* ── Brand column ──────────────────────────────────────────── */
.cfh-brand {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 9px;
	padding-top: 6px;
}
.cfh-logo {
	height: 48px;
	width: auto;
	max-width: 180px;
	object-fit: contain;
	border-radius: 4px;
	display: block;
}
.cfh-initials {
	width: 58px;
	height: 58px;
	border-radius: 13px;
	display: flex;
	align-items: center;
	justify-content: center;
	font-family: 'Playfair Display', serif;
	font-size: 20px;
	font-weight: 700;
	color: #fff;
	letter-spacing: 1px;
	flex-shrink: 0;
}
.cfh-business-name {
	font-family: 'DM Sans', sans-serif;
	font-size: 10.5px;
	font-weight: 700;
	color: #9CA3AF;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	text-align: center;
	white-space: nowrap;
}

/* ── Content column ────────────────────────────────────────── */
.cfh-eyebrow {
	font-family: 'DM Sans', sans-serif;
	font-size: 10.5px;
	font-weight: 700;
	color: #C4B5FD;
	letter-spacing: 0.14em;
	text-transform: uppercase;
	margin-bottom: 10px;
}
.cfh-title {
	font-family: 'Playfair Display', serif;
	font-size: 40px;
	font-weight: 700;
	color: #1A1A2E;
	line-height: 1.12;
	margin: 0 0 12px;
	letter-spacing: -0.5px;
}
.cfh-prepared {
	font-family: 'DM Sans', sans-serif;
	font-size: 15px;
	color: #6B7280;
	margin: 0;
}
.cfh-prepared strong {
	color: #1A1A2E;
	font-weight: 600;
}

/* ── Meta row ──────────────────────────────────────────────── */
.cfh-meta {
	display: none;
	align-items: center;
	flex-wrap: wrap;
	gap: 10px;
}

/* ── Status badge ──────────────────────────────────────────── */
.cfh-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 5px 12px;
	border-radius: 100px;
	font-family: 'DM Sans', sans-serif;
	font-size: 11.5px;
	font-weight: 700;
	letter-spacing: 0.04em;
}
.cfh-badge--draft    { background: #F1F5F9; color: #64748B; }
.cfh-badge--sent     { background: #EEF2FF; color: #4338CA; }
.cfh-badge--viewed   { background: #FFFBEB; color: #92400E; }
.cfh-badge--accepted { background: #ECFDF5; color: #065F46; }
.cfh-badge--declined { background: #FEF2F2; color: #991B1B; }
.cfh-badge--expired  { background: #FEF3C7; color: #92400E; }

/* ── Expiry chip ───────────────────────────────────────────── */
.cfh-expiry {
	font-family: 'DM Sans', sans-serif;
	font-size: 12.5px;
	color: #9CA3AF;
	display: inline-flex;
	align-items: center;
	gap: 5px;
}
.cfh-expiry--warn    { color: #D97706; font-weight: 600; }
.cfh-expiry--expired { color: #EF4444; font-weight: 700; }

/* ── Responsive ────────────────────────────────────────────── */
@media (max-width: 680px) {
	.cfh-banner { padding: 12px 24px; }
	.cfh-main { grid-template-columns: 1fr; gap: 20px; padding: 28px 24px 24px; }
	.cfh-brand { flex-direction: row; align-items: center; gap: 14px; }
	.cfh-business-name { text-align: left; }
	.cfh-title { font-size: 28px; }
}

@media print {
	.cfh-banner { display: none !important; }
	.cfh-header { box-shadow: none; border-bottom: 1px solid #ddd; }
}
`;

const STATUS_DOTS = {
	draft:    '#94A3B8',
	sent:     '#818CF8',
	viewed:   '#F59E0B',
	accepted: '#10B981',
	declined: '#EF4444',
	expired:  '#F59E0B',
};

const STATUS_LABELS = {
	draft: 'Draft', sent: 'Sent', viewed: 'Viewed',
	accepted: 'Accepted', declined: 'Declined', expired: 'Expired',
};

function StatusDot( { status } ) {
	const fill = STATUS_DOTS[ status ] || '#94A3B8';
	return (
		<svg width="7" height="7" viewBox="0 0 7 7" fill="none">
			<circle cx="3.5" cy="3.5" r="3.5" fill={ fill } />
		</svg>
	);
}

function expiryInfo( expiryDate, status ) {
	if ( ! expiryDate || [ 'accepted', 'declined' ].includes( status ) ) return null;
	const expiry = new Date( expiryDate );
	const now    = new Date();
	const days   = Math.ceil( ( expiry - now ) / 86400000 );
	const fmt    = expiry.toLocaleDateString( 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' } );

	if ( days < 0 )  return { text: `Expired ${ fmt }`, cls: 'cfh-expiry--expired' };
	if ( days <= 7 ) return { text: `Expires ${ fmt } · ${ days }d left`, cls: 'cfh-expiry--warn' };
	return { text: `Expires ${ fmt }`, cls: '' };
}

export default function ClientProposalHeader( { proposal, businessName, businessLogo, hideBusinessName = false } ) {
	injectStyles( 'co-header-s', CSS );

	const { title, expiry_date, status, client_name, accepted_at } = proposal;
	const initials    = ( businessName || 'CF' ).replace( /[^A-Za-z]/g, '' ).slice( 0, 2 ).toUpperCase() || 'CF';
	const expiry      = expiryInfo( expiry_date, status );
	const brandColor  = window.coClientData?.brandColor || '#6366F1';

	const fmtDate = iso =>
		iso ? new Date( iso ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' } ) : '';

	const isPortalClient = window.coClientData?.isPortalClient;

	return (
		<header className="cfh-header">

			{ isPortalClient && (
				<a href="/clientoctopus/proposals" className="cfh-portal-back">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
						<polyline points="15 18 9 12 15 6"/>
					</svg>
					Back to portal
				</a>
			) }

			{ status === 'expired' && (
				<div className="cfh-banner cfh-banner--expired">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
						<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
						<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
					</svg>
					This proposal has expired. Please contact us to discuss next steps.
				</div>
			) }

			{ status === 'accepted' && (
				<div className="cfh-banner cfh-banner--accepted">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
						<polyline points="20 6 9 17 4 12"/>
					</svg>
					You accepted this proposal{ accepted_at ? ` on ${ fmtDate( accepted_at ) }` : '' }. Thank you!
				</div>
			) }

			{ status === 'declined' && (
				<div className="cfh-banner cfh-banner--declined">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
						<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
					</svg>
					You declined this proposal.
				</div>
			) }

			<div className="cfh-main">
				<div className="cfh-brand">
					{ businessLogo
						? <img src={ businessLogo } alt={ businessName } className="cfh-logo" />
						: <div className="cfh-initials" style={ { background: brandColor } }>{ initials }</div>
					}
					{ ! hideBusinessName && (
						<span className="cfh-business-name">{ businessName || 'Your Business' }</span>
					) }
				</div>

				<div>
					<div className="cfh-eyebrow" style={ { color: brandColor } }>Proposal</div>
					<h1 className="cfh-title">{ title }</h1>
					{ client_name && (
						<p className="cfh-prepared">
							Prepared for <strong>{ client_name }</strong>
						</p>
					) }
					<div className="cfh-meta">
						<span className={ `cfh-badge cfh-badge--${ status }` }>
							<StatusDot status={ status } />
							{ STATUS_LABELS[ status ] || status }
						</span>
						{ expiry && (
							<span className={ `cfh-expiry ${ expiry.cls }` }>
								<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
									<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
								</svg>
								{ expiry.text }
							</span>
						) }
					</div>
				</div>
			</div>

		</header>
	);
}
