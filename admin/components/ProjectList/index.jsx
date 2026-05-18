/**
 * ProjectList
 *
 * Lists all projects with status filter tabs, milestone progress bars,
 * and quick-action buttons.
 *
 * Props: { onViewProject }
 */
import { useState, useEffect } from '@wordpress/element';
import { coFetch } from '../ProjectsApp';

const STATUS_TABS = [
	{ id: '',          label: 'All'       },
	{ id: 'active',    label: 'Active'    },
	{ id: 'on-hold',   label: 'On Hold'   },
	{ id: 'completed', label: 'Completed' },
];

const STATUS_CONFIG = {
	'active':    { bg: 'var(--co-indigo-bg)',  color: 'var(--co-indigo)',   label: 'Active'    },
	'on-hold':   { bg: 'var(--co-amber-bg)',   color: 'var(--co-amber)',    label: 'On Hold'   },
	'completed': { bg: 'var(--co-emerald-bg)', color: 'var(--co-emerald)',  label: 'Completed' },
};

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
}

const CSS = `
.co-pl-wrap { display: flex; flex-direction: column; }

.co-pl-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  margin-bottom: 28px; gap: 16px;
}
.co-pl-title {
  font-family: var(--co-font);
  font-size: 28px; font-weight: 800; color: var(--co-navy);
  letter-spacing: -.5px; margin: 0; line-height: 1;
}
.co-pl-subtitle {
  font-size: 14px; color: var(--co-slate-400); margin: 6px 0 0; line-height: 1.5;
}

.co-pl-tabs {
  display: flex; gap: 2px; margin-bottom: 24px;
  border-bottom: 2px solid var(--co-slate-100);
}
.co-pl-tab {
  padding: 9px 18px; font-size: 13px; font-weight: 500;
  color: var(--co-slate-500); border: none; background: none;
  cursor: pointer; border-bottom: 2px solid transparent;
  margin-bottom: -2px; transition: color .12s, border-color .12s;
}
.co-pl-tab:hover { color: var(--co-indigo); }
.co-pl-tab.active { color: var(--co-indigo); border-bottom-color: var(--co-indigo); }

.co-pl-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 16px;
}

.co-pl-card {
  background: #fff;
  border: 1px solid var(--co-slate-200);
  border-radius: var(--co-radius);
  padding: 22px 24px 20px;
  box-shadow: var(--co-shadow);
  display: flex; flex-direction: column; gap: 14px;
  transition: box-shadow .15s, transform .15s;
  cursor: pointer;
}
.co-pl-card:hover {
  box-shadow: var(--co-shadow-lg);
  transform: translateY(-1px);
}

.co-pl-card-top {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
}
.co-pl-card-title {
  font-family: var(--co-font-display);
  font-size: 17px; color: var(--co-navy); line-height: 1.3;
  flex: 1;
}
.co-pl-badge {
  display: inline-flex; align-items: center;
  padding: 3px 10px; border-radius: 20px;
  font-size: 11px; font-weight: 600; letter-spacing: .4px;
  white-space: nowrap; flex-shrink: 0;
}

.co-pl-card-meta {
  font-size: 13px; color: var(--co-slate-500);
  display: flex; flex-direction: column; gap: 3px;
}
.co-pl-card-meta span { display: flex; align-items: center; gap: 6px; }

.co-pl-progress-wrap { display: flex; flex-direction: column; gap: 5px; }
.co-pl-progress-label {
  font-size: 12px; color: var(--co-slate-500);
  display: flex; justify-content: space-between;
}
.co-pl-progress-bar {
  height: 6px; background: var(--co-slate-100); border-radius: 99px; overflow: hidden;
}
.co-pl-progress-fill {
  height: 100%; border-radius: 99px;
  background: var(--co-emerald);
  transition: width .4s ease;
}
.co-pl-progress-fill.complete { background: var(--co-emerald); }

.co-pl-card-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding-top: 10px; border-top: 1px solid var(--co-slate-100);
}
.co-pl-view-btn {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 13px; font-weight: 600; color: var(--co-indigo);
  background: none; border: none; padding: 0; cursor: pointer;
  transition: gap .12s;
}
.co-pl-view-btn:hover { gap: 8px; }
.co-pl-date { font-size: 12px; color: var(--co-slate-400); }

.co-pl-empty {
  grid-column: 1/-1;
  background: #fff; border: 1.5px dashed var(--co-slate-200);
  border-radius: var(--co-radius); padding: 56px 32px;
  text-align: center;
}
.co-pl-empty-icon { color: var(--co-slate-300); margin: 0 auto 16px; display: block; }
.co-pl-empty-title { font-family: var(--co-font-display); font-size: 20px; color: var(--co-navy); margin-bottom: 8px; }
.co-pl-empty-sub { font-size: 14px; color: var(--co-slate-500); max-width: 380px; margin: 0 auto; line-height: 1.6; }

.co-pl-skeleton {
  background: linear-gradient(90deg, var(--co-slate-100) 25%, var(--co-slate-50) 50%, var(--co-slate-100) 75%);
  background-size: 200% 100%;
  animation: co-pl-shimmer 1.5s infinite;
  border-radius: 6px;
}
@keyframes co-pl-shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

.co-pl-upgrade-banner {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  background: var(--co-amber-bg, #fffbeb);
  border: 1.5px solid rgba(245,158,11,.25);
  border-left: 4px solid var(--co-amber, #f59e0b);
  border-radius: var(--co-radius, 12px);
  padding: 14px 18px;
  margin-bottom: 24px;
}
.co-pl-upgrade-banner__left { display: flex; align-items: center; gap: 12px; min-width: 0; }
.co-pl-upgrade-banner__icon {
  width: 34px; height: 34px; border-radius: 8px;
  background: rgba(245,158,11,.15);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.co-pl-upgrade-banner__icon svg { stroke: var(--co-amber, #f59e0b); }
.co-pl-upgrade-banner__text { min-width: 0; }
.co-pl-upgrade-banner__title { font-size: 13px; font-weight: 700; color: var(--co-navy, #1a1a2e); }
.co-pl-upgrade-banner__sub { font-size: 12px; color: var(--co-slate-500, #64748b); margin-top: 2px; line-height: 1.45; }
.co-pl-upgrade-banner__btn {
  display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;
  padding: 7px 14px; border-radius: 7px;
  background: rgba(245,158,11,.12); border: 1.5px solid rgba(245,158,11,.4);
  font-size: 12px; font-weight: 600; color: #92400e;
  text-decoration: none; transition: background .15s, border-color .15s;
  cursor: pointer;
}
.co-pl-upgrade-banner__btn svg {
fill: #92400e;
}
.co-pl-upgrade-banner__btn:hover { background: rgba(245,158,11,.2); border-color: rgba(245,158,11,.6); color: #92400e; }
`;

