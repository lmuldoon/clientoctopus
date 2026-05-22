/**
 * ContentEditor
 *
 * Full-screen proposal content editor. Lets the admin edit heading,
 * text, and list sections before sending to a client.
 *
 * Props:
 *   proposal  {object}  Full proposal object (with decoded content)
 *   onSave    {fn}      Called with updatedProposal on successful save
 *   onCancel  {fn}      Called when user dismisses without saving
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { coFetch } from '../../App';
import {
	parseSections,
	moveSectionUp,
	moveSectionDown,
	deleteSection,
	addSection,
	buildContentPayload,
} from './utils';

const canUseAi = window.clientoctopusData?.featureAccess?.use_ai ?? false;

// ─── Style injection ──────────────────────────────────────────────────────────

function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
}

const CSS = `
/* ── Wrapper ────────────────────────────────────────────────── */
.co-ce {
	max-width: 820px;
	margin: 0 auto;
	padding: 0 0 120px;
	animation: co-slide-in-right .25s cubic-bezier(.22,.68,0,1.2) both;
}

/* ── Header ─────────────────────────────────────────────────── */
.co-ce-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 20px;
	padding: 32px 0 20px;
	border-bottom: 1px solid var(--co-slate-200);
	margin-bottom: 8px;
}
.co-ce-header-left {}
.co-ce-title {
	font-family: var(--co-font-display);
	font-size: 26px;
	color: var(--co-navy);
	letter-spacing: -.3px;
	line-height: 1.2;
	margin: 0;
}
.co-ce-subtitle {
	font-size: 13px;
	color: var(--co-slate-400);
	margin-top: 5px;
	font-style: italic;
}
.co-ce-cancel-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 8px 16px;
	background: none;
	border: 1.5px solid var(--co-slate-200);
	border-radius: var(--co-radius-sm);
	font-size: 13px;
	font-weight: 500;
	font-family: var(--co-font);
	color: var(--co-slate-500);
	cursor: pointer;
	transition: border-color .15s, color .15s, background .15s;
	flex-shrink: 0;
}
.co-ce-cancel-btn:hover {
	border-color: var(--co-slate-300);
	color: var(--co-slate-700);
	background: var(--co-slate-50);
}

/* ── Subheader hint ─────────────────────────────────────────── */
.co-ce-subheader {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 14px;
	background: var(--co-indigo-bg);
	border-radius: var(--co-radius-sm);
	margin: 12px 0 24px;
	font-size: 12.5px;
	color: var(--co-indigo);
	line-height: 1.5;
}
.co-ce-subheader svg {
	width: 15px;
	height: 15px;
	stroke: currentColor;
	stroke-width: 2;
	flex-shrink: 0;
}

/* ── Section cards body ──────────────────────────────────────── */
.co-ce-body {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

/* ── Section card ────────────────────────────────────────────── */
.co-ce-card {
	background: var(--co-white);
	border: 1px solid var(--co-slate-200);
	border-radius: var(--co-radius-sm);
	border-left: 3px solid var(--co-slate-200);
	overflow: hidden;
	transition: border-color .15s, box-shadow .15s, transform .12s;
}
.co-ce-card:hover {
	border-color: var(--co-slate-300);
	border-left-color: inherit;
	box-shadow: 0 2px 12px rgba(15,23,42,.06);
}
.co-ce-card[data-type="heading"] { border-left-color: var(--co-indigo); }
.co-ce-card[data-type="text"]    { border-left-color: var(--co-slate-300); }
.co-ce-card[data-type="list"]    { border-left-color: var(--co-emerald); }
.co-ce-card[data-type="pricing"] { border-left-color: var(--co-amber); }

.co-ce-card-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 14px 8px;
	gap: 10px;
}
.co-ce-card-head-left {
	display: flex;
	align-items: center;
	gap: 8px;
}
.co-ce-card-head-right {
	display: flex;
	align-items: center;
	gap: 4px;
}

/* Type badge */
.co-ce-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 10.5px;
	font-weight: 700;
	letter-spacing: .06em;
	text-transform: uppercase;
}
.co-ce-badge[data-type="heading"] {
	background: var(--co-indigo-bg);
	color: var(--co-indigo);
}
.co-ce-badge[data-type="text"] {
	background: var(--co-slate-100);
	color: var(--co-slate-500);
}
.co-ce-badge[data-type="list"] {
	background: var(--co-emerald-bg);
	color: var(--co-emerald);
}
.co-ce-badge[data-type="pricing"] {
	background: var(--co-amber-bg);
	color: var(--co-amber);
}
.co-ce-badge svg {
	width: 11px;
	height: 11px;
	stroke: currentColor;
	stroke-width: 2.5;
}

