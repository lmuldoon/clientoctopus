/**
 * PortalSidebar
 *
 * 260px fixed sidebar. Active item: 3px indigo left-border + pale indigo bg.
 * Collapses to a bottom tab bar on mobile (with sign-out tab).
 *
 * Props: { page } — active page slug
 */

const { useState } = wp.element;

const apiFetch = ( path, opts = {} ) =>
	fetch( window.coPortalData.apiUrl + path, {
		headers: {
			'X-WP-Nonce':   window.coPortalData.nonce,
			'Content-Type': 'application/json',
		},
		...opts,
	} ).then( r => r.json() );

injectStyles( 'cps-s', `
/* ── Sidebar (desktop) ───────────────────────────────── */
.cps-sidebar {
	width: 260px;
	flex-shrink: 0;
	background: #FAFAF8;
	border-right: 1px solid #EEECEA;
	display: flex;
	flex-direction: column;
	min-height: 100vh;
	position: sticky;
	top: 0;
	height: 100vh;
	overflow-y: auto;
}

/* ── Branding ─────────────────────────────────────────── */
.cps-brand {
	padding: 28px 24px 22px;
	display: flex;
	align-items: center;
	gap: 12px;
	position: relative;
	overflow: hidden;
	flex-direction:column;
}

.cps-brand-overlay {
	position: absolute;
	inset: 0;
	background: linear-gradient(135deg, rgba(0,0,0,0.22) 0%, rgba(0,0,0,0.04) 100%);
	pointer-events: none;
}

.cps-logo-wrap {
	width: 40px;
	height: 40px;
	border-radius: 10px;
	background: rgba(255,255,255,0.22);
	border: 1px solid rgba(255,255,255,0.28);
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	overflow: hidden;
	position: relative;
	z-index: 1;
}

.cps-logo-wrap img {
	width: 100%;
	height: 100%;
	object-fit: contain;
}

.cps-logo-wrap.cps-logo-wrap--image {
	width: auto;
	max-width: 120px;
	background: transparent;
	border: none;
	border-radius: 0;
	overflow: visible;
}

.cps-logo-wrap.cps-logo-wrap--image img {
	width: auto;
	height: 100%;
	max-height: 40px;
	max-width: 120px;
	object-fit: contain;
}

.cps-logo-initials {
	font-family: 'Playfair Display', serif;
	font-size: 15px;
	font-weight: 700;
	color: #fff;
}

.cps-biz-name {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	font-weight: 600;
	color: #fff;
	line-height: 1.3;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	position: relative;
	z-index: 1;
	text-shadow: 0 1px 3px rgba(0,0,0,0.18);
}

/* ── Divider ──────────────────────────────────────────── */
.cps-divider {
	height: 1px;
	background: #EEECEA;
	margin: 0 0 12px;
}

/* ── Nav ──────────────────────────────────────────────── */
.cps-nav {
	flex: 1;
	padding: 4px 12px 12px;
}

.cps-nav-item {
	display: flex;
	align-items: center;
	gap: 11px;
	padding: 11px 14px;
	border-radius: 9px;
	text-decoration: none;
	font-family: 'DM Sans', sans-serif;
	font-size: 14px;
	font-weight: 500;
	color: #374151;
	margin-bottom: 2px;
	transition: background .12s, color .12s;
	position: relative;
	border-left: 3px solid transparent;
}

.cps-nav-item:hover:not(.cps-active) {
	background: #F3F4FF;
	color: #4F46E5;
}

.cps-nav-item.cps-active {
	background: #EEF2FF;
	color: #6366F1;
	font-weight: 600;
	border-left-color: #6366F1;
}

/* Soft gradient halo behind active icon */
.cps-nav-item.cps-active .cps-nav-icon {
	position: relative;
}
.cps-nav-item.cps-active .cps-nav-icon::before {
	content: '';
	position: absolute;
	inset: -6px;
	background: radial-gradient(circle, rgba(99,102,241,.18) 0%, transparent 70%);
	border-radius: 50%;
	pointer-events: none;
}

.cps-nav-icon {
	width: 18px;
	height: 18px;
	flex-shrink: 0;
}

/* ── Profile card ─────────────────────────────────────── */
.cps-profile-card {
	margin: 4px 12px 0;
	padding: 10px 12px;
	background: #F0F1FF;
	border-radius: 10px;
	display: flex;
	align-items: center;
	gap: 10px;
	min-width: 0;
}

.cps-avatar {
	width: 34px;
	height: 34px;
	border-radius: 50%;
	background: linear-gradient(135deg, #6366F1 0%, #818CF8 100%);
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	font-family: 'DM Sans', sans-serif;
	font-size: 12px;
	font-weight: 700;
	color: #fff;
	letter-spacing: 0.04em;
	user-select: none;
}

.cps-profile-info {
	flex: 1;
	min-width: 0;
}

.cps-profile-name {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-weight: 600;
	color: #1A1A2E;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	line-height: 1.3;
}

.cps-profile-email {
	font-family: 'DM Sans', sans-serif;
	font-size: 11px;
	color: #9CA3AF;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	margin-top: 1px;
}

.cps-signout-btn {
	width: 28px;
	height: 28px;
	border-radius: 7px;
	background: none;
	border: none;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	color: #9CA3AF;
	transition: background .15s, color .15s;
	flex-shrink: 0;
	padding: 0;
}

.cps-signout-btn:hover {
	background: rgba(239, 68, 68, 0.12);
	color: #EF4444;
}

.cps-signout-btn:disabled {
	opacity: 0.5;
	cursor: default;
}

/* ── Footer ───────────────────────────────────────────── */
.cps-footer {
	padding: 20px 20px 24px;
	display: flex;
	flex-direction: column;
	gap: 14px;
}

/* ── Client Octopus branding ──────────────────────────────── */
.cps-co-branding {
	display: flex;
	align-items: center;
	gap: 7px;
	text-decoration: none;
	opacity: 0.55;
	transition: opacity .15s;
}

.cps-co-branding:hover { opacity: 1; }

.cps-co-branding img {
	height: 20px;
	width: auto;
	flex-shrink: 0;
}

.cps-co-branding span {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-weight: 500;
	color: #9CA3AF;
	letter-spacing: 0.02em;
}

/* ── Mobile bottom tab bar ────────────────────────────── */
@media (max-width: 768px) {
	.cps-sidebar {
		position: fixed;
		bottom: 0;
		left: 0;
		right: 0;
		width: 100%;
		height: auto;
		min-height: auto;
		top: auto;
		border-right: none;
		border-top: 1px solid #EEECEA;
		flex-direction: row;
		z-index: 100;
	}

	.cps-brand      { display: none; }
	.cps-divider    { display: none; }
	.cps-footer     { display: none; }
	.cps-profile-card { display: none; }

	.cps-nav {
		display: flex;
		flex: 1;
		padding: 8px 4px;
		gap: 0;
	}

	.cps-nav-item {
		flex: 1;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 4px;
		padding: 8px 4px;
		margin-bottom: 0;
		border-left: none;
		border-radius: 8px;
		font-size: 11px;
		text-align: center;
		background: none;
		border: none;
		cursor: pointer;
		font-family: 'DM Sans', sans-serif;
		font-weight: 500;
		color: #374151;
		text-decoration: none;
		transition: background .12s, color .12s;
	}

	.cps-nav-item.cps-active {
		border-left-color: transparent;
		background: #EEF2FF;
		color: #6366F1;
	}

	.cps-nav-item:hover:not(.cps-active) {
		background: #F3F4FF;
		color: #4F46E5;
	}

	.cps-nav-item.cps-signout-tab:hover {
		background: rgba(239, 68, 68, 0.08);
		color: #EF4444;
	}

	.cps-nav-item.cps-signout-tab:disabled {
		opacity: 0.6;
		cursor: default;
	}

	.cps-nav-icon { width: 20px; height: 20px; }
}

/* Hide the mobile-only sign-out tab on desktop */
.cps-signout-tab {
	display: none;
}

@media (max-width: 768px) {
	.cps-signout-tab {
		display: flex;
	}
}
` );

