/**
 * ProjectMessages
 *
 * Conversation-style messaging between agency and client, scoped to a project.
 * Agency messages appear right (indigo), client messages left (white/slate).
 * Polls every 30 s for new messages while mounted. Marks unread on open.
 *
 * Props: { projectId }
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { cfFetch } from '../ProjectsApp';

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id; s.textContent = css;
	document.head.appendChild( s );
}

const CSS = `
/* ─── Shell ────────────────────────────────────────────────────────── */
.co-pm {
  display: flex;
  flex-direction: column;
  gap: 0;
}

/* ─── Error banner ─────────────────────────────────────────────────── */
.co-pm-error {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--co-red-bg);
  border: 1px solid rgba(239,68,68,.2);
  color: var(--co-red);
  border-radius: var(--co-radius-sm);
  padding: 10px 14px;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 14px;
  animation: co-pm-fadein .18s ease both;
}
.co-pm-error svg { width: 14px; height: 14px; stroke: currentColor; flex-shrink: 0; }
.co-pm-error-dismiss {
  margin-left: auto; background: none; border: none; cursor: pointer;
  color: var(--co-red); padding: 0; display: flex; opacity: .7; transition: opacity .12s;
}
.co-pm-error-dismiss:hover { opacity: 1; }
.co-pm-error-dismiss svg { width: 12px; height: 12px; }

/* ─── Message window ───────────────────────────────────────────────── */
.co-pm-window {
  background: var(--co-slate-50);
  border: 1px solid var(--co-slate-200);
  border-radius: var(--co-radius) var(--co-radius) 0 0;
  min-height: 320px;
  max-height: 480px;
  overflow-y: auto;
  padding: 20px 18px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  scroll-behavior: smooth;
}

/* ─── Date divider ─────────────────────────────────────────────────── */
.co-pm-date-divider {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 14px 0 10px;
}
.co-pm-date-divider::before,
.co-pm-date-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--co-slate-200);
}
.co-pm-date-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--co-slate-400);
  letter-spacing: .5px;
  white-space: nowrap;
}

/* ─── Message row ──────────────────────────────────────────────────── */
.co-pm-row {
  display: flex;
  flex-direction: column;
  margin-bottom: 2px;
  animation: co-pm-fadein .2s ease both;
}
.co-pm-row.admin { align-items: flex-end; }
.co-pm-row.client { align-items: flex-start; }

/* Consecutive from same sender get reduced top spacing */
.co-pm-row.same-sender { margin-top: -4px; }

/* ─── Bubble ───────────────────────────────────────────────────────── */
.co-pm-bubble {
  max-width: 85%;
  min-width: 60px;
  padding: 10px 14px;
  border-radius: 16px;
  font-size: 13.5px;
  line-height: 1.55;
  word-wrap: break-word;
  overflow-wrap: break-word;
  position: relative;
}

.co-pm-row.admin .co-pm-bubble {
  background: var(--co-indigo);
  color: #fff;
  border-bottom-right-radius: 4px;
  box-shadow: 0 2px 8px rgba(99,102,241,.25);
}

.co-pm-row.client .co-pm-bubble {
  background: var(--co-white);
  color: var(--co-navy);
  border: 1px solid var(--co-slate-200);
  border-bottom-left-radius: 4px;
  box-shadow: 0 1px 3px rgba(15,23,42,.06);
}

/* Adjust corner for consecutive bubbles */
.co-pm-row.admin.same-sender .co-pm-bubble { border-bottom-right-radius: 16px; border-top-right-radius: 4px; }
.co-pm-row.client.same-sender .co-pm-bubble { border-bottom-left-radius: 16px; border-top-left-radius: 4px; }

/* ─── Bubble meta ──────────────────────────────────────────────────── */
.co-pm-meta {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 4px;
  padding: 0 2px;
}
.co-pm-row.admin .co-pm-meta { flex-direction: row-reverse; }

.co-pm-sender {
  font-size: 11px;
  font-weight: 600;
  color: var(--co-slate-500);
}
.co-pm-time {
  font-size: 11px;
  color: var(--co-slate-400);
}

