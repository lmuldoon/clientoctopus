/**
 * WebhooksApp
 *
 * Outbound webhook management for ClientFlow Pro/Agency accounts.
 * Add, edit, enable/disable, test, and delete webhook endpoints.
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { cfFetch } from '../../App.jsx';

// ── Styles ─────────────────────────────────────────────────────────────────────

const WH_CSS = `
@import url('https://fonts.googleapis.com/css2?family=Archivo:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400&display=swap');

:root {
  --co-navy:       #0F172A;
  --co-indigo:     #6366F1;
  --co-indigo-lt:  #818CF8;
  --co-indigo-bg:  #EEF2FF;
  --co-emerald:    #10B981;
  --co-emerald-bg: #ECFDF5;
  --co-amber:      #F59E0B;
  --co-red:        #EF4444;
  --co-red-bg:     #FEF2F2;
  --co-slate-50:   #F8FAFC;
  --co-slate-100:  #F1F5F9;
  --co-slate-200:  #E2E8F0;
  --co-slate-400:  #94A3B8;
  --co-slate-500:  #64748B;
  --co-white:      #FFFFFF;
  --co-radius:     12px;
  --co-radius-sm:  8px;
  --co-shadow:     0 1px 3px rgba(15,23,42,.06), 0 4px 16px rgba(15,23,42,.08);
  --co-font:       'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
}

.co-wh * { box-sizing: border-box; }

.co-wh {
  font-family: var(--co-font);
  min-height: 100vh;
  padding: 32px 28px 64px;
  color: var(--co-navy);
  -webkit-font-smoothing: antialiased;
}

/* Header */
.co-wh-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 28px;
}
.co-wh-title { font-size: 28px; font-weight: 800; letter-spacing: -.5px; margin: 0; line-height: 1.1; }
.co-wh-sub   { font-size: 14px; color: var(--co-slate-500); margin: 6px 0 0; line-height: 1.5; }