function StatusBadge( { status } ) {
	const cfg = STATUS_CONFIG[ status ] || STATUS_CONFIG['active'];
	return (
		<span className="co-pl-badge" style={ { background: cfg.bg, color: cfg.color } }>
			{ cfg.label }
		</span>
	);
}

function formatDate( d ) {
	if ( ! d ) return '—';
	try { return new Date( d ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' } ); }
	catch { return d; }
}

function SkeletonCard() {
	return (
		<div className="co-pl-card" style={ { cursor: 'default' } }>
			<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' } }>
				<div className="co-pl-skeleton" style={ { width: '55%', height: 18, borderRadius: 6 } } />
				<div className="co-pl-skeleton" style={ { width: 68, height: 20, borderRadius: 20 } } />
			</div>
			<div className="co-pl-skeleton" style={ { width: '40%', height: 13, borderRadius: 4 } } />
			<div>
				<div className="co-pl-skeleton" style={ { width: '100%', height: 6, borderRadius: 99 } } />
			</div>
		</div>
	);
}

export default function ProjectList( { onViewProject } ) {
	injectStyles( 'co-pl-styles', CSS );

	const isAgency    = window.coData?.featureAccess?.use_projects === true;
	const currentPlan = window.coData?.userPlan ?? 'free';
	const settingsUrl = ( window.coData?.adminUrl || '/wp-admin/' ) + 'admin.php?page=clientoctopus-settings';

	const [ projects, setProjects ] = useState( [] );
	const [ loading, setLoading ]   = useState( true );
	const [ error, setError ]       = useState( null );
	const [ tab, setTab ]           = useState( '' );

	useEffect( () => {
		fetchProjects();
	}, [] );

	async function fetchProjects() {
		setLoading( true );
		setError( null );
		try {
			const data = await coFetch( 'projects' );
			setProjects( data.projects || [] );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setLoading( false );
		}
	}

	const filtered = tab
		? projects.filter( p => p.status === tab )
		: projects;

	return (
		<div className="co-pl-wrap">
			<div className="co-pl-header">
				<div>
					<h1 className="co-pl-title">Projects</h1>
					<p className="co-pl-subtitle">
						{ loading ? '' : `${ projects.length } project${ projects.length !== 1 ? 's' : '' } total` }
					</p>
				</div>
			</div>

			{ ! isAgency && (
				<div className="co-pl-upgrade-banner">
					<div className="co-pl-upgrade-banner__left">
						<div className="co-pl-upgrade-banner__icon">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
								<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
							</svg>
						</div>
						<div className="co-pl-upgrade-banner__text">
							<div className="co-pl-upgrade-banner__title">Projects — Read Only</div>
							<div className="co-pl-upgrade-banner__sub">
								You're on the { currentPlan.charAt(0).toUpperCase() + currentPlan.slice(1) } plan. Your project data is preserved — upgrade to Agency to create and manage projects.
							</div>
						</div>
					</div>
					<a href={ settingsUrl } className="co-pl-upgrade-banner__btn">
						Upgrade to Agency
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
							<path d="M5 12h14M12 5l7 7-7 7"/>
						</svg>
					</a>
				</div>
			) }

			{ error && (
				<div style={ {
					background: 'var(--co-red-bg)', color: 'var(--co-red)',
					borderRadius: 8, padding: '12px 16px', marginBottom: 24, fontSize: 14,
				} }>
					{ error }
				</div>
			) }

			<div className="co-pl-tabs">
				{ STATUS_TABS.map( t => (
					<button
						key={ t.id }
						className={ `co-pl-tab${ tab === t.id ? ' active' : '' }` }
						onClick={ () => setTab( t.id ) }
					>
						{ t.label }
					</button>
				) ) }
			</div>

			<div className="co-pl-grid">
				{ loading ? (
					[ 1, 2, 3 ].map( i => <SkeletonCard key={ i } /> )
				) : filtered.length === 0 ? (
					<div className="co-pl-empty">
						<svg className="co-pl-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none"
							stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
							<rect x="2" y="7" width="20" height="14" rx="2"/>
							<path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/>
							<line x1="12" y1="12" x2="12" y2="16"/>
							<line x1="10" y1="14" x2="14" y2="14"/>
						</svg>
						<p className="co-pl-empty-title">No projects yet</p>
						<p className="co-pl-empty-sub">
							{ tab
								? `No ${ tab } projects found.`
								: <>Projects are created automatically when a client accepts a proposal. <a href="admin.php?page=clientoctopus-proposals" style={ { color: 'var(--co-indigo)' } }>Send your first proposal</a> to get started.</> }
						</p>
					</div>
				) : (
					filtered.map( project => (
						<ProjectCard
							key={ project.id }
							project={ project }
							onClick={ () => onViewProject( project.id ) }
						/>
					) )
				) }
			</div>
		</div>
	);
}

function ProjectCard( { project, onClick } ) {
	const total     = project.milestone_total     || 0;
	const completed = project.milestone_completed || 0;
	const pct       = project.progress_pct        || 0;

	return (
		<div className="co-pl-card" onClick={ onClick }>
			<div className="co-pl-card-top">
				<span className="co-pl-card-title">{ project.name }</span>
				<StatusBadge status={ project.status } />
			</div>

			<div className="co-pl-card-meta">
				{ project.client_name && (
					<span>
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none"
							stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
							<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
							<circle cx="12" cy="7" r="4"/>
						</svg>
						{ project.client_name }
						{ project.client_company ? ` · ${ project.client_company }` : '' }
					</span>
				) }
				{ project.proposal_title && (
					<span>
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none"
							stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
							<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
							<polyline points="14 2 14 8 20 8"/>
						</svg>
						{ project.proposal_title }
					</span>
				) }
			</div>

			<div className="co-pl-progress-wrap">
				<div className="co-pl-progress-label">
					<span>Milestones</span>
					<span>{ completed } / { total }</span>
				</div>
				<div className="co-pl-progress-bar">
					<div
						className={ `co-pl-progress-fill${ pct === 100 ? ' complete' : '' }` }
						style={ { width: `${ pct }%` } }
					/>
				</div>
			</div>

			<div className="co-pl-card-footer">
				<span className="co-pl-date">Created { formatDate( project.created_at ) }</span>
				<button className="co-pl-view-btn">
					View
					<svg width="13" height="13" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
						<polyline points="9 18 15 12 9 6"/>
					</svg>
				</button>
			</div>
		</div>
	);
}
