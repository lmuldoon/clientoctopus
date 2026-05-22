import { useState, useEffect, useRef } from '@wordpress/element';

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
}

const CSS = `
/* ── Section shell ─────────────────────────────────────────────── */
.co-pf {
	margin-top: 36px;
}

.co-pf-header {
	display: flex;
	align-items: center;
	gap: 10px;
	padding-bottom: 14px;
	border-bottom: 1.5px solid var(--co-slate-100);
	margin-bottom: 20px;
}

.co-pf-header-icon {
	width: 32px;
	height: 32px;
	background: var(--co-slate-100);
	border-radius: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.co-pf-header-icon svg {
	width: 15px;
	height: 15px;
	stroke: var(--co-slate-500);
	stroke-width: 2;
}

.co-pf-title {
	font-family: var(--co-font-display);
	font-size: 17px;
	font-weight: 600;
	color: var(--co-navy);
	letter-spacing: -.2px;
}

.co-pf-count {
	margin-left: auto;
	font-size: 12px;
	font-weight: 600;
	color: var(--co-slate-400);
	background: var(--co-slate-100);
	padding: 2px 8px;
	border-radius: 999px;
}

/* ── Error banner ──────────────────────────────────────────────── */
.co-pf-error {
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
	margin-bottom: 16px;
	animation: co-pf-fade-in .2s ease both;
}
.co-pf-error svg { width: 15px; height: 15px; stroke: currentColor; flex-shrink: 0; }
.co-pf-error-dismiss {
	margin-left: auto;
	background: none;
	border: none;
	cursor: pointer;
	color: var(--co-red);
	padding: 0;
	display: flex;
	align-items: center;
	opacity: .7;
	transition: opacity .15s;
}
.co-pf-error-dismiss:hover { opacity: 1; }
.co-pf-error-dismiss svg { width: 13px; height: 13px; }

/* ── Upload zone ───────────────────────────────────────────────── */
.co-pf-zone {
	border: 2px dashed var(--co-slate-200);
	border-radius: var(--co-radius);
	padding: 32px 24px;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 10px;
	cursor: pointer;
	transition: border-color .2s, background .2s;
	background: var(--co-white);
	margin-bottom: 20px;
	position: relative;
	overflow: hidden;
	min-height: 140px;
}
.co-pf-zone:hover {
	border-color: var(--co-slate-300);
	background: var(--co-slate-50);
}
.co-pf-zone.drag-over {
	border-color: var(--co-indigo);
	background: var(--co-indigo-bg);
}
.co-pf-zone.uploading {
	cursor: default;
	pointer-events: none;
}

.co-pf-zone-icon {
	width: 44px;
	height: 44px;
	background: var(--co-slate-100);
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: background .2s, transform .2s;
}
.co-pf-zone:hover .co-pf-zone-icon,
.co-pf-zone.drag-over .co-pf-zone-icon {
	background: var(--co-indigo-bg);
	transform: translateY(-2px);
}
.co-pf-zone.drag-over .co-pf-zone-icon {
	background: rgba(99,102,241,.15);
}
.co-pf-zone-icon svg {
	width: 20px;
	height: 20px;
	stroke: var(--co-slate-400);
	stroke-width: 1.75;
	transition: stroke .2s;
}
.co-pf-zone:hover .co-pf-zone-icon svg,
.co-pf-zone.drag-over .co-pf-zone-icon svg {
	stroke: var(--co-indigo);
}

.co-pf-zone-text {
	font-size: 13.5px;
	font-weight: 600;
	color: var(--co-slate-700);
	text-align: center;
}
.co-pf-zone.drag-over .co-pf-zone-text { color: var(--co-indigo); }

.co-pf-zone-hint {
	font-size: 12px;
	color: var(--co-slate-400);
	text-align: center;
}

/* Uploading state */
.co-pf-uploading-overlay {
	position: absolute;
	inset: 0;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	background: rgba(255,255,255,.92);
	backdrop-filter: blur(2px);
}
.co-pf-upload-spinner {
	width: 28px;
	height: 28px;
	border: 2.5px solid var(--co-slate-200);
	border-top-color: var(--co-indigo);
	border-radius: 50%;
	animation: co-pf-spin .7s linear infinite;
}
.co-pf-uploading-label {
	font-size: 13px;
	font-weight: 500;
	color: var(--co-slate-600);
}

/* ── File list ─────────────────────────────────────────────────── */
.co-pf-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.co-pf-row {
	display: grid;
	grid-template-columns: 36px 1fr auto auto auto;
	align-items: center;
	gap: 12px;
	padding: 12px 14px;
	background: var(--co-white);
	border: 1px solid var(--co-slate-200);
	border-radius: var(--co-radius-sm);
	transition: border-color .15s, box-shadow .15s, transform .12s;
	animation: co-pf-fade-in .3s ease both;
}
.co-pf-row:hover {
	border-color: var(--co-slate-300);
	box-shadow: var(--co-shadow);
	transform: translateY(-1px);
}
.co-pf-row:hover .co-pf-delete { opacity: 1; }

/* File type icon cell */
.co-pf-file-icon {
	width: 36px;
	height: 36px;
	border-radius: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.co-pf-file-icon svg {
	width: 18px;
	height: 18px;
	stroke-width: 1.75;
}
.co-pf-file-icon.image  { background: var(--co-emerald-bg); }
.co-pf-file-icon.image  svg { stroke: var(--co-emerald); }
.co-pf-file-icon.pdf    { background: var(--co-red-bg); }
.co-pf-file-icon.pdf    svg { stroke: var(--co-red); }
.co-pf-file-icon.archive { background: var(--co-amber-bg); }
.co-pf-file-icon.archive svg { stroke: var(--co-amber); }
.co-pf-file-icon.doc    { background: var(--co-indigo-bg); }
.co-pf-file-icon.doc    svg { stroke: var(--co-indigo); }
.co-pf-file-icon.generic { background: var(--co-slate-100); }
.co-pf-file-icon.generic svg { stroke: var(--co-slate-500); }

/* Info cell */
.co-pf-info {}
.co-pf-name {
	font-size: 13.5px;
	font-weight: 600;
	color: var(--co-slate-800);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 320px;
}
.co-pf-meta {
	font-size: 11.5px;
	color: var(--co-slate-400);
	margin-top: 2px;
}

/* Action cells */
.co-pf-download {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 7px 13px;
	border: 1.5px solid var(--co-indigo);
	border-radius: var(--co-radius-sm);
	font-size: 12.5px;
	font-weight: 600;
	font-family: var(--co-font);
	color: var(--co-indigo);
	background: transparent;
	text-decoration: none;
	transition: background .15s, color .15s;
	white-space: nowrap;
	cursor: pointer;
}
.co-pf-download:hover { background: var(--co-indigo-bg); }
.co-pf-download svg { width: 12px; height: 12px; stroke: currentColor; stroke-width: 2.5; flex-shrink: 0; }

.co-pf-delete {
	width: 30px;
	height: 30px;
	border: none;
	background: transparent;
	border-radius: 6px;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	color: var(--co-slate-400);
	opacity: 0;
	transition: opacity .15s, background .12s, color .12s;
}
.co-pf-delete:hover { background: var(--co-red-bg); color: var(--co-red); }
.co-pf-delete svg { width: 14px; height: 14px; stroke: currentColor; stroke-width: 2; }

/* ── Empty state ───────────────────────────────────────────────── */
.co-pf-empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 10px;
	padding: 40px 20px;
	text-align: center;
}
.co-pf-empty-icon {
	width: 64px;
	height: 64px;
	background: var(--co-slate-100);
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	margin-bottom: 4px;
}
.co-pf-empty-icon svg { width: 28px; height: 28px; stroke: var(--co-slate-300); stroke-width: 1.5; }
.co-pf-empty h4 {
	font-size: 14.5px;
	font-weight: 700;
	color: var(--co-slate-600);
	margin: 0;
}
.co-pf-empty p {
	font-size: 13px;
	color: var(--co-slate-400);
	margin: 0;
}

/* ── Animations ────────────────────────────────────────────────── */
@keyframes co-pf-fade-in {
	from { opacity: 0; transform: translateY(5px); }
	to   { opacity: 1; transform: translateY(0); }
}
@keyframes co-pf-spin { to { transform: rotate(360deg); } }

/* ── Mobile ────────────────────────────────────────────────────── */
@media (max-width: 600px) {
	.co-pf-row {
		grid-template-columns: 36px 1fr;
		grid-template-rows: auto auto;
	}
	.co-pf-download,
	.co-pf-delete {
		grid-column: 2;
	}
	.co-pf-delete { opacity: 1; }
	.co-pf-name { max-width: 220px; }
}
`;

