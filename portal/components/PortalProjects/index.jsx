/**
 * PortalProjects
 *
 * Client-facing project tracker. Shows project cards with inline milestone
 * expand/collapse, file downloads, and approval responses.
 *
 * Design: warm paper background, white cards, generous spacing.
 */

const { useState, useEffect } = wp.element;

const apiFetch = ( path, opts = {} ) => {
	const base = window.coPortalData.apiUrl.replace( /\/$/, '' );
	const url  = base + '/' + path.replace( /^\//, '' );
	return fetch( url, {
		headers: {
			'X-WP-Nonce':   window.coPortalData.nonce,
			'Content-Type': 'application/json',
		},
		...opts,
	} ).then( r => r.json() );
};

// For multipart / raw fetches (file download links use anchor href, not fetch).

injectStyles( 'cppr-s', `
/* ── Page header ──────────────────────────────────────── */
.cppr-header { margin-bottom: 36px; }
.cppr-title {
	font-family: 'Playfair Display', serif;
	font-size: 30px; font-weight: 700;
	color: #1A1A2E; letter-spacing: -.5px;
	margin-bottom: 6px;
}
.cppr-subtitle { font-family: 'DM Sans', sans-serif; font-size: 14px; color: #6B7280; }

/* ── Project cards ───────────────────────────────────── */
.cppr-grid { display: flex; flex-direction: column; gap: 16px; }

.cppr-card {
	background: #fff; border: 1px solid #EEECEA; border-radius: 12px;
	box-shadow: 0 1px 3px rgba(15,23,42,.04), 0 4px 16px rgba(15,23,42,.06);
	overflow: hidden;
	transition: box-shadow .15s;
}
.cppr-card:hover { box-shadow: 0 4px 24px rgba(15,23,42,.10); }

.cppr-card-header {
	padding: 22px 26px;
	display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
	cursor: pointer; user-select: none;
}

.cppr-card-left { flex: 1; min-width: 0; }
.cppr-card-name {
	font-family: 'Playfair Display', serif;
	font-size: 18px; color: #1A1A2E; margin-bottom: 4px;
}
.cppr-card-proposal {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px; color: #6B7280;
}

.cppr-card-right {
	display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex-shrink: 0;
}

/* Status badge */
.cppr-badge {
	display: inline-flex; align-items: center; padding: 3px 10px;
	border-radius: 20px; font-family: 'DM Sans', sans-serif;
	font-size: 11px; font-weight: 600; letter-spacing: .4px; white-space: nowrap;
}
.cppr-badge-active    { background: #EEF2FF; color: #6366F1; }
.cppr-badge-on-hold   { background: #FFFBEB; color: #F59E0B; }
.cppr-badge-completed { background: #ECFDF5; color: #10B981; }
.cppr-badge-archived  { background: #F3F4F6; color: #9CA3AF; }

/* Progress */
.cppr-progress-wrap { width: 160px; }
.cppr-progress-label {
	display: flex; justify-content: space-between; align-items: center;
	font-family: 'DM Sans', sans-serif; font-size: 11px; color: #9CA3AF; margin-bottom: 5px;
}
.cppr-progress-bar {
	height: 5px; background: #F1F5F9; border-radius: 99px; overflow: hidden;
}
.cppr-progress-fill {
	height: 100%; border-radius: 99px; background: #10B981;
	transition: width .5s cubic-bezier(.4,0,.2,1);
}

/* Expand chevron */
.cppr-chevron {
	width: 20px; height: 20px; color: #9CA3AF;
	transition: transform .2s;
}
.cppr-chevron.open { transform: rotate(180deg); }

/* ── Expanded body ────────────────────────────────── */
.cppr-card-body {
	border-top: 1px solid #F1F5F9;
	display: none;
}
.cppr-card-body.open { display: block; }

/* ── Milestones panel ────────────────────────────────── */
.cppr-milestones { padding: 20px 26px 20px; }

.cppr-ms-list { display: flex; flex-direction: column; gap: 0; margin-top: 16px; }

.cppr-ms-item {
	display: flex; align-items: center; gap: 12px;
	padding: 11px 0;
	border-bottom: 1px solid #F8F7F5;
	animation: cppr-fade-in .25s ease both;
}
.cppr-ms-item:last-child { border-bottom: none; }

@keyframes cppr-fade-in {
	from { opacity: 0; transform: translateY(6px); }
	to   { opacity: 1; transform: translateY(0); }
}

/* Status icon */
.cppr-ms-icon {
	width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0;
	display: flex; align-items: center; justify-content: center;
}
.cppr-ms-icon-pending     { background: #F1F5F9; }
.cppr-ms-icon-in-progress { background: #EEF2FF; }
.cppr-ms-icon-completed   { background: #10B981; }

.cppr-ms-text { flex: 1; }
.cppr-ms-title {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px; color: #1A1A2E; line-height: 1.4;
}
.cppr-ms-title.completed { text-decoration: line-through; color: #9CA3AF; }
.cppr-ms-due {
	font-size: 11px; color: #9CA3AF; margin-top: 2px;
}
.cppr-ms-due.overdue { color: #EF4444; font-weight: 600; }

.cppr-ms-status-label {
	font-family: 'DM Sans', sans-serif;
	font-size: 11px; color: #9CA3AF; white-space: nowrap;
}
.cppr-ms-status-label.pending      { color: #9CA3AF; }
.cppr-ms-status-label.in-progress { color: #6366F1; }
.cppr-ms-status-label.completed   { color: #10B981; }

.cppr-payment-due-banner {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	margin: 18px 26px 0;
	padding: 14px 18px;
	border-radius: 10px;
	background: #FFFBEB;
	border: 1.5px solid #FDE68A;
	font-family: 'DM Sans', sans-serif;
}
.cppr-pdb-left {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-shrink: 0;
}
.cppr-pdb-left svg {
	flex-shrink: 0;
	color: #F59E0B;
}
.cppr-pdb-label {
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.07em;
	text-transform: uppercase;
	color: #92400E;
	margin-bottom: 2px;
}
.cppr-pdb-amount {
	font-family: 'DM Mono', monospace;
	font-size: 20px;
	font-weight: 700;
	color: #78350F;
	line-height: 1;
}
.cppr-pdb-pay-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 10px 20px;
	border-radius: 6px;
	background: #92400E;
	color: #FFFBEB;
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-weight: 600;
	text-decoration: none;
	white-space: nowrap;
	flex-shrink: 0;
	transition: background .15s, transform .15s;
}
.cppr-pdb-pay-btn:hover {
	background: #78350F;
	transform: translateY(-1px);
	color: #FFFBEB;
	text-decoration: none;
}

.cppr-ms-approve-btn {
	font-family: 'DM Sans', sans-serif;
	font-size: 11px; font-weight: 600;
	padding: 4px 12px;
	border-radius: 6px;
	border: 1.5px solid #6366F1;
	background: transparent;
	color: #6366F1;
	cursor: pointer;
	white-space: nowrap;
	transition: background .15s, color .15s;
}
.cppr-ms-approve-btn:hover:not(:disabled) {
	background: #6366F1;
	color: #fff;
}
.cppr-ms-approve-btn:disabled {
	opacity: .55;
	cursor: not-allowed;
}

/* ── Panel divider ───────────────────────────────────── */
.cppr-panel-divider {
	height: 1px;
	background: #F1F5F9;
	margin: 0 26px;
}

/* ── Section header ──────────────────────────────────── */
.cppr-section {
	padding: 20px 26px;
}
.cppr-section-title {
	font-family: 'DM Sans', sans-serif;
	font-size: 12px; font-weight: 700;
	text-transform: uppercase; letter-spacing: .07em;
	color: #9CA3AF;
	margin-bottom: 14px;
	display: flex; align-items: center; gap: 7px;
}
.cppr-section-title svg {
	width: 13px; height: 13px;
	stroke: currentColor; stroke-width: 2;
}

/* ── Files ───────────────────────────────────────────── */
.cppr-files { display: flex; flex-direction: column; gap: 8px; }

.cppr-file-row {
	display: flex; align-items: center; gap: 12px;
	padding: 10px 14px;
	background: #F8F7F5;
	border-radius: 8px;
	animation: cppr-fade-in .2s ease both;
}

.cppr-file-icon {
	width: 32px; height: 32px; border-radius: 7px;
	display: flex; align-items: center; justify-content: center;
	background: #fff; flex-shrink: 0;
}
.cppr-file-icon svg { width: 14px; height: 14px; stroke: #6B7280; stroke-width: 1.75; }

.cppr-file-info { flex: 1; min-width: 0; }
.cppr-file-name {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px; font-weight: 600; color: #1A1A2E;
	white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cppr-file-meta {
	font-size: 11px; color: #9CA3AF; margin-top: 2px;
}

.cppr-download-btn {
	display: inline-flex; align-items: center; gap: 5px;
	padding: 6px 12px;
	border: 1.5px solid #6366F1;
	border-radius: 7px;
	font-family: 'DM Sans', sans-serif;
	font-size: 12px; font-weight: 600;
	color: #6366F1;
	background: transparent;
	text-decoration: none;
	transition: background .15s, color .15s;
	white-space: nowrap; flex-shrink: 0;
}
.cppr-download-btn:hover { background: #EEF2FF; }
.cppr-download-btn svg { width: 11px; height: 11px; stroke: currentColor; stroke-width: 2.5; }

.cppr-no-items {
	font-family: 'DM Sans', sans-serif;
	font-size: 13px; color: #9CA3AF; font-style: italic;
}

/* ── Approvals ───────────────────────────────────────── */
.cppr-approvals { display: flex; flex-direction: column; gap: 10px; }

.cppr-approval-card {
	border: 1px solid #EEECEA;
	border-radius: 10px;
	padding: 14px 16px;
	background: #fff;
	animation: cppr-fade-in .2s ease both;
}

.cppr-approval-top {
	display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
	margin-bottom: 8px;
}

.cppr-aprv-type {
	display: inline-flex; padding: 2px 8px;
	border-radius: 999px; font-size: 10px; font-weight: 700;
	text-transform: uppercase; letter-spacing: .05em;
	background: #EEF2FF; color: #6366F1;
}
.cppr-aprv-type.content     { background: #FFFBEB; color: #F59E0B; }
.cppr-aprv-type.deliverable { background: #ECFDF5; color: #10B981; }
.cppr-aprv-type.other       { background: #F1F5F9; color: #64748B; }

.cppr-aprv-status {
	display: inline-flex; align-items: center; gap: 4px;
	padding: 2px 8px; border-radius: 999px;
	font-size: 10px; font-weight: 700;
}
.cppr-aprv-status.pending  { background: #FFFBEB; color: #F59E0B; }
.cppr-aprv-status.approved { background: #ECFDF5; color: #10B981; }
.cppr-aprv-status.rejected { background: #FEF2F2; color: #EF4444; }
.cppr-aprv-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

.cppr-approval-desc {
	font-family: 'DM Sans', sans-serif;
	font-size: 13.5px; color: #374151; line-height: 1.5; margin-bottom: 8px;
}

.cppr-approval-meta {
	font-size: 11px; color: #9CA3AF; margin-bottom: 12px;
}

/* Comment/responded state */
.cppr-aprv-response {
	background: #F8F7F5; border-radius: 7px;
	padding: 10px 12px; margin-bottom: 0;
	font-size: 12.5px; color: #6B7280;
	font-style: italic; line-height: 1.5;
}
.cppr-aprv-response strong {
	font-style: normal; font-weight: 600; font-size: 10.5px;
	text-transform: uppercase; letter-spacing: .04em;
	display: block; margin-bottom: 4px; color: #9CA3AF;
}

/* Response form */
.cppr-aprv-form { margin-top: 4px; }
.cppr-aprv-comment {
	width: 100%; font-family: 'DM Sans', sans-serif;
	font-size: 13px; color: #1A1A2E;
	background: #F8F7F5; border: 1.5px solid #EEECEA;
	border-radius: 8px; padding: 10px 12px;
	resize: vertical; min-height: 70px; outline: none;
	transition: border-color .15s;
	box-sizing: border-box; line-height: 1.5;
	margin-bottom: 10px;
}
.cppr-aprv-comment::placeholder { color: #CBD5E1; font-style: italic; }
.cppr-aprv-comment:focus { border-color: #6366F1; }

.cppr-aprv-actions { display: flex; gap: 8px; }

.cppr-aprv-approve-btn,
.cppr-aprv-reject-btn {
	display: inline-flex; align-items: center; gap: 6px;
	padding: 8px 16px; border: none; border-radius: 8px;
	font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600;
	cursor: pointer; transition: opacity .15s, transform .12s;
}
.cppr-aprv-approve-btn:disabled,
.cppr-aprv-reject-btn:disabled { opacity: .55; cursor: not-allowed; }
.cppr-aprv-approve-btn {
	background: #10B981; color: #fff;
	box-shadow: 0 2px 8px rgba(16,185,129,.3);
}
.cppr-aprv-approve-btn:hover:not(:disabled) { opacity: .9; transform: translateY(-1px); }
.cppr-aprv-reject-btn {
	background: transparent; color: #6B7280;
	border: 1.5px solid #E2E8F0;
}
.cppr-aprv-reject-btn:hover:not(:disabled) { background: #FEF2F2; border-color: #FECACA; color: #EF4444; }
.cppr-aprv-approve-btn svg,
.cppr-aprv-reject-btn svg { width: 12px; height: 12px; stroke: currentColor; stroke-width: 2.5; }

/* ── Messages ────────────────────────────────────────── */
.cppr-msg-window {
	background: #FAFAF9;
	border: 1px solid #EEECEA;
	border-radius: 10px 10px 0 0;
	min-height: 220px;
	max-height: 360px;
	overflow-y: auto;
	padding: 16px 14px;
	display: flex;
	flex-direction: column;
	gap: 3px;
}

.cppr-msg-date-divider {
	display: flex; align-items: center; gap: 8px; margin: 12px 0 8px;
}
.cppr-msg-date-divider::before, .cppr-msg-date-divider::after {
	content: ''; flex: 1; height: 1px; background: #EEECEA;
}
.cppr-msg-date-label {
	font-size: 10.5px; font-weight: 600; color: #9CA3AF; letter-spacing: .4px;
}

.cppr-msg-row { display: flex; flex-direction: column; margin-bottom: 2px; }

.cppr-msg-bubble-wrap { display: flex; align-items: flex-end; gap: 6px; }
.cppr-msg-row.admin  .cppr-msg-bubble-wrap { justify-content: flex-start; }
.cppr-msg-row.client .cppr-msg-bubble-wrap { justify-content: flex-end; }

.cppr-msg-bubble {
	max-width: 88%;
	min-width: 60px;
	padding: 9px 13px;
	border-radius: 14px;
	font-family: 'DM Sans', sans-serif;
	font-size: 13.5px;
	line-height: 1.5;
	word-wrap: break-word;
	overflow-wrap: break-word;
}
.cppr-msg-row.admin .cppr-msg-bubble {
	background: #F1F5F9;
	color: #1A1A2E;
	border: 1px solid #EEECEA;
	border-bottom-left-radius: 4px;
}
.cppr-msg-row.client .cppr-msg-bubble {
	background: #6366F1;
	color: #fff;
	border-bottom-right-radius: 4px;
	box-shadow: 0 2px 8px rgba(99,102,241,.22);
}
.cppr-msg-row.client.same-sender .cppr-msg-bubble { border-bottom-right-radius: 14px; border-top-right-radius: 4px; }
.cppr-msg-row.admin.same-sender  .cppr-msg-bubble { border-bottom-left-radius: 14px; border-top-left-radius: 4px; }

.cppr-msg-meta {
	display: flex; align-items: center; gap: 5px; margin-top: 3px; padding: 0 2px;
}
.cppr-msg-row.client .cppr-msg-meta { flex-direction: row-reverse; justify-content: flex-end; }
.cppr-msg-row.admin  .cppr-msg-meta { justify-content: flex-start; }
.cppr-msg-sender { font-size: 10.5px; font-weight: 600; color: #9CA3AF; }
.cppr-msg-time   { font-size: 10.5px; color: #CBD5E1; }

.cppr-msg-empty {
	flex: 1; display: flex; flex-direction: column;
	align-items: center; justify-content: center;
	gap: 8px; padding: 32px 16px; text-align: center;
}
.cppr-msg-empty-icon {
	width: 44px; height: 44px; background: #F1F5F9; border-radius: 50%;
	display: flex; align-items: center; justify-content: center;
}
.cppr-msg-empty-icon svg { width: 20px; height: 20px; stroke: #CBD5E1; stroke-width: 1.5; }
.cppr-msg-empty p { font-family: 'DM Sans', sans-serif; font-size: 13px; color: #9CA3AF; margin: 0; }

.cppr-msg-composer {
	display: flex; gap: 8px; align-items: flex-end;
	padding: 10px 12px;
	background: #fff;
	border: 1px solid #EEECEA;
	border-top: none;
	border-radius: 0 0 10px 10px;
}
.cppr-msg-textarea {
	flex: 1; min-height: 38px; max-height: 100px;
	padding: 8px 11px;
	border: 1.5px solid #EEECEA;
	border-radius: 8px;
	font-family: 'DM Sans', sans-serif;
	font-size: 13px; color: #1A1A2E;
	background: #FAFAF9;
	resize: none; outline: none;
	line-height: 1.5; overflow-y: auto;
	transition: border-color .12s;
	box-sizing: border-box;
}
.cppr-msg-textarea:focus { border-color: #6366F1; background: #fff; }
.cppr-msg-textarea::placeholder { color: #CBD5E1; }
.cppr-msg-send {
	flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center;
	gap: 5px; padding: 8px 14px; height: 38px;
	background: #6366F1; color: #fff; border: none;
	border-radius: 8px; font-family: 'DM Sans', sans-serif;
	font-size: 12.5px; font-weight: 600; cursor: pointer;
	transition: opacity .12s; white-space: nowrap;
}
.cppr-msg-send:disabled { opacity: .45; cursor: not-allowed; }
.cppr-msg-send:hover:not(:disabled) { opacity: .88; }
.cppr-msg-send svg { width: 12px; height: 12px; stroke: currentColor; stroke-width: 2.5; }
.cppr-msg-send-spinner {
	width: 12px; height: 12px;
	border: 2px solid rgba(255,255,255,.3);
	border-top-color: #fff; border-radius: 50%;
	animation: cppr-spin .65s linear infinite;
}
@keyframes cppr-spin { to { transform: rotate(360deg); } }

/* ── Skeleton ─────────────────────────────────────────── */
.cppr-skeleton {
	background: linear-gradient(90deg, #F1F5F9 25%, #F8FAFC 50%, #F1F5F9 75%);
	background-size: 200% 100%;
	animation: cppr-shimmer 1.4s infinite;
	border-radius: 6px;
}
@keyframes cppr-shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

/* ── Empty state ──────────────────────────────────────── */
.cppr-empty {
	text-align: center; padding: 64px 32px;
	background: #fff; border: 1.5px dashed #EEECEA;
	border-radius: 12px;
}
.cppr-empty-icon { color: #CBD5E1; display: block; margin: 0 auto 16px; }
.cppr-empty-title {
	font-family: 'Playfair Display', serif;
	font-size: 20px; color: #1A1A2E; margin-bottom: 8px;
}
.cppr-empty-sub {
	font-family: 'DM Sans', sans-serif;
	font-size: 14px; color: #6B7280; max-width: 340px; margin: 0 auto; line-height: 1.6;
}
` );

// ── Helpers ──────────────────────────────────────────────────────────────────

function badgeClass( status, archived = false ) {
	if ( archived ) return 'cppr-badge cppr-badge-archived';
	return {
		'active':    'cppr-badge cppr-badge-active',
		'on-hold':   'cppr-badge cppr-badge-on-hold',
		'completed': 'cppr-badge cppr-badge-completed',
	}[ status ] || 'cppr-badge cppr-badge-active';
}

function badgeLabel( status, archived = false ) {
	if ( archived ) return 'Archived';
	return { 'active': 'Active', 'on-hold': 'On Hold', 'completed': 'Completed' }[ status ] || status;
}

function formatDate( d ) {
	if ( ! d ) return null;
	try { return new Date( d ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short' } ); }
	catch { return d; }
}

function formatDateLong( d ) {
	if ( ! d ) return null;
	try { return new Date( d ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' } ); }
	catch { return d; }
}

function isOverdue( due, status ) {
	return status !== 'completed' && due && new Date( due ) < new Date();
}

// ── Milestone status icon ─────────────────────────────────────────────────────

function MilestoneIcon( { status } ) {
	if ( status === 'completed' ) {
		return (
			<span className="cppr-ms-icon cppr-ms-icon-completed">
				<svg width="11" height="8" viewBox="0 0 11 8" fill="none">
					<path d="M1 4l3 3 6-6" stroke="#fff" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/>
				</svg>
			</span>
		);
	}
	if ( status === 'in-progress' ) {
		return (
			<span className="cppr-ms-icon cppr-ms-icon-in-progress">
				<svg width="10" height="10" viewBox="0 0 10 10" fill="none">
					<circle cx="5" cy="5" r="3" fill="#6366F1"/>
				</svg>
			</span>
		);
	}
	return (
		<span className="cppr-ms-icon cppr-ms-icon-pending">
			<svg width="10" height="10" viewBox="0 0 10 10" fill="none">
				<circle cx="5" cy="5" r="3.5" stroke="#CBD5E1" strokeWidth="1.5"/>
			</svg>
		</span>
	);
}

// ── Project card ──────────────────────────────────────────────────────────────

function ProjectCard( { project } ) {
	const [ open,         setOpen        ] = useState( false );
	const [ files,        setFiles       ] = useState( [] );
	const [ approvals,    setApprovals   ] = useState( [] );
	const [ messages,     setMessages    ] = useState( [] );
	const [ extrasLoaded, setExtrasLoaded ] = useState( false );
	const [ comments,     setComments    ] = useState( {} ); // { [approvalId]: string }
	const [ responding,   setResponding  ] = useState( null ); // approval id in-flight
	const [ msgText,      setMsgText     ] = useState( '' );
	const [ sendingMsg,   setSendingMsg  ] = useState( false );
	const [ milestones,   setMilestones  ] = useState( project.milestones || [] );
	const [ approvingId,  setApprovingId ] = useState( null );
	const msgWindowRef = { current: null };

	const { apiUrl, nonce } = window.coPortalData;

	const isArchived = !! project.deleted_at;

	const total     = parseInt( project.milestone_total,     10 ) || 0;
	const completed = parseInt( project.milestone_completed, 10 ) || 0;
	const pct       = parseInt( project.progress_pct,        10 ) || 0;

	// Load files + approvals + messages on first expand.
	function handleToggle() {
		const opening = ! open;
		setOpen( opening );

		if ( opening && ! extrasLoaded ) {
			setExtrasLoaded( true );
			Promise.all( [
				apiFetch( `portal/projects/${ project.id }/files` ).catch( () => ( { files: [] } ) ),
				apiFetch( `portal/projects/${ project.id }/approvals` ).catch( () => ( { approvals: [] } ) ),
				apiFetch( `portal/projects/${ project.id }/messages` ).catch( () => ( { messages: [] } ) ),
			] ).then( ( [ fd, ad, md ] ) => {
				setFiles( fd.files || [] );
				setApprovals( ad.approvals || [] );
				setMessages( md.messages || [] );
			} );
		}
	}

	async function handleSendMsg() {
		const trimmed = msgText.trim();
		if ( ! trimmed || sendingMsg ) return;
		setSendingMsg( true );
		try {
			const data = await apiFetch( `portal/projects/${ project.id }/messages`, {
				method: 'POST',
				body:   JSON.stringify( { message: trimmed } ),
			} );
			setMessages( data.messages || [] );
			setMsgText( '' );
		} catch {}
		finally { setSendingMsg( false ); }
	}

	function handleMsgKeyDown( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) { e.preventDefault(); handleSendMsg(); }
	}

	async function handleRespond( approvalId, status ) {
		const comment = comments[ approvalId ] || '';
		setResponding( approvalId );
		try {
			const data = await apiFetch( `/portal/approvals/${ approvalId }/respond`, {
				method: 'POST',
				body:   JSON.stringify( { status, comment } ),
			} );
			// Update local state.
			setApprovals( prev => prev.map( a => a.id === approvalId ? ( data.approval || { ...a, status } ) : a ) );
		} catch {
			// Silent — user can retry.
		} finally {
			setResponding( null );
		}
	}

	async function handleApproveMilestone( milestoneId ) {
		setApprovingId( milestoneId );
		try {
			const data = await apiFetch( `portal/projects/${ project.id }/milestones/${ milestoneId }/approve`, {
				method: 'POST',
			} );
			// Update milestones from the refreshed project response.
			if ( data.project?.milestones ) {
				setMilestones( data.project.milestones );
			} else {
				// Optimistic fallback.
				setMilestones( prev => prev.map( m => m.id === milestoneId ? { ...m, status: 'in-progress' } : m ) );
			}
		} catch {}
		finally { setApprovingId( null ); }
	}

	const TYPE_LABELS = { design: 'Design', content: 'Content', deliverable: 'Deliverable', other: 'Other' };
	const STATUS_LABELS = { pending: 'Awaiting Review', approved: 'Approved', rejected: 'Changes Requested' };

	return (
		<div className="cppr-card">
			{ /* Card header */ }
			<div className="cppr-card-header" onClick={ handleToggle }>
				<div className="cppr-card-left">
					<p className="cppr-card-name">{ project.name }</p>
					{ project.proposal_title && (
						<p className="cppr-card-proposal">Proposal: { project.proposal_title }</p>
					) }
				</div>

				<div className="cppr-card-right">
					<span className={ badgeClass( project.status, isArchived ) }>
						{ badgeLabel( project.status, isArchived ) }
					</span>

					{ total > 0 && (
						<div className="cppr-progress-wrap">
							<div className="cppr-progress-label">
								<span>{ completed }/{ total } milestones</span>
								<span>{ pct }%</span>
							</div>
							<div className="cppr-progress-bar">
								<div className="cppr-progress-fill" style={ { width: `${ pct }%` } } />
							</div>
						</div>
					) }
				</div>

				<svg className={ `cppr-chevron${ open ? ' open' : '' }` }
					viewBox="0 0 24 24" fill="none" stroke="currentColor"
					strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
					<polyline points="6 9 12 15 18 9"/>
				</svg>
			</div>

			{ /* Expanded body */ }
			<div className={ `cppr-card-body${ open ? ' open' : '' }` }>

				{ /* Milestones */ }
				<div className="cppr-milestones">
					<div className="cppr-section-title">
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<polyline points="9 11 12 14 22 4"/>
							<path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
						</svg>
						Milestones
					</div>
					{ milestones.length === 0 ? (
						<p className="cppr-no-items">No milestones added yet.</p>
					) : (
						<div className="cppr-ms-list">
							{ milestones.map( ( m, i ) => {
								const due  = m.due_date ? formatDate( m.due_date ) : null;
								const over = isOverdue( m.due_date, m.status );
								return (
									<div key={ m.id } className="cppr-ms-item" style={ { animationDelay: `${ i * 40 }ms` } }>
										<MilestoneIcon status={ m.status } />
										<div className="cppr-ms-text">
											<p className={ `cppr-ms-title${ m.status === 'completed' ? ' completed' : '' }` }>
												{ m.title }
											</p>
											{ due && (
												<p className={ `cppr-ms-due${ over ? ' overdue' : '' }` }>
													{ over ? 'Overdue · ' : 'Due ' }{ due }
												</p>
											) }
										</div>
										{ m.status === 'submitted' && ! isArchived ? (
											<button
												className="cppr-ms-approve-btn"
												disabled={ approvingId === m.id }
												onClick={ () => handleApproveMilestone( m.id ) }
											>
												{ approvingId === m.id ? '…' : 'Approve' }
											</button>
										) : (
											<span className={ `cppr-ms-status-label ${ m.status }` }>
												{ m.status === 'pending' ? 'Not Started' : m.status === 'in-progress' ? 'In Progress' : 'Done' }
											</span>
										) }
									</div>
								);
							} ) }
						</div>
					) }
				</div>

				{ project.status === 'completed' && project.remaining_balance > 0 && (
					<div className="cppr-payment-due-banner">
						<div className="cppr-pdb-left">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
								<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
								<line x1="12" y1="9" x2="12" y2="13"/>
								<line x1="12" y1="17" x2="12.01" y2="17"/>
							</svg>
							<div>
								<div className="cppr-pdb-label">Final payment due</div>
								<div className="cppr-pdb-amount">
									{ new Intl.NumberFormat( 'en-GB', { style: 'currency', currency: project.currency || 'GBP' } ).format( project.remaining_balance ) }
								</div>
							</div>
						</div>
						<a href={ `/proposals/${ project.proposal_token }` } className="cppr-pdb-pay-btn">
							Pay now <span aria-hidden="true">→</span>
						</a>
					</div>
				) }

				{ /* Files */ }
				<div className="cppr-panel-divider" />
				<div className="cppr-section">
					<div className="cppr-section-title">
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
						</svg>
						Deliverables
					</div>
					{ files.length === 0 ? (
						<p className="cppr-no-items">No files uploaded yet.</p>
					) : (
						<div className="cppr-files">
							{ files.map( ( file, i ) => (
								<div key={ file.id } className="cppr-file-row" style={ { animationDelay: `${ i * 40 }ms` } }>
									<div className="cppr-file-icon">
										<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
											<path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/>
											<polyline points="13 2 13 9 20 9"/>
										</svg>
									</div>
									<div className="cppr-file-info">
										<div className="cppr-file-name" title={ file.file_name }>{ file.file_name }</div>
										<div className="cppr-file-meta">
											{ file.file_size_human } · Uploaded { formatDateLong( file.created_at ) }
										</div>
									</div>
									<a
										className="cppr-download-btn"
										href={ `${ apiUrl.replace( /\/$/, '' ) }/portal/projects/${ project.id }/files/${ file.id }/download?_wpnonce=${ nonce }` }
										download={ file.file_name }
									>
										<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
											<polyline points="8 17 12 21 16 17"/>
											<line x1="12" y1="12" x2="12" y2="21"/>
											<path d="M20.88 18.09A5 5 0 0018 9h-1.26A8 8 0 103 16.11"/>
										</svg>
										Download
									</a>
								</div>
							) ) }
						</div>
					) }
				</div>

				{ /* Approvals */ }
				<div className="cppr-panel-divider" />
				<div className="cppr-section">
					<div className="cppr-section-title">
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
							<polyline points="22 4 12 14.01 9 11.01"/>
						</svg>
						Approvals
					</div>
					{ approvals.length === 0 ? (
						<p className="cppr-no-items">No approval requests yet.</p>
					) : (
						<div className="cppr-approvals">
							{ approvals.map( ( approval, i ) => (
								<div key={ approval.id } className="cppr-approval-card" style={ { animationDelay: `${ i * 40 }ms` } }>
									<div className="cppr-approval-top">
										<span className={ `cppr-aprv-type ${ approval.type }` }>
											{ TYPE_LABELS[ approval.type ] || approval.type }
										</span>
										<span className={ `cppr-aprv-status ${ approval.status }` }>
											<span className="cppr-aprv-dot" />
											{ STATUS_LABELS[ approval.status ] || approval.status }
										</span>
									</div>

									{ approval.description && (
										<p className="cppr-approval-desc">{ approval.description }</p>
									) }
									<p className="cppr-approval-meta">
										Requested { formatDateLong( approval.created_at ) }
									</p>

									{ approval.status !== 'pending' ? (
										approval.client_comment ? (
											<div className="cppr-aprv-response">
												<strong>Your note</strong>
												{ approval.client_comment }
											</div>
										) : null
									) : project.status !== 'completed' && ! isArchived ? (
										<div className="cppr-aprv-form">
											<textarea
												className="cppr-aprv-comment"
												placeholder="Add a note (optional)…"
												value={ comments[ approval.id ] || '' }
												onChange={ e => setComments( c => ( { ...c, [ approval.id ]: e.target.value } ) ) }
												rows={ 2 }
											/>
											<div className="cppr-aprv-actions">
												<button
													type="button"
													className="cppr-aprv-approve-btn"
													disabled={ responding === approval.id }
													onClick={ () => handleRespond( approval.id, 'approved' ) }
												>
													<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
														<polyline points="20 6 9 17 4 12"/>
													</svg>
													{ responding === approval.id ? 'Submitting…' : 'Approve' }
												</button>
												<button
													type="button"
													className="cppr-aprv-reject-btn"
													disabled={ responding === approval.id }
													onClick={ () => handleRespond( approval.id, 'rejected' ) }
												>
													<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
														<circle cx="12" cy="12" r="10"/>
														<line x1="12" y1="8" x2="12" y2="12"/>
														<line x1="12" y1="16" x2="12.01" y2="16"/>
													</svg>
													Request Changes
												</button>
											</div>
										</div>
									) : null }
								</div>
							) ) }
						</div>
					) }
				</div>

				{ /* Messages */ }
				<div className="cppr-panel-divider" />
				<div className="cppr-section">
					<div className="cppr-section-title">
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
						</svg>
						Messages
					</div>
					<div className="cppr-msg-window">
						{ messages.length === 0 ? (
							<div className="cppr-msg-empty">
								<div className="cppr-msg-empty-icon">
									<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
										<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
									</svg>
								</div>
								<p>No messages yet. Say hello!</p>
							</div>
						) : (
							messages.map( ( msg, idx ) => {
								const prev     = messages[ idx - 1 ];
								const next     = messages[ idx + 1 ];
								const isClient = msg.sender_type === 'client';
								const samePrev = prev && prev.sender_type === msg.sender_type;
								const sameNext = next && next.sender_type === msg.sender_type;
								const showDate = ! prev || new Date( prev.created_at ).toDateString() !== new Date( msg.created_at ).toDateString();

								const rowClass = [
									'cppr-msg-row',
									isClient ? 'client' : 'admin',
									samePrev ? 'same-sender' : '',
								].filter( Boolean ).join( ' ' );

								return (
									<div key={ msg.id }>
										{ showDate && (
											<div className="cppr-msg-date-divider">
												<span className="cppr-msg-date-label">
													{ ( () => {
														const d = new Date( msg.created_at );
														const diff = Math.floor( ( new Date() - d ) / 86400000 );
														if ( diff === 0 ) return 'Today';
														if ( diff === 1 ) return 'Yesterday';
														return d.toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short' } );
													} )() }
												</span>
											</div>
										) }
										<div className={ rowClass }>
											<div className="cppr-msg-bubble-wrap">
												<div className="cppr-msg-bubble">{ msg.message }</div>
											</div>
											{ ! sameNext && (
												<div className="cppr-msg-meta">
													<span className="cppr-msg-sender">{ msg.sender_name }</span>
													<span className="cppr-msg-time">
														{ new Date( msg.created_at ).toLocaleTimeString( 'en-GB', { hour: '2-digit', minute: '2-digit' } ) }
													</span>
												</div>
											) }
										</div>
									</div>
								);
							} )
						) }
					</div>
					{ ! isArchived && (
						<div className="cppr-msg-composer">
							<textarea
								className="cppr-msg-textarea"
								placeholder="Send a message… (Enter to send, Shift+Enter for new line)"
								value={ msgText }
								onChange={ e => setMsgText( e.target.value ) }
								onKeyDown={ handleMsgKeyDown }
								rows={ 1 }
								disabled={ sendingMsg }
							/>
							<button
								type="button"
								className="cppr-msg-send"
								onClick={ handleSendMsg }
								disabled={ ! msgText.trim() || sendingMsg }
							>
								{ sendingMsg ? (
									<span className="cppr-msg-send-spinner" />
								) : (
									<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
										<line x1="22" y1="2" x2="11" y2="13"/>
										<polygon points="22 2 15 22 11 13 2 9 22 2" fill="currentColor" stroke="none"/>
									</svg>
								) }
								{ ! sendingMsg && 'Send' }
							</button>
						</div>
					) }
				</div>

			</div>
		</div>
	);
}

// ── Main component ────────────────────────────────────────────────────────────

export default function PortalProjects() {
	const [ projects, setProjects ] = useState( [] );
	const [ loading, setLoading ]   = useState( true );

	useEffect( () => {
		apiFetch( '/portal/projects' )
			.then( d => {
				const ids = ( d.projects || [] ).map( p => p.id );
				return Promise.all(
					ids.map( id => apiFetch( `/portal/projects/${ id }` ).then( r => r.project ) )
				);
			} )
			.then( full => setProjects( full.filter( Boolean ) ) )
			.catch( () => setProjects( [] ) )
			.finally( () => setLoading( false ) );
	}, [] );

	if ( loading ) {
		return (
			<div>
				<div className="cppr-header">
					<div className="cppr-skeleton" style={ { width: 200, height: 30, marginBottom: 8 } } />
					<div className="cppr-skeleton" style={ { width: 140, height: 14 } } />
				</div>
				{ [ 1, 2 ].map( i => (
					<div key={ i } className="cppr-card" style={ { marginBottom: 16, padding: 24 } }>
						<div className="cppr-skeleton" style={ { width: '50%', height: 20, marginBottom: 12 } } />
						<div className="cppr-skeleton" style={ { width: '30%', height: 12 } } />
					</div>
				) ) }
			</div>
		);
	}

	return (
		<div>
			<div className="cppr-header">
				<h1 className="cppr-title">Your Projects</h1>
				<p className="cppr-subtitle">
					{ projects.length > 0
						? `${ projects.length } project${ projects.length !== 1 ? 's' : '' } in progress`
						: 'Track the progress of your accepted proposals.' }
				</p>
			</div>

			{ projects.length === 0 ? (
				<div className="cppr-empty">
					<svg className="cppr-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
						<rect x="2" y="7" width="20" height="14" rx="2"/>
						<path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/>
					</svg>
					<p className="cppr-empty-title">No active projects yet</p>
					<p className="cppr-empty-sub">
						You'll see your projects here once a proposal has been accepted.
					</p>
				</div>
			) : (
				<div className="cppr-grid">
					{ projects.map( p => <ProjectCard key={ p.id } project={ p } /> ) }
				</div>
			) }
		</div>
	);
}