/* Arrow / control buttons */
.co-ce-ctrl-btn {
	width: 28px;
	height: 28px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: none;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	color: var(--co-slate-400);
	transition: background .12s, color .12s;
	padding: 0;
	font-family: var(--co-font);
}
.co-ce-ctrl-btn:hover:not(:disabled) {
	background: var(--co-slate-100);
	color: var(--co-slate-700);
}
.co-ce-ctrl-btn:disabled {
	opacity: .3;
	cursor: not-allowed;
}
.co-ce-ctrl-btn svg {
	width: 13px;
	height: 13px;
	stroke: currentColor;
	stroke-width: 2.5;
}
.co-ce-ctrl-btn.delete:hover:not(:disabled) {
	background: var(--co-red-bg);
	color: var(--co-red);
}

/* Divider between card head and body */
.co-ce-card-divider {
	height: 1px;
	background: var(--co-slate-100);
	margin: 0;
}

/* Card body padding */
.co-ce-card-body {
	padding: 14px 16px 16px;
}

/* ── HeadingEditor ──────────────────────────────────────────── */
.co-ce-heading-input {
	width: 100%;
	border: none;
	outline: none;
	background: var(--co-slate-50);
	border-left: 3px solid var(--co-indigo);
	padding: 10px 14px;
	border-radius: 0 6px 6px 0;
	font-family: var(--co-font-display);
	font-size: 20px;
	color: var(--co-navy);
	letter-spacing: -.2px;
	line-height: 1.3;
	transition: background .15s, box-shadow .15s;
}
.co-ce-heading-input::placeholder { color: var(--co-slate-300); font-style: italic; }
.co-ce-heading-input:focus {
	background: var(--co-white);
	box-shadow: var(--co-input-focus);
}

/* ── TextEditor ─────────────────────────────────────────────── */
.co-ce-text-area {
	width: 100%;
	border: 1.5px solid var(--co-slate-200);
	border-radius: 6px;
	outline: none;
	background: var(--co-white);
	padding: 12px 14px;
	font-family: var(--co-font);
	font-size: 14px;
	line-height: 1.75;
	color: var(--co-slate-700);
	resize: none;
	overflow: hidden;
	transition: border-color .15s, box-shadow .15s;
	min-height: 80px;
	box-sizing: border-box;
}
.co-ce-text-area::placeholder { color: var(--co-slate-300); font-style: italic; }
.co-ce-text-area:focus {
	border-color: var(--co-indigo);
	box-shadow: var(--co-input-focus);
}

/* ── ListEditor ─────────────────────────────────────────────── */
.co-ce-list-items {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-bottom: 8px;
}
.co-ce-list-item-row {
	display: flex;
	align-items: center;
	gap: 8px;
}
.co-ce-list-bullet {
	width: 6px;
	height: 6px;
	border-radius: 50%;
	background: var(--co-emerald);
	flex-shrink: 0;
}
.co-ce-list-item-input {
	flex: 1;
	border: 1.5px solid var(--co-slate-200);
	border-radius: 6px;
	padding: 8px 12px;
	font-family: var(--co-font);
	font-size: 13.5px;
	color: var(--co-slate-700);
	background: var(--co-white);
	outline: none;
	transition: border-color .15s, box-shadow .15s;
}
.co-ce-list-item-input::placeholder { color: var(--co-slate-300); }
.co-ce-list-item-input:focus {
	border-color: var(--co-emerald);
	box-shadow: 0 0 0 3px rgba(16,185,129,.1);
}
.co-ce-list-remove-btn {
	width: 26px;
	height: 26px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: none;
	border: none;
	border-radius: 5px;
	cursor: pointer;
	color: var(--co-slate-400);
	font-size: 16px;
	line-height: 1;
	padding: 0;
	flex-shrink: 0;
	transition: background .12s, color .12s;
	font-family: var(--co-font);
}
.co-ce-list-remove-btn:hover:not(:disabled) {
	background: var(--co-red-bg);
	color: var(--co-red);
}
.co-ce-list-remove-btn:disabled { opacity: .3; cursor: not-allowed; }
.co-ce-list-add-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	background: none;
	border: 1.5px dashed var(--co-slate-300);
	border-radius: 6px;
	font-size: 12.5px;
	font-weight: 500;
	font-family: var(--co-font);
	color: var(--co-slate-500);
	cursor: pointer;
	transition: border-color .15s, color .15s, background .15s;
}
.co-ce-list-add-btn:hover {
	border-color: var(--co-emerald);
	color: var(--co-emerald);
	background: var(--co-emerald-bg);
}
.co-ce-list-add-btn svg {
	width: 12px;
	height: 12px;
	stroke: currentColor;
	stroke-width: 2.5;
}