// ── File type helpers ─────────────────────────────────────────────────────────

function getFileType( mime ) {
	if ( ! mime ) return 'generic';
	if ( mime.startsWith( 'image/' ) ) return 'image';
	if ( mime === 'application/pdf' ) return 'pdf';
	if ( [ 'application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed', 'application/x-tar', 'application/gzip' ].includes( mime ) ) return 'archive';
	if ( mime.includes( 'word' ) || mime.includes( 'document' ) || mime.includes( 'text/' ) ) return 'doc';
	return 'generic';
}

function FileTypeIcon( { mime } ) {
	const type = getFileType( mime );

	if ( type === 'image' ) {
		return (
			<div className="co-pf-file-icon image">
				<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
					<rect x="3" y="3" width="18" height="18" rx="2"/>
					<circle cx="8.5" cy="8.5" r="1.5"/>
					<polyline points="21 15 16 10 5 21"/>
				</svg>
			</div>
		);
	}

	if ( type === 'pdf' ) {
		return (
			<div className="co-pf-file-icon pdf">
				<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
					<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
					<polyline points="14 2 14 8 20 8"/>
					<line x1="9" y1="13" x2="15" y2="13"/>
					<line x1="9" y1="17" x2="12" y2="17"/>
				</svg>
			</div>
		);
	}

	if ( type === 'archive' ) {
		return (
			<div className="co-pf-file-icon archive">
				<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
					<polyline points="21 8 21 21 3 21 3 8"/>
					<rect x="1" y="3" width="22" height="5"/>
					<line x1="10" y1="12" x2="14" y2="12"/>
				</svg>
			</div>
		);
	}

	if ( type === 'doc' ) {
		return (
			<div className="co-pf-file-icon doc">
				<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
					<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
					<polyline points="14 2 14 8 20 8"/>
					<line x1="16" y1="13" x2="8" y2="13"/>
					<line x1="16" y1="17" x2="8" y2="17"/>
					<polyline points="10 9 9 9 8 9"/>
				</svg>
			</div>
		);
	}

	return (
		<div className="co-pf-file-icon generic">
			<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
				<path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/>
				<polyline points="13 2 13 9 20 9"/>
			</svg>
		</div>
	);
}