/* Only show meta for the last in a consecutive group */
.co-pm-row.same-sender:not(.last-in-group) .co-pm-meta { display: none; }

/* ─── Delete button (admin only, hover) ────────────────────────────── */
.co-pm-del {
  width: 22px; height: 22px;
  border: none; background: transparent;
  border-radius: 50%; cursor: pointer;
  color: var(--co-slate-400);
  display: flex; align-items: center; justify-content: center;
  padding: 0; opacity: 0;
  transition: opacity .12s, color .12s, background .12s;
  flex-shrink: 0;
  align-self: center;
}
.co-pm-row:hover .co-pm-del { opacity: 1; }
.co-pm-del:hover { color: var(--co-red); background: var(--co-red-bg); }
.co-pm-del svg { width: 12px; height: 12px; stroke: currentColor; stroke-width: 2; }

.co-pm-bubble-wrap {
  display: flex;
  align-items: flex-end;
  gap: 6px;
  width: 100%;
}
.co-pm-row.client .co-pm-bubble-wrap { flex-direction: row; }
.co-pm-row.admin  .co-pm-bubble-wrap { flex-direction: row-reverse; }

/* ─── Empty state ──────────────────────────────────────────────────── */
.co-pm-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 40px 20px;
  text-align: center;
}
.co-pm-empty-icon {
  width: 56px; height: 56px;
  background: var(--co-slate-100);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 4px;
}
.co-pm-empty-icon svg { width: 24px; height: 24px; stroke: var(--co-slate-300); stroke-width: 1.5; }
.co-pm-empty h4 { font-size: 14px; font-weight: 700; color: var(--co-slate-600); margin: 0; }
.co-pm-empty p  { font-size: 13px; color: var(--co-slate-400); margin: 0; }

/* ─── Loading spinner ──────────────────────────────────────────────── */
.co-pm-loading {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 200px;
}
.co-pm-spinner {
  width: 24px; height: 24px;
  border: 2px solid var(--co-slate-200);
  border-top-color: var(--co-indigo);
  border-radius: 50%;
  animation: co-pm-spin .8s linear infinite;
}

/* ─── Composer ─────────────────────────────────────────────────────── */
.co-pm-composer {
  border: 1px solid var(--co-slate-200);
  border-top: none;
  border-radius: 0 0 var(--co-radius) var(--co-radius);
  background: var(--co-white);
  padding: 12px 14px;
  display: flex;
  gap: 10px;
  align-items: flex-end;
}

.co-pm-textarea {
  flex: 1;
  min-height: 40px;
  max-height: 120px;
  padding: 9px 12px;
  border-radius: var(--co-radius-sm);
  border: var(--co-input-border);
  font-family: var(--co-font);
  font-size: 13.5px;
  color: var(--co-navy);
  background: var(--co-slate-50);
  resize: none;
  line-height: 1.5;
  overflow-y: auto;
  transition: border-color .12s, box-shadow .12s;
  box-sizing: border-box;
}
.co-pm-textarea:focus {
  outline: none;
  border-color: var(--co-indigo);
  box-shadow: var(--co-input-focus);
  background: var(--co-white);
}
.co-pm-textarea::placeholder { color: var(--co-slate-300); }

.co-pm-send {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 9px 18px;
  background: var(--co-indigo);
  color: #fff;
  border: none;
  border-radius: var(--co-radius-sm);
  font-family: var(--co-font);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity .12s, box-shadow .15s;
  box-shadow: 0 2px 8px rgba(99,102,241,.28);
  white-space: nowrap;
  height: 40px;
}
.co-pm-send:hover:not(:disabled) {
  opacity: .9;
  box-shadow: 0 4px 14px rgba(99,102,241,.38);
}
.co-pm-send:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; }

.co-pm-send-spinner {
  width: 13px; height: 13px;
  border: 2px solid rgba(255,255,255,.35);
  border-top-color: #fff;
  border-radius: 50%;
  animation: co-pm-spin .65s linear infinite;
  flex-shrink: 0;
}

