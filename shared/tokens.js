/**
 * Canonical design tokens for the admin app.
 *
 * Each admin screen is its own standalone webpack entry point / page load
 * (admin/index.jsx, projects.jsx, clients.jsx, invoices.jsx, team.jsx,
 * webhooks.jsx, setup.jsx — analytics.jsx is intentionally excluded, see
 * below), so there is no shared JS runtime between them and each one must
 * inject its own copy of this `:root` block into its own document. Before
 * this file existed, every entry point hand-copied the block independently,
 * which is exactly how two real bugs shipped this session (a settings.css
 * colour/radius drift, and several screens silently losing to WordPress's
 * own default input styling). Import CO_TOKENS_CSS here instead of
 * redeclaring the block, so there is exactly one place to change a value.
 *
 * Not used by admin/components/AnalyticsApp/index.jsx — that screen uses an
 * intentionally separate, non-overlapping token vocabulary (--co-bg,
 * --co-surface, --co-text, --co-muted, --co-accent, --co-accent2, --co-green)
 * and also hardcodes some of the same colours directly in inline SVG chart
 * markup, so swapping it to this set requires a coordinated CSS + SVG rework,
 * not a drop-in replacement. Left as a separate follow-up.
 */
export const CO_TOKENS_CSS = `
:root {
  --co-navy:       #0F172A;
  --co-navy-mid:   #1E293B;
  --co-navy-dim:   #334155;
  --co-indigo:     #6366F1;
  --co-indigo-lt:  #818CF8;
  --co-indigo-bg:  #EEF2FF;
  --co-emerald:    #10B981;
  --co-emerald-bg: #ECFDF5;
  --co-amber:      #F59E0B;
  --co-amber-bg:   #FFFBEB;
  --co-red:        #EF4444;
  --co-red-bg:     #FEF2F2;
  --co-slate-50:   #F8FAFC;
  --co-slate-100:  #F1F5F9;
  --co-slate-200:  #E2E8F0;
  --co-slate-300:  #CBD5E1;
  --co-slate-400:  #94A3B8;
  --co-slate-500:  #64748B;
  --co-slate-600:  #475569;
  --co-slate-700:  #334155;
  --co-slate-800:  #1E293B;
  --co-white:      #FFFFFF;
  --co-radius:     12px;
  --co-radius-sm:  8px;
  --co-shadow:     0 1px 3px rgba(15,23,42,.06), 0 4px 16px rgba(15,23,42,.08);
  --co-shadow-lg:  0 4px 6px rgba(15,23,42,.05), 0 10px 40px rgba(15,23,42,.12);
  --co-font:         'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
  --co-font-display: 'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
  --co-input-border: 1.5px solid var(--co-slate-200);
  --co-input-focus: 0 0 0 3px rgba(99,102,241,.12);
}
`;
