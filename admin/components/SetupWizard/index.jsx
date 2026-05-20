/**
 * SetupWizard
 *
 * Full-screen onboarding wizard. Hides WP admin chrome on mount.
 * Free plan:  Welcome → Brand → Done  (3 steps)
 * Paid plans: Welcome → Stripe → Brand → Done  (4 steps)
 */
import { useState, useEffect } from '@wordpress/element';

const API  = window.coData?.apiUrl  || '/wp-json/clientoctopus/v1/';
const NONCE = window.coData?.nonce  || '';

async function apiFetch( path, opts = {} ) {
	const res = await fetch( API + path, {
		headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
		...opts,
	} );
	const json = await res.json();
	if ( ! res.ok ) throw new Error( json.message || 'Request failed' );
	return json;
}

// ─── CSS ─────────────────────────────────────────────────────────────────────

const CSS = `

body.co-setup-active #adminmenuback,
body.co-setup-active #adminmenuwrap,
body.co-setup-active #wpadminbar,
body.co-setup-active #wpfooter,
body.co-setup-active .notice,
body.co-setup-active .update-nag {
  display: none !important;
}
body.co-setup-active #wpcontent,
body.co-setup-active #wpbody,
body.co-setup-active #wpbody-content {
  margin: 0 !important;
  padding: 0 !important;
  float: none !important;
}
body.co-setup-active {
  background: #0f172a !important;
  overflow: hidden;
}

.co-sw-root {
  font-family: 'Archivo', sans-serif;
  display: flex;
  position: fixed;
  inset: 0;
  overflow: hidden;
  background: #0f172a;
}

/* ── Left Panel ── */
.co-sw-left {
  width: 380px;
  flex-shrink: 0;
  background: #0f172a;
  display: flex;
  flex-direction: column;
  padding: 48px 40px;
  position: relative;
  overflow: hidden;
}
.co-sw-left::before {
  content: '';
  position: absolute;
  top: -120px;
  right: -80px;
  width: 320px;
  height: 320px;
  background: radial-gradient(circle, rgba(99,102,241,.18) 0%, transparent 70%);
  pointer-events: none;
}
.co-sw-left::after {
  content: '';
  position: absolute;
  bottom: -80px;
  left: -60px;
  width: 280px;
  height: 280px;
  background: radial-gradient(circle, rgba(99,102,241,.10) 0%, transparent 70%);
  pointer-events: none;
}

.co-sw-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 56px;
}
.co-sw-logo-icon {
  width: 36px;
  height: 36px;
  background: #6366f1;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.co-sw-logo-name {
  font-size: 18px;
  font-weight: 800;
  color: #fff;
  letter-spacing: -.4px;
}

.co-sw-steps {
  display: flex;
  flex-direction: column;
  gap: 0;
  flex: 1;
}
.co-sw-step-row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding-bottom: 32px;
  position: relative;
}
.co-sw-step-row:not(:last-child)::before {
  content: '';
  position: absolute;
  left: 14px;
  top: 28px;
  bottom: 0;
  width: 1px;
  background: rgba(255,255,255,.08);
}
.co-sw-step-dot {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
  flex-shrink: 0;
  transition: all .3s ease;
  border: 2px solid rgba(255,255,255,.12);
  color: rgba(255,255,255,.3);
  background: transparent;
}
.co-sw-step-dot.done {
  background: #6366f1;
  border-color: #6366f1;
  color: #fff;
}
.co-sw-step-dot.active {
  background: rgba(99,102,241,.15);
  border-color: #6366f1;
  color: #6366f1;
  box-shadow: 0 0 0 4px rgba(99,102,241,.12);
}
.co-sw-step-info {
  padding-top: 3px;
}
.co-sw-step-label {
  font-size: 13px;
  font-weight: 600;
  line-height: 1;
  transition: color .3s;
}
.co-sw-step-label.done,
.co-sw-step-label.active { color: #fff; }
.co-sw-step-label.upcoming { color: rgba(255,255,255,.3); }
.co-sw-step-sub {
  font-size: 11.5px;
  color: rgba(255,255,255,.35);
  margin-top: 3px;
}

.co-sw-left-footer {
  margin-top: auto;
  padding-top: 32px;
  border-top: 1px solid rgba(255,255,255,.06);
}
.co-sw-tagline {
  font-size: 12px;
  color: rgba(255,255,255,.3);
  line-height: 1.6;
}
.co-sw-tagline strong {
  color: rgba(255,255,255,.6);
  font-weight: 600;
}

/* ── Right Panel ── */
.co-sw-right {
  flex: 1;
  background: #fff;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  position: relative;
}
.co-sw-content {
  flex: 1;
  overflow-y: auto;
  padding: 64px 72px;
  display: flex;
  flex-direction: column;
}

.co-sw-step-num {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  color: #6366f1;
  margin-bottom: 12px;
}
.co-sw-heading {
  font-size: 32px;
  font-weight: 900;
  color: #0f172a;
  letter-spacing: -.7px;
  line-height: 1.15;
  margin: 0 0 10px;
}
.co-sw-sub {
  font-size: 15px;
  color: #64748b;
  margin: 0 0 40px;
  line-height: 1.6;
  max-width: 440px;
}

/* Feature bullets (welcome step) */
.co-sw-features {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-bottom: 44px;
}
.co-sw-feature {
  display: flex;
  align-items: flex-start;
  gap: 14px;
}
.co-sw-feature-icon {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.co-sw-feature-text h4 {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
  margin: 0 0 2px;
}
.co-sw-feature-text p {
  font-size: 13px;
  color: #64748b;
  margin: 0;
  line-height: 1.5;
}

/* Form fields */
.co-sw-field {
  margin-bottom: 24px;
}
.co-sw-label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: #334155;
  margin-bottom: 7px;
}
.co-sw-label-hint {
  font-size: 11.5px;
  font-weight: 400;
  color: #94a3b8;
  margin-left: 6px;
}
.co-sw-input {
  width: 100%;
  padding: 11px 14px;
  font-family: 'Archivo', sans-serif;
  font-size: 14px;
  color: #0f172a;
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  outline: none;
  transition: border-color .15s, box-shadow .15s;
  background: #fff;
  box-sizing: border-box;
}
.co-sw-input:focus {
  border-color: #6366f1;
  box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}
.co-sw-input.monospace {
  font-family: 'Courier New', monospace;
  font-size: 13px;
  letter-spacing: .3px;
}

.co-sw-color-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.co-sw-color-swatch {
  width: 42px;
  height: 42px;
  border-radius: 10px;
  border: 1.5px solid #e2e8f0;
  padding: 2px;
  cursor: pointer;
  background: transparent;
}
.co-sw-color-hex {
  flex: 1;
}

.co-sw-skip {
  background: none;
  border: none;
  font-family: 'Archivo', sans-serif;
  font-size: 13px;
  color: #94a3b8;
  cursor: pointer;
  padding: 0;
  text-decoration: underline;
  text-underline-offset: 3px;
  transition: color .12s;
}
.co-sw-skip:hover { color: #64748b; }

.co-sw-stripe-note {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 14px 16px;
  font-size: 13px;
  color: #64748b;
  line-height: 1.5;
  margin-bottom: 24px;
}
.co-sw-stripe-note strong { color: #334155; }

/* Plan badge */
.co-sw-plan-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .4px;
  text-transform: uppercase;
  margin-top: 12px;
}
.co-sw-plan-badge.free     { background: #f1f5f9; color: #64748b; }
.co-sw-plan-badge.pro      { background: #eef2ff; color: #6366f1; }
.co-sw-plan-badge.agency   { background: #ecfdf5; color: #059669; }

/* Error */
.co-sw-error {
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 13px;
  color: #dc2626;
  margin-top: 12px;
}

/* Done step */
.co-sw-done-check {
  width: 80px;
  height: 80px;
  background: #ecfdf5;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 28px;
}
.co-sw-done-check svg {
  stroke-dasharray: 60;
  stroke-dashoffset: 60;
  animation: co-sw-draw 0.6s ease forwards 0.2s;
}
@keyframes co-sw-draw {
  to { stroke-dashoffset: 0; }
}

.co-sw-done-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 8px;
}

/* Navigation footer */
.co-sw-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 24px 72px;
  border-top: 1px solid #f1f5f9;
  background: #fff;
  flex-shrink: 0;
}
.co-sw-nav-left { display: flex; align-items: center; gap: 12px; }

/* Buttons */
.co-sw-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 11px 24px;
  font-family: 'Archivo', sans-serif;
  font-size: 14px;
  font-weight: 700;
  border-radius: 10px;
  border: none;
  cursor: pointer;
  transition: all .15s;
  text-decoration: none;
}
.co-sw-btn:disabled {
  opacity: .5;
  cursor: not-allowed;
}
.co-sw-btn-primary {
  background: #6366f1;
  color: #fff !important;
}
.co-sw-btn-primary:hover:not(:disabled) {
  background: #5557e8;
  box-shadow: 0 4px 14px rgba(99,102,241,.35);
  transform: translateY(-1px);
}
.co-sw-btn-ghost {
  background: transparent;
  color: #64748b;
  border: 1.5px solid #e2e8f0;
}
.co-sw-btn-ghost:hover:not(:disabled) {
  border-color: #cbd5e1;
  color: #334155;
}
.co-sw-btn-success {
  background: #059669;
  color: #fff;
}
.co-sw-btn-success:hover:not(:disabled) {
  background: #047857;
  transform: translateY(-1px);
}

/* Slide transition */
.co-sw-slide-enter {
  animation: co-sw-slide-in .28s ease both;
}
@keyframes co-sw-slide-in {
  from { opacity: 0; transform: translateX(24px); }
  to   { opacity: 1; transform: translateX(0); }
}
.co-sw-slide-back {
  animation: co-sw-slide-back-in .28s ease both;
}
@keyframes co-sw-slide-back-in {
  from { opacity: 0; transform: translateX(-24px); }
  to   { opacity: 1; transform: translateX(0); }
}
`;

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
}

