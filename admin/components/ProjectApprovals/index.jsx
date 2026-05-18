import { useState, useEffect } from '@wordpress/element';
import { coFetch } from '../../App';

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
}

const CSS = `
/* ── Section shell ─────────────────────────────────────────────── */
.co-pa {
	margin-top: 36px;
}

.co-pa-header {
	display: flex;
	align-items: center;
	gap: 10px;
	padding-bottom: 14px;
	border-bottom: 1.5px solid var(--co-slate-100);
	margin-bottom: 20px;
}

.co-pa-header-icon {
	width: 32px;
	height: 32px;
	background: var(--co-slate-100);
	border-radius: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.co-pa-header-icon svg {
	width: 15px;
	height: 15px;
	stroke: var(--co-slate-500);
	stroke-width: 2;
}

.co-pa-title {
	font-family: var(--co-font-display);
	font-size: 17px;
	font-weight: 600;
	color: var(--co-navy);
	letter-spacing: -.2px;
}

.co-pa-new-btn {
	margin-left: auto;
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 7px 14px;
	background: var(--co-indigo);
	color: white;
	border: none;
	border-radius: var(--co-radius-sm);
	font-size: 12.5px;
	font-weight: 600;
	font-family: var(--co-font);
	cursor: pointer;
	transition: background .15s, box-shadow .15s, transform .12s;
	box-shadow: 0 2px 8px rgba(99,102,241,.3);
}
.co-pa-new-btn:hover { background: #4F46E5; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(99,102,241,.4); }
.co-pa-new-btn svg { width: 13px; height: 13px; stroke: currentColor; stroke-width: 2.5; }

/* ── Create form ───────────────────────────────────────────────── */
.co-pa-form {
	background: var(--co-slate-50);
	border: 1px solid var(--co-slate-200);
	border-radius: var(--co-radius);
	padding: 20px;
	margin-bottom: 20px;
	animation: co-pa-fade-in .2s ease both;
}

.co-pa-form-title {
	font-size: 13.5px;
	font-weight: 700;
	color: var(--co-navy);
	margin-bottom: 16px;
}

.co-pa-form-row {
	display: grid;
	grid-template-columns: 200px 1fr;
	gap: 12px;
	margin-bottom: 12px;
}

.co-pa-label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	color: var(--co-slate-600);
	margin-bottom: 6px;
	text-transform: uppercase;
	letter-spacing: .04em;
}

.co-pa-select,
.co-pa-textarea {
	width: 100%;
	font-family: var(--co-font);
	font-size: 13.5px;
	color: var(--co-slate-800);
	background: var(--co-white);
	border: var(--co-input-border);
	border-radius: var(--co-radius-sm);
	padding: 9px 12px;
	outline: none;
	transition: border-color .15s, box-shadow .15s;
	box-sizing: border-box;
}
.co-pa-select:focus,
.co-pa-textarea:focus {
	border-color: var(--co-indigo);
	box-shadow: var(--co-input-focus);
}
.co-pa-textarea {
	resize: vertical;
	min-height: 80px;
	line-height: 1.55;
}
.co-pa-textarea::placeholder { color: var(--co-slate-300); }

.co-pa-form-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 4px;
}

.co-pa-submit-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 8px 18px;
	background: var(--co-indigo);
	color: white;
	border: none;
	border-radius: var(--co-radius-sm);
	font-size: 13px;
	font-weight: 600;
	font-family: var(--co-font);
	cursor: pointer;
	transition: background .15s;
}
.co-pa-submit-btn:hover:not(:disabled) { background: #4F46E5; }
.co-pa-submit-btn:disabled { opacity: .55; cursor: not-allowed; }
.co-pa-submit-spinner {
	width: 12px;
	height: 12px;
	border: 2px solid rgba(255,255,255,.35);
	border-top-color: #fff;
	border-radius: 50%;
	animation: co-pa-spin .65s linear infinite;
}

.co-pa-cancel-btn {
	padding: 8px 16px;
	background: transparent;
	color: var(--co-slate-500);
	border: 1.5px solid var(--co-slate-200);
	border-radius: var(--co-radius-sm);
	font-size: 13px;
	font-weight: 500;
	font-family: var(--co-font);
	cursor: pointer;
	transition: background .12s, border-color .12s, color .12s;
}
.co-pa-cancel-btn:hover { background: var(--co-slate-50); border-color: var(--co-slate-300); color: var(--co-slate-700); }

/* ── Approval cards ────────────────────────────────────────────── */
.co-pa-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.co-pa-card {
	background: var(--co-white);
	border: 1px solid var(--co-slate-200);
	border-radius: var(--co-radius-sm);
	padding: 14px 16px;
	display: grid;
	grid-template-columns: 1fr auto;
	gap: 12px;
	align-items: start;
	transition: border-color .15s, box-shadow .15s;
	animation: co-pa-fade-in .3s ease both;
}
.co-pa-card:hover {
	border-color: var(--co-slate-300);
	box-shadow: var(--co-shadow);
}
.co-pa-card:hover .co-pa-card-delete { opacity: 1; }

.co-pa-card-left {}

.co-pa-card-top {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;
	flex-wrap: wrap;
}

.co-pa-type-badge {
	display: inline-flex;
	padding: 3px 9px;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .05em;
}
.co-pa-type-badge.design      { background: var(--co-indigo-bg);  color: var(--co-indigo);  }
.co-pa-type-badge.content     { background: var(--co-amber-bg);   color: var(--co-amber);   }
.co-pa-type-badge.deliverable { background: var(--co-emerald-bg); color: var(--co-emerald); }
.co-pa-type-badge.other       { background: var(--co-slate-100);  color: var(--co-slate-500); }

.co-pa-status-badge {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 3px 9px;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 700;
}
.co-pa-status-badge.pending  { background: var(--co-amber-bg);   color: var(--co-amber);   }
.co-pa-status-badge.approved { background: var(--co-emerald-bg); color: var(--co-emerald); }
.co-pa-status-badge.rejected { background: var(--co-red-bg);     color: var(--co-red);     }
.co-pa-status-dot {
	width: 6px;
	height: 6px;
	border-radius: 50%;
	background: currentColor;
	flex-shrink: 0;
}

.co-pa-description {
	font-size: 13.5px;
	color: var(--co-slate-700);
	line-height: 1.55;
	margin-bottom: 6px;
}

.co-pa-meta {
	font-size: 11.5px;
	color: var(--co-slate-400);
}

.co-pa-client-comment {
	margin-top: 10px;
	padding: 10px 13px;
	background: var(--co-slate-50);
	border-left: 3px solid var(--co-slate-200);
	border-radius: 0 var(--co-radius-sm) var(--co-radius-sm) 0;
	font-size: 12.5px;
	color: var(--co-slate-600);
	font-style: italic;
	line-height: 1.5;
}
.co-pa-client-comment strong {
	font-style: normal;
	font-weight: 600;
	color: var(--co-slate-500);
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: .04em;
	display: block;
	margin-bottom: 4px;
}

.co-pa-card-delete {
	width: 28px;
	height: 28px;
	border: none;
	background: transparent;
	border-radius: 6px;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	color: var(--co-slate-300);
	opacity: 0;
	transition: opacity .15s, background .12s, color .12s;
	flex-shrink: 0;
}
.co-pa-card-delete:hover { background: var(--co-red-bg); color: var(--co-red); }
.co-pa-card-delete svg { width: 13px; height: 13px; stroke: currentColor; stroke-width: 2; }

/* ── Empty state ───────────────────────────────────────────────── */
.co-pa-empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 10px;
	padding: 40px 20px;
	text-align: center;
}
.co-pa-empty-icon {
	width: 64px;
	height: 64px;
	background: var(--co-slate-100);
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	margin-bottom: 4px;
}
.co-pa-empty-icon svg { width: 28px; height: 28px; stroke: var(--co-slate-300); stroke-width: 1.5; }
.co-pa-empty h4 { font-size: 14.5px; font-weight: 700; color: var(--co-slate-600); margin: 0; }
.co-pa-empty p  { font-size: 13px; color: var(--co-slate-400); margin: 0; }

/* ── Locked note ───────────────────────────────────────────────── */
.co-pa-locked-note {
	margin: 0 0 16px;
	padding: 9px 13px;
	background: var(--co-slate-50);
	border: 1px solid var(--co-slate-200);
	border-radius: var(--co-radius-sm);
	font-size: 12.5px;
	color: var(--co-slate-500);
	font-style: italic;
}

/* ── Error ─────────────────────────────────────────────────────── */
.co-pa-error {
	display: flex;
	align-items: center;
	gap: 10px;
	background: var(--co-red-bg);
	border: 1px solid rgba(239,68,68,.2);
	color: var(--co-red);
	border-radius: var(--co-radius-sm);
	padding: 11px 14px;
	font-size: 13px;
	font-weight: 500;
	margin-bottom: 14px;
}
.co-pa-error svg { width: 14px; height: 14px; stroke: currentColor; flex-shrink: 0; }

/* ── Animations ────────────────────────────────────────────────── */
@keyframes co-pa-fade-in {
	from { opacity: 0; transform: translateY(5px); }
	to   { opacity: 1; transform: translateY(0); }
}
@keyframes co-pa-spin { to { transform: rotate(360deg); } }

/* ── Mobile ────────────────────────────────────────────────────── */
@media (max-width: 600px) {
	.co-pa-form-row { grid-template-columns: 1fr; }
}
`;

