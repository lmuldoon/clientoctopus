/**
 * ProjectDetail — redesigned
 *
 * Layout: hero header → tab bar (Overview | Files | Approvals) → two-column body
 * Tabs keep components mounted after first visit (no re-fetch on switch).
 * Sidebar shows project info, sticky on desktop.
 *
 * Props: { projectId, onBack }
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { coFetch } from '../ProjectsApp';
import ProjectFiles     from '../ProjectFiles';
import ProjectApprovals from '../ProjectApprovals';
import ProjectMessages  from '../ProjectMessages';

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id; s.textContent = css;
	document.head.appendChild( s );
}

const CSS = `
/* ─── Page enter ───────────────────────────────────────────────────── */
.co-pd {
  display: flex;
  flex-direction: column;
  gap: 0;
  padding: 32px 28px 64px;
  animation: co-pd-enter .22s ease both;
}
@keyframes co-pd-enter {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ─── Back button ──────────────────────────────────────────────────── */
.co-pd-back {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 500;
  color: var(--co-slate-400);
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  margin-bottom: 24px;
  letter-spacing: .01em;
  transition: color .12s;
}
.co-pd-back:hover { color: var(--co-indigo); }
.co-pd-back svg { flex-shrink: 0; transition: transform .15s; }
.co-pd-back:hover svg { transform: translateX(-2px); }

/* ─── Hero ─────────────────────────────────────────────────────────── */
.co-pd-hero {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 24px;
  padding-bottom: 22px;
  margin-bottom: 0;
  border-bottom: 1px solid var(--co-slate-200);
  flex-wrap: wrap;
}
.co-pd-hero-left { flex: 1; min-width: 0; }

.co-pd-project-name {
  font-family: var(--co-font);
  font-size: 26px;
  font-weight: 800;
  color: var(--co-navy);
  letter-spacing: -.5px;
  line-height: 1.15;
  margin: 0 0 8px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.co-pd-hero-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--co-slate-400);
  flex-wrap: wrap;
}
.co-pd-meta-dot {
  width: 3px;
  height: 3px;
  border-radius: 50%;
  background: var(--co-slate-300);
  flex-shrink: 0;
}

/* ─── Status pill select ───────────────────────────────────────────── */
.co-pd-status-select {
  appearance: none;
  -webkit-appearance: none;
  padding: 7px 28px 7px 12px;
  border-radius: 999px;
  font-family: var(--co-font);
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  outline: none;
  border: 1.5px solid transparent;
  transition: box-shadow .15s;
  background-repeat: no-repeat;
  background-position: right 9px center;
  background-size: 10px 6px;
  flex-shrink: 0;
}
.co-pd-status-select:focus {
  box-shadow: 0 0 0 3px rgba(99,102,241,.15);
}
.co-pd-status-active {
  background-color: var(--co-emerald-bg);
  border-color: rgba(16,185,129,.3);
  color: var(--co-emerald);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%2310b981' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
}
.co-pd-status-on-hold {
  background-color: var(--co-amber-bg);
  border-color: rgba(245,158,11,.3);
  color: var(--co-amber);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23f59e0b' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
}
.co-pd-status-completed {
  background-color: var(--co-indigo-bg);
  border-color: rgba(99,102,241,.3);
  color: var(--co-indigo);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%236366f1' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
}

/* ─── Tab bar ──────────────────────────────────────────────────────── */
.co-pd-tabs {
  display: flex;
  border-bottom: 1px solid var(--co-slate-200);
  margin-bottom: 28px;
  overflow-x: auto;
  scrollbar-width: none;
  -webkit-overflow-scrolling: touch;
}
.co-pd-tabs::-webkit-scrollbar { display: none; }

.co-pd-tab {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 14px 20px;
  font-size: 13.5px;
  font-weight: 500;
  color: var(--co-slate-400);
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  cursor: pointer;
  white-space: nowrap;
  transition: color .15s, border-color .15s;
}
.co-pd-tab:hover:not(.active) { color: var(--co-slate-600); }
.co-pd-tab.active {
  color: var(--co-navy);
  font-weight: 600;
  border-bottom-color: var(--co-indigo);
}

.co-pd-tab-badge {
  font-size: 11px;
  font-weight: 700;
  padding: 1px 7px;
  border-radius: 999px;
  background: var(--co-slate-100);
  color: var(--co-slate-500);
  transition: background .15s, color .15s;
}
.co-pd-tab.active .co-pd-tab-badge {
  background: var(--co-indigo-bg);
  color: var(--co-indigo);
}

/* ─── Body layout ──────────────────────────────────────────────────── */
.co-pd-body {
  display: grid;
  grid-template-columns: 1fr 256px;
  gap: 28px;
  align-items: start;
}
@media (max-width: 900px) {
  .co-pd-body { grid-template-columns: 1fr; }
  .co-pd-sidebar { position: static !important; }
  .co-pd-project-name { font-size: 24px; }
}