// ── Contrast helper ───────────────────────────────────────────────────────────

function getContrastColor( hex ) {
	const c = ( hex || '#6366F1' ).replace( '#', '' );
	const r = parseInt( c.substring( 0, 2 ), 16 ) / 255;
	const g = parseInt( c.substring( 2, 4 ), 16 ) / 255;
	const b = parseInt( c.substring( 4, 6 ), 16 ) / 255;
	const lin = x => x <= 0.04045 ? x / 12.92 : Math.pow( ( x + 0.055 ) / 1.055, 2.4 );
	const L = 0.2126 * lin( r ) + 0.7152 * lin( g ) + 0.0722 * lin( b );
	return L > 0.35 ? '#1A1A2E' : '#ffffff';
}

// ── Icons ─────────────────────────────────────────────────────────────────────

function IconDashboard( { active } ) {
	const c = active ? '#6366F1' : '#6B7280';
	return (
		<svg className="cps-nav-icon" viewBox="0 0 20 20" fill="none"
			stroke={ c } strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
			<rect x="2" y="2"  width="6" height="6"  rx="1.5"/>
			<rect x="12" y="2" width="6" height="6"  rx="1.5"/>
			<rect x="2" y="12" width="6" height="6"  rx="1.5"/>
			<rect x="12" y="12" width="6" height="6" rx="1.5"/>
		</svg>
	);
}