const TYPE_LABELS = {
	design:      'Design',
	content:     'Content',
	deliverable: 'Deliverable',
	other:       'Other',
};

const STATUS_LABELS = {
	pending:  'Awaiting review',
	approved: 'Approved',
	rejected: 'Changes requested',
};

function formatDate( dateStr ) {
	if ( ! dateStr ) return '';
	try {
		return new Date( dateStr ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' } );
	} catch {
		return dateStr;
	}
}

export default function ProjectApprovals( { projectId, isLocked = false } ) {
	injectStyles( 'co-pa-styles', CSS );

	const [ approvals,   setApprovals  ] = useState( [] );
	const [ loading,     setLoading    ] = useState( true );
	const [ showForm,    setShowForm   ] = useState( false );
	const [ creating,    setCreating   ] = useState( false );
	const [ error,       setError      ] = useState( null );
	const [ form,        setForm       ] = useState( { type: 'design', description: '' } );

	// ── Fetch on mount ────────────────────────────────────────────
	useEffect( () => {
		coFetch( `projects/${ projectId }/approvals` )
			.then( data => setApprovals( data.approvals || [] ) )
			.catch( () => setError( 'Failed to load approval requests.' ) )
			.finally( () => setLoading( false ) );
	}, [ projectId ] );

	// ── Create ────────────────────────────────────────────────────
	async function handleCreate( e ) {
		e.preventDefault();
		setCreating( true );
		setError( null );
		try {
			const data = await coFetch( `projects/${ projectId }/approvals`, {
				method: 'POST',
				body:   JSON.stringify( form ),
			} );
			setApprovals( data.approvals || [] );
			setShowForm( false );
			setForm( { type: 'design', description: '' } );
		} catch ( err ) {
			setError( err.message || 'Failed to create approval request.' );
		} finally {
			setCreating( false );
		}
	}

	// ── Delete ────────────────────────────────────────────────────
	async function handleDelete( approvalId ) {
		try {
			await coFetch( `projects/${ projectId }/approvals/${ approvalId }`, { method: 'DELETE' } );
			setApprovals( prev => prev.filter( a => a.id !== approvalId ) );
		} catch ( err ) {
			setError( err.message || 'Delete failed.' );
		}
	}

	return (
		<div className="co-pa">
			<div className="co-pa-header">
				<div className="co-pa-header-icon">
					<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<polyline points="9 11 12 14 22 4"/>
						<path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
					</svg>
				</div>
				<span className="co-pa-title">Approvals</span>
				{ ! isLocked && ! showForm && (
					<button type="button" className="co-pa-new-btn" onClick={ () => setShowForm( true ) }>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
						</svg>
						New Request
					</button>
				) }
			</div>

			{ error && (
				<div className="co-pa-error">
					<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
					</svg>
					{ error }
				</div>
			) }

			{ isLocked && approvals.length > 0 && (
				<p className="co-pa-locked-note">
					This project is complete — approvals are read-only.
				</p>
			) }

			{ ! isLocked && showForm && (
				<form className="co-pa-form" onSubmit={ handleCreate }>
					<div className="co-pa-form-title">New Approval Request</div>
					<div className="co-pa-form-row">
						<div>
							<label className="co-pa-label" htmlFor="co-pa-type">Type</label>
							<select
								id="co-pa-type"
								className="co-pa-select"
								value={ form.type }
								onChange={ e => setForm( f => ( { ...f, type: e.target.value } ) ) }
							>
								<option value="design">Design</option>
								<option value="content">Content</option>
								<option value="deliverable">Deliverable</option>
								<option value="other">Other</option>
							</select>
						</div>
						<div>
							<label className="co-pa-label" htmlFor="co-pa-desc">Description</label>
							<textarea
								id="co-pa-desc"
								className="co-pa-textarea"
								placeholder="Describe what needs reviewing…"
								value={ form.description }
								onChange={ e => setForm( f => ( { ...f, description: e.target.value } ) ) }
								rows={ 3 }
							/>
						</div>
					</div>
					<div className="co-pa-form-actions">
						<button
							type="button"
							className="co-pa-cancel-btn"
							onClick={ () => { setShowForm( false ); setError( null ); } }
						>
							Cancel
						</button>
						<button type="submit" className="co-pa-submit-btn" disabled={ creating }>
							{ creating ? <><div className="co-pa-submit-spinner" /> Sending…</> : 'Send Request' }
						</button>
					</div>
				</form>
			) }

			{ ! loading && approvals.length === 0 ? (
				<div className="co-pa-empty">
					<div className="co-pa-empty-icon">
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<polyline points="9 11 12 14 22 4"/>
							<path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
						</svg>
					</div>
					<h4>No approval requests yet</h4>
					<p>Create a request to get client sign-off on deliverables</p>
				</div>
			) : (
				<div className="co-pa-list">
					{ approvals.map( ( approval, idx ) => (
						<div
							key={ approval.id }
							className="co-pa-card"
							style={ { animationDelay: `${ idx * 0.04 }s` } }
						>
							<div className="co-pa-card-left">
								<div className="co-pa-card-top">
									<span className={ `co-pa-type-badge ${ approval.type }` }>
										{ TYPE_LABELS[ approval.type ] || approval.type }
									</span>
									<span className={ `co-pa-status-badge ${ approval.status }` }>
										<span className="co-pa-status-dot" />
										{ STATUS_LABELS[ approval.status ] || approval.status }
									</span>
								</div>
								{ approval.description && (
									<div className="co-pa-description">{ approval.description }</div>
								) }
								<div className="co-pa-meta">
									Requested { formatDate( approval.created_at ) }
									{ approval.responded_at && ` · Responded ${ formatDate( approval.responded_at ) }` }
								</div>
								{ approval.client_comment && (
									<div className="co-pa-client-comment">
										<strong>Client note</strong>
										{ approval.client_comment }
									</div>
								) }
							</div>

							{ ! isLocked && (
								<button
									type="button"
									className="co-pa-card-delete"
									title="Delete request"
									onClick={ () => handleDelete( approval.id ) }
									aria-label="Delete approval request"
								>
									<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
										<polyline points="3 6 5 6 21 6"/>
										<path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
										<path d="M10 11v6M14 11v6"/>
									</svg>
								</button>
							) }
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}
