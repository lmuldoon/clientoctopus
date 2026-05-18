/**
 * TemplateSelector
 *
 * 2×2 grid of proposal templates. Free templates are selectable;
 * the Marketing Campaign template is locked behind Pro.
 *
 * Props:
 *   selected   {string}   — currently selected template ID
 *   onSelect   {fn}       — callback(templateId)
 *   userPlan   {string}   — 'free' | 'pro' | 'agency'
 */
import { useState } from '@wordpress/element';

// ─── Template definitions ─────────────────────────────────────────────────────
const TEMPLATES = [
	{
		id: 'web-design',
		label: 'Web Design',
		description: 'Full website project scope with design, development & launch phases.',
		tier: 'free',
		accent: '#6366F1',
		accentBg: '#EEF2FF',
		icon: (
			<svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
				<rect x="4" y="8" width="32" height="24" rx="3" stroke="currentColor" strokeWidth="1.75"/>
				<line x1="4" y1="15" x2="36" y2="15" stroke="currentColor" strokeWidth="1.75"/>
				<circle cx="9" cy="11.5" r="1.5" fill="currentColor"/>
				<circle cx="14" cy="11.5" r="1.5" fill="currentColor"/>
				<circle cx="19" cy="11.5" r="1.5" fill="currentColor"/>
				<rect x="8" y="20" width="10" height="7" rx="1.5" fill="currentColor" opacity=".2"/>
				<rect x="8" y="20" width="10" height="7" rx="1.5" stroke="currentColor" strokeWidth="1.5"/>
				<line x1="22" y1="21" x2="32" y2="21" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
				<line x1="22" y1="24" x2="29" y2="24" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
				<line x1="22" y1="27" x2="31" y2="27" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
			</svg>
		),
	},
	{
		id: 'retainer',
		label: 'Retainer',
		description: 'Monthly ongoing services with recurring scope and deliverables.',
		tier: 'free',
		accent: '#10B981',
		accentBg: '#ECFDF5',
		icon: (
			<svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
				<circle cx="20" cy="20" r="14" stroke="currentColor" strokeWidth="1.75"/>
				<path d="M20 10v10l6 4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
				<circle cx="20" cy="20" r="2" fill="currentColor"/>
				<path d="M8 20h2M30 20h2M20 8v2M20 30v2" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
			</svg>
		),
	},
	{
		id: 'marketing',
		label: 'Marketing Campaign',
		description: 'SEO, paid ads & content strategy scope for growth campaigns.',
		tier: 'pro',
		accent: '#F59E0B',
		accentBg: '#FFFBEB',
		icon: (
			<svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M8 28L16 18l6 6 6-8 6 4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
				<circle cx="32" cy="11" r="4" stroke="currentColor" strokeWidth="1.75"/>
				<path d="M32 9v2l1 1" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
				<rect x="6" y="30" width="28" height="3" rx="1.5" fill="currentColor" opacity=".15"/>
			</svg>
		),
	},
	{
		id: 'blank',
		label: 'Blank Proposal',
		description: 'Start from scratch with a fully customisable empty canvas.',
		tier: 'free',
		accent: '#94A3B8',
		accentBg: '#F8FAFC',
		icon: (
			<svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
				<rect x="10" y="6" width="20" height="28" rx="2" stroke="currentColor" strokeWidth="1.75"/>
				<line x1="15" y1="14" x2="25" y2="14" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
				<line x1="15" y1="19" x2="25" y2="19" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
				<line x1="15" y1="24" x2="21" y2="24" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
				<circle cx="27" cy="27" r="5" fill="currentColor" opacity=".08" stroke="currentColor" strokeWidth="1.5"/>
				<path d="M25 27h4M27 25v4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
			</svg>
		),
	},
];