// ─── Step definitions ─────────────────────────────────────────────────────────

const FREE_STEPS = [
	{ label: 'Welcome', sub: 'Get started'   },
	{ label: 'Brand',   sub: 'Your identity' },
	{ label: 'Done',    sub: 'All set!'      },
];

const ALL_STEPS = [
	{ label: 'Welcome',  sub: 'Get started'        },
	{ label: 'Payments', sub: 'Stripe integration'  },
	{ label: 'Brand',    sub: 'Your identity'       },
	{ label: 'Done',     sub: 'All set!'            },
];

// ─── Sub-components ───────────────────────────────────────────────────────────

function CheckIcon( { size = 20, color = 'currentColor' } ) {
	return (
		<svg width={ size } height={ size } viewBox="0 0 24 24" fill="none"
			stroke={ color } strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
			<polyline points="20 6 9 17 4 12"/>
		</svg>
	);
}

function WelcomeStep() {
	const features = [
		{
			icon: (
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none"
					stroke="#6366f1" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
					<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
					<polyline points="14 2 14 8 20 8"/>
				</svg>
			),
			title: 'Beautiful Proposals',
			desc:  'Create, send, and track professional proposals your clients can sign online.',
		},
		{
			icon: (
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none"
					stroke="#6366f1" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
					<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
					<line x1="1" y1="10" x2="23" y2="10"/>
				</svg>
			),
			title: 'Stripe Payments',
			desc:  'Collect deposits and full payments at the point of proposal acceptance.',
		},
		{
			icon: (
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none"
					stroke="#6366f1" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
					<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
					<circle cx="9" cy="7" r="4"/>
					<path d="M23 21v-2a4 4 0 00-3-3.87"/>
					<path d="M16 3.13a4 4 0 010 7.75"/>
				</svg>
			),
			title: 'Client Portal',
			desc:  'Give clients a branded portal to view proposals, projects, and files.',
		},
	];

	return (
		<div>
			<p className="co-sw-step-num">Getting Started</p>
			<h1 className="co-sw-heading">Welcome to Client Octopus</h1>
			<p className="co-sw-sub">
				The complete client management toolkit — proposals, payments, and projects in one WordPress plugin.
			</p>
			<div className="co-sw-features">
				{ features.map( f => (
					<div className="co-sw-feature" key={ f.title }>
						<div className="co-sw-feature-icon">{ f.icon }</div>
						<div className="co-sw-feature-text">
							<h4>{ f.title }</h4>
							<p>{ f.desc }</p>
						</div>
					</div>
				) ) }
			</div>
		</div>
	);
}

