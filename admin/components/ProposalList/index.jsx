/**
 * ProposalList
 *
 * Full proposals list view with status tabs, search, CSS grid rows,
 * quick actions, empty state, and pagination.
 *
 * Props:
 *   proposals       {array}   — proposal objects from API
 *   loading         {bool}
 *   error           {string|null}
 *   onNewProposal   {fn}
 *   onEditProposal  {fn}      — onEditProposal(id)
 *   onRefresh       {fn}
 */
import { useState, useMemo } from '@wordpress/element';
import { coFetch } from '../../App';

const TABS = [
	{ id: 'all',       label: 'All'       },
	{ id: 'draft',     label: 'Draft'     },
	{ id: 'sent',      label: 'Sent'      },
	{ id: 'viewed',    label: 'Viewed'    },
	{ id: 'accepted',  label: 'Accepted'  },
	{ id: 'completed', label: 'Completed' },
	{ id: 'declined',  label: 'Declined'  },
];

const STATUS_CONFIG = {
	draft:              { bg: 'var(--co-slate-100)',   color: 'var(--co-slate-600)',   label: 'Draft'             },
	sent:               { bg: 'var(--co-indigo-bg)',   color: 'var(--co-indigo)',      label: 'Sent'              },
	viewed:             { bg: 'var(--co-amber-bg)',    color: 'var(--co-amber)',       label: 'Viewed'            },
	accepted:           { bg: 'var(--co-emerald-bg)',  color: 'var(--co-emerald)',     label: 'Accepted'          },
	completed:          { bg: 'var(--co-emerald-bg)',  color: 'var(--co-emerald)',     label: 'Completed'         },
	declined:           { bg: 'var(--co-red-bg)',      color: 'var(--co-red)',         label: 'Declined'          },
	expired:            { bg: 'var(--co-slate-100)',   color: 'var(--co-slate-400)',   label: 'Expired'           },
	revision_requested: { bg: '#FEF3C7',               color: '#92400E',               label: 'Changes Requested' },
};

const CURRENCY_SYMBOLS = { GBP: '£', USD: '$', EUR: '€', CAD: '$', AUD: '$' };

const PER_PAGE = 20;