function IconProposals( { active } ) {
	const c = active ? '#6366F1' : '#6B7280';
	return (
		<svg className="cps-nav-icon" viewBox="0 0 20 20" fill="none"
			stroke={ c } strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
			<path d="M4 3h8l4 4v10a1 1 0 01-1 1H5a1 1 0 01-1-1V4a1 1 0 011-1z"/>
			<polyline points="12 3 12 7 16 7"/>
			<line x1="7" y1="11" x2="13" y2="11"/>
			<line x1="7" y1="14" x2="11" y2="14"/>
		</svg>
	);
}

function IconProjects( { active } ) {
	const c = active ? '#6366F1' : '#6B7280';
	return (
		<svg className="cps-nav-icon" viewBox="0 0 20 20" fill="none"
			stroke={ c } strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
			<rect x="2" y="7" width="16" height="11" rx="1.5"/>
			<path d="M13 7V5a1 1 0 00-1-1H8a1 1 0 00-1 1v2"/>
			<line x1="7" y1="11" x2="13" y2="11"/>
			<line x1="7" y1="14" x2="10" y2="14"/>
		</svg>
	);
}

function IconPayments( { active } ) {
	const c = active ? '#6366F1' : '#6B7280';
	return (
		<svg className="cps-nav-icon" viewBox="0 0 20 20" fill="none"
			stroke={ c } strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
			<rect x="2" y="5" width="16" height="12" rx="2"/>
			<line x1="2" y1="9" x2="18" y2="9"/>
			<line x1="6" y1="13" x2="8" y2="13"/>
		</svg>
	);
}

