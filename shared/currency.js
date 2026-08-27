/**
 * Currency formatting shared across admin/, portal/, and client/ components.
 * Previously copy-pasted independently in ~10 files (fmt) and ~4 files
 * (SYMBOLS/CURRENCY_SYMBOLS maps) — with divergent CAD/AUD symbols between
 * copies ('CA$'/'A$' vs plain '$'). This is the single corrected map.
 */
export const SYMBOLS = { GBP: '£', USD: '$', EUR: '€', CAD: 'CA$', AUD: 'A$' };

/**
 * @param {number} amount
 * @param {string} [currency='GBP'] ISO currency code.
 * @return {string} e.g. "£1,234.56"
 */
export function fmt( amount, currency = 'GBP' ) {
	const sym = SYMBOLS[ currency ] || ( currency + ' ' );
	return sym + Number( amount ).toLocaleString( 'en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
}