const CSS = `
/* Layout */
.co-list-wrap { display: flex; flex-direction: column; gap: 0; }

/* Header */
.co-list-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 28px;
  gap: 16px;
}
.co-list-title {
  font-family: var(--co-font);
  font-size: 28px;
  font-weight: 800;
  color: var(--co-navy);
  letter-spacing: -.5px;
  margin:0;
  line-height: 1;
}
.co-list-subtitle {
  font-size: 14px;
  color: var(--co-slate-400);
  margin: 6px 0 0;
  line-height: 1.5;
}
.co-list-new-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 10px 20px;
  background: var(--co-indigo);
  color: white;
  border-radius: var(--co-radius-sm);
  font-size: 13.5px;
  font-weight: 600;
  font-family: var(--co-font);
  border: none;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(99,102,241,.35);
  transition: background .15s, box-shadow .15s, transform .12s;
  flex-shrink: 0;
}
.co-list-new-btn:hover { background: #4F46E5; box-shadow: 0 4px 16px rgba(99,102,241,.4); transform: translateY(-1px); }
.co-list-new-btn svg { width: 15px; height: 15px; stroke: currentColor; stroke-width: 2.5; }

.co-list-refresh-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 16px;
  background: var(--co-white);
  color: var(--co-slate-600);
  border-radius: var(--co-radius-sm);
  font-size: 13px;
  font-weight: 500;
  font-family: var(--co-font);
  border: 1.5px solid var(--co-slate-200);
  cursor: pointer;
  transition: border-color .15s, color .15s, background .15s;
  flex-shrink: 0;
}
.co-list-refresh-btn:hover:not(:disabled) { border-color: var(--co-indigo); color: var(--co-indigo); background: var(--co-indigo-bg); }
.co-list-refresh-btn:disabled { opacity: .5; cursor: not-allowed; }
.co-list-refresh-btn svg { width: 14px; height: 14px; stroke: currentColor; stroke-width: 2; }
.co-list-refresh-btn.spinning svg { animation: co-spin 0.7s linear infinite; }

/* Tabs + search bar */
.co-list-controls {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.co-list-tabs {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--co-slate-200);
}
.co-list-tab {
  display: flex; align-items: center; gap: 7px;
  padding: 8px 14px 10px;
  background: none; border: none;
  font-size: 13px; font-weight: 500;
  font-family: var(--co-font);
  color: var(--co-slate-500);
  cursor: pointer;
  position: relative;
  bottom: -2px;
  border-bottom: 2px solid transparent;
  transition: color .15s, border-color .15s;
  white-space: nowrap;
}
.co-list-tab:hover { color: var(--co-slate-800); }
.co-list-tab.active {
  color: var(--co-indigo);
  border-bottom-color: var(--co-indigo);
  font-weight: 600;
}
.co-list-tab-count {
  font-size: 11px;
  font-weight: 700;
  background: var(--co-slate-100);
  color: var(--co-slate-500);
  border-radius: 999px;
  padding: 1px 7px;
  min-width: 20px;
  text-align: center;
}
.co-list-tab.active .co-list-tab-count {
  background: var(--co-indigo-bg);
  color: var(--co-indigo);
}

/* Search */
.co-list-search-wrap {
  position: relative;
  flex-shrink: 0;
}
.co-list-search-icon {
  position: absolute;
  right: 12px; top: 50%;
  transform: translateY(-50%);
  width: 15px; height: 15px;
  stroke: var(--co-slate-400);
  stroke-width: 2;
  pointer-events: none;
  display:none;
}
.co-list-search {
  padding: 9px 14px 9px 36px;
  border: var(--co-input-border);
  border-radius: var(--co-radius-sm);
  font-size: 13.5px;
  font-family: var(--co-font);
  color: var(--co-slate-800);
  background: var(--co-white);
  outline: none;
  width: 220px;
  transition: border-color .15s, box-shadow .15s;
}
.co-list-search::placeholder { color: var(--co-slate-300); }
.co-list-search:focus { border-color: var(--co-indigo); box-shadow: var(--co-input-focus); }

/* Table header row */
.co-list-col-headers {
  display: grid;
  grid-template-columns: 2fr 2fr 110px 124px 120px 115px;
  gap: 12px;
  padding: 8px 16px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--co-slate-400);
  border-bottom: 1px solid var(--co-slate-100);
  margin-bottom: 4px;
}

/* Row card */
.co-list-row {
  display: grid;
  grid-template-columns: 2fr 2fr 110px 124px 120px 115px;
  gap: 12px;
  align-items: center;
  padding: 14px 16px;
  background: var(--co-white);
  border: 1px solid var(--co-slate-200);
  border-radius: var(--co-radius-sm);
  margin-bottom: 6px;
  position: relative;
  transition: border-color .15s, box-shadow .15s, transform .12s;
  cursor: default;
}
.co-list-row:hover {
  border-color: var(--co-slate-300);
  box-shadow: var(--co-shadow);
  transform: translateY(-1px);
}
.co-list-row:hover .co-list-actions { opacity: 1; }

/* Left accent bar by status */
.co-list-row::before {
  content: '';
  position: absolute;
  left: 0; top: 8px; bottom: 8px;
  width: 3px;
  border-radius: 0 3px 3px 0;
  background: var(--co-slate-200);
  transition: background .15s;
}
.co-list-row[data-status="accepted"]::before           { background: var(--co-emerald); }
.co-list-row[data-status="sent"]::before               { background: var(--co-indigo); }
.co-list-row[data-status="viewed"]::before             { background: var(--co-amber); }
.co-list-row[data-status="declined"]::before           { background: var(--co-red); }
.co-list-row[data-status="revision_requested"]::before { background: #F59E0B; }

/* Client cell */
.co-list-client-name { font-size: 13.5px; font-weight: 600; color: var(--co-slate-800); }
.co-list-client-company { font-size: 12px; color: var(--co-slate-400); margin-top: 2px; }

/* Proposal title */
.co-list-proposal-title {
  font-size: 13px;
  color: var(--co-slate-600);
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

/* Amount */
.co-list-amount {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--co-slate-800);
  font-variant-numeric: tabular-nums;
}

/* Status badge */
.co-list-badge {
  display: inline-flex;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
  white-space: nowrap;
}

/* Decline reason modal */
.co-decline-overlay {
  position: fixed;
  inset: 0;
  z-index: 9000;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  backdrop-filter: blur(3px);
  -webkit-backdrop-filter: blur(3px);
  animation: co-fade-overlay 0.18s ease both;
}
@keyframes co-fade-overlay {
  from { opacity: 0; }
  to   { opacity: 1; }
}
.co-decline-modal {
  background: var(--co-white);
  border-radius: var(--co-radius);
  border-left: 3px solid var(--co-red);
  padding: 28px 32px 32px;
  width: 100%;
  max-width: 460px;
  box-shadow: var(--co-shadow-lg);
  animation: co-modal-in 0.22s cubic-bezier(0.22, 1, 0.36, 1) both;
}
@keyframes co-modal-in {
  from { opacity: 0; transform: scale(0.96) translateY(6px); }
  to   { opacity: 1; transform: scale(1)    translateY(0);   }
}
.co-decline-modal-header {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 16px;
}
.co-decline-modal-icon {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--co-red-bg);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 1px;
}
.co-decline-modal-icon svg {
  width: 16px;
  height: 16px;
  stroke: var(--co-red);
  stroke-width: 2;
}
.co-decline-modal-titles { flex: 1; }
.co-decline-modal-title {
  font-family: var(--co-font-display);
  font-size: 17px;
  font-weight: 600;
  color: var(--co-navy);
  margin-bottom: 3px;
  line-height: 1.3;
}
.co-decline-modal-subtitle {
  font-size: 12.5px;
  color: var(--co-slate-400);
}
.co-decline-modal-body {
  background: var(--co-slate-50);
  border: 1px solid var(--co-slate-200);
  border-radius: var(--co-radius-sm);
  padding: 14px 16px;
  font-size: 14px;
  line-height: 1.65;
  color: var(--co-slate-700);
  font-style: italic;
  white-space: pre-wrap;
  word-break: break-word;
  margin-bottom: 20px;
}
.co-decline-modal-body::before { content: '\u201C'; color: var(--co-red); font-style: normal; font-size: 18px; line-height: 0; vertical-align: -3px; margin-right: 2px; }
.co-decline-modal-body::after  { content: '\u201D'; color: var(--co-red); font-style: normal; font-size: 18px; line-height: 0; vertical-align: -3px; margin-left: 2px;  }
.co-decline-modal-close {
  display: flex;
  justify-content: flex-end;
}
.co-decline-modal-close-btn {
  padding: 8px 18px;
  border-radius: var(--co-radius-sm);
  border: 1.5px solid var(--co-slate-200);
  background: var(--co-white);
  font-family: var(--co-font);
  font-size: 13px;
  font-weight: 500;
  color: var(--co-slate-600);
  cursor: pointer;
  transition: background .12s, border-color .12s, color .12s;
}
.co-decline-modal-close-btn:hover { background: var(--co-slate-50); border-color: var(--co-slate-300); color: var(--co-slate-800); }

/* Date */
.co-list-date {
  font-size: 12px;
  color: var(--co-slate-400);
}

/* Actions */
.co-list-actions {
  display: flex;
  gap: 4px;
  opacity: 0;
  transition: opacity .15s;
  justify-content: flex-end;
}
.co-list-action-btn {
  width: 30px; height: 30px;
  display: flex; align-items: center; justify-content: center;
  background: var(--co-slate-100);
  border: none;
  border-radius: 6px;
  cursor: pointer;
  color: var(--co-slate-500);
  transition: background .12s, color .12s;
}
.co-list-action-btn:hover { background: var(--co-indigo-bg); color: var(--co-indigo); }
.co-list-action-btn.danger:hover { background: var(--co-red-bg); color: var(--co-red); }
.co-list-action-btn svg { width: 13px; height: 13px; stroke: currentColor; stroke-width: 2; }

/* Skeleton */
.co-list-skeleton {
  height: 64px;
  background: linear-gradient(90deg, var(--co-slate-100) 25%, var(--co-slate-50) 50%, var(--co-slate-100) 75%);
  background-size: 200% 100%;
  border-radius: var(--co-radius-sm);
  animation: co-shimmer 1.4s ease infinite;
  margin-bottom: 6px;
}
@keyframes co-shimmer {
  0%   { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* Empty state */
.co-list-empty {
  display: flex; flex-direction: column; align-items: center;
  padding: 60px 20px;
  text-align: center;
  gap: 12px;
  animation: co-fade-up .3s ease both;
}
.co-list-empty-icon {
  width: 72px; height: 72px;
  background: var(--co-slate-100);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 4px;
}
.co-list-empty-icon svg { width: 34px; height: 34px; stroke: var(--co-slate-300); stroke-width: 1.5; }
.co-list-empty h3 { font-size: 18px; font-weight: 700; color: var(--co-slate-700); }
.co-list-empty p { font-size: 14px; color: var(--co-slate-400); max-width: 320px; line-height: 1.6; }

/* Error banner */
.co-list-error {
  display: flex; align-items: center; gap: 10px;
  background: var(--co-red-bg); border: 1px solid rgba(239,68,68,.2);
  color: var(--co-red); border-radius: 8px;
  padding: 12px 16px; font-size: 13px; margin-bottom: 16px;
}
.co-list-error svg { width: 16px; height: 16px; stroke: currentColor; flex-shrink: 0; }

/* Pagination */
.co-list-pager {
  display: flex; align-items: center; justify-content: center;
  gap: 6px; margin-top: 20px;
}
.co-list-page-btn {
  min-width: 34px; height: 34px;
  display: flex; align-items: center; justify-content: center;
  border-radius: 8px;
  border: 1.5px solid var(--co-slate-200);
  background: var(--co-white);
  font-size: 13px; font-weight: 600; font-family: var(--co-font);
  color: var(--co-slate-600);
  cursor: pointer;
  transition: border-color .12s, background .12s, color .12s;
}
.co-list-page-btn:hover:not(:disabled) { border-color: var(--co-indigo); color: var(--co-indigo); background: var(--co-indigo-bg); }
.co-list-page-btn.active { background: var(--co-indigo); color: white; border-color: var(--co-indigo); }
.co-list-page-btn:disabled { opacity: .4; cursor: not-allowed; }
.co-list-page-btn svg { width: 14px; height: 14px; stroke: currentColor; stroke-width: 2; }

/* ─── Proposal limit banner ──────────────────────────────────────────── */
.co-list-limit-banner {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 11px 16px;
  background: var(--co-amber-bg); border: 1px solid rgba(245,158,11,.25);
  border-radius: var(--co-radius-sm); margin-bottom: 16px;
  animation: co-fadein .2s ease both;
}
@keyframes co-fadein { from { opacity:0; transform:translateY(-4px); } to { opacity:1; transform:none; } }
.co-list-limit-banner__left { display: flex; align-items: center; gap: 10px; min-width: 0; }
.co-list-limit-banner__icon {
  flex-shrink: 0; width: 32px; height: 32px;
  background: rgba(245,158,11,.12); border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
}
.co-list-limit-banner__icon svg { stroke: var(--co-amber); }
.co-list-limit-banner__text { min-width: 0; }
.co-list-limit-banner__title { font-size: 13px; font-weight: 700; color: var(--co-navy); white-space: nowrap; }
.co-list-limit-banner__sub { font-size: 12px; color: var(--co-slate-400); margin-top: 1px; }
.co-list-limit-banner__btn {
  flex-shrink: 0; display: inline-flex; align-items: center; gap: 5px;
  height: 32px; padding: 0 14px;
  font-size: 12px; font-weight: 600; font-family: var(--co-font);
  color: #92400E; background: rgba(245,158,11,.1);
  border: 1.5px solid rgba(245,158,11,.4); border-radius: var(--co-radius-sm);
  cursor: pointer; text-decoration: none; white-space: nowrap;
  transition: background .14s, border-color .14s;
}
.co-list-limit-banner__btn:hover { background: rgba(245,158,11,.18); border-color: rgba(245,158,11,.65); }

/* ─── Send Proposal Modal ────────────────────────────────────────────── */
.co-send-overlay {
  position: fixed;
  inset: 0;
  z-index: 9000;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  backdrop-filter: blur(3px);
  -webkit-backdrop-filter: blur(3px);
  animation: co-fade-overlay 0.18s ease both;
}
.co-send-modal {
  background: var(--co-white);
  border-radius: var(--co-radius);
  border-left: 3px solid var(--co-indigo);
  padding: 28px 32px 32px;
  width: 100%;
  max-width: 480px;
  box-shadow: var(--co-shadow-lg);
  animation: co-modal-in 0.22s cubic-bezier(0.22, 1, 0.36, 1) both;
}
.co-send-modal-header {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 24px;
}
.co-send-modal-icon {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--co-indigo-bg);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 1px;
}
.co-send-modal-icon svg {
  width: 16px;
  height: 16px;
  stroke: var(--co-indigo);
  stroke-width: 2;
}
.co-send-modal-title {
  font-family: var(--co-font-display);
  font-size: 17px;
  font-weight: 600;
  color: var(--co-navy);
  margin-bottom: 3px;
  line-height: 1.3;
}
.co-send-modal-subtitle {
  font-size: 12.5px;
  color: var(--co-slate-400);
}
.co-send-modal-fields {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-bottom: 24px;
}
.co-send-modal-label {
  display: block;
  font-size: 11.5px;
  font-weight: 600;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: var(--co-slate-500);
  margin-bottom: 6px;
}
.co-send-modal-input {
  width: 100%;
  padding: 10px 13px;
  border: var(--co-input-border);
  border-radius: var(--co-radius-sm);
  font-size: 13.5px;
  font-family: var(--co-font);
  color: var(--co-slate-800);
  background: var(--co-white);
  outline: none;
  transition: border-color .15s, box-shadow .15s;
  box-sizing: border-box;
}
.co-send-modal-input::placeholder { color: var(--co-slate-300); }
.co-send-modal-input:focus {
  border-color: var(--co-indigo);
  box-shadow: var(--co-input-focus);
}
.co-send-modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.co-send-modal-cancel {
  padding: 9px 18px;
  border-radius: var(--co-radius-sm);
  border: 1.5px solid var(--co-slate-200);
  background: var(--co-white);
  font-family: var(--co-font);
  font-size: 13px;
  font-weight: 500;
  color: var(--co-slate-600);
  cursor: pointer;
  transition: background .12s, border-color .12s, color .12s;
}
.co-send-modal-cancel:hover { background: var(--co-slate-50); border-color: var(--co-slate-300); color: var(--co-slate-800); }
.co-send-modal-submit {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 9px 20px;
  background: var(--co-indigo);
  color: white;
  border: none;
  border-radius: var(--co-radius-sm);
  font-family: var(--co-font);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(99,102,241,.3);
  transition: background .12s, box-shadow .12s, opacity .12s;
}
.co-send-modal-submit:hover:not(:disabled) { background: #4F46E5; box-shadow: 0 4px 12px rgba(99,102,241,.4); }
.co-send-modal-submit:disabled { opacity: .6; cursor: not-allowed; }
.co-send-modal-submit svg { width: 14px; height: 14px; stroke: currentColor; stroke-width: 2.5; }
.co-send-modal-error {
  padding: 10px 14px;
  background: var(--co-red-bg);
  color: var(--co-red);
  border: 1px solid rgba(239,68,68,.2);
  border-radius: var(--co-radius-sm);
  font-size: 13px;
  line-height: 1.5;
  margin-bottom: 4px;
}
.co-no-sender-warning {
  padding: 12px 16px;
  background: var(--co-amber-bg);
  color: #92400E;
  border: 1px solid rgba(245,158,11,.25);
  border-radius: var(--co-radius-sm);
  font-size: 13.5px;
  margin-bottom: 20px;
  line-height: 1.5;
}
.co-no-sender-warning a { color: var(--co-amber); text-decoration: underline; }
.co-send-success-banner {
  display: flex; align-items: center; gap: 8px;
  padding: 12px 16px;
  background: var(--co-emerald-bg);
  color: var(--co-emerald);
  border: 1px solid rgba(16,185,129,.2);
  border-radius: var(--co-radius-sm);
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 12px;
  animation: co-fade-up .2s ease both;
}
`;

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
}

