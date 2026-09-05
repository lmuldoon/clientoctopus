/**
 * BookingsApp
 *
 * Admin UI for calls booked via the [clientoctopus_booking_form] shortcode.
 * Design matches LeadsApp exactly (same tokens, tab/search/table/pager
 * conventions) — intentionally not a new visual style.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { injectStyles } from '../../../shared/injectStyles';
import { CO_TOKENS_CSS } from '../../../shared/tokens';

// ── Fetch helper (identical pattern to LeadsApp) ────────────────────────────

async function coFetch( path, options = {} ) {
	const { apiUrl, nonce } = window.clientoctopusData || {};
	const [ base, qs ] = path.split( '?' );
	const url = ( apiUrl || '/wp-json/clientoctopus/v1/' ) + base.replace( /\/?$/, '/' ) + ( qs ? `?${ qs }` : '' );
	const res = await fetch( url, {
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce || '',
			...( options.headers || {} ),
		},
		...options,
	} );
	if ( ! res.ok ) {
		const err = await res.json().catch( () => ( {} ) );
		const e   = new Error( err.message || `HTTP ${ res.status }` );
		e.data    = err;
		throw e;
	}
	return res.json();
}

// ── Constants ───────────────────────────────────────────────────────────────

const STATUS_CONFIG = {
	confirmed: { bg: 'var(--co-indigo-bg)', color: 'var(--co-indigo)', label: 'Confirmed' },
	cancelled: { bg: 'var(--co-slate-100)', color: 'var(--co-slate-400)', label: 'Cancelled' },
};

const SOURCE_CONFIG = {
	manual:    { bg: 'var(--co-slate-100)', color: 'var(--co-slate-500)', label: 'Manual' },
	google:    { bg: 'var(--co-indigo-bg)', color: 'var(--co-indigo)',    label: 'Google Calendar' },
	microsoft: { bg: 'var(--co-indigo-bg)', color: 'var(--co-indigo)',    label: 'Microsoft 365' },
	apple:     { bg: 'var(--co-indigo-bg)', color: 'var(--co-indigo)',    label: 'Apple Calendar' },
};

const TABS = [
	{ id: '',          label: 'All'       },
	{ id: 'confirmed', label: 'Confirmed' },
	{ id: 'cancelled', label: 'Cancelled' },
];

const EMPTY_BLOCK_FORM = {
	id: null,
	label: '',
	allDay: false,
	startDate: '',
	startTime: '09:00',
	endDate: '',
	endTime: '17:00',
};

// ── Styles (same class shapes as LeadsApp, co-bk prefix) ────────────────────

const CSS = `
${ CO_TOKENS_CSS }
.co-bk {
  font-family: 'Archivo', -apple-system, sans-serif;
  padding: 32px 28px 64px;
}
@keyframes co-bk-enter {
  from { opacity:0; transform:translateY(8px); }
  to   { opacity:1; transform:translateY(0); }
}
.co-bk { animation: co-bk-enter .2s ease; }

.co-bk-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; gap:16px; flex-wrap:wrap; }
.co-bk-title { font-family:'Archivo', -apple-system, sans-serif; font-size:28px; font-weight:800; color:var(--co-navy); margin:0; line-height: 1;}
.co-bk-sub { font-size:14px; color:var(--co-slate-400); margin:6px 0 0; }

.co-bk-controls {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.co-bk-tabs { display:flex; gap:6px; flex-wrap:wrap; }
.co-bk-tab {
  display:flex; align-items:center; gap:7px;
  padding:7px 16px;
  border-radius:20px;
  border:1.5px solid var(--co-slate-200);
  background:#fff;
  font-size:13px; font-weight:500;
  font-family:var(--co-font);
  color:var(--co-slate-500);
  cursor:pointer;
  transition:all .12s;
  white-space:nowrap;
}
.co-bk-tab:hover { border-color:var(--co-indigo); color:var(--co-indigo); }
.co-bk-tab.active {
  background:var(--co-indigo);
  border-color:var(--co-indigo);
  color:#fff;
  font-weight:600;
}
.co-bk-tab-count {
  font-size:11px;
  font-weight:700;
  background:var(--co-slate-100);
  color:var(--co-slate-500);
  border-radius:999px;
  padding:1px 7px;
  min-width:20px;
  text-align:center;
}
.co-bk-tab.active .co-bk-tab-count { background:rgba(255,255,255,.22); color:#fff; }

.co-bk-search-wrap { position:relative; flex-shrink:0; }
input.co-bk-search {
  padding:9px 14px;
  border:var(--co-input-border);
  border-radius:var(--co-radius-sm);
  font-size:13.5px;
  font-family:var(--co-font);
  color:var(--co-slate-800);
  background:var(--co-white);
  outline:none;
  width:220px;
  transition:border-color .15s, box-shadow .15s;
}
input.co-bk-search::placeholder { color:var(--co-slate-300); }
input.co-bk-search:focus { border-color:var(--co-indigo); box-shadow:var(--co-input-focus); }

.co-bk-table-wrap { background:var(--co-white); border-radius:var(--co-radius); border:1px solid var(--co-slate-200); overflow-x:auto; box-shadow: 0 1px 3px rgba(26,26,46,.04), 0 6px 24px rgba(26,26,46,.06); }
.co-bk-table { width:100%; min-width:640px; border-collapse:collapse; }
.co-bk-table th {
  text-align:left;
  padding:11px 16px;
  font-size:11px;
  font-weight:600;
  letter-spacing:.06em;
  text-transform:uppercase;
  color:var(--co-slate-400);
  border-bottom:1px solid var(--co-slate-100);
  background:var(--co-slate-50);
}
.co-bk-table td { padding:14px 16px; border-bottom:1px solid var(--co-slate-100); font-size:14px; color:var(--co-slate-600); vertical-align:middle; }
.co-bk-table tr:last-child td { border-bottom:none; }
.co-bk-table tr:hover td { background:var(--co-slate-50); }

.co-bk-name { font-weight:600; color:var(--co-navy); }
.co-bk-badge { display:inline-flex; align-items:center; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600; }

.co-bk-actions { display:flex; gap:6px; flex-wrap:wrap; }
.co-bk-btn { padding:6px 10px; font-size:12px; font-weight:600; border-radius:6px; border:1px solid var(--co-slate-200); background:#fff; color:var(--co-slate-600); cursor:pointer; text-decoration:none; display:inline-block; }
.co-bk-btn:hover { background:var(--co-slate-50); }
.co-bk-btn.danger { color:#B91C1C; border-color:#FECACA; }
.co-bk-btn.danger:hover { background:#FEF2F2; }

.co-bk-empty { text-align:center; padding:64px 24px; }
.co-bk-empty-icon { color:var(--co-slate-300); margin:0 auto 16px; display:block; }
.co-bk-empty-title { font-family:'Archivo', -apple-system, sans-serif; font-size:20px; color:var(--co-navy); margin:0 0 8px; }
.co-bk-empty-sub { font-size:14px; color:var(--co-slate-500); max-width:420px; margin:0 auto; line-height:1.6; }
.co-bk-empty-shortcode { display:inline-block; margin-top:16px; padding:8px 14px; background:var(--co-slate-100); border-radius:8px; font-family:monospace; font-size:13px; color:var(--co-navy); }

.co-bk-pager { display:flex; align-items:center; justify-content:center; gap:6px; margin-top:20px; }
.co-bk-page-btn {
  min-width:34px; height:34px;
  display:flex; align-items:center; justify-content:center;
  border-radius:8px;
  border:1.5px solid var(--co-slate-200);
  background:var(--co-white);
  font-size:13px; font-weight:600; font-family:var(--co-font);
  color:var(--co-slate-600);
  cursor:pointer;
  transition:border-color .12s, background .12s, color .12s;
}
.co-bk-page-btn:hover:not(:disabled) { border-color:var(--co-indigo); color:var(--co-indigo); background:var(--co-indigo-bg); }
.co-bk-page-btn.active { background:var(--co-indigo); color:#fff; border-color:var(--co-indigo); }
.co-bk-page-btn:disabled { opacity:.4; cursor:not-allowed; }
.co-bk-page-btn svg { width:14px; height:14px; stroke:currentColor; stroke-width:2; }

/* Time Off form */
.co-bk-form-card { background:var(--co-white); border-radius:var(--co-radius); border:1px solid var(--co-slate-200); padding:20px; margin-bottom:20px; }
.co-bk-form-title { font-family:'Archivo', -apple-system, sans-serif; font-size:15px; font-weight:700; color:var(--co-navy); margin:0 0 14px; }
.co-bk-form-row { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:14px; align-items:flex-end; }
.co-bk-field { display:flex; flex-direction:column; gap:6px; }
.co-bk-field label { font-size:12px; font-weight:600; color:var(--co-slate-500); }
.co-bk-field input[type="text"],
.co-bk-field input[type="date"],
.co-bk-field input[type="time"],
.co-bk-field input[type="datetime-local"] {
  padding:8px 10px;
  border:1.5px solid var(--co-slate-200);
  border-radius:var(--co-radius-sm);
  font-size:13.5px;
  font-family:var(--co-font);
  color:var(--co-slate-800);
  background:var(--co-white);
}
.co-bk-field input:focus { outline:none; border-color:var(--co-indigo); box-shadow:var(--co-input-focus); }
.co-bk-allday-label { display:flex; align-items:center; gap:7px; margin-top:8px; font-size:13px; font-weight:500; color:var(--co-slate-600); cursor:pointer; }
.co-bk-form-actions { display:flex; gap:10px; align-items:center; }
.co-bk-btn.primary { background:var(--co-indigo); border-color:var(--co-indigo); color:#fff; }
.co-bk-btn.primary:hover { background:var(--co-indigo-dark, #4F46E5); }
.co-bk-cancel-edit { font-size:13px; color:var(--co-slate-500); background:none; border:none; cursor:pointer; }
.co-bk-hint { font-size:12px; color:var(--co-slate-400); margin:6px 0 0; }
`;

// ── Sub-components ──────────────────────────────────────────────────────────

function StatusBadge( { status } ) {
	const cfg = STATUS_CONFIG[ status ] || STATUS_CONFIG.confirmed;
	return (
		<span className="co-bk-badge" style={ { background: cfg.bg, color: cfg.color } }>
			{ cfg.label }
		</span>
	);
}

function SourceBadge( { source } ) {
	const cfg = SOURCE_CONFIG[ source ] || SOURCE_CONFIG.manual;
	return (
		<span className="co-bk-badge" style={ { background: cfg.bg, color: cfg.color } }>
			{ cfg.label }
		</span>
	);
}

function formatDateTime( iso ) {
	// scheduled_at is a UTC MySQL datetime string ("Y-m-d H:i:s") — treat as
	// UTC explicitly so it renders in the admin viewer's own local time.
	const d = new Date( iso.replace( ' ', 'T' ) + 'Z' );
	return d.toLocaleString( undefined, {
		weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
	} );
}

// ── Main component ────────────────────────────────────────────────────────────

export default function BookingsApp() {
	injectStyles( 'co-bookings-styles', CSS );

	const [ bookings, setBookings ] = useState( [] );
	const [ total, setTotal ]       = useState( 0 );
	const [ counts, setCounts ]     = useState( {} );
	const [ tab, setTab ]           = useState( '' );
	const [ page, setPage ]         = useState( 1 );
	const [ loading, setLoading ]   = useState( true );
	const [ error, setError ]       = useState( '' );
	const [ search, setSearch ]     = useState( '' );
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	const perPage = 20;

	const [ view, setView ]           = useState( 'bookings' ); // 'bookings' | 'timeoff'
	const [ blocks, setBlocks ]       = useState( [] );
	const [ blocksLoading, setBlocksLoading ] = useState( false );
	const [ blockError, setBlockError ] = useState( '' );
	const [ blockForm, setBlockForm ] = useState( EMPTY_BLOCK_FORM );

	useEffect( () => {
		const t = setTimeout( () => {
			setDebouncedSearch( search );
			setPage( 1 );
		}, 300 );
		return () => clearTimeout( t );
	}, [ search ] );

	const load = useCallback( () => {
		setLoading( true );
		setError( '' );
		const qs = new URLSearchParams( {
			page, per_page: perPage,
			...( tab ? { status: tab } : {} ),
			...( debouncedSearch ? { search: debouncedSearch } : {} ),
		} ).toString();
		coFetch( `bookings?${ qs }` )
			.then( ( data ) => {
				setBookings( data.bookings || [] );
				setTotal( data.total || 0 );
				setCounts( data.counts || {} );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setLoading( false ) );
	}, [ tab, page, debouncedSearch ] );

	useEffect( () => { load(); }, [ load ] );

	const cancelBooking = ( id ) => {
		if ( ! window.confirm( 'Cancel this booking?' ) ) {
			return;
		}
		coFetch( `bookings/${ id }`, { method: 'PATCH', body: JSON.stringify( { status: 'cancelled' } ) } )
			.then( load )
			.catch( ( e ) => setError( e.message ) );
	};

	const loadBlocks = useCallback( () => {
		setBlocksLoading( true );
		setBlockError( '' );
		coFetch( 'booking/blocks' )
			.then( ( data ) => setBlocks( data.blocks || [] ) )
			.catch( ( e ) => setBlockError( e.message ) )
			.finally( () => setBlocksLoading( false ) );
	}, [] );

	// Fetched once on mount (not gated on `view`) so the "Time Off" tab's
	// count badge reflects reality immediately, not "0" until first opened.
	useEffect( () => {
		loadBlocks();
	}, [ loadBlocks ] );

	useEffect( () => {
		if ( 'timeoff' === view ) {
			loadBlocks();
		}
	}, [ view, loadBlocks ] );

	// One day after `dateStr` (YYYY-MM-DD), for the "all day" range's
	// exclusive end boundary — an all-day block from the 10th to the 12th
	// should cover through the end of the 12th, i.e. end at the start of the 13th.
	const addOneDay = ( dateStr ) => {
		const d = new Date( dateStr + 'T00:00' );
		d.setDate( d.getDate() + 1 );
		return d.getFullYear() + '-' + String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-' + String( d.getDate() ).padStart( 2, '0' );
	};

	const submitBlock = ( e ) => {
		e.preventDefault();
		setBlockError( '' );

		if ( ! blockForm.startDate || ( ! blockForm.allDay && ! blockForm.endDate ) ) {
			setBlockError( 'Please choose a start (and end) date.' );
			return;
		}

		const startsAt = blockForm.allDay
			? `${ blockForm.startDate }T00:00`
			: `${ blockForm.startDate }T${ blockForm.startTime }`;
		const endsAt = blockForm.allDay
			? `${ addOneDay( blockForm.endDate || blockForm.startDate ) }T00:00`
			: `${ blockForm.endDate }T${ blockForm.endTime }`;

		const payload = { label: blockForm.label, starts_at: startsAt, ends_at: endsAt };
		const request = blockForm.id
			? coFetch( `booking/blocks/${ blockForm.id }`, { method: 'PATCH', body: JSON.stringify( payload ) } )
			: coFetch( 'booking/blocks', { method: 'POST', body: JSON.stringify( payload ) } );

		request
			.then( () => {
				setBlockForm( EMPTY_BLOCK_FORM );
				loadBlocks();
			} )
			.catch( ( e ) => setBlockError( e.message ) );
	};

	const editBlock = ( block ) => {
		const [ startDate, startTime ] = block.starts_at_local.split( 'T' );
		const [ endDateRaw, endTime ]  = block.ends_at_local.split( 'T' );
		// An all-day block was stored with an exclusive end at next-day 00:00 —
		// detect that shape and show the inclusive end date in the form.
		const isAllDay = '00:00' === startTime && '00:00' === endTime;
		let endDate = endDateRaw;
		if ( isAllDay ) {
			const d = new Date( endDateRaw + 'T00:00' );
			d.setDate( d.getDate() - 1 );
			endDate = d.getFullYear() + '-' + String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-' + String( d.getDate() ).padStart( 2, '0' );
		}
		setBlockForm( {
			id: block.id,
			label: block.label || '',
			allDay: isAllDay,
			startDate,
			startTime: isAllDay ? '09:00' : startTime,
			endDate,
			endTime: isAllDay ? '17:00' : endTime,
		} );
	};

	const deleteBlock = ( id ) => {
		if ( ! window.confirm( 'Delete this block?' ) ) {
			return;
		}
		coFetch( `booking/blocks/${ id }`, { method: 'DELETE' } )
			.then( loadBlocks )
			.catch( ( e ) => setBlockError( e.message ) );
	};

	// starts_at_local/ends_at_local arrive as "Y-m-d\TH:i" wall-clock strings
	// already converted to the SITE's timezone server-side. They must be
	// formatted as-is, not re-parsed with `new Date()` — that constructor
	// treats a timezone-less string as browser-local time, which mislabels
	// the time for any admin whose browser timezone differs from the site's.
	const formatLocal = ( localStr ) => {
		const [ datePart, timePart ] = localStr.split( 'T' );
		const [ year, month, day ] = datePart.split( '-' ).map( Number );
		const [ hour, minute ] = timePart.split( ':' ).map( Number );
		const asUtc = new Date( Date.UTC( year, month - 1, day, hour, minute ) );
		return asUtc.toLocaleString( undefined, {
			timeZone: 'UTC',
			weekday: 'short',
			month: 'short',
			day: 'numeric',
			hour: 'numeric',
			minute: '2-digit',
		} );
	};

	const totalPages = Math.max( 1, Math.ceil( total / perPage ) );

	return (
		<div className="co-bk">
			<div className="co-bk-header">
				<div>
					<h1 className="co-bk-title">Bookings</h1>
					<p className="co-bk-sub">Calls booked through your booking page.</p>
				</div>
			</div>

			<div className="co-bk-controls">
				<div className="co-bk-tabs">
					{ TABS.map( ( t ) => (
						<button
							key={ t.id || 'all' }
							type="button"
							className={ `co-bk-tab${ 'bookings' === view && tab === t.id ? ' active' : '' }` }
							onClick={ () => { setView( 'bookings' ); setTab( t.id ); setPage( 1 ); } }
						>
							{ t.label }
							<span className="co-bk-tab-count">{ counts[ t.id || 'all' ] || 0 }</span>
						</button>
					) ) }
					<button
						type="button"
						className={ `co-bk-tab${ 'timeoff' === view ? ' active' : '' }` }
						onClick={ () => setView( 'timeoff' ) }
					>
						Time Off
						<span className="co-bk-tab-count">{ blocks.length }</span>
					</button>
				</div>

				{ 'bookings' === view && (
					<div className="co-bk-search-wrap">
						<input
							type="search"
							className="co-bk-search"
							placeholder="Search bookings…"
							value={ search }
							onChange={ ( e ) => setSearch( e.target.value ) }
						/>
					</div>
				) }
			</div>

			{ 'timeoff' === view ? (
				<>
					{ blockError && (
						<p style={ { color: '#B91C1C', fontSize: 14, marginBottom: 16 } }>{ blockError }</p>
					) }

					<div className="co-bk-form-card">
						<p className="co-bk-form-title">{ blockForm.id ? 'Edit blocked time' : 'Add blocked time' }</p>
						<form onSubmit={ submitBlock }>
							<div className="co-bk-form-row">
								<div className="co-bk-field">
									<label htmlFor="co-bk-label">Label (optional)</label>
									<input
										type="text"
										id="co-bk-label"
										value={ blockForm.label }
										onChange={ ( e ) => setBlockForm( ( f ) => ( { ...f, label: e.target.value } ) ) }
										placeholder="e.g. Dentist appointment"
									/>
									<label className="co-bk-allday-label">
										<input
											type="checkbox"
											checked={ blockForm.allDay }
											onChange={ ( e ) => setBlockForm( ( f ) => ( { ...f, allDay: e.target.checked } ) ) }
										/>
										All day
									</label>
								</div>
							</div>

							<div className="co-bk-form-row">
								<div className="co-bk-field">
									<label htmlFor="co-bk-start-date">Start</label>
									<input
										type="date"
										id="co-bk-start-date"
										required
										value={ blockForm.startDate }
										onChange={ ( e ) => setBlockForm( ( f ) => ( { ...f, startDate: e.target.value } ) ) }
									/>
								</div>
								{ ! blockForm.allDay && (
									<div className="co-bk-field">
										<label htmlFor="co-bk-start-time">&nbsp;</label>
										<input
											type="time"
											id="co-bk-start-time"
											required={ ! blockForm.allDay }
											value={ blockForm.startTime }
											onChange={ ( e ) => setBlockForm( ( f ) => ( { ...f, startTime: e.target.value } ) ) }
										/>
									</div>
								) }
								<div className="co-bk-field">
									<label htmlFor="co-bk-end-date">End</label>
									<input
										type="date"
										id="co-bk-end-date"
										required={ ! blockForm.allDay }
										value={ blockForm.endDate }
										onChange={ ( e ) => setBlockForm( ( f ) => ( { ...f, endDate: e.target.value } ) ) }
									/>
								</div>
								{ ! blockForm.allDay && (
									<div className="co-bk-field">
										<label htmlFor="co-bk-end-time">&nbsp;</label>
										<input
											type="time"
											id="co-bk-end-time"
											required={ ! blockForm.allDay }
											value={ blockForm.endTime }
											onChange={ ( e ) => setBlockForm( ( f ) => ( { ...f, endTime: e.target.value } ) ) }
										/>
									</div>
								) }
							</div>

							<div className="co-bk-form-actions">
								<button type="submit" className="co-bk-btn primary">
									{ blockForm.id ? 'Update' : 'Add' }
								</button>
								{ blockForm.id && (
									<button type="button" className="co-bk-cancel-edit" onClick={ () => setBlockForm( EMPTY_BLOCK_FORM ) }>
										Cancel edit
									</button>
								) }
							</div>
						</form>
						<p className="co-bk-hint">Times use your site&rsquo;s timezone setting.</p>
					</div>

					{ blocksLoading ? (
						<p style={ { color: 'var(--co-slate-500)' } }>Loading…</p>
					) : blocks.length === 0 ? (
						<div className="co-bk-empty">
							<p className="co-bk-empty-title">No blocked time</p>
							<p className="co-bk-empty-sub">Appointments, days off, and holidays you add above will show up here.</p>
						</div>
					) : (
						<div className="co-bk-table-wrap">
							<table className="co-bk-table">
								<thead>
									<tr>
										<th>Label</th>
										<th>Source</th>
										<th>Start</th>
										<th>End</th>
										<th>Actions</th>
									</tr>
								</thead>
								<tbody>
									{ blocks.map( ( block ) => {
										const isManual = 'manual' === ( block.source || 'manual' );
										return (
											<tr key={ block.id }>
												<td><div className="co-bk-name">{ block.label || '—' }</div></td>
												<td><SourceBadge source={ block.source || 'manual' } /></td>
												<td>{ formatLocal( block.starts_at_local ) }</td>
												<td>{ formatLocal( block.ends_at_local ) }</td>
												<td>
													{ isManual ? (
														<div className="co-bk-actions">
															<button className="co-bk-btn" onClick={ () => editBlock( block ) }>Edit</button>
															<button className="co-bk-btn danger" onClick={ () => deleteBlock( block.id ) }>Delete</button>
														</div>
													) : (
														<span className="co-bk-hint" title="Managed by the connected calendar — edit or remove the event there instead.">Managed externally</span>
													) }
												</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>
					) }
				</>
			) : (
			<>
			{ error && (
				<p style={ { color: '#B91C1C', fontSize: 14, marginBottom: 16 } }>{ error }</p>
			) }

			{ loading ? (
				<p style={ { color: 'var(--co-slate-500)' } }>Loading…</p>
			) : bookings.length === 0 ? (
				<div className="co-bk-empty">
					<svg className="co-bk-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
						<rect x="3" y="4" width="18" height="18" rx="2" />
						<line x1="16" y1="2" x2="16" y2="6" />
						<line x1="8" y1="2" x2="8" y2="6" />
						<line x1="3" y1="10" x2="21" y2="10" />
					</svg>
					<p className="co-bk-empty-title">No bookings yet</p>
					<p className="co-bk-empty-sub">
						Add the booking form to a page on your site and confirmed calls will show up here.
					</p>
					<span className="co-bk-empty-shortcode">[clientoctopus_booking_form]</span>
				</div>
			) : (
				<div className="co-bk-table-wrap">
					<table className="co-bk-table">
						<thead>
							<tr>
								<th>Name</th>
								<th>Email</th>
								<th>Date &amp; Time</th>
								<th>Status</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							{ bookings.map( ( booking ) => (
								<tr key={ booking.id }>
									<td><div className="co-bk-name">{ booking.name }</div></td>
									<td>{ booking.email || '—' }</td>
									<td>{ formatDateTime( booking.scheduled_at ) }</td>
									<td><StatusBadge status={ booking.status } /></td>
									<td>
										<div className="co-bk-actions">
											{ booking.lead_id && (
												<a
													className="co-bk-btn"
													href={ ( window.clientoctopusData?.adminUrl || '/wp-admin/' ) + 'admin.php?page=clientoctopus-leads' }
												>
													View Lead
												</a>
											) }
											{ 'confirmed' === booking.status && (
												<button className="co-bk-btn danger" onClick={ () => cancelBooking( booking.id ) }>
													Cancel
												</button>
											) }
										</div>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ totalPages > 1 && (
				<div className="co-bk-pager">
					<button
						type="button"
						className="co-bk-page-btn"
						disabled={ page === 1 }
						onClick={ () => setPage( ( p ) => p - 1 ) }
						aria-label="Previous page"
					>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
						</svg>
					</button>

					{ Array.from( { length: totalPages }, ( _, i ) => i + 1 )
						.filter( ( p ) => p === 1 || p === totalPages || Math.abs( p - page ) <= 1 )
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
									className={ `co-bk-page-btn${ page === p ? ' active' : '' }` }
									onClick={ () => setPage( p ) }
								>
									{ p }
								</button>
							)
						)
					}

					<button
						type="button"
						className="co-bk-page-btn"
						disabled={ page === totalPages }
						onClick={ () => setPage( ( p ) => p + 1 ) }
						aria-label="Next page"
					>
						<svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round">
							<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
						</svg>
					</button>
				</div>
			) }
			</>
			) }
		</div>
	);
}
