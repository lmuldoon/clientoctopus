/**
 * ContentEditor utilities
 *
 * Pure helpers — no React, no side effects.
 * All functions return new arrays/objects; they never mutate arguments.
 */

/**
 * Safely extract the sections array from a proposal object.
 * Handles both pre-decoded objects and raw JSON strings.
 *
 * @param {object} proposal
 * @returns {Array}
 */
export function parseSections( proposal ) {
	let content = proposal?.content;

	if ( typeof content === 'string' ) {
		try {
			content = JSON.parse( content );
		} catch {
			return [];
		}
	}

	if ( ! content || ! Array.isArray( content.sections ) ) {
		return [];
	}

	// Deep-clone so we don't mutate the proposal object.
	return JSON.parse( JSON.stringify( content.sections ) );
}

/**
 * Swap section at index i with the one above it.
 *
 * @param {Array}  sections
 * @param {number} i
 * @returns {Array}
 */
export function moveSectionUp( sections, i ) {
	if ( i <= 0 ) return sections;
	const next = [ ...sections ];
	[ next[ i - 1 ], next[ i ] ] = [ next[ i ], next[ i - 1 ] ];
	return next;
}

/**
 * Swap section at index i with the one below it.
 *
 * @param {Array}  sections
 * @param {number} i
 * @returns {Array}
 */
export function moveSectionDown( sections, i ) {
	if ( i >= sections.length - 1 ) return sections;
	const next = [ ...sections ];
	[ next[ i ], next[ i + 1 ] ] = [ next[ i + 1 ], next[ i ] ];
	return next;
}

/**
 * Remove the section at index i.
 * Guards: will not remove a pricing section.
 *
 * @param {Array}  sections
 * @param {number} i
 * @returns {Array}
 */
export function deleteSection( sections, i ) {
	if ( sections[ i ]?.type === 'pricing' ) return sections;
	return sections.filter( ( _, idx ) => idx !== i );
}

/**
 * Append a new blank section of the given type.
 * 'pricing' sections cannot be added via this function.
 *
 * @param {Array}  sections
 * @param {'heading'|'text'|'list'} type
 * @returns {Array}
 */
export function addSection( sections, type ) {
	if ( type === 'pricing' ) return sections;

	const blank =
		type === 'heading' ? { type: 'heading', content: '' } :
		type === 'text'    ? { type: 'text',    content: '' } :
		                     { type: 'list',    items: [ '' ] };

	return [ ...sections, blank ];
}

/**
 * Merge edited sections back into the proposal's full content object
 * and return a JSON string ready for the API.
 *
 * Preserves template_id, line_items, discount_pct, vat_pct,
 * deposit_pct, require_deposit.
 *
 * @param {object} proposal  Original proposal (with decoded content object).
 * @param {Array}  sections  Edited sections array.
 * @returns {string}         JSON string.
 */
export function buildContentPayload( proposal, sections ) {
	let base = proposal?.content;

	if ( typeof base === 'string' ) {
		try {
			base = JSON.parse( base );
		} catch {
			base = {};
		}
	}

	const payload = {
		template_id:      base?.template_id      ?? '',
		line_items:       base?.line_items        ?? [],
		discount_pct:     base?.discount_pct      ?? 0,
		vat_pct:          base?.vat_pct           ?? 0,
		deposit_pct:      base?.deposit_pct       ?? 0,
		require_deposit:  base?.require_deposit   ?? false,
		sections,
	};

	return JSON.stringify( payload );
}
