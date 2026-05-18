/**
 * ClientProposalSection
 *
 * Renders a single content block from the proposal's sections array.
 * Types: heading | text | list | pricing
 */

const injectStyles = ( id, css ) => {
	if ( document.getElementById( id ) ) return;
	const s = document.createElement( 'style' );
	s.id = id;
	s.textContent = css;
	document.head.appendChild( s );
};

const CSS = `
/* ── Heading ───────────────────────────────────────────────── */
.cfs-heading {
	margin: 52px 0 18px;
}
.cfs-heading:first-child {
	margin-top: 0;
}
.cfs-heading h2 {
	font-family: 'Playfair Display', serif;
	font-size: 25px;
	font-weight: 700;
	color: #1A1A2E;
	margin: 0;
	padding-left: 18px;
	border-left: 3px solid #6366F1;
	line-height: 1.3;
	letter-spacing: -0.2px;
}

/* ── Text ──────────────────────────────────────────────────── */
.cfs-text {
	margin: 0 0 24px;
}
.cfs-text p {
	font-family: 'DM Sans', sans-serif;
	font-size: 15.5px;
	line-height: 1.78;
	color: #374151;
	margin: 0 0 14px;
}
.cfs-text p:last-child {
	margin-bottom: 0;
}

/* ── List ──────────────────────────────────────────────────── */
.cfs-list {
	margin: 0 0 28px;
}
.cfs-list ul {
	list-style: none;
	padding: 0;
	margin: 0;
}
.cfs-list li {
	display: flex;
	align-items: flex-start;
	gap: 13px;
	padding: 7px 0;
	font-family: 'DM Sans', sans-serif;
	font-size: 15px;
	line-height: 1.65;
	color: #374151;
}
.cfs-bullet {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	background: #6366F1;
	flex-shrink: 0;
	margin-top: 8px;
}

/* ── Pricing placeholder ────────────────────────────────────── */
.cfs-pricing-hint {
	margin: 28px 0 0;
	display: inline-flex;
	align-items: center;
	gap: 7px;
	font-family: 'DM Sans', sans-serif;
	font-size: 13px;
	font-style: italic;
	color: #9CA3AF;
	padding: 10px 16px;
	background: #F9FAFB;
	border: 1.5px dashed #E5E7EB;
	border-radius: 8px;
}

/* ── Print ──────────────────────────────────────────────────── */
@media print {
	.cfs-heading h2 { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
	.cfs-bullet     { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
`;

export default function ClientProposalSection( { section } ) {
	injectStyles( 'co-section-s', CSS );

	const { type, content, items, note } = section;

	if ( type === 'heading' ) {
		return (
			<div className="cfs-heading">
				<h2>{ content }</h2>
			</div>
		);
	}

	if ( type === 'text' ) {
		const renderInline = ( text ) =>
			text.split( /(\*\*[^*]+\*\*)/ ).map( ( chunk, i ) =>
				chunk.startsWith( '**' ) && chunk.endsWith( '**' )
					? <strong key={ i }>{ chunk.slice( 2, -2 ) }</strong>
					: chunk
			);

		const paragraphs = ( content || '' ).split( /\n\n+/ );

		return (
			<div className="cfs-text">
				{ paragraphs.map( ( para, pi ) => (
					<p key={ pi }>
						{ para.split( '\n' ).map( ( line, li, arr ) => (
							<wp.element.Fragment key={ li }>
								{ renderInline( line ) }
								{ li < arr.length - 1 && <br /> }
							</wp.element.Fragment>
						) ) }
					</p>
				) ) }
			</div>
		);
	}

	if ( type === 'list' ) {
		return (
			<div className="cfs-list">
				<ul>
					{ ( items || [] ).map( ( item, i ) => (
						<li key={ i }>
							<span className="cfs-bullet" aria-hidden="true" />
							{ item }
						</li>
					) ) }
				</ul>
			</div>
		);
	}

	if ( type === 'pricing' ) {
		return (
			<p className="cfs-pricing-hint">
				<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#C4B5FD" strokeWidth="2">
					<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
				</svg>
				See pricing breakdown below ↓
			</p>
		);
	}

	return null;
}