/* ── Pricing card (locked) ───────────────────────────────────── */
.co-ce-pricing-locked {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background: var(--co-amber-bg);
	border-radius: 6px;
}
.co-ce-pricing-locked-icon {
	width: 32px;
	height: 32px;
	background: rgba(245,158,11,.15);
	border-radius: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.co-ce-pricing-locked-icon svg {
	width: 16px;
	height: 16px;
	stroke: var(--co-amber);
	stroke-width: 2;
}
.co-ce-pricing-locked-text {
	font-size: 13px;
	color: #92400E;
	line-height: 1.5;
}
.co-ce-pricing-locked-text strong {
	display: block;
	font-weight: 600;
	margin-bottom: 1px;
}

/* ── Add section bar ────────────────────────────────────────── */
.co-ce-add-bar-wrap {
	margin-top: 16px;
}
.co-ce-add-bar {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 14px;
	border: 1.5px dashed var(--co-slate-200);
	border-radius: var(--co-radius-sm);
	background: var(--co-slate-50);
	flex-wrap: wrap;
}
.co-ce-add-bar-label {
	font-size: 11.5px;
	font-weight: 600;
	letter-spacing: .06em;
	text-transform: uppercase;
	color: var(--co-slate-400);
	margin-right: 4px;
}
.co-ce-add-section-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 7px 14px;
	border-radius: 6px;
	font-size: 12.5px;
	font-weight: 500;
	font-family: var(--co-font);
	cursor: pointer;
	transition: all .15s;
	border: 1.5px solid transparent;
}
.co-ce-add-section-btn svg {
	width: 12px;
	height: 12px;
	stroke: currentColor;
	stroke-width: 2.5;
}
.co-ce-add-section-btn.heading {
	background: var(--co-indigo-bg);
	color: var(--co-indigo);
	border-color: transparent;
}
.co-ce-add-section-btn.heading:hover {
	background: var(--co-indigo);
	color: white;
	box-shadow: 0 2px 8px rgba(99,102,241,.3);
}
.co-ce-add-section-btn.text {
	background: var(--co-slate-100);
	color: var(--co-slate-600);
}
.co-ce-add-section-btn.text:hover {
	background: var(--co-slate-200);
	color: var(--co-slate-800);
}
.co-ce-add-section-btn.list {
	background: var(--co-emerald-bg);
	color: var(--co-emerald);
}
.co-ce-add-section-btn.list:hover {
	background: var(--co-emerald);
	color: white;
	box-shadow: 0 2px 8px rgba(16,185,129,.3);
}

/* ── Error banner ───────────────────────────────────────────── */
.co-ce-error {
	display: flex;
	align-items: center;
	gap: 10px;
	background: var(--co-red-bg);
	border: 1px solid rgba(239,68,68,.2);
	color: var(--co-red);
	border-radius: 8px;
	padding: 11px 16px;
	font-size: 13px;
	margin-bottom: 12px;
}
.co-ce-error svg {
	width: 16px;
	height: 16px;
	stroke: currentColor;
	flex-shrink: 0;
}

/* ── Sticky footer ──────────────────────────────────────────── */
.co-ce-footer {
	position: sticky;
	bottom: 0;
	background: rgba(255,255,255,.92);
	backdrop-filter: blur(10px);
	-webkit-backdrop-filter: blur(10px);
	border-top: 1px solid var(--co-slate-200);
	padding: 14px 0;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	margin-top: 8px;
	z-index: 10;
}
.co-ce-dirty-indicator {
	display: flex;
	align-items: center;
	gap: 7px;
	font-size: 12.5px;
	color: var(--co-slate-500);
	font-weight: 500;
}
.co-ce-dirty-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: var(--co-amber);
	flex-shrink: 0;
	animation: co-pulse-dot 2s ease infinite;
}
@keyframes co-pulse-dot {
	0%, 100% { opacity: 1; transform: scale(1); }
	50% { opacity: .6; transform: scale(.85); }
}
.co-ce-save-btn {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	height: 42px;
	padding: 0 24px;
	background: var(--co-indigo);
	color: white;
	border: none;
	border-radius: var(--co-radius-sm);
	font-size: 13.5px;
	font-weight: 600;
	font-family: var(--co-font);
	cursor: pointer;
	box-shadow: 0 2px 8px rgba(99,102,241,.35);
	transition: background .15s, box-shadow .15s, transform .12s;
}
.co-ce-save-btn:hover:not(:disabled) {
	background: #4F46E5;
	box-shadow: 0 4px 16px rgba(99,102,241,.4);
	transform: translateY(-1px);
}
.co-ce-save-btn:disabled { opacity: .7; cursor: not-allowed; transform: none; }
.co-ce-save-btn svg {
	width: 14px;
	height: 14px;
	stroke: currentColor;
	stroke-width: 2.5;
}
.co-ce-spinner {
	width: 15px;
	height: 15px;
	border: 2px solid rgba(255,255,255,.35);
	border-top-color: white;
	border-radius: 50%;
	animation: co-spin .7s linear infinite;
	flex-shrink: 0;
}