// ─── Styles ───────────────────────────────────────────────────────────────────
const CSS = `
.co-ts-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}
.co-ts-card {
  position: relative;
  background: var(--co-white);
  border: 2px solid var(--co-slate-200);
  border-radius: var(--co-radius);
  padding: 22px;
  cursor: pointer;
  transition: border-color .18s, box-shadow .18s, transform .18s;
  overflow: hidden;
  outline: none;
  text-align: left;
  width: 100%;
}
.co-ts-card:hover:not(.co-ts-locked) {
  border-color: var(--co-indigo);
  box-shadow: var(--co-shadow), 0 0 0 3px rgba(99,102,241,.1);
  transform: translateY(-2px);
}
.co-ts-card.co-ts-selected {
  border-color: var(--co-indigo);
  box-shadow: 0 0 0 3px rgba(99,102,241,.15);
}
.co-ts-card.co-ts-locked {
  cursor: not-allowed;
  opacity: .75;
}
.co-ts-card.co-ts-locked:hover .co-ts-lock-overlay {
  opacity: 1;
}
.co-ts-icon-wrap {
  width: 52px; height: 52px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 14px;
  transition: background .18s;
}
.co-ts-label {
  font-size: 15px;
  font-weight: 600;
  color: var(--co-slate-800);
  margin-bottom: 5px;
  line-height: 1.3;
}
.co-ts-desc {
  font-size: 12.5px;
  color: var(--co-slate-400);
  line-height: 1.5;
}
/* Selected checkmark */
.co-ts-check {
  position: absolute;
  top: 14px; right: 14px;
  width: 26px; height: 26px;
  background: var(--co-indigo);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  animation: co-pop .2s cubic-bezier(.34,1.56,.64,1);
}
.co-ts-check svg { width: 13px; height: 13px; stroke: #fff; stroke-width: 2.5; }
@keyframes co-pop {
  from { transform: scale(0); opacity: 0; }
  to   { transform: scale(1); opacity: 1; }
}
/* Pro lock */
.co-ts-lock-badge {
  position: absolute;
  top: 14px; right: 14px;
  display: flex; align-items: center; gap: 5px;
  background: var(--co-amber-bg);
  border: 1px solid rgba(245,158,11,.25);
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 11px;
  font-weight: 600;
  color: var(--co-amber);
  letter-spacing: .04em;
}
.co-ts-lock-badge svg { width: 11px; height: 11px; stroke: currentColor; stroke-width: 2.5; }
.co-ts-lock-overlay {
  position: absolute;
  inset: 0;
  background: rgba(255,255,255,.85);
  backdrop-filter: blur(2px);
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 8px;
  border-radius: var(--co-radius);
  opacity: 0;
  transition: opacity .2s;
}
.co-ts-lock-overlay svg { width: 28px; height: 28px; stroke: var(--co-slate-400); stroke-width: 1.5; }
.co-ts-lock-overlay-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--co-slate-600);
}
.co-ts-lock-overlay-sub {
  font-size: 12px;
  color: var(--co-slate-400);
}
`;

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const el = document.createElement( 'style' );
	el.id = id;
	el.textContent = css;
	document.head.appendChild( el );
}

// ─── Component ────────────────────────────────────────────────────────────────
export default function TemplateSelector( { selected, onSelect, userPlan = 'free' } ) {
	injectStyles( 'co-ts-styles', CSS );

	return (
		<div className="co-ts-grid">
			{ TEMPLATES.map( ( tpl ) => {
				const isLocked   = tpl.tier === 'pro' && userPlan === 'free';
				const isSelected = selected === tpl.id;

				return (
					<button
						key={ tpl.id }
						type="button"
						className={ [
							'co-ts-card',
							isSelected ? 'co-ts-selected' : '',
							isLocked   ? 'co-ts-locked'   : '',
						].join( ' ' ) }
						onClick={ () => ! isLocked && onSelect( tpl.id ) }
						aria-pressed={ isSelected }
						aria-disabled={ isLocked }
					>
						{/* Icon */ }
						<div
							className="co-ts-icon-wrap"
							style={ { background: isSelected ? tpl.accent + '22' : tpl.accentBg, color: tpl.accent } }
						>
							{ tpl.icon }
						</div>

						{/* Text */ }
						<div className="co-ts-label">{ tpl.label }</div>
						<div className="co-ts-desc">{ tpl.description }</div>

						{/* Selected state */ }
						{ isSelected && ! isLocked && (
							<div className="co-ts-check">
								<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
									<polyline points="20 6 9 17 4 12"/>
								</svg>
							</div>
						) }

						{/* Locked state */ }
						{ isLocked && (
							<>
								<div className="co-ts-lock-badge">
									<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
										<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
									</svg>
									Pro
								</div>
								<div className="co-ts-lock-overlay">
									<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
										<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
									</svg>
									<div className="co-ts-lock-overlay-label">Pro Required</div>
									<div className="co-ts-lock-overlay-sub">Upgrade to access this template</div>
								</div>
							</>
						) }
					</button>
				);
			} ) }
		</div>
	);
}