function formatDate( dateStr ) {
	if ( ! dateStr ) return '—';
	try {
		return new Date( dateStr ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' } );
	} catch {
		return dateStr;
	}
}

function formatAmount( amount, currency ) {
	if ( ! amount ) return '—';
	const sym = CURRENCY_SYMBOLS[ currency ] || '£';
	return `${ sym }${ parseFloat( amount ).toLocaleString( 'en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) }`;
}

function StatusBadge( { status } ) {
	const cfg = STATUS_CONFIG[ status ] || STATUS_CONFIG.draft;
	return (
		<span className="co-list-badge" style={ { background: cfg.bg, color: cfg.color } }>
			{ cfg.label }
		</span>
	);
}

export default function ProposalList( {
	proposals = [],
	loading = false,
	error = null,
	onNewProposal,
	onEditProposal,
	onEditContent,
	onRefresh,
	onProposalDeleted,
	onProposalSent,
} ) {
	injectStyles( 'co-list-styles', CSS );

	const proposalLimit         = window.coData?.planLimits?.proposals ?? null;
	const proposalUsage         = window.coData?.proposalUsage ?? 0;
	const proposalNextReset     = window.coData?.proposalNextReset ?? '';
	const isAtLimit             = proposalLimit !== null && proposalUsage >= proposalLimit;
	const settingsUrl           = ( window.coData?.adminUrl || '/wp-admin/' ) + 'admin.php?page=clientoctopus-settings';
	const senderEmailConfigured = window.coData?.senderEmailConfigured ?? true;

	const [ activeTab, setActiveTab ]         = useState( 'all' );
	const [ search, setSearch ]               = useState( '' );
	const [ page, setPage ]                   = useState( 1 );
	const [ deletingId, setDeletingId ]       = useState( null );
	const [ sendingId, setSendingId ]         = useState( null );
	const [ refreshing, setRefreshing ]       = useState( false );
	const [ declineReason, setDeclineReason ] = useState( null );
	const [ sendModal, setSendModal ]         = useState( { open: false, proposal: null, email: '', subject: '', error: '' } );
	const [ successMsg, setSuccessMsg ]       = useState( '' );

	async function handleRefresh() {
		setRefreshing( true );
		try {
			await onRefresh();
		} finally {
			setRefreshing( false );
		}
	}

	// Count per tab
	const counts = useMemo( () => {
		const c = { all: proposals.length };
		TABS.slice( 1 ).forEach( t => {
			c[ t.id ] = proposals.filter( p => p.status === t.id ).length;
		} );
		return c;
	}, [ proposals ] );

	// Filter
	const filtered = useMemo( () => {
		let list = proposals;

		if ( activeTab !== 'all' ) {
			list = list.filter( p => p.status === activeTab );
		}

		if ( search.trim() ) {
			const q = search.toLowerCase();
			list = list.filter( p =>
				( p.client_name || '' ).toLowerCase().includes( q ) ||
				( p.title || '' ).toLowerCase().includes( q ) ||
				( p.client_email || '' ).toLowerCase().includes( q )
			);
		}

		return list;
	}, [ proposals, activeTab, search ] );

	// Pagination
	const pageCount = Math.ceil( filtered.length / PER_PAGE );
	const paginated = filtered.slice( ( page - 1 ) * PER_PAGE, page * PER_PAGE );

	function handleTabChange( id ) {
		setActiveTab( id );
		setPage( 1 );
	}

	function handleSearch( e ) {
		setSearch( e.target.value );
		setPage( 1 );
	}

	async function handleDelete( id ) {
		if ( ! window.confirm( 'Delete this proposal? This cannot be undone.' ) ) return;
		setDeletingId( id );
		try {
			await coFetch( `proposals/${ id }`, { method: 'DELETE' } );
			onProposalDeleted( id );
		} catch ( e ) {
			alert( e.message || 'Delete failed.' );
		} finally {
			setDeletingId( null );
		}
	}

	function handleSend( proposal ) {
		const defaultSubject = proposal.title
			? `You have received a proposal: ${ proposal.title }`
			: 'You have a new proposal to review';
		setSendModal( {
			open:     true,
			proposal,
			email:    proposal.client_email || '',
			subject:  defaultSubject,
			error:    '',
		} );
	}

	async function handleModalSend() {
		const { proposal, email, subject } = sendModal;
		if ( ! email.trim() ) return;
		setSendModal( m => ( { ...m, error: '' } ) );
		setSendingId( proposal.id );
		try {
			await coFetch( `proposals/${ proposal.id }/send`, {
				method: 'POST',
				body:   JSON.stringify( { client_email: email.trim(), email_subject: subject.trim() } ),
			} );
			setSendModal( m => ( { ...m, open: false } ) );
			onProposalSent( proposal.id );
			setActiveTab( 'sent' );
			setPage( 1 );
			setSuccessMsg( '✓ Proposal sent — it now appears in the Sent tab.' );
			setTimeout( () => setSuccessMsg( '' ), 4000 );
			onRefresh();
		} catch ( e ) {
			setSendModal( m => ( { ...m, error: e.message || 'Send failed. Please try again.' } ) );
		} finally {
			setSendingId( null );
		}
	}

	async function handleDuplicate( id ) {
		try {
			await coFetch( `proposals/${ id }/duplicate`, { method: 'POST' } );
			setActiveTab( 'draft' );
			setPage( 1 );
			setSuccessMsg( '✓ Proposal cloned — it now appears in the Draft tab.' );
			setTimeout( () => setSuccessMsg( '' ), 4000 );
			onRefresh();
		} catch ( e ) {
			alert( e.message || 'Duplicate failed.' );
		}
	}

	async function handleMarkCompleted( id ) {
		if ( ! window.confirm( 'Mark this proposal as completed? This cannot be undone.' ) ) return;
		try {
			await coFetch( `proposals/${ id }/update`, {
				method: 'POST',
				body:   JSON.stringify( { status: 'completed' } ),
			} );
			onRefresh();
		} catch ( e ) {
			alert( e.message || 'Update failed.' );
		}
	}

	return (
		<div className="co-list-wrap">
			{ ! senderEmailConfigured && (
				<div className="co-no-sender-warning">
					⚠ No sender email configured — you won&apos;t be able to send proposals until you add one in{ ' ' }
					<a href={ settingsUrl }>Settings</a>.
				</div>
			) }

			{/* Header */ }
			<div className="co-list-header">
				<div>
					<h1 className="co-list-title">Proposals</h1>
					<p className="co-list-subtitle">Manage and track your client proposals</p>
				</div>
				<div style={ { display: 'flex', gap: 8, alignItems: 'center' } }>
					<button
						type="button"
						className={ `co-list-refresh-btn${ refreshing ? ' spinning' : '' }` }
						onClick={ handleRefresh }
						disabled={ refreshing || loading }
						title="Refresh proposals"
					>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<polyline points="23 4 23 10 17 10"/>
							<path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>
						</svg>
						Refresh
					</button>
					<button
						type="button"
						className="co-list-new-btn"
						onClick={ isAtLimit ? undefined : onNewProposal }
						disabled={ isAtLimit }
						title={ isAtLimit ? `Limit reached — resets on ${ proposalNextReset }` : undefined }
						style={ isAtLimit ? { opacity: 0.45, cursor: 'not-allowed' } : undefined }
					>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
						</svg>
						New Proposal
					</button>
				</div>
			</div>

			{ error && (
				<div className="co-list-error">
					<svg viewBox="0 0 24 24" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
						<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
					</svg>
					{ error }
				</div>
			) }

			{ isAtLimit && (
				<div className="co-list-limit-banner">
					<div className="co-list-limit-banner__left">
						<div className="co-list-limit-banner__icon">
							<svg width="15" height="15" viewBox="0 0 24 24" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
								<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
							</svg>
						</div>
						<div className="co-list-limit-banner__text">
							<div className="co-list-limit-banner__title">{ proposalUsage }/{ proposalLimit } proposals used this month</div>
							<div className="co-list-limit-banner__sub">Resets on { proposalNextReset } — upgrade for unlimited proposals</div>
						</div>
					</div>
					<a href={ settingsUrl } className="co-list-limit-banner__btn">
						<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
							<polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/>
						</svg>
						Upgrade to Pro
					</a>
				</div>
			) }

			{/* Tabs + search */ }
			<div className="co-list-controls">
				<div className="co-list-tabs">
					{ TABS.map( tab => (
						<button
							key={ tab.id }
							type="button"
							className={ `co-list-tab${ activeTab === tab.id ? ' active' : '' }` }
							onClick={ () => handleTabChange( tab.id ) }
						>
							{ tab.label }
							<span className="co-list-tab-count">{ counts[ tab.id ] || 0 }</span>
						</button>
					) ) }
				</div>

				<div className="co-list-search-wrap">
					<svg className="co-list-search-icon" viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
					</svg>
					<input
						type="search"
						className="co-list-search"
						placeholder="Search proposals…"
						value={ search }
						onChange={ handleSearch }
					/>
				</div>
			</div>

			{ successMsg && (
				<div className="co-send-success-banner">
					{ successMsg }
				</div>
			) }

			{/* Column headers */ }
			{ ( loading || paginated.length > 0 ) && (
				<div className="co-list-col-headers">
					<div>Client</div>
					<div>Proposal</div>
					<div>Amount</div>
					<div>Status</div>
					<div>Created</div>
					<div />
				</div>
			) }

			{/* Loading skeletons */ }
			{ loading && [ 1, 2, 3, 4 ].map( i => (
				<div key={ i } className="co-list-skeleton" style={ { animationDelay: `${ i * 0.1 }s` } } />
			) ) }

			{/* Rows */ }
			{ ! loading && paginated.map( ( proposal, idx ) => (
				<div
					key={ proposal.id }
					className="co-list-row"
					data-status={ proposal.status }
					style={ { animation: `co-fade-up .25s ease ${ idx * 0.04 }s both` } }
				>
					{/* Client */ }
					<div>
						<div className="co-list-client-name">{ proposal.client_name || 'No client' }</div>
						{ proposal.client_company && (
							<div className="co-list-client-company">{ proposal.client_company }</div>
						) }
					</div>

					{/* Title */ }
					<div className="co-list-proposal-title" title={ proposal.title }>
						{ proposal.title || 'Untitled' }
					</div>

					{/* Amount */ }
					<div className="co-list-amount">
						{ formatAmount( proposal.total_amount, proposal.currency ) }
					</div>

					{/* Status */ }
					<div>
						<StatusBadge status={ proposal.status } />
					</div>

					{/* Date */ }
					<div className="co-list-date">{ formatDate( proposal.created_at ) }</div>

					{/* Actions */ }
					<div className="co-list-actions">
						{ proposal.status === 'declined' && proposal.decline_reason && (
							<button
								type="button"
								className="co-list-action-btn"
								title="View decline reason"
								onClick={ () => setDeclineReason( { title: proposal.title, reason: proposal.decline_reason } ) }
							>
								<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
									<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
								</svg>
							</button>
						) }
						{ proposal.status === 'revision_requested' && proposal.revision_note && (
							<button
								type="button"
								className="co-list-action-btn"
								title="View client note"
								onClick={ () => setDeclineReason( { title: proposal.title, reason: proposal.revision_note, isRevision: true } ) }
							>
								<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
									<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
								</svg>
							</button>
						) }
						{ [ 'draft', 'declined', 'expired', 'revision_requested' ].includes( proposal.status ) && (
							<>
								{ proposal.status === 'draft' && (
									<button
										type="button"
										className="co-list-action-btn"
										title="Send to client"
										disabled={ sendingId === proposal.id }
										onClick={ () => handleSend( proposal ) }
									>
										{ sendingId === proposal.id ? (
											<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round" style={ { animation: 'co-spin 1s linear infinite' } }>
												<circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="2.5" strokeDasharray="40 20"/>
											</svg>
										) : (
											<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
												<line x1="22" y1="2" x2="11" y2="13"/>
												<polygon points="22 2 15 22 11 13 2 9 22 2"/>
											</svg>
										) }
									</button>
								) }
								<button
									type="button"
									className="co-list-action-btn"
									title="Edit proposal"
									onClick={ () => onEditProposal( proposal.id ) }
								>
									<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
										<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
										<path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
									</svg>
								</button>
								<button
									type="button"
									className="co-list-action-btn"
									title="Edit content"
									onClick={ () => onEditContent( proposal.id ) }
								>
									<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
										<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
										<polyline points="14 2 14 8 20 8"/>
										<line x1="16" y1="13" x2="8" y2="13"/>
										<line x1="16" y1="17" x2="8" y2="17"/>
										<polyline points="10 9 9 9 8 9"/>
									</svg>
								</button>
							</>
						) }
						{ proposal.status === 'accepted' && (
							<button
								type="button"
								className="co-list-action-btn"
								title="Mark as completed"
								onClick={ () => handleMarkCompleted( proposal.id ) }
							>
								<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
									<polyline points="20 6 9 17 4 12"/>
								</svg>
							</button>
						) }
						<button
							type="button"
							className="co-list-action-btn"
							title="Duplicate"
							onClick={ () => handleDuplicate( proposal.id ) }
						>
							<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
								<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
							</svg>
						</button>
						<button
							type="button"
							className="co-list-action-btn danger"
							title="Delete"
							disabled={ deletingId === proposal.id }
							onClick={ () => handleDelete( proposal.id ) }
						>
							<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
								<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
							</svg>
						</button>
					</div>
				</div>
			) ) }

			{/* Empty state */ }
			{ ! loading && paginated.length === 0 && (
				<div className="co-list-empty">
					<div className="co-list-empty-icon">
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
							<polyline points="14 2 14 8 20 8"/>
							<line x1="12" y1="18" x2="12" y2="12"/>
							<line x1="9" y1="15" x2="15" y2="15"/>
						</svg>
					</div>
					<h3>{ search || activeTab !== 'all' ? 'No proposals found' : 'No proposals yet' }</h3>
					<p>
						{ search
							? `No proposals match "${ search }". Try a different search.`
							: activeTab !== 'all'
							? `You have no ${ activeTab } proposals yet.`
							: window.coData?.onboardingComplete === false
							? <>New to Client Octopus? <a href="admin.php?page=clientoctopus-setup" style={ { color: 'var(--co-indigo)' } }>Complete your setup</a> then create your first proposal.</>
							: 'Create your first proposal to get started.' }
					</p>
					{ ! search && activeTab === 'all' && ! isAtLimit && (
						<button type="button" className="co-list-new-btn" onClick={ onNewProposal } style={ { marginTop: 8 } }>
							<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round" style={ { width: 15, height: 15, stroke: 'currentColor', strokeWidth: 2.5 } }>
								<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
							</svg>
							Create Proposal
						</button>
					) }
				</div>
			) }

			{/* Pagination */ }
			{ pageCount > 1 && (
				<div className="co-list-pager">
					<button
						type="button"
						className="co-list-page-btn"
						disabled={ page === 1 }
						onClick={ () => setPage( p => p - 1 ) }
						aria-label="Previous page"
					>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
						</svg>
					</button>

					{ Array.from( { length: pageCount }, ( _, i ) => i + 1 )
						.filter( p => p === 1 || p === pageCount || Math.abs( p - page ) <= 1 )
						.reduce( ( acc, p, idx, arr ) => {
							if ( idx > 0 && p - arr[ idx - 1 ] > 1 ) acc.push( '…' );
							acc.push( p );
							return acc;
						}, [] )
						.map( ( p, i ) =>
							p === '…' ? (
								<span key={ `ellipsis-${ i }` } style={ { padding: '0 4px', color: 'var(--co-slate-400)' } }>…</span>
							) : (
								<button
									key={ p }
									type="button"
									className={ `co-list-page-btn${ page === p ? ' active' : '' }` }
									onClick={ () => setPage( p ) }
								>
									{ p }
								</button>
							)
						)
					}

					<button
						type="button"
						className="co-list-page-btn"
						disabled={ page === pageCount }
						onClick={ () => setPage( p => p + 1 ) }
						aria-label="Next page"
					>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
						</svg>
					</button>
				</div>
			) }

			{ sendModal.open && (
				<div
					className="co-send-overlay"
					onClick={ e => { if ( e.target === e.currentTarget ) setSendModal( m => ( { ...m, open: false } ) ); } }
					role="dialog"
					aria-modal="true"
					aria-labelledby="co-send-modal-title"
				>
					<div className="co-send-modal">
						<div className="co-send-modal-header">
							<div className="co-send-modal-icon">
								<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
									<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
								</svg>
							</div>
							<div>
								<div className="co-send-modal-title" id="co-send-modal-title">Send Proposal</div>
								<div className="co-send-modal-subtitle">{ sendModal.proposal?.title || 'Untitled Proposal' }</div>
							</div>
						</div>

						<div className="co-send-modal-fields">
							<div>
								<label className="co-send-modal-label" htmlFor="co-send-email">Client Email</label>
								<input
									id="co-send-email"
									type="email"
									className="co-send-modal-input"
									placeholder="client@example.com"
									value={ sendModal.email }
									onChange={ e => setSendModal( m => ( { ...m, email: e.target.value } ) ) }
									autoFocus
								/>
							</div>
							<div>
								<label className="co-send-modal-label" htmlFor="co-send-subject">Email Subject</label>
								<input
									id="co-send-subject"
									type="text"
									className="co-send-modal-input"
									placeholder="Email subject line"
									value={ sendModal.subject }
									onChange={ e => setSendModal( m => ( { ...m, subject: e.target.value } ) ) }
									onKeyDown={ e => { if ( e.key === 'Enter' && sendModal.email.trim() ) handleModalSend(); } }
								/>
							</div>
						</div>

						{ sendModal.error && (
							<div className="co-send-modal-error">
								{ sendModal.error }
							</div>
						) }

						<div className="co-send-modal-actions">
							<button
								type="button"
								className="co-send-modal-cancel"
								onClick={ () => setSendModal( m => ( { ...m, open: false } ) ) }
							>
								Cancel
							</button>
							<button
								type="button"
								className="co-send-modal-submit"
								disabled={ ! sendModal.email.trim() }
								onClick={ handleModalSend }
							>
								<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
									<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
								</svg>
								Send Proposal
							</button>
						</div>
					</div>
				</div>
			) }

			{ declineReason && (
				<div
					className="co-decline-overlay"
					onClick={ e => { if ( e.target === e.currentTarget ) setDeclineReason( null ); } }
					role="dialog"
					aria-modal="true"
					aria-labelledby="co-decline-modal-title"
				>
					<div className="co-decline-modal">
						<div className="co-decline-modal-header">
							<div className="co-decline-modal-icon">
								{ declineReason.isRevision ? (
									<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
										<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
										<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
									</svg>
								) : (
									<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
										<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
									</svg>
								) }
							</div>
							<div className="co-decline-modal-titles">
								<div className="co-decline-modal-title" id="co-decline-modal-title">
									{ declineReason.isRevision ? "Client's Change Request" : "Client's Decline Reason" }
								</div>
								<div className="co-decline-modal-subtitle">{ declineReason.title }</div>
							</div>
						</div>
						<div className="co-decline-modal-body">{ declineReason.reason }</div>
						<div className="co-decline-modal-close">
							<button
								type="button"
								className="co-decline-modal-close-btn"
								onClick={ () => setDeclineReason( null ) }
							>
								Close
							</button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}