function StripeStep( { data, onChange, plan } ) {
	const isFree = plan === 'free' || ! plan;

	return (
		<div>
			<p className="co-sw-step-num">Step 2 of 4</p>
			<h1 className="co-sw-heading">Connect Stripe</h1>
			<p className="co-sw-sub">
				Accept payments directly within proposals. You can always add this later in Settings.
			</p>
			{ isFree && (
				<div className="co-sw-stripe-note">
					<strong>Stripe is available on Pro and Agency plans.</strong> You can skip this step and upgrade later from Settings → Plan & Usage.
				</div>
			) }
			<div className="co-sw-field">
				<label className="co-sw-label">Publishable Key</label>
				<input
					className={ `co-sw-input monospace${ isFree ? '' : '' }` }
					type="text"
					placeholder="pk_live_…"
					value={ data.stripe_pk || '' }
					onChange={ e => onChange( 'stripe_pk', e.target.value ) }
					disabled={ isFree }
				/>
			</div>
			<div className="co-sw-field">
				<label className="co-sw-label">Secret Key</label>
				<input
					className="co-sw-input monospace"
					type="password"
					placeholder="sk_live_…"
					value={ data.stripe_sk || '' }
					onChange={ e => onChange( 'stripe_sk', e.target.value ) }
					disabled={ isFree }
				/>
			</div>
			<div className="co-sw-field">
				<label className="co-sw-label">
					Webhook Secret
					<span className="co-sw-label-hint">from Stripe Dashboard → Webhooks</span>
				</label>
				<input
					className="co-sw-input monospace"
					type="password"
					placeholder="whsec_…"
					value={ data.stripe_webhook_secret || '' }
					onChange={ e => onChange( 'stripe_webhook_secret', e.target.value ) }
					disabled={ isFree }
				/>
			</div>
		</div>
	);
}