/* Buttons */
.co-wh-btn {
  display: inline-flex; align-items: center; gap: 7px;
  height: 40px; padding: 0 18px;
  border-radius: var(--co-radius-sm);
  font-size: 13px; font-weight: 600; font-family: var(--co-font);
  cursor: pointer; transition: background .15s, box-shadow .15s, transform .1s;
  border: none; outline: none;
}
.co-wh-btn--primary {
  background: var(--co-indigo); color: #fff !important; 
  box-shadow: 0 2px 8px rgba(99,102,241,.3);
}
.co-wh-btn--primary:hover { background: #4F46E5; transform: translateY(-1px); }
.co-wh-btn--primary:disabled { opacity: .5; cursor: default; transform: none; }
.co-wh-btn--ghost {
  background: var(--co-white); color: var(--co-slate-500);
  border: 1.5px solid var(--co-slate-200);
}
.co-wh-btn--ghost:hover { border-color: var(--co-slate-400); color: var(--co-navy); }
.co-wh-btn--danger { background: var(--co-red-bg); color: var(--co-red); border: 1.5px solid #FECACA; }
.co-wh-btn--danger:hover { background: #FEE2E2; }
.co-wh-btn--sm { height: 32px; padding: 0 12px; font-size: 12px; }

/* Form card */
.co-wh-form-card {
  background: var(--co-white);
  border: 1.5px solid var(--co-indigo);
  border-radius: var(--co-radius);
  padding: 24px 28px 28px;
  margin-bottom: 24px;
  box-shadow: var(--co-shadow);
}
.co-wh-form-title { font-size: 15px; font-weight: 700; margin: 0 0 20px; }
.co-wh-field { margin-bottom: 18px; }
.co-wh-label {
  display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 7px;
}
.co-wh-input {
  width: 100%; height: 42px; border: 1.5px solid var(--co-slate-200);
  border-radius: var(--co-radius-sm); padding: 0 14px;
  font-size: 13px; font-family: var(--co-font); color: var(--co-navy);
  background: #FAFAFA; transition: border-color .15s, box-shadow .15s; outline: none;
}
.co-wh-input:focus { border-color: var(--co-indigo); box-shadow: 0 0 0 3px rgba(99,102,241,.12); background: #fff; }
.co-wh-input::placeholder { color: var(--co-slate-400); }
.co-wh-error-text { font-size: 12px; color: var(--co-red); margin-top: 5px; }

/* Event checkboxes */
.co-wh-events { display: flex; flex-wrap: wrap; gap: 8px; }
.co-wh-event-label {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; border: 1.5px solid var(--co-slate-200);
  border-radius: 100px; font-size: 12px; font-weight: 500; cursor: pointer;
  transition: border-color .15s, background .15s;
  user-select: none;
}
.co-wh-event-label:hover { border-color: var(--co-indigo-lt); }
.co-wh-event-label--on { border-color: var(--co-indigo); background: var(--co-indigo-bg); color: var(--co-indigo); font-weight: 600; }
.co-wh-event-label input { display: none; }

/* Form row */
.co-wh-form-actions { display: flex; gap: 10px; margin-top: 24px; }

/* Webhook card */
.co-wh-card {
  background: var(--co-white);
  border: 1px solid var(--co-slate-200);
  border-radius: var(--co-radius);
  padding: 20px 24px;
  margin-bottom: 14px;
  box-shadow: var(--co-shadow);
  transition: border-color .15s;
}
.co-wh-card:hover { border-color: var(--co-slate-400); }
.co-wh-card--disabled { opacity: .65; }

.co-wh-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.co-wh-card-url { font-size: 14px; font-weight: 600; word-break: break-all; flex: 1; }
.co-wh-card-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

/* Toggle */
.co-wh-toggle { display: inline-flex; align-items: center; gap: 7px; cursor: pointer; user-select: none; }
.co-wh-toggle-track {
  width: 36px; height: 20px; border-radius: 10px; background: var(--co-slate-200);
  position: relative; transition: background .2s;
}
.co-wh-toggle-track--on { background: var(--co-emerald); }
.co-wh-toggle-thumb {
  position: absolute; top: 2px; left: 2px; width: 16px; height: 16px;
  border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.2);
  transition: left .2s;
}
.co-wh-toggle-track--on .co-wh-toggle-thumb { left: 18px; }
.co-wh-toggle-label { font-size: 12px; font-weight: 500; color: var(--co-slate-500); }

/* Event pills */
.co-wh-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
.co-wh-pill {
  display: inline-block; padding: 3px 10px; border-radius: 100px;
  font-size: 11px; font-weight: 600; letter-spacing: .02em;
  background: var(--co-indigo-bg); color: var(--co-indigo);
}

/* Delivery log */
.co-wh-log { margin-top: 14px; border-top: 1px solid var(--co-slate-100); padding-top: 12px; }
.co-wh-log-title { font-size: 11px; font-weight: 700; color: var(--co-slate-400); letter-spacing: .06em; text-transform: uppercase; margin-bottom: 8px; }
.co-wh-log-row { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--co-slate-500); padding: 3px 0; }
.co-wh-log-code {
  display: inline-block; padding: 2px 7px; border-radius: 5px;
  font-size: 11px; font-weight: 700; font-family: 'DM Mono', monospace;
}
.co-wh-log-code--ok  { background: var(--co-emerald-bg); color: #065F46; }
.co-wh-log-code--err { background: var(--co-red-bg); color: var(--co-red); }

/* Test result inline */
.co-wh-test-result { font-size: 12px; font-weight: 600; }
.co-wh-test-result--ok  { color: var(--co-emerald); }
.co-wh-test-result--err { color: var(--co-red); }

/* Secret banner */
.co-wh-secret-box {
  background: var(--co-slate-50); border: 1.5px solid var(--co-slate-200);
  border-radius: var(--co-radius-sm); padding: 12px 16px; margin-top: 16px;
}
.co-wh-secret-label { font-size: 11px; font-weight: 700; color: var(--co-slate-400); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }
.co-wh-secret-row { display: flex; gap: 8px; align-items: center; }
.co-wh-secret-val {
  flex: 1; font-family: 'DM Mono', 'Courier New', monospace;
  font-size: 12px; color: var(--co-slate-500); word-break: break-all;
  background: #fff; border: 1px solid var(--co-slate-200); border-radius: 6px;
  padding: 6px 10px;
}
.co-wh-secret-note { font-size: 11px; color: var(--co-slate-400); margin-top: 6px; }

/* Upgrade banner */
.co-wh-upgrade {
  background: linear-gradient(135deg, #EEF2FF 0%, #FAF5FF 100%);
  border: 1.5px solid var(--co-indigo);
  border-radius: var(--co-radius);
  padding: 28px 32px; text-align: center; margin-bottom: 24px;
}
.co-wh-upgrade-title { font-size: 18px; font-weight: 800; margin: 0 0 8px; }
.co-wh-upgrade-sub { font-size: 14px; color: var(--co-slate-500); margin: 0 0 20px; }

/* Empty state */
.co-wh-empty {
  text-align: center; padding: 60px 24px; color: var(--co-slate-500);
}
.co-wh-empty-title { font-size: 16px; font-weight: 700; color: var(--co-navy); margin: 16px 0 8px; }
.co-wh-empty-sub   { font-size: 14px; margin: 0; }

/* Notice */
.co-wh-notice {
  padding: 12px 18px; border-radius: var(--co-radius-sm);
  font-size: 13px; font-weight: 500;
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 20px;
}
.co-wh-notice--error   { background: var(--co-red-bg); color: #991B1B; border: 1px solid #FECACA; }
`;

// ── Constants ───────────────────────────────────────────────────────────────────

const ALL_EVENTS = [
	{ value: 'proposal.sent',      label: 'Proposal Sent' },
	{ value: 'proposal.accepted',  label: 'Proposal Accepted' },
	{ value: 'proposal.declined',  label: 'Proposal Declined' },
	{ value: 'payment.completed',  label: 'Payment Completed' },
	{ value: 'project.created',    label: 'Project Created' },
	{ value: 'project.completed',  label: 'Project Completed' },
];

// ── Sub-components ──────────────────────────────────────────────────────────────

function Toggle( { on, onChange, label } ) {
	return (
		<label className="co-wh-toggle" onClick={ () => onChange( !on ) }>
			<span className={ `co-wh-toggle-track${ on ? ' co-wh-toggle-track--on' : '' }` }>
				<span className="co-wh-toggle-thumb" />
			</span>
			{ label && <span className="co-wh-toggle-label">{ label }</span> }
		</label>
	);
}

function EventCheckboxes( { selected, onChange, availableEvents } ) {
	const toggle = ( value ) => {
		onChange( selected.includes( value )
			? selected.filter( v => v !== value )
			: [ ...selected, value ]
		);
	};
	return (
		<div className="co-wh-events">
			{ availableEvents.map( ev => (
				<label key={ ev.value } className={ `co-wh-event-label${ selected.includes( ev.value ) ? ' co-wh-event-label--on' : '' }` }>
					<input type="checkbox" checked={ selected.includes( ev.value ) } onChange={ () => toggle( ev.value ) } />
					{ ev.label }
				</label>
			) ) }
		</div>
	);
}

function LogRow( { log } ) {
	const codeClass = log.success ? 'co-wh-log-code--ok' : 'co-wh-log-code--err';
	const code      = log.response_code === 0 ? 'ERR' : String( log.response_code );
	const ts        = log.delivered_at ? new Date( log.delivered_at.replace( ' ', 'T' ) + 'Z' ).toLocaleString() : '';
	return (
		<div className="co-wh-log-row">
			<span className={ `co-wh-log-code ${ codeClass }` }>{ code }</span>
			<span>{ log.event }</span>
			<span style={{ marginLeft: 'auto', color: '#94A3B8' }}>{ ts }</span>
		</div>
	);
}

function WebhookForm( { initial, onSave, onCancel, saving, availableEvents } ) {
	const [ url, setUrl ]       = useState( initial?.url || '' );
	const [ events, setEvents ] = useState( initial?.events || [] );
	const [ error, setError ]   = useState( '' );

	const handleSave = () => {
		if ( ! url.trim() ) { setError( 'URL is required.' ); return; }
		if ( events.length === 0 ) { setError( 'Select at least one event.' ); return; }
		setError( '' );
		onSave( { url: url.trim(), events } );
	};

	return (
		<div className="co-wh-form-card">
			<p className="co-wh-form-title">{ initial ? 'Edit Webhook' : 'Add Webhook' }</p>

			<div className="co-wh-field">
				<label className="co-wh-label">Endpoint URL</label>
				<input
					className="co-wh-input"
					type="url"
					placeholder="https://hooks.zapier.com/hooks/catch/…"
					value={ url }
					onChange={ e => setUrl( e.target.value ) }
					spellCheck="false"
					autoComplete="off"
				/>
			</div>

			<div className="co-wh-field">
				<label className="co-wh-label">Events</label>
				<EventCheckboxes selected={ events } onChange={ setEvents } availableEvents={ availableEvents } />
			</div>

			{ error && <p className="co-wh-error-text">{ error }</p> }

			<div className="co-wh-form-actions">
				<button className="co-wh-btn co-wh-btn--primary" onClick={ handleSave } disabled={ saving }>
					{ saving ? 'Saving…' : ( initial ? 'Save Changes' : 'Add Webhook' ) }
				</button>
				<button className="co-wh-btn co-wh-btn--ghost" onClick={ onCancel }>Cancel</button>
			</div>
		</div>
	);
}

function WebhookCard( { webhook, onUpdate, onDelete, availableEvents } ) {
	const [ testState, setTestState ]   = useState( null ); // null | 'loading' | {ok, msg}
	const [ toggling, setToggling ]     = useState( false );
	const [ editing, setEditing ]       = useState( false );
	const [ saving, setSaving ]         = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ newSecret, setNewSecret ]   = useState( webhook.secret );

	const handleToggle = async ( enabled ) => {
		setToggling( true );
		try {
			const res = await cfFetch( `webhooks/${ webhook.id }`, {
				method: 'PATCH',
				body: JSON.stringify( { enabled } ),
			} );
			onUpdate( res.webhook );
		} catch {}
		setToggling( false );
	};

	const handleSave = async ( data ) => {
		setSaving( true );
		try {
			const res = await cfFetch( `webhooks/${ webhook.id }`, {
				method: 'PATCH',
				body: JSON.stringify( data ),
			} );
			onUpdate( res.webhook );
			setEditing( false );
		} catch {}
		setSaving( false );
	};

	const handleTest = async () => {
		setTestState( 'loading' );
		try {
			const res = await cfFetch( `webhooks/${ webhook.id }/test`, { method: 'POST' } );
			setTestState( { ok: res.success, msg: res.message } );
		} catch ( e ) {
			setTestState( { ok: false, msg: e.message || 'Request failed.' } );
		}
		setTimeout( () => setTestState( null ), 6000 );
	};

	const handleDelete = async () => {
		if ( ! confirming ) { setConfirming( true ); return; }
		try {
			await cfFetch( `webhooks/${ webhook.id }`, { method: 'DELETE' } );
			onDelete( webhook.id );
		} catch {}
		setConfirming( false );
	};

	if ( editing ) {
		return (
			<WebhookForm
				initial={ webhook }
				onSave={ handleSave }
				onCancel={ () => setEditing( false ) }
				saving={ saving }
				availableEvents={ availableEvents }
			/>
		);
	}

	const logs = webhook.logs || [];

	return (
		<div className={ `co-wh-card${ !webhook.enabled ? ' co-wh-card--disabled' : '' }` }>
			<div className="co-wh-card-top">
				<div style={{ flex: 1 }}>
					<div className="co-wh-card-url">{ webhook.url }</div>
				</div>
				<div className="co-wh-card-actions">
					<Toggle on={ webhook.enabled } onChange={ handleToggle } label={ webhook.enabled ? 'Enabled' : 'Disabled' } />

					<button className="co-wh-btn co-wh-btn--ghost co-wh-btn--sm" onClick={ handleTest } disabled={ testState === 'loading' }>
						{ testState === 'loading' ? 'Sending…' : 'Send Test' }
					</button>

					<button className="co-wh-btn co-wh-btn--ghost co-wh-btn--sm" onClick={ () => setEditing( true ) }>Edit</button>

					<button
						className="co-wh-btn co-wh-btn--danger co-wh-btn--sm"
						onClick={ handleDelete }
						onBlur={ () => setTimeout( () => setConfirming( false ), 200 ) }
					>
						{ confirming ? 'Confirm?' : 'Delete' }
					</button>
				</div>
			</div>

			{ testState && testState !== 'loading' && (
				<p className={ `co-wh-test-result ${ testState.ok ? 'co-wh-test-result--ok' : 'co-wh-test-result--err' }` }
					style={{ marginTop: 10, marginBottom: 0 }}>
					{ testState.ok ? '✓' : '✗' } { testState.msg }
				</p>
			) }

			<div className="co-wh-pills">
				{ ( webhook.events || [] ).map( ev => (
					<span key={ ev } className="co-wh-pill">{ ev }</span>
				) ) }
			</div>

			{ logs.length > 0 && (
				<div className="co-wh-log">
					<div className="co-wh-log-title">Recent Deliveries</div>
					{ logs.map( ( log, i ) => <LogRow key={ i } log={ log } /> ) }
				</div>
			) }
		</div>
	);
}

// ── Main component ──────────────────────────────────────────────────────────────

export default function WebhooksApp() {
	const { featureAccess = {}, userPlan = 'free' } = window.coData || {};
	const canUse    = featureAccess.use_webhooks;
	const hasProjects = featureAccess.use_projects;
	const EVENTS    = ALL_EVENTS.filter( ev => hasProjects || ! ev.value.startsWith( 'project.' ) );

	const [ webhooks, setWebhooks ] = useState( [] );
	const [ loading, setLoading ]   = useState( true );
	const [ error, setError ]       = useState( '' );
	const [ adding, setAdding ]     = useState( false );
	const [ saving, setSaving ]     = useState( false );
	const [ newWebhookSecret, setNewWebhookSecret ] = useState( null );
	const stylesInjected = useRef( false );

	// Inject styles once.
	useEffect( () => {
		if ( stylesInjected.current ) return;
		const el = document.createElement( 'style' );
		el.textContent = WH_CSS;
		document.head.appendChild( el );
		stylesInjected.current = true;
	}, [] );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const res = await cfFetch( 'webhooks' );
			setWebhooks( res.webhooks || [] );
		} catch ( e ) {
			setError( e.message || 'Failed to load webhooks.' );
		}
		setLoading( false );
	}, [] );

	useEffect( () => { load(); }, [ load ] );

	const handleCreate = async ( data ) => {
		setSaving( true );
		setNewWebhookSecret( null );
		try {
			const res = await cfFetch( 'webhooks', {
				method: 'POST',
				body: JSON.stringify( data ),
			} );
			setWebhooks( prev => [ res.webhook, ...prev ] );
			setNewWebhookSecret( res.webhook.secret );
			setAdding( false );
		} catch ( e ) {
			setError( e.message || 'Failed to create webhook.' );
		}
		setSaving( false );
	};

	const handleUpdate = ( updated ) => {
		setWebhooks( prev => prev.map( w => w.id === updated.id ? { ...updated, logs: w.logs } : w ) );
	};

	const handleDelete = ( id ) => {
		setWebhooks( prev => prev.filter( w => w.id !== id ) );
	};

	return (
		<div className="co-wh">
			{ /* Header */ }
			<div className="co-wh-header">
				<div>
					<h1 className="co-wh-title">Webhooks</h1>
					<p className="co-wh-sub">Automatically POST to any URL when key events happen — connect Zapier, Make, or your own systems.</p>
				</div>
				{ canUse && ! adding && (
					<button className="co-wh-btn co-wh-btn--primary" onClick={ () => { setAdding( true ); setNewWebhookSecret( null ); } }>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
						Add Webhook
					</button>
				) }
			</div>

			{ /* Upgrade banner */ }
			{ ! canUse && (
				<div className="co-wh-upgrade">
					<p className="co-wh-upgrade-title">Unlock Outbound Webhooks</p>
					<p className="co-wh-upgrade-sub">Connect ClientFlow to Zapier, Make, Slack, and 7,000+ other tools. Available on Pro and Agency plans.</p>
					<a href="https://clientoctopus.com/pricing" target="_blank" rel="noreferrer" className="co-wh-btn co-wh-btn--primary" style={{ textDecoration: 'none' }}>
						Upgrade Plan
					</a>
				</div>
			) }

			{ /* Error notice */ }
			{ error && (
				<div className="co-wh-notice co-wh-notice--error">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
					{ error }
				</div>
			) }

			{ /* Secret reveal after creation */ }
			{ newWebhookSecret && (
				<div className="co-wh-secret-box" style={{ marginBottom: 24 }}>
					<p className="co-wh-secret-label">Signing Secret — copy now</p>
					<div className="co-wh-secret-row">
						<span className="co-wh-secret-val">{ newWebhookSecret }</span>
						<button className="co-wh-btn co-wh-btn--ghost co-wh-btn--sm" onClick={ () => { navigator.clipboard.writeText( newWebhookSecret ); } }>Copy</button>
					</div>
					<p className="co-wh-secret-note">Use this secret to verify the <code>X-ClientFlow-Signature</code> header on incoming requests. It won't be shown again.</p>
				</div>
			) }

			{ /* Add form */ }
			{ adding && canUse && (
				<WebhookForm
					onSave={ handleCreate }
					onCancel={ () => setAdding( false ) }
					saving={ saving }
					availableEvents={ EVENTS }
				/>
			) }

			{ /* List */ }
			{ loading ? (
				<p style={{ color: '#94A3B8', fontSize: 14 }}>Loading…</p>
			) : webhooks.length === 0 && canUse ? (
				<div className="co-wh-empty">
					<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#CBD5E1" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
						<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>
					</svg>
					<p className="co-wh-empty-title">No webhooks yet</p>
					<p className="co-wh-empty-sub">Click "Add Webhook" to start automating your workflow.</p>
				</div>
			) : (
				webhooks.map( wh => (
					<WebhookCard
						key={ wh.id }
						webhook={ wh }
						onUpdate={ handleUpdate }
						onDelete={ handleDelete }
						availableEvents={ EVENTS }
					/>
				) )
			) }
		</div>
	);
}