/* ─── Animations ───────────────────────────────────────────────────── */
@keyframes co-pm-fadein {
  from { opacity: 0; transform: translateY(4px); }
  to   { opacity: 1; transform: translateY(0); }
}
@keyframes co-pm-spin { to { transform: rotate(360deg); } }

/* ─── Mobile ───────────────────────────────────────────────────────── */
@media (max-width: 600px) {
  .co-pm-bubble { max-width: 92%; }
  .co-pm-window { max-height: 360px; }
}
`;

// ── Date helpers ───────────────────────────────────────────────────────────────

function formatTime( dateStr ) {
	if ( ! dateStr ) return '';
	try {
		return new Date( dateStr ).toLocaleTimeString( 'en-GB', { hour: '2-digit', minute: '2-digit' } );
	} catch { return ''; }
}

function dateDividerLabel( dateStr ) {
	if ( ! dateStr ) return '';
	const d    = new Date( dateStr );
	const now  = new Date();
	const diff = Math.floor( ( now - d ) / 86400000 );
	if ( diff === 0 ) return 'Today';
	if ( diff === 1 ) return 'Yesterday';
	return d.toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: diff > 300 ? 'numeric' : undefined } );
}

function isSameDay( a, b ) {
	if ( ! a || ! b ) return false;
	const da = new Date( a );
	const db = new Date( b );
	return da.getFullYear() === db.getFullYear() &&
	       da.getMonth()    === db.getMonth()    &&
	       da.getDate()     === db.getDate();
}

// ── Main component ─────────────────────────────────────────────────────────────

export default function ProjectMessages( { projectId, onUnreadChange } ) {
	injectStyles( 'co-pm-styles', CSS );

	const [ messages, setMessages ] = useState( [] );
	const [ loading,  setLoading  ] = useState( true );
	const [ sending,  setSending  ] = useState( false );
	const [ text,     setText     ] = useState( '' );
	const [ error,    setError    ] = useState( null );

	const windowRef   = useRef( null );
	const textareaRef = useRef( null );
	const prevCountRef = useRef( 0 );

	// ── Fetch ─────────────────────────────────────────────────────────

	const fetchMessages = useCallback( async ( silent = false ) => {
		if ( ! silent ) setLoading( true );
		try {
			const data = await cfFetch( `projects/${ projectId }/messages` );
			const msgs = data.messages || [];
			setMessages( msgs );
			if ( onUnreadChange ) onUnreadChange( 0 ); // tab opened = read
		} catch {
			if ( ! silent ) setError( 'Failed to load messages.' );
		} finally {
			if ( ! silent ) setLoading( false );
		}
	}, [ projectId ] );

	// ── Mount + poll ──────────────────────────────────────────────────

	useEffect( () => {
		fetchMessages();
		const interval = setInterval( () => fetchMessages( true ), 30000 );
		return () => clearInterval( interval );
	}, [ fetchMessages ] );

	// ── Scroll to bottom when messages change ─────────────────────────

	useEffect( () => {
		if ( windowRef.current ) {
			windowRef.current.scrollTop = windowRef.current.scrollHeight;
		}
	}, [ messages.length ] );

	// ── Auto-resize textarea ──────────────────────────────────────────

	function handleTextChange( e ) {
		setText( e.target.value );
		const el = e.target;
		el.style.height = 'auto';
		el.style.height = Math.min( el.scrollHeight, 120 ) + 'px';
	}

	// ── Send ──────────────────────────────────────────────────────────

	async function handleSend() {
		const trimmed = text.trim();
		if ( ! trimmed || sending ) return;
		setSending( true );
		setError( null );
		try {
			const data = await cfFetch( `projects/${ projectId }/messages`, {
				method: 'POST',
				body:   JSON.stringify( { message: trimmed } ),
			} );
			setMessages( data.messages || [] );
			setText( '' );
			if ( textareaRef.current ) {
				textareaRef.current.style.height = 'auto';
			}
		} catch ( err ) {
			setError( err.message || 'Failed to send message.' );
		} finally {
			setSending( false );
		}
	}

	function handleKeyDown( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			handleSend();
		}
	}

	// ── Delete ────────────────────────────────────────────────────────

	async function handleDelete( mid ) {
		try {
			await cfFetch( `projects/${ projectId }/messages/${ mid }`, { method: 'DELETE' } );
			setMessages( prev => prev.filter( m => m.id !== mid ) );
		} catch ( err ) {
			setError( err.message || 'Delete failed.' );
		}
	}

	// ── Render ────────────────────────────────────────────────────────

	return (
		<div className="co-pm">

			{ error && (
				<div className="co-pm-error">
					<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
					</svg>
					{ error }
					<button type="button" className="co-pm-error-dismiss" onClick={ () => setError( null ) }>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5">
							<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
						</svg>
					</button>
				</div>
			) }

			{ /* Message window */ }
			<div className="co-pm-window" ref={ windowRef }>

				{ loading ? (
					<div className="co-pm-loading"><div className="co-pm-spinner" /></div>
				) : messages.length === 0 ? (
					<div className="co-pm-empty">
						<div className="co-pm-empty-icon">
							<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
								<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
							</svg>
						</div>
						<h4>No messages yet</h4>
						<p>Start the conversation below</p>
					</div>
				) : (
					messages.map( ( msg, idx ) => {
						const prev     = messages[ idx - 1 ];
						const next     = messages[ idx + 1 ];
						const samePrev = prev && prev.sender_type === msg.sender_type &&
						                 isSameDay( prev.created_at, msg.created_at );
						const sameNext = next && next.sender_type === msg.sender_type &&
						                 isSameDay( next.created_at, msg.created_at );
						const showDate = ! prev || ! isSameDay( prev.created_at, msg.created_at );
						const isAdmin  = msg.sender_type === 'admin';

						const rowClass = [
							'co-pm-row',
							isAdmin ? 'admin' : 'client',
							samePrev ? 'same-sender' : '',
							! sameNext ? 'last-in-group' : '',
						].filter( Boolean ).join( ' ' );

						return (
							<div key={ msg.id }>
								{ showDate && (
									<div className="co-pm-date-divider">
										<span className="co-pm-date-label">{ dateDividerLabel( msg.created_at ) }</span>
									</div>
								) }
								<div className={ rowClass }>
									<div className="co-pm-bubble-wrap">
										{ /* Delete button — only for admin-sent messages */ }
										{ isAdmin && (
											<button
												type="button"
												className="co-pm-del"
												title="Delete message"
												onClick={ () => handleDelete( msg.id ) }
											>
												<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
													<polyline points="3 6 5 6 21 6"/>
													<path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
												</svg>
											</button>
										) }
										<div className="co-pm-bubble">{ msg.message }</div>
									</div>
									{ ! sameNext && (
										<div className="co-pm-meta">
											<span className="co-pm-sender">{ msg.sender_name }</span>
											<span className="co-pm-time">{ formatTime( msg.created_at ) }</span>
										</div>
									) }
								</div>
							</div>
						);
					} )
				) }
			</div>

			{ /* Composer */ }
			<div className="co-pm-composer">
				<textarea
					ref={ textareaRef }
					className="co-pm-textarea"
					placeholder="Type a message… (Enter to send, Shift+Enter for new line)"
					value={ text }
					onChange={ handleTextChange }
					onKeyDown={ handleKeyDown }
					rows={ 1 }
					disabled={ sending }
				/>
				<button
					type="button"
					className="co-pm-send"
					onClick={ handleSend }
					disabled={ ! text.trim() || sending }
				>
					{ sending ? (
						<><div className="co-pm-send-spinner" /> Sending…</>
					) : (
						<>
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
								<line x1="22" y1="2" x2="11" y2="13"/>
								<polygon points="22 2 15 22 11 13 2 9 22 2"/>
							</svg>
							Send
						</>
					) }
				</button>
			</div>
		</div>
	);
}
