/**
 * PortalApp
 *
 * Authenticated shell: sidebar + main content area.
 * Imports fonts once here (idempotent injectStyles id ensures no double-load).
 *
 * Props: { page } — 'dashboard' | 'proposals' | 'payments'
 */

import PortalSidebar   from '../PortalSidebar';
import PortalDashboard from '../PortalDashboard';
import PortalProposals from '../PortalProposals';
import PortalPayments  from '../PortalPayments';
import PortalProjects  from '../PortalProjects';

injectStyles( 'co-global-s', `@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@400;500;600;700&family=DM+Mono&display=swap');
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: 'DM Sans', sans-serif; -webkit-font-smoothing: antialiased; background: #F8F7F5; }` );

injectStyles( 'cpa-s', `
/* ── Shell ───────────────────────────────────────────── */
.cpa-shell {
	display: flex;
	min-height: 100vh;
}

/* ── Main content ─────────────────────────────────────── */
.cpa-main {
	flex: 1;
	min-width: 0;
	background: #F8F7F5;
	padding: 48px 52px;
}

/* ── Mobile: sidebar becomes bottom tab bar ───────────── */
@media (max-width: 768px) {
	.cpa-shell { flex-direction: column; }
	.cpa-main  { padding: 28px 20px 100px; }
}

@media print {
	.cpa-sidebar { display: none !important; }
	.cpa-main    { padding: 0; }
}
` );

export default function PortalApp( { page } ) {
	return (
		<div className="cpa-shell">
			<PortalSidebar page={ page } />
			<main className="cpa-main">
				{ 'dashboard'  === page && <PortalDashboard /> }
				{ 'proposals'  === page && <PortalProposals /> }
				{ 'projects'   === page && <PortalProjects /> }
				{ 'payments'   === page && <PortalPayments /> }
			</main>
		</div>
	);
}