function BrandStep( { data, onChange, stepNum = 3, totalSteps = 4 } ) {
	const color = data.brand_color || '#6366f1';

	return (
		<div>
			<p className="co-sw-step-num">Step { stepNum } of { totalSteps }</p>
			<h1 className="co-sw-heading">Your Brand</h1>
			<p className="co-sw-sub">
				Your branding appears on proposals, emails, and the client portal.
			</p>
			<div className="co-sw-field">
				<label className="co-sw-label">Business Name</label>
				<input
					className="co-sw-input"
					type="text"
					placeholder="Acme Creative Studio"
					value={ data.business_name || '' }
					onChange={ e => onChange( 'business_name', e.target.value ) }
				/>
			</div>
			<div className="co-sw-field">
				<label className="co-sw-label">
					Sender Name
					<span className="co-sw-label-hint">shown in client inboxes</span>
				</label>
				<input
					className="co-sw-input"
					type="text"
					placeholder="Acme Creative Studio"
					value={ data.from_name || '' }
					onChange={ e => onChange( 'from_name', e.target.value ) }
				/>
			</div>
			<div className="co-sw-field">
				<label className="co-sw-label">
					Sender Email
					<span className="co-sw-label-hint">address you control</span>
				</label>
				<input
					className="co-sw-input"
					type="email"
					placeholder="hello@youragency.com"
					value={ data.from_email || '' }
					onChange={ e => onChange( 'from_email', e.target.value ) }
				/>
			</div>
			<div className="co-sw-field">
				<label className="co-sw-label">Brand Colour</label>
				<div className="co-sw-color-row">
					<input
						className="co-sw-color-swatch"
						type="color"
						value={ color }
						onChange={ e => onChange( 'brand_color', e.target.value ) }
						style={ { background: color } }
					/>
					<input
						className="co-sw-input co-sw-color-hex"
						type="text"
						placeholder="#6366f1"
						value={ color }
						onChange={ e => onChange( 'brand_color', e.target.value ) }
					/>
				</div>
			</div>
			<div className="co-sw-field">
				<label className="co-sw-label">
					Logo URL
					<span className="co-sw-label-hint">optional — paste a direct image URL</span>
				</label>
				<input
					className="co-sw-input"
					type="url"
					placeholder="https://example.com/logo.png"
					value={ data.logo_url || '' }
					onChange={ e => onChange( 'logo_url', e.target.value ) }
				/>
			</div>
		</div>
	);
}

