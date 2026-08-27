/**
 * injectStyles
 *
 * Appends a <style> tag with the given CSS text to <head>, once per id.
 * Shared across admin/, portal/, and client/ — the single canonical
 * implementation (previously copy-pasted independently in ~28 component
 * files, plus a window-global variant in portal/).
 *
 * @param {string} id  Unique element id — used to avoid re-injecting the
 *                      same styles if the component re-renders/re-mounts.
 * @param {string} css Raw CSS text.
 */
export function injectStyles( id, css ) {
	if ( document.getElementById( id ) ) return;
	const el = document.createElement( 'style' );
	el.id = id;
	el.textContent = css;
	document.head.appendChild( el );
}
