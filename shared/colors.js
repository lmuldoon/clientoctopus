/**
 * Brand-colour helpers shared across client/ and portal/ components that
 * render a tenant-configurable brand/button colour (buttons, badges, header
 * bands). Previously copy-pasted independently in ~13 (getContrastColor) and
 * ~12 (getBrandButtonColors) component files.
 */

/**
 * Return #ffffff or #1A1A2E — whichever has better WCAG contrast against hex.
 * Uses relative luminance (WCAG 2.1) with a threshold of 0.35.
 *
 * @param {string} hex Hex colour, with or without leading #.
 * @return {string} '#1A1A2E' or '#ffffff'
 */
export function getContrastColor( hex ) {
	const c = ( hex || '#6366F1' ).replace( '#', '' );
	const r = parseInt( c.substring( 0, 2 ), 16 ) / 255;
	const g = parseInt( c.substring( 2, 4 ), 16 ) / 255;
	const b = parseInt( c.substring( 4, 6 ), 16 ) / 255;
	const lin = x => x <= 0.04045 ? x / 12.92 : Math.pow( ( x + 0.055 ) / 1.055, 2.4 );
	const L = 0.2126 * lin( r ) + 0.7152 * lin( g ) + 0.0722 * lin( b );
	return L > 0.35 ? '#1A1A2E' : '#ffffff';
}

/**
 * Return hex as-is if it's dark enough to read as TEXT on a white
 * background, otherwise return fallback. Looser threshold (0.55) than
 * getContrastColor's 0.35, since this checks a colour against a fixed white
 * background rather than picking between black/white.
 *
 * @param {string} hex      Hex colour, with or without leading #.
 * @param {string} fallback Hex colour to use when hex is too light to read.
 * @return {string}
 */
export function getReadableOnWhite( hex, fallback ) {
	const c = ( hex || fallback ).replace( '#', '' );
	const r = parseInt( c.substring( 0, 2 ), 16 ) / 255;
	const g = parseInt( c.substring( 2, 4 ), 16 ) / 255;
	const b = parseInt( c.substring( 4, 6 ), 16 ) / 255;
	const lin = x => x <= 0.04045 ? x / 12.92 : Math.pow( ( x + 0.055 ) / 1.055, 2.4 );
	const L = 0.2126 * lin( r ) + 0.7152 * lin( g ) + 0.0722 * lin( b );
	return L > 0.55 ? fallback : ( '#' + c );
}

/**
 * Derive a button's background/hover/text/shadow colours from a single
 * brand hex value.
 *
 * @param {string} hex Hex colour, with or without leading #.
 * @return {{bg: string, hover: string, text: string, shadow: string, shadowStrong: string}}
 */
export function getBrandButtonColors( hex ) {
	const base = hex || '#6366F1';
	const c = base.replace( '#', '' );
	const r = parseInt( c.substring( 0, 2 ), 16 );
	const g = parseInt( c.substring( 2, 4 ), 16 );
	const b = parseInt( c.substring( 4, 6 ), 16 );
	const darken = ( v ) => Math.max( 0, Math.round( v * 0.85 ) );
	const hoverHex = '#' + [ darken( r ), darken( g ), darken( b ) ]
		.map( v => v.toString( 16 ).padStart( 2, '0' ) )
		.join( '' );
	return {
		bg:           base,
		hover:        hoverHex,
		text:         getContrastColor( base ),
		shadow:       `rgba(${ r },${ g },${ b },.3)`,
		shadowStrong: `rgba(${ r },${ g },${ b },.4)`,
	};
}