function DoneStep() {
	const adminUrl = window.location.origin + window.location.pathname.replace( /admin\.php.*/, 'admin.php' );

	return (
		<div>
			<div className="co-sw-done-check">
				<svg width="40" height="40" viewBox="0 0 24 24" fill="none"
					stroke="#059669" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
					<polyline points="20 6 9 17 4 12"/>
				</svg>
			</div>
			<p className="co-sw-step-num">Setup Complete</p>
			<h1 className="co-sw-heading">You're all set!</h1>
			<p className="co-sw-sub">
				Client Octopus is configured and ready to use. Create your first proposal to start winning clients.
			</p>
			<div className="co-sw-done-actions">
				<a
					className="co-sw-btn co-sw-btn-primary"
					href={ `${ adminUrl }?page=clientoctopus-proposals` }
				>
					Create First Proposal
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
						<polyline points="9 18 15 12 9 6"/>
					</svg>
				</a>
				<a
					className="co-sw-btn co-sw-btn-ghost"
					href={ `${ adminUrl }?page=clientoctopus` }
				>
					Go to Dashboard
				</a>
			</div>
		</div>
	);
}

// ─── Main wizard ──────────────────────────────────────────────────────────────

export default function SetupWizard() {
	injectStyles( 'co-sw-styles', CSS );

	const [ step,      setStep      ] = useState( 0 );
	const [ direction, setDirection ] = useState( 'forward' );
	const [ saving,    setSaving    ] = useState( false );
	const [ error,     setError     ] = useState( null );
	const [ plan ] = useState( window.coData?.userPlan || '' );
	const isFree      = plan === 'free' || ! plan;
	const steps       = isFree ? FREE_STEPS : ALL_STEPS;
	const lastDataStep = isFree ? 1 : 2;
	const [ data,      setData      ] = useState( {
		stripe_pk:             '',
		stripe_sk:             '',
		stripe_webhook_secret: '',
		business_name:         '',
		brand_color:           '#6366f1',
		logo_url:              '',
	} );

	// Hide WP chrome, load saved state.
	useEffect( () => {
		document.body.classList.add( 'co-setup-active' );

		apiFetch( 'onboarding/status' ).then( status => {
			if ( status.saved ) {
				setData( prev => ( { ...prev, ...status.saved } ) );
			}
			if ( status.complete ) {
				// Already completed — jump to Done screen so the user isn't forced
				// through the wizard again (e.g. after a Freemius opt-in redirect).
				setStep( steps.length - 1 );
			} else if ( status.step > 0 ) {
				setStep( Math.min( status.step, steps.length - 1 ) );
			}
		} ).catch( () => {} );

		return () => document.body.classList.remove( 'co-setup-active' );
	}, [] );

	function handleChange( key, value ) {
		setData( prev => ( { ...prev, [ key ]: value } ) );
	}

	async function goNext() {
		setError( null );
		setSaving( true );
		try {
			if ( step > 0 && step < steps.length - 1 ) {
				await apiFetch( 'onboarding/save', {
					method: 'POST',
					body:   JSON.stringify( { step, ...data } ),
				} );
			}
			if ( step === lastDataStep ) {
				await apiFetch( 'onboarding/complete', { method: 'POST', body: '{}' } );
			}
			setDirection( 'forward' );
			setStep( s => s + 1 );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setSaving( false );
		}
	}

	function goBack() {
		setDirection( 'back' );
		setStep( s => s - 1 );
	}

	const animClass = direction === 'forward' ? 'co-sw-slide-enter' : 'co-sw-slide-back';

	const stepDotState = ( idx ) => {
		if ( idx < step )  return 'done';
		if ( idx === step ) return 'active';
		return 'upcoming';
	};

	const primaryLabel = step === 0 ? 'Get Started →'
		: step === lastDataStep ? ( saving ? 'Saving…' : 'Finish Setup →' )
		: ( saving ? 'Saving…' : 'Save & Continue →' );

	return (
		<div className="co-sw-root">
			{/* Left panel */ }
			<div className="co-sw-left">
				<div className="co-sw-logo">
					<div className="co-sw-logo-icon">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none"
							stroke="#fff" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
							<path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
						</svg>
					</div>
					<span className="co-sw-logo-name">Client Octopus</span>
				</div>

				<div className="co-sw-steps">
					{ steps.map( ( s, idx ) => {
						const state = stepDotState( idx );
						return (
							<div className="co-sw-step-row" key={ idx }>
								<div className={ `co-sw-step-dot ${ state }` }>
									{ state === 'done'
										? <CheckIcon size={ 13 } color="#fff" />
										: idx + 1
									}
								</div>
								<div className="co-sw-step-info">
									<div className={ `co-sw-step-label ${ state }` }>{ s.label }</div>
									<div className="co-sw-step-sub">{ s.sub }</div>
								</div>
							</div>
						);
					} ) }
				</div>

				<div className="co-sw-left-footer">
					<p className="co-sw-tagline">
						<strong>Need help?</strong> Visit our{' '}
						<a href="https://clientoctopus.com/docs" target="_blank" rel="noreferrer"
							style={ { color: 'rgba(255,255,255,.5)', textDecoration: 'underline' } }>
							documentation
						</a>{' '}
						or reach out to support.
					</p>
				</div>
			</div>

			{/* Right panel */ }
			<div className="co-sw-right">
				<div className="co-sw-content">
					<div key={ step } className={ animClass }>
						{ isFree ? (
							<>
								{ step === 0 && <WelcomeStep /> }
								{ step === 1 && <BrandStep data={ data } onChange={ handleChange } stepNum={ 2 } totalSteps={ 3 } /> }
								{ step === 2 && <DoneStep /> }
							</>
						) : (
							<>
								{ step === 0 && <WelcomeStep /> }
								{ step === 1 && <StripeStep data={ data } onChange={ handleChange } plan={ plan } /> }
								{ step === 2 && <BrandStep data={ data } onChange={ handleChange } stepNum={ 3 } totalSteps={ 4 } /> }
								{ step === 3 && <DoneStep /> }
							</>
						) }
					</div>
					{ error && <div className="co-sw-error">{ error }</div> }
				</div>

				{ step < steps.length - 1 && (
					<div className="co-sw-nav">
						<div className="co-sw-nav-left">
							{ step > 0 && (
								<button className="co-sw-btn co-sw-btn-ghost" onClick={ goBack } disabled={ saving }>
									← Back
								</button>
							) }
							{ step === 1 && ! isFree && (
								<button className="co-sw-skip" onClick={ goNext } disabled={ saving }>
									Skip for now
								</button>
							) }
						</div>
						<button
							className="co-sw-btn co-sw-btn-primary"
							onClick={ goNext }
							disabled={ saving }
						>
							{ primaryLabel }
						</button>
					</div>
				) }
			</div>
		</div>
	);
}