/* ── AI controls ─────────────────────────────────────────────── */
.co-ce-ai-trigger-wrap {
	position: relative;
}
.co-ce-ai-btn {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	height: 26px;
	padding: 0 10px;
	border-radius: 6px;
	border: 1.5px solid rgba(99,102,241,.25);
	background: rgba(99,102,241,.06);
	color: var(--co-indigo);
	font-size: 11.5px;
	font-weight: 600;
	font-family: var(--co-font);
	cursor: pointer;
	transition: background .13s, border-color .13s, box-shadow .13s;
	white-space: nowrap;
	letter-spacing: .01em;
}
.co-ce-ai-btn:hover {
	background: rgba(99,102,241,.12);
	border-color: var(--co-indigo);
	box-shadow: 0 1px 6px rgba(99,102,241,.18);
}
.co-ce-ai-btn.loading {
	opacity: .65;
	cursor: not-allowed;
	pointer-events: none;
}
.co-ce-ai-popover {
	position: absolute;
	top: calc(100% + 6px);
	right: 0;
	background: #fff;
	border: 1px solid var(--co-slate-200);
	border-radius: 10px;
	box-shadow: 0 4px 20px rgba(15,23,42,.12), 0 1px 4px rgba(15,23,42,.06);
	padding: 6px;
	display: flex;
	flex-direction: column;
	gap: 2px;
	z-index: 100;
	min-width: 170px;
	animation: co-fade-up .15s ease both;
}
.co-ce-ai-action-btn {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border: none;
	border-radius: 7px;
	background: none;
	font-size: 12.5px;
	font-weight: 500;
	font-family: var(--co-font);
	color: var(--co-slate-700);
	cursor: pointer;
	text-align: left;
	transition: background .12s, color .12s;
	width: 100%;
}
.co-ce-ai-action-btn:hover {
	background: var(--co-indigo-bg);
	color: var(--co-indigo);
}
.co-ce-ai-action-btn svg {
	width: 13px;
	height: 13px;
	stroke: currentColor;
	stroke-width: 2;
	flex-shrink: 0;
}

/* AI preview panel */
.co-ce-ai-preview {
	border-top: 1px solid var(--co-indigo-bg);
	padding: 14px 16px 16px;
	background: linear-gradient(180deg, rgba(99,102,241,.03) 0%, transparent 100%);
}
.co-ce-ai-preview-label {
	font-size: 10.5px;
	font-weight: 700;
	letter-spacing: .07em;
	text-transform: uppercase;
	color: var(--co-slate-400);
	margin-bottom: 8px;
}
.co-ce-ai-preview-cols {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
	margin-bottom: 12px;
}
@media (max-width: 600px) {
	.co-ce-ai-preview-cols { grid-template-columns: 1fr; }
}
.co-ce-ai-preview-col {
	border-radius: 8px;
	padding: 12px 14px;
	font-size: 13px;
	line-height: 1.65;
	color: var(--co-slate-700);
	min-height: 60px;
}
.co-ce-ai-preview-col.original {
	background: var(--co-slate-50);
	border: 1px solid var(--co-slate-200);
}
.co-ce-ai-preview-col.suggestion {
	background: rgba(99,102,241,.04);
	border: 1.5px solid rgba(99,102,241,.2);
}
.co-ce-ai-preview-loading {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
	color: var(--co-slate-400);
	font-style: italic;
	min-height: 60px;
}
.co-ce-ai-preview-spinner {
	width: 14px;
	height: 14px;
	border: 2px solid rgba(99,102,241,.2);
	border-top-color: var(--co-indigo);
	border-radius: 50%;
	animation: co-spin .7s linear infinite;
	flex-shrink: 0;
}
.co-ce-ai-preview-error {
	font-size: 12.5px;
	color: var(--co-red);
	background: var(--co-red-bg);
	border-radius: 7px;
	padding: 8px 12px;
	margin-bottom: 8px;
}
.co-ce-ai-preview-btns {
	display: flex;
	gap: 8px;
}
.co-ce-ai-accept-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 16px;
	background: var(--co-indigo);
	color: #fff;
	border: none;
	border-radius: 7px;
	font-size: 12.5px;
	font-weight: 600;
	font-family: var(--co-font);
	cursor: pointer;
	transition: background .13s, box-shadow .13s;
}
.co-ce-ai-accept-btn:hover {
	background: #4F46E5;
	box-shadow: 0 2px 8px rgba(99,102,241,.35);
}
.co-ce-ai-reject-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 14px;
	background: none;
	color: var(--co-slate-500);
	border: 1.5px solid var(--co-slate-200);
	border-radius: 7px;
	font-size: 12.5px;
	font-weight: 500;
	font-family: var(--co-font);
	cursor: pointer;
	transition: border-color .13s, color .13s;
}
.co-ce-ai-reject-btn:hover {
	border-color: var(--co-red);
	color: var(--co-red);
}