function IconSignOut( { color = '#6B7280' } ) {
	return (
		<svg width="16" height="16" viewBox="0 0 24 24" fill="none"
			stroke={ color } strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
			<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
			<polyline points="16 17 21 12 16 7"/>
			<line x1="21" y1="12" x2="9" y2="12"/>
		</svg>
	);
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function PortalSidebar( { page } ) {
	const { businessName, businessLogo, clientData, pluginUrl, brandColor, hasProjects, hideBusinessName } = window.coPortalData || {};
	const brandBg        = brandColor || '#6366F1';
	const brandTextColor = getContrastColor( brandBg );
	const [ loggingOut, setLoggingOut ] = useState( false );

	const initials = ( businessName || 'CF' )
		.split( ' ' ).slice( 0, 2 ).map( w => w[0] ).join( '' ).toUpperCase();

	const cfLogoUrl = pluginUrl ? pluginUrl + 'assets/images/logo-inline.svg' : '';

	const clientName  = clientData?.name  || 'Client';
	const clientEmail = clientData?.email || '';

	const avatarInitials = clientName
		.split( ' ' ).slice( 0, 2 ).map( w => w[0] ).join( '' ).toUpperCase() || '?';

	const nav = [
		{ slug: 'dashboard', label: 'Dashboard', Icon: IconDashboard },
		{ slug: 'proposals', label: 'Proposals', Icon: IconProposals },
		...( hasProjects ? [ { slug: 'projects', label: 'Projects', Icon: IconProjects } ] : [] ),
		{ slug: 'payments',  label: 'Payments',  Icon: IconPayments  },
	];

	async function handleLogout() {
		setLoggingOut( true );
		try {
			const res = await apiFetch( '/portal/logout', { method: 'POST' } );
			window.location.href = res.redirect_url || '/clientoctopus/login';
		} catch {
			window.location.href = '/clientoctopus/login';
		}
	}

	return (
		<aside className="cps-sidebar">

			{ /* ── Branding ── */ }
			<div className="cps-brand" style={ { background: brandBg } }>
				<div className="cps-brand-overlay" />
				<div className={ `cps-logo-wrap${ businessLogo ? ' cps-logo-wrap--image' : '' }` }>
					{ businessLogo
						? <img src={ businessLogo } alt={ businessName } />
						: <span className="cps-logo-initials" style={ { color: brandTextColor } }>{ initials }</span>
					}
				</div>
				{ ! hideBusinessName && (
					<span className="cps-biz-name" style={ { color: brandTextColor, textShadow: brandTextColor === '#ffffff' ? undefined : 'none' } }>{ businessName || 'Client Octopus' }</span>
				) }
			</div>

			<div className="cps-divider" />

			{ /* ── Navigation ── */ }
			<nav className="cps-nav">
				{ nav.map( ( { slug, label, Icon } ) => (
					<a
						key={ slug }
						href={ `/clientoctopus/${ slug }` }
						className={ `cps-nav-item${ page === slug ? ' cps-active' : '' }` }
					>
						<span className="cps-nav-icon">
							<Icon active={ page === slug } />
						</span>
						{ label }
					</a>
				) ) }

				{ /* Mobile-only sign-out tab */ }
				<button
					className="cps-nav-item cps-signout-tab"
					onClick={ handleLogout }
					disabled={ loggingOut }
				>
					<span className="cps-nav-icon">
						<IconSignOut color={ loggingOut ? '#9CA3AF' : '#6B7280' } />
					</span>
					{ loggingOut ? '…' : 'Sign out' }
				</button>
			</nav>

			{ /* ── Profile card (desktop) ── */ }
			{ clientData && (
				<div className="cps-profile-card">
					<div className="cps-avatar">{ avatarInitials }</div>
					<div className="cps-profile-info">
						<div className="cps-profile-name">{ clientName }</div>
						{ clientEmail && (
							<div className="cps-profile-email">{ clientEmail }</div>
						) }
					</div>
					<button
						className="cps-signout-btn"
						onClick={ handleLogout }
						disabled={ loggingOut }
						title="Sign out"
					>
						<IconSignOut color="currentColor" />
					</button>
				</div>
			) }

			{ /* ── Footer ── */ }
			<div className="cps-footer">
				{ cfLogoUrl && (
					<a
						href="https://clientoctopus.com"
						className="cps-co-branding"
						target="_blank"
						rel="noopener noreferrer"
					>
						<span>Powered by</span>
						<img src={ cfLogoUrl } alt="Client Octopus" />
					</a>
				) }
			</div>

		</aside>
	);
}