function formatDate( dateStr ) {
	if ( ! dateStr ) return '';
	try {
		return new Date( dateStr ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' } );
	} catch {
		return dateStr;
	}
}

// ── Main component ────────────────────────────────────────────────────────────

export default function ProjectFiles( { projectId } ) {
	injectStyles( 'co-pf-styles', CSS );

	const { apiUrl, nonce } = window.clientoctopusData || {};

	const [ files,     setFiles     ] = useState( [] );
	const [ loading,   setLoading   ] = useState( true );
	const [ uploading, setUploading ] = useState( false );
	const [ dragOver,  setDragOver  ] = useState( false );
	const [ error,     setError     ] = useState( null );

	const fileInputRef = useRef( null );
	const dragCounter  = useRef( 0 );

	// ── Fetch files on mount ──────────────────────────────────────
	useEffect( () => {
		fetch( `${ apiUrl }projects/${ projectId }/files`, {
			headers: { 'X-WP-Nonce': nonce },
		} )
			.then( r => r.json() )
			.then( data => setFiles( data.files || [] ) )
			.catch( () => setError( 'Failed to load files.' ) )
			.finally( () => setLoading( false ) );
	}, [ projectId ] );

	// ── Upload handler ────────────────────────────────────────────
	async function uploadFile( file ) {
		if ( ! file ) return;
		setUploading( true );
		setError( null );

		const form = new FormData();
		form.append( 'file', file );

		try {
			const res  = await fetch( `${ apiUrl }projects/${ projectId }/files`, {
				method:  'POST',
				headers: { 'X-WP-Nonce': nonce },
				body:    form,
			} );
			const data = await res.json();
			if ( ! res.ok ) throw new Error( data.message || `Upload failed (${ res.status })` );
			setFiles( data.files || [] );
		} catch ( err ) {
			setError( err.message || 'Upload failed. Please try again.' );
		} finally {
			setUploading( false );
		}
	}

	// ── Delete handler ────────────────────────────────────────────
	async function deleteFile( fileId ) {
		try {
			const res = await fetch( `${ apiUrl }projects/${ projectId }/files/${ fileId }`, {
				method:  'DELETE',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			} );
			if ( ! res.ok ) throw new Error( 'Delete failed.' );
			setFiles( prev => prev.filter( f => f.id !== fileId ) );
		} catch ( err ) {
			setError( err.message );
		}
	}

	// ── Drag events ───────────────────────────────────────────────
	function onDragEnter( e ) {
		e.preventDefault();
		dragCounter.current++;
		setDragOver( true );
	}
	function onDragLeave( e ) {
		e.preventDefault();
		dragCounter.current--;
		if ( dragCounter.current === 0 ) setDragOver( false );
	}
	function onDragOver( e ) { e.preventDefault(); }
	function onDrop( e ) {
		e.preventDefault();
		dragCounter.current = 0;
		setDragOver( false );
		const file = e.dataTransfer.files[ 0 ];
		if ( file ) uploadFile( file );
	}

	function onInputChange( e ) {
		const file = e.target.files[ 0 ];
		if ( file ) {
			uploadFile( file );
			e.target.value = '';
		}
	}

	const zoneClass = [
		'co-pf-zone',
		dragOver  ? 'drag-over'  : '',
		uploading ? 'uploading' : '',
	].filter( Boolean ).join( ' ' );

	return (
		<div className="co-pf">
			<div className="co-pf-header">
				<div className="co-pf-header-icon">
					<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
					</svg>
				</div>
				<span className="co-pf-title">Files</span>
				{ files.length > 0 && (
					<span className="co-pf-count">{ files.length } { files.length === 1 ? 'file' : 'files' }</span>
				) }
			</div>

			{ error && (
				<div className="co-pf-error">
					<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
					</svg>
					{ error }
					<button type="button" className="co-pf-error-dismiss" onClick={ () => setError( null ) } aria-label="Dismiss">
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5">
							<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
						</svg>
					</button>
				</div>
			) }

			{ /* Upload zone */ }
			<div
				className={ zoneClass }
				onDragEnter={ onDragEnter }
				onDragLeave={ onDragLeave }
				onDragOver={ onDragOver }
				onDrop={ onDrop }
				onClick={ () => ! uploading && fileInputRef.current?.click() }
				role="button"
				tabIndex={ 0 }
				aria-label="Upload file"
				onKeyDown={ e => e.key === 'Enter' && fileInputRef.current?.click() }
			>
				{ uploading ? (
					<div className="co-pf-uploading-overlay">
						<div className="co-pf-upload-spinner" />
						<span className="co-pf-uploading-label">Uploading…</span>
					</div>
				) : (
					<>
						<div className="co-pf-zone-icon">
							<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
								<polyline points="16 16 12 12 8 16"/>
								<line x1="12" y1="12" x2="12" y2="21"/>
								<path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/>
							</svg>
						</div>
						<span className="co-pf-zone-text">
							{ dragOver ? 'Release to upload' : 'Drop files here or click to upload' }
						</span>
						<span className="co-pf-zone-hint">Any file type · Max 1 GB total storage</span>
					</>
				) }
				<input
					ref={ fileInputRef }
					type="file"
					style={ { display: 'none' } }
					onChange={ onInputChange }
				/>
			</div>

			{ /* File list */ }
			{ loading ? null : files.length === 0 ? (
				<div className="co-pf-empty">
					<div className="co-pf-empty-icon">
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<polyline points="16 16 12 12 8 16"/>
							<line x1="12" y1="12" x2="12" y2="21"/>
							<path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/>
						</svg>
					</div>
					<h4>No files yet</h4>
					<p>Upload your first deliverable above</p>
				</div>
			) : (
				<div className="co-pf-list">
					{ files.map( ( file, idx ) => (
						<div
							key={ file.id }
							className="co-pf-row"
							style={ { animationDelay: `${ idx * 0.04 }s` } }
						>
							<FileTypeIcon mime={ file.file_mime } />

							<div className="co-pf-info">
								<div className="co-pf-name" title={ file.file_name }>{ file.file_name }</div>
								<div className="co-pf-meta">{ file.file_size_human } · { formatDate( file.created_at ) }</div>
							</div>

							<a
								className="co-pf-download"
								href={ `${ apiUrl }projects/${ projectId }/files/${ file.id }/download?_wpnonce=${ nonce }` }
								download={ file.file_name }
							>
								<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
									<polyline points="8 17 12 21 16 17"/>
									<line x1="12" y1="12" x2="12" y2="21"/>
									<path d="M20.88 18.09A5 5 0 0018 9h-1.26A8 8 0 103 16.11"/>
								</svg>
								Download
							</a>

							<button
								type="button"
								className="co-pf-delete"
								title="Delete file"
								onClick={ () => deleteFile( file.id ) }
								aria-label={ `Delete ${ file.file_name }` }
							>
								<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
									<polyline points="3 6 5 6 21 6"/>
									<path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
									<path d="M10 11v6M14 11v6"/>
									<path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
								</svg>
							</button>
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}