/* Generate from brief */
.co-ce-generate-form {
	padding: 14px;
	border: 1.5px dashed rgba(99,102,241,.3);
	border-radius: var(--co-radius-sm);
	background: rgba(99,102,241,.03);
	margin-top: 8px;
	animation: co-fade-up .18s ease both;
}
.co-ce-generate-label {
	font-size: 12px;
	font-weight: 600;
	color: var(--co-indigo);
	margin-bottom: 8px;
	display: flex;
	align-items: center;
	gap: 6px;
}
.co-ce-generate-textarea {
	width: 100%;
	border: 1.5px solid rgba(99,102,241,.2);
	border-radius: 8px;
	padding: 10px 12px;
	font-family: var(--co-font);
	font-size: 13px;
	line-height: 1.6;
	color: var(--co-slate-700);
	background: #fff;
	outline: none;
	resize: vertical;
	min-height: 72px;
	box-sizing: border-box;
	transition: border-color .15s, box-shadow .15s;
}
.co-ce-generate-textarea::placeholder { color: var(--co-slate-300); font-style: italic; }
.co-ce-generate-textarea:focus {
	border-color: var(--co-indigo);
	box-shadow: 0 0 0 3px rgba(99,102,241,.1);
}
.co-ce-generate-row {
	display: flex;
	gap: 8px;
	margin-top: 8px;
	align-items: center;
}
.co-ce-generate-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 34px;
	padding: 0 16px;
	background: var(--co-indigo);
	color: #fff;
	border: none;
	border-radius: 7px;
	font-size: 12.5px;
	font-weight: 600;
	font-family: var(--co-font);
	cursor: pointer;
	transition: background .13s, box-shadow .13s;
}
.co-ce-generate-btn:hover:not(:disabled) {
	background: #4F46E5;
	box-shadow: 0 2px 8px rgba(99,102,241,.3);
}
.co-ce-generate-btn:disabled { opacity: .65; cursor: not-allowed; }
.co-ce-generate-cancel-btn {
	height: 34px;
	padding: 0 12px;
	background: none;
	border: 1.5px solid var(--co-slate-200);
	border-radius: 7px;
	font-size: 12.5px;
	font-weight: 500;
	font-family: var(--co-font);
	color: var(--co-slate-500);
	cursor: pointer;
	transition: border-color .13s, color .13s;
}
.co-ce-generate-cancel-btn:hover { border-color: var(--co-slate-300); color: var(--co-slate-700); }
.co-ce-add-section-btn.ai {
	background: rgba(99,102,241,.07);
	color: var(--co-indigo);
	border-color: rgba(99,102,241,.2);
}
.co-ce-add-section-btn.ai:hover {
	background: var(--co-indigo);
	color: white;
	border-color: transparent;
	box-shadow: 0 2px 8px rgba(99,102,241,.3);
}
`;

// ─── Sub-components ───────────────────────────────────────────────────────────

function HeadingEditor( { value, onChange } ) {
	return (
		<input
			type="text"
			className="co-ce-heading-input"
			value={ value }
			placeholder="Section heading…"
			onChange={ e => onChange( e.target.value ) }
		/>
	);
}

function TextEditor( { value, onChange } ) {
	const ref = useRef( null );

	useEffect( () => {
		if ( ref.current ) {
			ref.current.style.height = 'auto';
			ref.current.style.height = ref.current.scrollHeight + 'px';
		}
	}, [ value ] );

	return (
		<textarea
			ref={ ref }
			className="co-ce-text-area"
			value={ value }
			placeholder="Write your section content…"
			rows={ 3 }
			onChange={ e => onChange( e.target.value ) }
			onInput={ e => {
				e.target.style.height = 'auto';
				e.target.style.height = e.target.scrollHeight + 'px';
			} }
		/>
	);
}

function ListEditor( { items, onChange } ) {
	const canRemove = items.length > 1;

	return (
		<div>
			<div className="co-ce-list-items">
				{ items.map( ( item, i ) => (
					<div key={ i } className="co-ce-list-item-row">
						<div className="co-ce-list-bullet" />
						<input
							type="text"
							className="co-ce-list-item-input"
							value={ item }
							placeholder={ `List item ${ i + 1 }…` }
							onChange={ e => {
								const next = [ ...items ];
								next[ i ] = e.target.value;
								onChange( next );
							} }
						/>
						<button
							type="button"
							className="co-ce-list-remove-btn"
							disabled={ ! canRemove }
							title="Remove item"
							onClick={ () => onChange( items.filter( ( _, idx ) => idx !== i ) ) }
						>
							×
						</button>
					</div>
				) ) }
			</div>
			<button
				type="button"
				className="co-ce-list-add-btn"
				onClick={ () => onChange( [ ...items, '' ] ) }
			>
				<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
					<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
				</svg>
				Add item
			</button>
		</div>
	);
}

function PricingCard() {
	return (
		<div className="co-ce-pricing-locked">
			<div className="co-ce-pricing-locked-icon">
				<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
					<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
					<path d="M7 11V7a5 5 0 0110 0v4"/>
				</svg>
			</div>
			<div className="co-ce-pricing-locked-text">
				<strong>Pricing section</strong>
				Edit line items and totals in the Pricing step of the wizard.
			</div>
		</div>
	);
}

const TYPE_LABELS = {
	heading: 'Heading',
	text:    'Text',
	list:    'List',
	pricing: 'Pricing',
};

function SectionCard( { section, index, total, onChange, onMoveUp, onMoveDown, onDelete, aiState, aiMenuOpen, onAiMenuToggle, onAiAction, onAiAccept, onAiReject } ) {
	const isPricing  = section.type === 'pricing';
	const isText     = section.type === 'text';
	const isLoading  = aiState === 'loading';
	const hasPreview = aiState && aiState !== 'loading' && ! aiState.error;
	const hasError   = aiState && aiState.error;
	const menuOpen   = aiMenuOpen === index;

	return (
		<div
			className="co-ce-card"
			data-type={ section.type }
			style={ { animation: `co-fade-up .2s ease ${ index * 0.04 }s both` } }
		>
			<div className="co-ce-card-head">
				<div className="co-ce-card-head-left">
					<span className="co-ce-badge" data-type={ section.type }>
						{ isPricing && (
							<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
								<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
							</svg>
						) }
						{ TYPE_LABELS[ section.type ] || section.type }
					</span>
					<button
						type="button"
						className="co-ce-ctrl-btn"
						title="Move up"
						disabled={ index === 0 || isPricing }
						onClick={ onMoveUp }
						aria-label="Move section up"
					>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<polyline points="18 15 12 9 6 15"/>
						</svg>
					</button>
					<button
						type="button"
						className="co-ce-ctrl-btn"
						title="Move down"
						disabled={ index === total - 1 || isPricing }
						onClick={ onMoveDown }
						aria-label="Move section down"
					>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<polyline points="6 9 12 15 18 9"/>
						</svg>
					</button>
				</div>
				<div className="co-ce-card-head-right">
					{ isText && canUseAi && (
						<div className="co-ce-ai-trigger-wrap">
							<button
								type="button"
								className={ `co-ce-ai-btn${ isLoading ? ' loading' : '' }` }
								onClick={ () => onAiMenuToggle( index ) }
								title="AI writing tools"
							>
								✨ AI
							</button>
							{ menuOpen && (
								<div className="co-ce-ai-popover">
									{ [
										{ action: 'improve',    label: 'Improve',         icon: <svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> },
										{ action: 'shorten',    label: 'Shorten',         icon: <svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg> },
										{ action: 'persuasive', label: 'Make Persuasive', icon: <svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> },
									].map( ( { action, label, icon } ) => (
										<button
											key={ action }
											type="button"
											className="co-ce-ai-action-btn"
											onClick={ () => { onAiMenuToggle( null ); onAiAction( index, action ); } }
										>
											{ icon }
											{ label }
										</button>
									) ) }
								</div>
							) }
						</div>
					) }
					{ ! isPricing && (
						<button
							type="button"
							className="co-ce-ctrl-btn delete"
							title="Delete section"
							onClick={ onDelete }
							aria-label="Delete section"
						>
							<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
								<polyline points="3 6 5 6 21 6"/>
								<path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
								<path d="M10 11v6M14 11v6"/>
								<path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
							</svg>
						</button>
					) }
				</div>
			</div>

			<div className="co-ce-card-divider" />

			<div className="co-ce-card-body">
				{ section.type === 'heading' && (
					<HeadingEditor
						value={ section.content || '' }
						onChange={ val => onChange( { ...section, content: val } ) }
					/>
				) }
				{ section.type === 'text' && (
					<TextEditor
						value={ section.content || '' }
						onChange={ val => onChange( { ...section, content: val } ) }
					/>
				) }
				{ section.type === 'list' && (
					<ListEditor
						items={ section.items || [ '' ] }
						onChange={ items => onChange( { ...section, items } ) }
					/>
				) }
				{ section.type === 'pricing' && <PricingCard /> }
			</div>

			{ /* AI preview panel */ }
			{ ( isLoading || hasPreview || hasError ) && (
				<div className="co-ce-ai-preview">
					{ hasError && (
						<div className="co-ce-ai-preview-error">{ aiState.error }</div>
					) }
					{ isLoading && (
						<div className="co-ce-ai-preview-loading">
							<div className="co-ce-ai-preview-spinner" />
							Generating suggestion…
						</div>
					) }
					{ hasPreview && (
						<>
							<div className="co-ce-ai-preview-cols">
								<div>
									<div className="co-ce-ai-preview-label">Original</div>
									<div className="co-ce-ai-preview-col original">{ aiState.original }</div>
								</div>
								<div>
									<div className="co-ce-ai-preview-label">Suggestion</div>
									<div className="co-ce-ai-preview-col suggestion">{ aiState.suggestion }</div>
								</div>
							</div>
							<div className="co-ce-ai-preview-btns">
								<button type="button" className="co-ce-ai-accept-btn" onClick={ () => onAiAccept( index ) }>
									✓ Accept
								</button>
								<button type="button" className="co-ce-ai-reject-btn" onClick={ () => onAiReject( index ) }>
									Reject
								</button>
							</div>
						</>
					) }
					{ hasError && (
						<div className="co-ce-ai-preview-btns" style={ { marginTop: 8 } }>
							<button type="button" className="co-ce-ai-reject-btn" onClick={ () => onAiReject( index ) }>
								Dismiss
							</button>
						</div>
					) }
				</div>
			) }
		</div>
	);
}

function AddSectionBar( { onAdd, onGenerate } ) {
	const [ showForm,  setShowForm  ] = useState( false );
	const [ brief,     setBrief     ] = useState( '' );
	const [ loading,   setLoading   ] = useState( false );

	async function handleGenerate() {
		if ( ! brief.trim() ) return;
		setLoading( true );
		await onGenerate( brief );
		setLoading( false );
		setBrief( '' );
		setShowForm( false );
	}

	return (
		<div className="co-ce-add-bar-wrap">
			<div className="co-ce-add-bar">
				<span className="co-ce-add-bar-label">Add</span>
				<button
					type="button"
					className="co-ce-add-section-btn heading"
					onClick={ () => onAdd( 'heading' ) }
				>
					<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
					</svg>
					Heading
				</button>
				<button
					type="button"
					className="co-ce-add-section-btn text"
					onClick={ () => onAdd( 'text' ) }
				>
					<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
					</svg>
					Text
				</button>
				<button
					type="button"
					className="co-ce-add-section-btn list"
					onClick={ () => onAdd( 'list' ) }
				>
					<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
						<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
					</svg>
					List
				</button>
				{ canUseAi && (
					<button
						type="button"
						className="co-ce-add-section-btn ai"
						onClick={ () => setShowForm( f => ! f ) }
					>
						✨ Generate
					</button>
				) }
			</div>
			{ canUseAi && showForm && (
				<div className="co-ce-generate-form">
					<div className="co-ce-generate-label">
						✨ Describe what this section should cover
					</div>
					<textarea
						className="co-ce-generate-textarea"
						value={ brief }
						onChange={ e => setBrief( e.target.value ) }
						placeholder="e.g. Explain our project timeline and key milestones over 12 weeks…"
						rows={ 3 }
					/>
					<div className="co-ce-generate-row">
						<button
							type="button"
							className="co-ce-generate-btn"
							disabled={ loading || ! brief.trim() }
							onClick={ handleGenerate }
						>
							{ loading ? (
								<>
									<div className="co-ce-ai-preview-spinner" style={ { borderTopColor: 'white', borderColor: 'rgba(255,255,255,.3)' } } />
									Generating…
								</>
							) : (
								<>✨ Generate Section</>
							) }
						</button>
						<button
							type="button"
							className="co-ce-generate-cancel-btn"
							onClick={ () => { setShowForm( false ); setBrief( '' ); } }
						>
							Cancel
						</button>
					</div>
				</div>
			) }
		</div>
	);
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function ContentEditor( { proposal, onSave, onCancel } ) {
	injectStyles( 'co-ce-styles', CSS );

	const [ sections,   setSections  ] = useState( () => parseSections( proposal ) );
	const [ saving,     setSaving    ] = useState( false );
	const [ saveError,  setSaveError ] = useState( null );
	const [ dirty,      setDirty     ] = useState( false );
	const [ aiStates,   setAiStates  ] = useState( {} ); // { [index]: null | 'loading' | { original, suggestion } | { error } }
	const [ aiMenuOpen, setAiMenuOpen ] = useState( null ); // index of open popover, or null

	function updateSection( index, updated ) {
		setSections( prev => prev.map( ( s, i ) => i === index ? updated : s ) );
		setDirty( true );
	}

	function handleMoveUp( index ) {
		setSections( prev => moveSectionUp( prev, index ) );
		setDirty( true );
	}

	function handleMoveDown( index ) {
		setSections( prev => moveSectionDown( prev, index ) );
		setDirty( true );
	}

	function handleDelete( index ) {
		setSections( prev => deleteSection( prev, index ) );
		setAiStates( prev => { const n = { ...prev }; delete n[ index ]; return n; } );
		setDirty( true );
	}

	function handleAdd( type ) {
		setSections( prev => addSection( prev, type ) );
		setDirty( true );
	}

	async function handleAiAction( index, action ) {
		const text = sections[ index ]?.content || '';
		if ( ! text.trim() ) return;

		setAiStates( prev => ( { ...prev, [ index ]: 'loading' } ) );
		try {
			const data = await coFetch( 'ai/process', {
				method: 'POST',
				body: JSON.stringify( { action, text } ),
			} );
			setAiStates( prev => ( {
				...prev,
				[ index ]: { original: text, suggestion: data.result },
			} ) );
		} catch ( e ) {
			setAiStates( prev => ( {
				...prev,
				[ index ]: { error: e.message || 'AI request failed. Please try again.' },
			} ) );
		}
	}

	function handleAiAccept( index ) {
		const state = aiStates[ index ];
		if ( ! state || state === 'loading' || ! state.suggestion ) return;
		updateSection( index, { ...sections[ index ], content: state.suggestion } );
		setAiStates( prev => { const n = { ...prev }; delete n[ index ]; return n; } );
	}

	function handleAiReject( index ) {
		setAiStates( prev => { const n = { ...prev }; delete n[ index ]; return n; } );
	}

	async function handleGenerate( brief ) {
		try {
			const data = await coFetch( 'ai/process', {
				method: 'POST',
				body: JSON.stringify( { action: 'generate', text: brief, brief } ),
			} );
			setSections( prev => [ ...prev, { type: 'text', content: data.result } ] );
			setDirty( true );
		} catch ( e ) {
			// Surface error via saveError banner
			setSaveError( 'AI generate failed: ' + ( e.message || 'Please try again.' ) );
		}
	}

	async function handleSave() {
		setSaving( true );
		setSaveError( null );
		try {
			const data = await coFetch( `proposals/${ proposal.id }/update`, {
				method: 'POST',
				body: JSON.stringify( { content: buildContentPayload( proposal, sections ) } ),
			} );
			onSave( data.proposal );
		} catch ( e ) {
			setSaveError( e.message || 'Save failed.' );
		} finally {
			setSaving( false );
		}
	}

	return (
		<div className="co-ce" onClick={ () => aiMenuOpen !== null && setAiMenuOpen( null ) }>
			{ /* Header */ }
			<div className="co-ce-header">
				<div className="co-ce-header-left">
					<h1 className="co-ce-title">Edit Content</h1>
					<div className="co-ce-subtitle">{ proposal.title || 'Untitled proposal' }</div>
				</div>
				<button type="button" className="co-ce-cancel-btn" onClick={ onCancel }>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
						<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
					</svg>
					Cancel
				</button>
			</div>

			{ /* Hint */ }
			<div className="co-ce-subheader">
				<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
					<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
				</svg>
				Editing text copy only — configure line items and totals in the Pricing step of the wizard.
			</div>

			{ /* Section cards */ }
			<div className="co-ce-body">
				{ sections.map( ( section, i ) => (
					<SectionCard
						key={ i }
						section={ section }
						index={ i }
						total={ sections.length }
						onChange={ updated => updateSection( i, updated ) }
						onMoveUp={ () => handleMoveUp( i ) }
						onMoveDown={ () => handleMoveDown( i ) }
						onDelete={ () => handleDelete( i ) }
						aiState={ aiStates[ i ] || null }
						aiMenuOpen={ aiMenuOpen }
						onAiMenuToggle={ idx => setAiMenuOpen( prev => prev === idx ? null : idx ) }
						onAiAction={ handleAiAction }
						onAiAccept={ handleAiAccept }
						onAiReject={ handleAiReject }
					/>
				) ) }
			</div>

			{ /* Add section bar */ }
			<AddSectionBar onAdd={ handleAdd } onGenerate={ handleGenerate } />

			{ /* Error */ }
			{ saveError && (
				<div className="co-ce-error" style={ { marginTop: 16 } }>
					<svg viewBox="0 0 24 24" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
						<circle cx="12" cy="12" r="10"/>
						<line x1="12" y1="8" x2="12" y2="12"/>
						<line x1="12" y1="16" x2="12.01" y2="16"/>
					</svg>
					{ saveError }
				</div>
			) }

			{ /* Sticky footer */ }
			<div className="co-ce-footer">
				<div className="co-ce-dirty-indicator">
					{ dirty ? (
						<>
							<div className="co-ce-dirty-dot" />
							Unsaved changes
						</>
					) : (
						<span style={ { color: 'var(--co-slate-300)', fontSize: 12 } }>All changes saved</span>
					) }
				</div>
				<button
					type="button"
					className="co-ce-save-btn"
					disabled={ saving || ! dirty }
					onClick={ handleSave }
				>
					{ saving ? (
						<>
							<div className="co-ce-spinner" />
							Saving…
						</>
					) : (
						<>
							<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
								<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
								<polyline points="17 21 17 13 7 13 7 21"/>
								<polyline points="7 3 7 8 15 8"/>
							</svg>
							Save Changes
						</>
					) }
				</button>
			</div>
		</div>
	);
}