/* ─── Tab panels ───────────────────────────────────────────────────── */
.co-pd-tab-panel { display: none; }
.co-pd-tab-panel.co-pd-tab-active {
  display: block;
  animation: co-pd-panel-in .18s ease both;
}
@keyframes co-pd-panel-in {
  from { opacity: 0; transform: translateY(5px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ─── Progress strip ───────────────────────────────────────────────── */
.co-pd-progress-strip {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 18px 20px;
  background: var(--co-white);
  border: 1px solid var(--co-slate-200);
  border-radius: var(--co-radius);
  margin-bottom: 20px;
}

.co-pd-pct-num {
  font-family: var(--co-font-display);
  font-size: 36px;
  font-weight: 800;
  color: var(--co-indigo);
  letter-spacing: -2px;
  line-height: 1;
  flex-shrink: 0;
}

.co-pd-pct-num.done { color: var(--co-emerald); }

.co-pd-progress-right { flex: 1; }

.co-pd-progress-label {
  font-size: 12.5px;
  color: var(--co-slate-500);
  margin-bottom: 8px;
  font-weight: 500;
}

.co-pd-bar-track {
  height: 5px;
  background: var(--co-slate-100);
  border-radius: 99px;
  overflow: hidden;
}

.co-pd-bar-fill {
  height: 100%;
  border-radius: 99px;
  background: linear-gradient(90deg, var(--co-indigo), var(--co-emerald));
  transition: width .6s cubic-bezier(.4,0,.2,1);
}

/* ─── Section label ────────────────────────────────────────────────── */
.co-pd-section-label {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: var(--co-slate-400);
  margin-bottom: 10px;
}

/* ─── Add milestone form ───────────────────────────────────────────── */
.co-pd-add-form {
  display: grid;
  grid-template-columns: 1fr 150px auto;
  gap: 8px;
  margin-bottom: 14px;
}
@media (max-width: 600px) {
  .co-pd-add-form { grid-template-columns: 1fr 1fr; }
  .co-pd-add-btn  { grid-column: 1 / -1; }
}

.co-pd-add-input {
  padding: 9px 12px;
  border-radius: var(--co-radius-sm);
  border: var(--co-input-border);
  font-family: var(--co-font);
  font-size: 13px;
  background: var(--co-slate-50);
  color: var(--co-navy);
  transition: border-color .12s, box-shadow .12s;
}
.co-pd-add-input:focus { outline: none; border-color: var(--co-indigo); box-shadow: var(--co-input-focus); }

.co-pd-add-date {
  padding: 9px 10px;
  border-radius: var(--co-radius-sm);
  border: var(--co-input-border);
  font-family: var(--co-font);
  font-size: 12px;
  background: var(--co-slate-50);
  color: var(--co-slate-600);
}
.co-pd-add-date:focus { outline: none; border-color: var(--co-indigo); box-shadow: var(--co-input-focus); }

.co-pd-add-btn {
  padding: 9px 16px;
  border-radius: var(--co-radius-sm);
  background: var(--co-indigo);
  color: #fff;
  border: none;
  font-family: var(--co-font);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity .12s;
  white-space: nowrap;
}
.co-pd-add-btn:hover:not(:disabled) { opacity: .88; }
.co-pd-add-btn:disabled { opacity: .5; cursor: not-allowed; }

/* ─── Milestone list ───────────────────────────────────────────────── */
.co-pd-ms-list { display: flex; flex-direction: column; }

.co-pd-ms-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 8px 10px 0;
  border-bottom: 1px solid var(--co-slate-100);
  border-radius: 4px;
  transition: background .12s;
  cursor: grab;
}
.co-pd-ms-row:last-child { border-bottom: none; }
.co-pd-ms-row:hover { background: var(--co-slate-50); }
.co-pd-ms-row:active { cursor: grabbing; }
.co-pd-ms-row.dragging { opacity: .35; }
.co-pd-ms-row.drag-over {
  background: var(--co-indigo-bg);
  border-bottom-color: var(--co-indigo);
  outline: 1px dashed var(--co-indigo);
  border-radius: 4px;
}

.co-pd-ms-drag {
  width: 12px;
  flex-shrink: 0;
  cursor: grab;
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 1px;
}
.co-pd-ms-drag span {
  display: block;
  width: 12px;
  height: 2px;
  background: var(--co-slate-300);
  border-radius: 1px;
}

.co-pd-ms-btn {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  flex-shrink: 0;
  border: 2px solid var(--co-slate-300);
  background: transparent;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all .15s;
  position: relative;
  padding: 0;
}
.co-pd-ms-btn[data-status="in-progress"] {
  border-color: var(--co-indigo);
  background: var(--co-indigo-bg);
}
.co-pd-ms-btn[data-status="in-progress"]::after {
  content: '';
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--co-indigo);
  opacity: .7;
}
.co-pd-ms-btn[data-status="completed"] {
  border-color: var(--co-emerald);
  background: var(--co-emerald);
}
.co-pd-ms-btn[data-status="completed"]::after {
  content: '';
  display: block;
  width: 9px;
  height: 5px;
  border-left: 2px solid #fff;
  border-bottom: 2px solid #fff;
  transform: rotate(-45deg) translateY(-1px);
}
.co-pd-ms-btn.locked {
  border-color: var(--co-slate-300);
  background: #F8FAFC;
  cursor: not-allowed;
  opacity: .7;
  color: var(--co-slate-400);
}
.co-pd-ms-submit-btn {
  font-family: var(--co-font);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
  padding: 3px 9px;
  border-radius: 5px;
  border: 1.5px solid var(--co-indigo);
  background: transparent;
  color: var(--co-indigo);
  cursor: pointer;
  white-space: nowrap;
  transition: background .13s, color .13s;
  flex-shrink: 0;
}
.co-pd-ms-submit-btn:hover { background: var(--co-indigo); color: #fff; }
.co-pd-ms-btn.just-completed {
  animation: co-ms-pop .35s cubic-bezier(.36,.07,.19,.97);
}
@keyframes co-ms-pop {
  0%   { transform: scale(1); }
  40%  { transform: scale(1.5); box-shadow: 0 0 0 6px rgba(16,185,129,.2); }
  70%  { transform: scale(.9); }
  100% { transform: scale(1); }
}

.co-pd-ms-label {
  flex: 1;
  font-size: 13.5px;
  color: var(--co-navy);
  line-height: 1.4;
}
.co-pd-ms-label.done {
  text-decoration: line-through;
  color: var(--co-slate-400);
}

.co-pd-ms-due {
  font-size: 11px;
  color: var(--co-slate-400);
  white-space: nowrap;
  flex-shrink: 0;
}
.co-pd-ms-due.overdue { color: var(--co-red); font-weight: 600; }

.co-pd-ms-del {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  flex-shrink: 0;
  border: none;
  background: transparent;
  color: var(--co-slate-300);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: color .12s, background .12s;
  padding: 0;
  opacity: 0;
  transition: opacity .12s, color .12s, background .12s;
}
.co-pd-ms-row:hover .co-pd-ms-del { opacity: 1; }
.co-pd-ms-del:hover { color: var(--co-red); background: var(--co-red-bg); }

.co-pd-ms-empty {
  padding: 32px 20px;
  text-align: center;
  font-size: 13px;
  color: var(--co-slate-400);
  border: 1.5px dashed var(--co-slate-200);
  border-radius: var(--co-radius-sm);
  margin-top: 4px;
}

/* ─── Notes section ────────────────────────────────────────────────── */
.co-pd-notes { margin-top: 28px; }

.co-pd-notes-textarea {
  width: 100%;
  min-height: 120px;
  resize: vertical;
  padding: 12px 14px;
  border-radius: var(--co-radius-sm);
  border: var(--co-input-border);
  font-family: var(--co-font);
  font-size: 13px;
  color: var(--co-navy);
  background: var(--co-slate-50);
  line-height: 1.6;
  transition: border-color .12s, box-shadow .12s;
  box-sizing: border-box;
}
.co-pd-notes-textarea:focus { outline: none; border-color: var(--co-indigo); box-shadow: var(--co-input-focus); }

.co-pd-notes-foot {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 8px;
}

.co-pd-notes-save {
  padding: 7px 16px;
  border-radius: var(--co-radius-sm);
  background: var(--co-indigo);
  color: #fff;
  border: none;
  font-family: var(--co-font);
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity .12s;
}
.co-pd-notes-save:hover:not(:disabled) { opacity: .88; }
.co-pd-notes-save:disabled { opacity: .5; cursor: not-allowed; }
.co-pd-notes-saved-tag {
  font-size: 12px;
  color: var(--co-emerald);
  font-weight: 500;
}

/* ─── Sidebar ──────────────────────────────────────────────────────── */
.co-pd-sidebar {
  position: sticky;
  top: 24px;
  display: flex;
  flex-direction: column;
  gap: 0;
}

.co-pd-info-card {
  background: var(--co-white);
  border: 1px solid var(--co-slate-200);
  border-radius: var(--co-radius);
  overflow: hidden;
}

.co-pd-info-head {
  padding: 14px 18px;
  border-bottom: 1px solid var(--co-slate-100);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: var(--co-slate-400);
}

.co-pd-info-body {
  padding: 16px 18px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.co-pd-info-item { display: flex; flex-direction: column; gap: 3px; }

.co-pd-info-lbl {
  font-size: 10.5px;
  font-weight: 600;
  letter-spacing: .8px;
  text-transform: uppercase;
  color: var(--co-slate-400);
}

.co-pd-info-val {
  font-size: 13.5px;
  color: var(--co-navy);
  word-break: break-word;
}
.co-pd-info-val a { color: var(--co-indigo); text-decoration: none; }
.co-pd-info-val a:hover { text-decoration: underline; }

.co-pd-payment-card {
  background: var(--co-white);
  border: 1px solid var(--co-slate-200);
  border-radius: var(--co-radius);
  overflow: hidden;
  margin-top: 12px;
}
.co-pd-payment-body {
  padding: 14px 18px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.co-pd-payment-row {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.co-pd-payment-row--pending { opacity: .7; }
.co-pd-payment-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 600;
  color: var(--co-slate-500);
  text-transform: uppercase;
  letter-spacing: .6px;
}
.co-pd-payment-badge {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .4px;
  padding: 1px 6px;
  border-radius: 20px;
  text-transform: uppercase;
}
.co-pd-payment-badge--paid    { background: #D1FAE5; color: #065F46; }
.co-pd-payment-badge--pending { background: #FEF3C7; color: #92400E; }
.co-pd-payment-amount {
  font-size: 15px;
  font-weight: 700;
  color: var(--co-navy);
  font-variant-numeric: tabular-nums;
}
.co-pd-payment-date {
  font-size: 11px;
  color: var(--co-slate-400);
}
.co-pd-payment-none {
  font-size: 12px;
  color: var(--co-slate-400);
}
.co-pd-payment-balance {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-top: 10px;
  border-top: 1px solid var(--co-slate-100);
  font-size: 12px;
  font-weight: 600;
  color: var(--co-slate-500);
}
.co-pd-balance--due   { color: #D97706; font-weight: 700; }
.co-pd-balance--clear { color: #059669; font-weight: 700; }

.co-pd-delete-project {
  width: 100%;
  margin-top: 12px;
  padding: 9px 14px;
  border: 1px solid #FCA5A5;
  border-radius: var(--co-radius);
  background: transparent;
  color: #DC2626;
  font-family: 'Archivo', sans-serif;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: .4px;
  cursor: pointer;
  transition: background .15s, color .15s;
}
.co-pd-delete-project:hover {
  background: #FEF2F2;
  border-color: #F87171;
}

/* ─── Loading / Error ──────────────────────────────────────────────── */
.co-pd-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 300px;
}
.co-pd-spinner {
  width: 28px;
  height: 28px;
  border: 2.5px solid var(--co-slate-200);
  border-top-color: var(--co-indigo);
  border-radius: 50%;
  animation: co-pd-spin .8s linear infinite;
}
@keyframes co-pd-spin { to { transform: rotate(360deg); } }

/* ─── Agency lock badge on tabs ─────────────────────────────────────── */
.co-pd-tab-agency-badge {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 9px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
  padding: 2px 6px; border-radius: 100px;
  background: var(--co-amber-bg); border: 1px solid rgba(245,158,11,.22);
  color: var(--co-amber); margin-left: 5px; line-height: 1; flex-shrink: 0;
}
.co-pd-tab-agency-badge svg { width: 9px; height: 9px; stroke: currentColor; stroke-width: 2.5; fill: none; flex-shrink: 0; }

/* ─── Upgrade panel ──────────────────────────────────────────────────── */
.co-pd-upgrade-panel {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 10px; min-height: 240px; padding: 48px 24px; text-align: center;
  animation: co-pd-enter .22s ease both;
}
.co-pd-upgrade-icon {
  width: 58px; height: 58px;
  background: var(--co-amber-bg); border: 1.5px solid rgba(245,158,11,.28);
  border-radius: 16px; display: flex; align-items: center; justify-content: center;
  margin-bottom: 6px;
}
.co-pd-upgrade-title { font-size: 15px; font-weight: 700; color: #1A1A2E; margin: 0; letter-spacing: -.01em; }
.co-pd-upgrade-desc  { font-size: 13px; color: var(--co-slate-400); margin: 0; max-width: 300px; line-height: 1.65; }
.co-pd-upgrade-btn {
  display: inline-flex; align-items: center; gap: 7px;
  height: 38px; padding: 0 20px; margin-top: 6px;
  background: var(--co-amber); color: #fff; border: none; border-radius: 9px;
  font-size: 13px; font-weight: 600; font-family: inherit; cursor: pointer; text-decoration: none;
  box-shadow: 0 2px 8px rgba(245,158,11,.3);
  transition: background .15s, box-shadow .15s, transform .1s;
}
.co-pd-upgrade-btn:hover { background: #D97706; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(245,158,11,.4); }
.co-pd-upgrade-btn:active { transform: none; }

/* ─── Milestone agency note ──────────────────────────────────────────── */
.co-pd-ms-agency-note {
  display: flex; align-items: center; gap: 8px;
  padding: 13px 15px;
  background: var(--co-amber-bg); border: 1px solid rgba(245,158,11,.2); border-radius: 10px;
  font-size: 13px; color: #6B7280; margin-top: 8px;
}
.co-pd-ms-agency-note svg { flex-shrink: 0; stroke: var(--co-amber); fill: none; }
.co-pd-ms-agency-inline-badge {
  display: inline-flex; align-items: center;
  font-size: 9px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
  padding: 2px 6px; border-radius: 100px;
  background: rgba(245,158,11,.15); color: var(--co-amber); margin-left: 2px;
}
`;

// ── Helpers ────────────────────────────────────────────────────────────────────

function formatDate( d ) {
	if ( ! d ) return '—';
	try { return new Date( d ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' } ); }
	catch { return d; }
}

function isOverdue( due_date, status ) {
	if ( status === 'completed' || ! due_date ) return false;
	return new Date( due_date ) < new Date();
}

const STATUS_CYCLE = { 'in-progress': 'completed', completed: 'pending' };

function statusClass( status ) {
	if ( status === 'active' )    return 'co-pd-status-active';
	if ( status === 'on-hold' )   return 'co-pd-status-on-hold';
	if ( status === 'completed' ) return 'co-pd-status-completed';
	return 'co-pd-status-active';
}

// ── Agency upgrade panel ───────────────────────────────────────────────────────

function AgencyUpgradePanel( { description } ) {
	const settingsUrl = ( window.clientoctopusData?.adminUrl || '/wp-admin/' ) + 'admin.php?page=clientoctopus-settings';
	return (
		<div className="co-pd-upgrade-panel">
			<div className="co-pd-upgrade-icon">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--co-amber)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
					<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
				</svg>
			</div>
			<p className="co-pd-upgrade-title">Agency Feature</p>
			<p className="co-pd-upgrade-desc">{ description }</p>
			<a href={ settingsUrl } className="co-pd-upgrade-btn">
				<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
					<polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/>
				</svg>
				Upgrade to Agency
			</a>
		</div>
	);
}

// ── Component ──────────────────────────────────────────────────────────────────

export default function ProjectDetail( { projectId, onBack } ) {
	injectStyles( 'co-pd-styles', CSS );

	const [ project,      setProject      ] = useState( null );
	const [ loading,      setLoading      ] = useState( true );
	const [ activeTab,    setActiveTab    ] = useState( 'overview' );
	const [ newTitle,     setNewTitle     ] = useState( '' );
	const [ newDue,       setNewDue       ] = useState( '' );
	const [ adding,       setAdding       ] = useState( false );
	const [ notes,        setNotes        ] = useState( '' );
	const [ notesSaved,   setNotesSaved   ] = useState( false );
	const [ savingNotes,  setSavingNotes  ] = useState( false );
	const [ justCompleted,   setJustCompleted   ] = useState( null );
	const [ tabsVisited,     setTabsVisited     ] = useState( { overview: true, files: false, approvals: false, messages: false } );
	const [ messagesUnread,  setMessagesUnread  ] = useState( 0 );
	const [ paymentData,     setPaymentData     ] = useState( null );

	const dragItem = useRef( null );
	const dragOver = useRef( null );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const [ projectData, msgData, pmtData ] = await Promise.all( [
				coFetch( `projects/${ projectId }` ),
				coFetch( `messages/unread-count` ).catch( () => ( { count: 0 } ) ),
				coFetch( `projects/${ projectId }/payments` ).catch( () => null ),
			] );
			setProject( projectData.project );
			setNotes( projectData.project.description || '' );
			setMessagesUnread( msgData.count || 0 );
			setPaymentData( pmtData );
		} catch {}
		finally { setLoading( false ); }
	}, [ projectId ] );

	useEffect( () => { load(); }, [ load ] );

	function switchTab( tab ) {
		setActiveTab( tab );
		if ( ! tabsVisited[ tab ] ) setTabsVisited( prev => ( { ...prev, [ tab ]: true } ) );
		// Clear unread badge when Messages tab is opened.
		if ( tab === 'messages' ) setMessagesUnread( 0 );
	}

	async function handleStatusChange( e ) {
		const status = e.target.value;
		try {
			const data = await coFetch( `projects/${ projectId }/update`, {
				method: 'POST',
				body:   JSON.stringify( { status } ),
			} );
			setProject( data.project );
		} catch ( err ) {
			const msg = err?.message || ( await err?.json?.().catch( () => null ) )?.message;
			if ( msg ) window.alert( msg );
		}
	}

	async function handleAddMilestone( e ) {
		e.preventDefault();
		if ( ! newTitle.trim() ) return;
		setAdding( true );
		try {
			const data = await coFetch( `projects/${ projectId }/milestones`, {
				method: 'POST',
				body:   JSON.stringify( { title: newTitle.trim(), due_date: newDue } ),
			} );
			setProject( data.project );
			setNewTitle( '' );
			setNewDue( '' );
		} catch {}
		setAdding( false );
	}

	async function handleSubmitMilestone( milestone ) {
		try {
			const data = await coFetch( `projects/${ projectId }/milestones/${ milestone.id }/submit`, {
				method: 'POST',
			} );
			setProject( data.project );
		} catch ( err ) {
			const msg = err?.message || ( await err?.json?.().catch( () => null ) )?.message;
			if ( msg ) window.alert( msg );
		}
	}

	async function handleCycleStatus( milestone ) {
		// Only in-progress and completed milestones can be cycled by the admin.
		if ( milestone.status === 'pending' || milestone.status === 'submitted' ) return;

		const nextStatus = STATUS_CYCLE[ milestone.status ] || 'pending';
		if ( nextStatus === 'completed' ) {
			setJustCompleted( milestone.id );
			setTimeout( () => setJustCompleted( null ), 500 );
		}
		try {
			const data = await coFetch( `projects/${ projectId }/milestones/${ milestone.id }/update`, {
				method: 'POST',
				body:   JSON.stringify( { status: nextStatus } ),
			} );
			setProject( data.project );
		} catch {}
	}

	async function handleDeleteMilestone( mid ) {
		if ( ! window.confirm( 'Delete this milestone?' ) ) return;
		try {
			const data = await coFetch( `projects/${ projectId }/milestones/${ mid }`, { method: 'DELETE' } );
			setProject( data.project );
		} catch {}
	}

	async function handleSaveNotes() {
		setSavingNotes( true );
		try {
			const data = await coFetch( `projects/${ projectId }/update`, {
				method: 'POST',
				body:   JSON.stringify( { description: notes } ),
			} );
			setProject( data.project );
			setNotesSaved( true );
			setTimeout( () => setNotesSaved( false ), 2500 );
		} catch {}
		setSavingNotes( false );
	}

	async function handleDeleteProject() {
		if ( ! window.confirm( 'Permanently delete this project? This will also remove all milestones, messages, approvals, and payment records. This cannot be undone.' ) ) return;
		try {
			await coFetch( `projects/${ projectId }`, { method: 'DELETE' } );
			onBack();
		} catch ( err ) {
			const msg = err?.message || ( await err?.json?.().catch( () => null ) )?.message;
			if ( msg ) window.alert( msg );
		}
	}

	function onDragStart( idx ) { dragItem.current = idx; }
	function onDragEnter( idx ) { dragOver.current = idx; }

	async function onDragEnd() {
		const milestones = [ ...( project.milestones || [] ) ];
		const dragged    = milestones.splice( dragItem.current, 1 )[ 0 ];
		milestones.splice( dragOver.current, 0, dragged );
		dragItem.current = null;
		dragOver.current = null;
		setProject( prev => ( { ...prev, milestones } ) );
		try {
			const data = await coFetch( `projects/${ projectId }/milestones/reorder`, {
				method: 'POST',
				body:   JSON.stringify( { ordered_ids: milestones.map( m => m.id ) } ),
			} );
			setProject( data.project );
		} catch {}
	}

	// ── Loading ──────────────────────────────────────────────────────────

	if ( loading ) {
		return (
			<div className="co-pd">
				<button className="co-pd-back" onClick={ onBack }>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
					Back to Projects
				</button>
				<div className="co-pd-loading"><div className="co-pd-spinner" /></div>
			</div>
		);
	}

	if ( ! project ) {
		return (
			<div className="co-pd">
				<button className="co-pd-back" onClick={ onBack }>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
					Back to Projects
				</button>
				<p style={ { color: 'var(--co-red)', fontSize: 14 } }>Project not found.</p>
			</div>
		);
	}

	const milestones = project.milestones || [];
	const total      = project.milestone_total     || 0;
	const completed  = project.milestone_completed || 0;
	const pct        = project.progress_pct        || 0;

	// Project is locked once completed and fully paid (or no payment required).
	const isLocked = project.status === 'completed' && paymentData !== null && (
		paymentData.remaining === null || paymentData.remaining <= 0
	);

	const isProPlan = window.clientoctopusData?.userPlan === 'pro';

	// ── Render ───────────────────────────────────────────────────────────

	return (
		<div className="co-pd">

			{ /* Back */ }
			<button className="co-pd-back" onClick={ onBack }>
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
				Back to Projects
			</button>

			{ /* ── Hero ── */ }
			<div className="co-pd-hero">
				<div className="co-pd-hero-left">
					<h1 className="co-pd-project-name">{ project.name }</h1>
					<div className="co-pd-hero-meta">
						{ project.client_name && <span>{ project.client_name }</span> }
						{ project.client_name && project.proposal_title && <span className="co-pd-meta-dot" /> }
						{ project.proposal_title && <span>{ project.proposal_title }</span> }
						{ project.created_at && (
							<>
								<span className="co-pd-meta-dot" />
								<span>Created { formatDate( project.created_at ) }</span>
							</>
						) }
					</div>
				</div>
				{ isLocked ? (
					<span className="co-pd-status-select co-pd-status-completed" style={{ cursor: 'default', pointerEvents: 'none' }}>
						Completed
					</span>
				) : (
					<select
						className={ `co-pd-status-select ${ statusClass( project.status ) }` }
						value={ project.status }
						onChange={ handleStatusChange }
					>
						<option value="active">Active</option>
						<option value="on-hold">On Hold</option>
						<option
							value="completed"
							disabled={ total > 0 && completed < total }
							title={ total > 0 && completed < total ? `${ total - completed } milestone${ total - completed !== 1 ? 's' : '' } still incomplete` : undefined }
						>
							{ total > 0 && completed < total ? `Completed (${ total - completed } pending)` : 'Completed' }
						</option>
					</select>
				) }
			</div>

			{ /* ── Tab bar ── */ }
			<div className="co-pd-tabs" role="tablist">
				<button
					role="tab"
					aria-selected={ activeTab === 'overview' }
					className={ `co-pd-tab${ activeTab === 'overview' ? ' active' : '' }` }
					onClick={ () => switchTab( 'overview' ) }
				>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
					Overview
					{ total > 0 && <span className="co-pd-tab-badge">{ completed }/{ total }</span> }
				</button>
				<button
					role="tab"
					aria-selected={ activeTab === 'files' }
					className={ `co-pd-tab${ activeTab === 'files' ? ' active' : '' }` }
					onClick={ () => switchTab( 'files' ) }
				>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
					Files
					{ isProPlan && (
						<span className="co-pd-tab-agency-badge">
							<svg viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
							Agency
						</span>
					) }
				</button>
				<button
					role="tab"
					aria-selected={ activeTab === 'approvals' }
					className={ `co-pd-tab${ activeTab === 'approvals' ? ' active' : '' }` }
					onClick={ () => switchTab( 'approvals' ) }
				>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
					Approvals
					{ isProPlan && (
						<span className="co-pd-tab-agency-badge">
							<svg viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
							Agency
						</span>
					) }
				</button>
				<button
					role="tab"
					aria-selected={ activeTab === 'messages' }
					className={ `co-pd-tab${ activeTab === 'messages' ? ' active' : '' }` }
					onClick={ () => switchTab( 'messages' ) }
				>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
					Messages
					{ messagesUnread > 0 && <span className="co-pd-tab-badge">{ messagesUnread }</span> }
					{ isProPlan && (
						<span className="co-pd-tab-agency-badge">
							<svg viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
							Agency
						</span>
					) }
				</button>
			</div>

			{ /* ── Body: tab panels + sidebar ── */ }
			<div className="co-pd-body">

				{ /* ── Main: all tab panels ── */ }
				<div>

					{ /* Overview panel */ }
					<div
						role="tabpanel"
						className={ `co-pd-tab-panel${ activeTab === 'overview' ? ' co-pd-tab-active' : '' }` }
					>
						{ /* Progress strip */ }
						<div className="co-pd-progress-strip">
							<div className={ `co-pd-pct-num${ pct === 100 ? ' done' : '' }` }>{ pct }%</div>
							<div className="co-pd-progress-right">
								<div className="co-pd-progress-label">
									{ completed } of { total } milestone{ total !== 1 ? 's' : '' } complete
								</div>
								<div className="co-pd-bar-track">
									<div className="co-pd-bar-fill" style={ { width: `${ pct }%` } } />
								</div>
							</div>
						</div>

						{ /* Add form */ }
						<p className="co-pd-section-label">Milestones</p>
						{ ! isLocked && ! isProPlan && (
							<form className="co-pd-add-form" onSubmit={ handleAddMilestone }>
								<input
									className="co-pd-add-input"
									placeholder="Add a milestone…"
									value={ newTitle }
									onChange={ e => setNewTitle( e.target.value ) }
								/>
								<input
									type="date"
									className="co-pd-add-date"
									value={ newDue }
									onChange={ e => setNewDue( e.target.value ) }
								/>
								<button type="submit" className="co-pd-add-btn" disabled={ adding || ! newTitle.trim() }>
									{ adding ? '…' : '+ Add' }
								</button>
							</form>
						) }

						{ /* Milestone list */ }
						<div className="co-pd-ms-list">
							{ isProPlan ? (
								<div className="co-pd-ms-agency-note">
									<svg width="16" height="16" viewBox="0 0 24 24" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
										<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
									</svg>
									Milestone tracking is an Agency feature.
									<span className="co-pd-ms-agency-inline-badge">Agency</span>
								</div>
							) : milestones.length === 0 ? (
								<div className="co-pd-ms-empty">No milestones yet — add one above.</div>
							) : (
								milestones.map( ( m, idx ) => (
									<div
										key={ m.id }
										className="co-pd-ms-row"
										draggable
										onDragStart={ () => onDragStart( idx ) }
										onDragEnter={ () => onDragEnter( idx ) }
										onDragEnd={ onDragEnd }
										onDragOver={ e => e.preventDefault() }
									>
										<div className="co-pd-ms-drag"><span/><span/><span/></div>

										{ isLocked ? (
											<button
												className="co-pd-ms-btn locked"
												data-status={ m.status }
												disabled
												title="Project is complete and locked"
											/>
										) : m.status === 'pending' ? (
											<button
												className="co-pd-ms-submit-btn"
												onClick={ () => handleSubmitMilestone( m ) }
												title="Submit this milestone for client approval"
											>
												Submit
											</button>
										) : (
											<button
												className={ `co-pd-ms-btn${ justCompleted === m.id ? ' just-completed' : '' }${ m.status === 'submitted' ? ' locked' : '' }` }
												data-status={ m.status }
												onClick={ () => handleCycleStatus( m ) }
												title={ m.status === 'submitted' ? 'Awaiting client approval' : `Status: ${ m.status }. Click to advance.` }
											>
												{ m.status === 'submitted' && (
													<svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" style={ { pointerEvents: 'none' } }>
														<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
														<path d="M7 11V7a5 5 0 0110 0v4"/>
													</svg>
												) }
											</button>
										) }

										<span className={ `co-pd-ms-label${ m.status === 'completed' ? ' done' : '' }` }>
											{ m.title }
										</span>

										{ m.due_date && (
											<span className={ `co-pd-ms-due${ isOverdue( m.due_date, m.status ) ? ' overdue' : '' }` }>
												{ isOverdue( m.due_date, m.status ) ? '⚠ ' : '' }{ formatDate( m.due_date ) }
											</span>
										) }

										{ ! isLocked && (
											<button
												className="co-pd-ms-del"
												onClick={ () => handleDeleteMilestone( m.id ) }
												title="Delete milestone"
											>
												<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
													<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
												</svg>
											</button>
										) }
									</div>
								) )
							) }
						</div>

						{ /* Notes */ }
						<div className="co-pd-notes">
							<p className="co-pd-section-label">Internal Notes</p>
							<textarea
								className="co-pd-notes-textarea"
								placeholder="Add private notes about this project…"
								value={ notes }
								onChange={ e => setNotes( e.target.value ) }
								readOnly={ isLocked }
								style={ isLocked ? { opacity: 0.6, cursor: 'default' } : undefined }
							/>
							{ ! isLocked && (
								<div className="co-pd-notes-foot">
									<button className="co-pd-notes-save" onClick={ handleSaveNotes } disabled={ savingNotes }>
										{ savingNotes ? 'Saving…' : 'Save Notes' }
									</button>
									{ notesSaved && <span className="co-pd-notes-saved-tag">✓ Saved</span> }
								</div>
							) }
						</div>
					</div>

					{ /* Files panel — mount on first visit, hide/show after */ }
					<div
						role="tabpanel"
						className={ `co-pd-tab-panel${ activeTab === 'files' ? ' co-pd-tab-active' : '' }` }
					>
						{ isProPlan
							? <AgencyUpgradePanel description="Share deliverables and project assets directly with your client." />
							: tabsVisited.files && <ProjectFiles projectId={ projectId } />
						}
					</div>

					{ /* Approvals panel */ }
					<div
						role="tabpanel"
						className={ `co-pd-tab-panel${ activeTab === 'approvals' ? ' co-pd-tab-active' : '' }` }
					>
						{ isProPlan
							? <AgencyUpgradePanel description="Collect client sign-offs and feedback on your work." />
							: tabsVisited.approvals && <ProjectApprovals projectId={ projectId } isLocked={ isLocked } />
						}
					</div>

					{ /* Messages panel */ }
					<div
						role="tabpanel"
						className={ `co-pd-tab-panel${ activeTab === 'messages' ? ' co-pd-tab-active' : '' }` }
					>
						{ isProPlan
							? <AgencyUpgradePanel description="Keep all client communication in one place." />
							: tabsVisited.messages && (
								<ProjectMessages
									projectId={ projectId }
									onUnreadChange={ count => setMessagesUnread( count ) }
								/>
							)
						}
					</div>

				</div>

				{ /* ── Sidebar: project info ── */ }
				<div className="co-pd-sidebar">
					<div className="co-pd-info-card">
						<div className="co-pd-info-head">Project Info</div>
						<div className="co-pd-info-body">
							{ project.client_name && (
								<div className="co-pd-info-item">
									<span className="co-pd-info-lbl">Client</span>
									<span className="co-pd-info-val">{ project.client_name }</span>
								</div>
							) }
							{ project.proposal_title && (
								<div className="co-pd-info-item">
									<span className="co-pd-info-lbl">Linked Proposal</span>
									<span className="co-pd-info-val">
										{ project.proposal_token ? (
											<a href={ `/proposals/${ project.proposal_token }` } target="_blank" rel="noreferrer">
												{ project.proposal_title }
											</a>
										) : project.proposal_title }
									</span>
								</div>
							) }
							<div className="co-pd-info-item">
								<span className="co-pd-info-lbl">Created</span>
								<span className="co-pd-info-val">{ formatDate( project.created_at ) }</span>
							</div>
							{ project.completed_at && (
								<div className="co-pd-info-item">
									<span className="co-pd-info-lbl">Completed</span>
									<span className="co-pd-info-val">{ formatDate( project.completed_at ) }</span>
								</div>
							) }
							<div className="co-pd-info-item">
								<span className="co-pd-info-lbl">Status</span>
								<span className="co-pd-info-val" style={ { textTransform: 'capitalize' } }>{ project.status.replace( '-', ' ' ) }</span>
							</div>
						</div>
					</div>

				{ paymentData && paymentData.proposal_total !== null && (
					<div className="co-pd-payment-card">
						<div className="co-pd-info-head">Payment</div>
						<div className="co-pd-payment-body">
							{ paymentData.payments.filter( p => p.status === 'completed' ).map( p => (
								<div key={ p.id } className="co-pd-payment-row">
									<div className="co-pd-payment-label">
										{ p.deposit_pct < 100 ? `Deposit (${ p.deposit_pct }%)` : 'Full payment' }
										<span className="co-pd-payment-badge co-pd-payment-badge--paid">Paid</span>
									</div>
									<div className="co-pd-payment-amount">
										{ new Intl.NumberFormat( undefined, { style: 'currency', currency: p.currency || 'GBP' } ).format( p.amount ) }
									</div>
									{ p.completed_at && (
										<div className="co-pd-payment-date">{ formatDate( p.completed_at ) }</div>
									) }
								</div>
							) ) }
							{ paymentData.payments.filter( p => p.status === 'pending' || p.status === 'processing' ).map( p => (
								<div key={ p.id } className="co-pd-payment-row co-pd-payment-row--pending">
									<div className="co-pd-payment-label">
										{ p.deposit_pct < 100 ? `Deposit (${ p.deposit_pct }%)` : 'Full payment' }
										<span className="co-pd-payment-badge co-pd-payment-badge--pending">Pending</span>
									</div>
									<div className="co-pd-payment-amount">
										{ new Intl.NumberFormat( undefined, { style: 'currency', currency: p.currency || 'GBP' } ).format( p.amount ) }
									</div>
								</div>
							) ) }
							{ paymentData.payments.length === 0 && (
								<div className="co-pd-payment-none">No payments recorded</div>
							) }
							<div className="co-pd-payment-balance">
								<span>Balance remaining</span>
								<span className={ ( paymentData.remaining ?? paymentData.proposal_total ) > 0 ? 'co-pd-balance--due' : 'co-pd-balance--clear' }>
									{ new Intl.NumberFormat( undefined, { style: 'currency', currency: paymentData.payments[0]?.currency || 'GBP' } ).format( paymentData.remaining ?? paymentData.proposal_total ) }
								</span>
							</div>
						</div>
					</div>
				) }

					<button className="co-pd-delete-project" onClick={ handleDeleteProject }>
						Delete Project
					</button>
				</div>

			</div>
		</div>
	);
}
