/**
 * TeamApp
 *
 * Team seat management for Client Octopus Agency accounts.
 * Displays seat usage, member list, and an invite form.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';

// ── Styles ─────────────────────────────────────────────────────────────────────

const TEAM_CSS = `
@import url('https://fonts.googleapis.com/css2?family=Archivo:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400&display=swap');

:root {
  --co-navy:       #0F172A;
  --co-indigo:     #6366F1;
  --co-indigo-lt:  #818CF8;
  --co-indigo-bg:  #EEF2FF;
  --co-emerald:    #10B981;
  --co-emerald-bg: #ECFDF5;
  --co-amber:      #F59E0B;
  --co-red:        #EF4444;
  --co-red-bg:     #FEF2F2;
  --co-slate-50:   #F8FAFC;
  --co-slate-100:  #F1F5F9;
  --co-slate-200:  #E2E8F0;
  --co-slate-300:  #CBD5E1;
  --co-slate-400:  #94A3B8;
  --co-slate-500:  #64748B;
  --co-slate-600:  #475569;
  --co-white:      #FFFFFF;
  --co-radius:     12px;
  --co-radius-sm:  8px;
  --co-shadow:     0 1px 3px rgba(15,23,42,.06), 0 4px 16px rgba(15,23,42,.08);
  --co-shadow-lg:  0 4px 6px rgba(15,23,42,.05), 0 10px 40px rgba(15,23,42,.12);
  --co-font:       'Archivo', -apple-system, BlinkMacSystemFont, sans-serif;
}

.co-tm * { box-sizing: border-box; }

.co-tm {
  font-family: var(--co-font);
  min-height: 100vh;
  padding: 32px 28px 64px;
  color: var(--co-navy);
  -webkit-font-smoothing: antialiased;
}

/* Header */
.co-tm-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 28px;
}
.co-tm-title {
  font-size: 28px;
  font-weight: 800;
  letter-spacing: -0.5px;
  color: var(--co-navy);
  margin: 0;
  line-height: 1;
}
.co-tm-subtitle {
  font-size: 14px;
  color: var(--co-slate-400);
  margin:6px 0 0;
}

/* Cards */
.co-tm-card {
  background: var(--co-white);
  border-radius: var(--co-radius);
  box-shadow: var(--co-shadow);
  border: 1px solid var(--co-slate-200);
  padding: 24px;
  margin-bottom: 20px;
}
.co-tm-card-title {
  font-size: 13px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--co-slate-400);
  margin: 0 0 16px;
}

/* Seat usage bar */
.co-tm-usage {
  display: flex;
  align-items: center;
  gap: 16px;
}
.co-tm-usage-label {
  font-size: 15px;
  font-weight: 600;
  color: var(--co-navy);
  white-space: nowrap;
}
.co-tm-bar-wrap {
  flex: 1;
  background: var(--co-slate-100);
  border-radius: 99px;
  height: 8px;
  overflow: hidden;
}
.co-tm-bar-fill {
  height: 100%;
  border-radius: 99px;
  background: var(--co-indigo);
  transition: width .4s ease;
}
.co-tm-bar-fill.full { background: var(--co-red); }
.co-tm-usage-count {
  font-size: 13px;
  color: var(--co-slate-500);
  white-space: nowrap;
}

/* Member table */
.co-tm-table {
  width: 100%;
  border-collapse: collapse;
}
.co-tm-th {
  text-align: left;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--co-slate-400);
  padding: 0 12px 12px;
  border-bottom: 1px solid var(--co-slate-200);
}
.co-tm-th:first-child { padding-left: 0; }
.co-tm-td {
  padding: 14px 12px;
  font-size: 14px;
  color: var(--co-navy);
  border-bottom: 1px solid var(--co-slate-100);
  vertical-align: middle;
}
.co-tm-td:first-child { padding-left: 0; }
.co-tm-tr:last-child .co-tm-td { border-bottom: none; }

/* Avatar */
.co-tm-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--co-indigo-bg);
  color: var(--co-indigo);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  font-weight: 700;
  flex-shrink: 0;
}
.co-tm-member-info {
  display: flex;
  align-items: center;
  gap: 12px;
}
.co-tm-member-name {
  font-weight: 600;
  font-size: 14px;
  color: var(--co-navy);
}
.co-tm-member-email {
  font-size: 12px;
  color: var(--co-slate-500);
}

/* Role badge */
.co-tm-badge {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 99px;
  font-size: 12px;
  font-weight: 600;
  text-transform: capitalize;
}
.co-tm-badge-admin   { background: var(--co-indigo-bg); color: var(--co-indigo); }
.co-tm-badge-editor  { background: var(--co-emerald-bg); color: var(--co-emerald); }
.co-tm-badge-viewer  { background: var(--co-slate-100); color: var(--co-slate-600); }

/* Pending badge */
.co-tm-pending {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: var(--co-amber);
  font-weight: 500;
}

/* Remove button */
.co-tm-remove-btn {
  background: none;
  border: 1px solid var(--co-slate-200);
  border-radius: var(--co-radius-sm);
  color: var(--co-slate-500);
  font-size: 13px;
  font-weight: 500;
  padding: 6px 12px;
  cursor: pointer;
  transition: all .15s;
  font-family: var(--co-font);
}
.co-tm-remove-btn:hover {
  border-color: var(--co-red);
  color: var(--co-red);
  background: var(--co-red-bg);
}
.co-tm-remove-btn:disabled { opacity: .4; cursor: not-allowed; }

/* Empty state */
.co-tm-empty {
  text-align: center;
  padding: 48px 24px;
  color: var(--co-slate-400);
}
.co-tm-empty-icon {
  font-size: 40px;
  margin-bottom: 12px;
}
.co-tm-empty p {
  margin: 0;
  font-size: 14px;
}

/* Invite form */
.co-tm-invite-form {
  display: grid;
  grid-template-columns: 1fr 1fr auto auto;
  gap: 12px;
  align-items: end;
}
@media (max-width: 768px) {
  .co-tm-invite-form { grid-template-columns: 1fr; }
}
.co-tm-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.co-tm-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--co-slate-600);
}
.co-tm-input, .co-tm-select {
  height: 40px;
  border: 1.5px solid var(--co-slate-200);
  border-radius: var(--co-radius-sm);
  padding: 0 12px;
  font-size: 14px;
  font-family: var(--co-font);
  color: var(--co-navy);
  background: var(--co-white);
  outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.co-tm-input:focus, .co-tm-select:focus {
  border-color: var(--co-indigo);
  box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}

/* Invite button */
.co-tm-invite-btn {
  height: 40px;
  padding: 0 20px;
  background: var(--co-indigo);
  color: var(--co-white);
  border: none;
  border-radius: var(--co-radius-sm);
  font-size: 14px;
  font-weight: 600;
  font-family: var(--co-font);
  cursor: pointer;
  white-space: nowrap;
  transition: background .15s, opacity .15s;
}
.co-tm-invite-btn:hover { background: var(--co-indigo-lt); }
.co-tm-invite-btn:disabled { opacity: .5; cursor: not-allowed; }

/* Upgrade lock */
.co-tm-upgrade {
  display: flex;
  align-items: center;
  gap: 16px;
  background: var(--co-indigo-bg);
  border: 1px solid rgba(99,102,241,.2);
  border-radius: var(--co-radius);
  padding: 20px 24px;
}
.co-tm-upgrade-text {
  flex: 1;
}
.co-tm-upgrade-text strong {
  display: block;
  font-size: 15px;
  font-weight: 700;
  color: var(--co-indigo);
  margin-bottom: 4px;
}
.co-tm-upgrade-text span {
  font-size: 13px;
  color: var(--co-slate-600);
}
.co-tm-upgrade-btn {
  flex-shrink: 0;
  padding: 10px 20px;
  background: var(--co-indigo);
  color: var(--co-white);
  border: none;
  border-radius: var(--co-radius-sm);
  font-size: 14px;
  font-weight: 600;
  font-family: var(--co-font);
  cursor: pointer;
  transition: background .15s;
}
.co-tm-upgrade-btn:hover { background: var(--co-indigo-lt); }

/* Notice */
.co-tm-notice {
  padding: 12px 16px;
  border-radius: var(--co-radius-sm);
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 16px;
}
.co-tm-notice-error   { background: var(--co-red-bg); color: var(--co-red); }
.co-tm-notice-success { background: var(--co-emerald-bg); color: var(--co-emerald); }

/* Spinner */
@keyframes co-spin { to { transform: rotate(360deg); } }
.co-tm-spinner {
  width: 16px; height: 16px;
  border: 2px solid rgba(255,255,255,.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: co-spin .7s linear infinite;
  display: inline-block;
  vertical-align: middle;
}
`;

function injectStyles() {
	if ( document.getElementById( 'co-team-css' ) ) return;
	const el = document.createElement( 'style' );
	el.id = 'co-team-css';
	el.textContent = TEAM_CSS;
	document.head.appendChild( el );
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function coFetch( endpoint, options = {} ) {
	const { apiUrl, nonce } = window.coData || {};
	return fetch( apiUrl + endpoint, {
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
			...( options.headers || {} ),
		},
		...options,
	} ).then( r => r.json() );
}

function avatarInitial( name = '' ) {
	const parts = name.trim().split( ' ' );
	return parts.length > 1
		? ( parts[ 0 ][ 0 ] + parts[ parts.length - 1 ][ 0 ] ).toUpperCase()
		: ( name[ 0 ] || '?' ).toUpperCase();
}

const ERROR_MESSAGES = {
	seat_limit_reached: 'You have used all available seats. Remove a member or upgrade to add more.',
	invalid_email:      'Please enter a valid email address.',
	cannot_invite_self: 'You cannot invite yourself as a team member.',
	already_a_member:   'This person is already a member of your team.',
};

// ── Component ──────────────────────────────────────────────────────────────────

export default function TeamApp() {
	const { userPlan, teamSeats: initialSeats, teamLimit: initialLimit } = window.coData || {};

	const [ members,    setMembers    ] = useState( [] );
	const [ seatsUsed,  setSeatsUsed  ] = useState( initialSeats || 1 );
	const [ seatsLimit, setSeatsLimit ] = useState( initialLimit || 1 );
	const [ loading,    setLoading    ] = useState( true );
	const [ removing,   setRemoving   ] = useState( null );
	const [ notice,     setNotice     ] = useState( null );

	const [ form, setForm ] = useState( { name: '', email: '', role: 'editor' } );
	const [ inviting, setInviting ] = useState( false );

	useEffect( () => { injectStyles(); }, [] );

	const loadMembers = useCallback( async () => {
		setLoading( true );
		try {
			const data = await coFetch( 'team/members' );
			if ( data.members ) {
				setMembers( data.members );
				setSeatsUsed( data.seats_used );
				setSeatsLimit( data.seats_limit );
			}
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => { loadMembers(); }, [ loadMembers ] );

	const showNotice = ( type, message ) => {
		setNotice( { type, message } );
		setTimeout( () => setNotice( null ), 5000 );
	};

	const handleInvite = async ( e ) => {
		e.preventDefault();
		if ( ! form.email || inviting ) return;

		setInviting( true );
		setNotice( null );
		try {
			const data = await coFetch( 'team/invite', {
				method: 'POST',
				body: JSON.stringify( form ),
			} );
			if ( data.success ) {
				setForm( { name: '', email: '', role: 'editor' } );
				showNotice( 'success', 'Invite sent! The team member will receive an email shortly.' );
				await loadMembers();
			} else {
				const msg = ERROR_MESSAGES[ data.error ] || data.error || 'Something went wrong.';
				showNotice( 'error', msg );
			}
		} catch {
			showNotice( 'error', 'Network error. Please try again.' );
		} finally {
			setInviting( false );
		}
	};

	const handleRemove = async ( memberId ) => {
		if ( removing ) return;
		setRemoving( memberId );
		try {
			const data = await coFetch( `team/members/${ memberId }`, { method: 'DELETE' } );
			if ( data.success ) {
				showNotice( 'success', 'Team member removed.' );
				await loadMembers();
			} else {
				showNotice( 'error', 'Could not remove member. Please try again.' );
			}
		} catch {
			showNotice( 'error', 'Network error. Please try again.' );
		} finally {
			setRemoving( null );
		}
	};

	const isAgency    = userPlan === 'agency';
	const pct         = seatsLimit > 0 ? Math.min( ( seatsUsed / seatsLimit ) * 100, 100 ) : 100;
	const atLimit     = seatsUsed >= seatsLimit;
	const canInvite   = isAgency && ! atLimit && ! inviting;

	return (
		<div className="co-tm">
			{ /* Header */ }
			<div className="co-tm-header">
				<div>
					<h1 className="co-tm-title">Team</h1>
					<p className="co-tm-subtitle">Manage who has access to your Client Octopus account.</p>
				</div>
			</div>

			{ /* Seat usage */ }
			<div className="co-tm-card">
				<p className="co-tm-card-title">Seat Usage</p>
				<div className="co-tm-usage">
					<span className="co-tm-usage-label">
						{ isAgency ? 'Agency Plan' : userPlan === 'pro' ? 'Pro Plan' : 'Free Plan' }
					</span>
					<div className="co-tm-bar-wrap">
						<div
							className={ `co-tm-bar-fill${ atLimit ? ' full' : '' }` }
							style={ { width: `${ pct }%` } }
						/>
					</div>
					<span className="co-tm-usage-count">{ seatsUsed } / { seatsLimit } seat{ seatsLimit !== 1 ? 's' : '' }</span>
				</div>
			</div>

			{ /* Members list */ }
			<div className="co-tm-card">
				<p className="co-tm-card-title">Members</p>

				{ notice && (
					<div className={ `co-tm-notice co-tm-notice-${ notice.type }` }>
						{ notice.message }
					</div>
				) }

				{ loading ? (
					<div className="co-tm-empty">
						<div style={ { margin: '0 auto 12px', width: 24, height: 24, border: '2px solid #E2E8F0', borderTopColor: '#6366F1', borderRadius: '50%', animation: 'co-spin .7s linear infinite' } } />
						<p>Loading team…</p>
					</div>
				) : members.length === 0 ? (
					<div className="co-tm-empty">
						<div className="co-tm-empty-icon">👥</div>
						<p>No team members yet.</p>
						{ isAgency && <p style={ { marginTop: 4 } }>Invite someone below to get started.</p> }
					</div>
				) : (
					<table className="co-tm-table">
						<thead>
							<tr>
								<th className="co-tm-th">Member</th>
								<th className="co-tm-th">Role</th>
								<th className="co-tm-th">Status</th>
								<th className="co-tm-th" style={ { textAlign: 'right' } }></th>
							</tr>
						</thead>
						<tbody>
							{ members.map( m => (
								<tr key={ m.id } className="co-tm-tr">
									<td className="co-tm-td">
										<div className="co-tm-member-info">
											<div className="co-tm-avatar">{ avatarInitial( m.display_name ) }</div>
											<div>
												<div className="co-tm-member-name">{ m.display_name }</div>
												<div className="co-tm-member-email">{ m.email }</div>
											</div>
										</div>
									</td>
									<td className="co-tm-td">
										<span className={ `co-tm-badge co-tm-badge-${ m.role }` }>{ m.role }</span>
									</td>
									<td className="co-tm-td">
										{ m.accepted_at ? (
											<span style={ { color: '#10B981', fontSize: 13, fontWeight: 500 } }>Active</span>
										) : (
											<span className="co-tm-pending">
												<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
												Invite pending
											</span>
										) }
									</td>
									<td className="co-tm-td" style={ { textAlign: 'right' } }>
										{ isAgency && (
											<button
												className="co-tm-remove-btn"
												onClick={ () => handleRemove( m.id ) }
												disabled={ removing === m.id }
											>
												{ removing === m.id ? 'Removing…' : 'Remove' }
											</button>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</div>

			{ /* Invite form / upgrade prompt */ }
			{ isAgency ? (
				<div className="co-tm-card">
					<p className="co-tm-card-title">Invite a Team Member</p>
					{ atLimit && (
						<div className="co-tm-notice co-tm-notice-error" style={ { marginBottom: 16 } }>
							You've reached your seat limit ({ seatsLimit } / { seatsLimit }). Remove a member to invite someone new.
						</div>
					) }
					<form className="co-tm-invite-form" onSubmit={ handleInvite }>
						<div className="co-tm-field">
							<label className="co-tm-label">Name</label>
							<input
								className="co-tm-input"
								type="text"
								placeholder="Jane Smith"
								value={ form.name }
								onChange={ e => setForm( f => ( { ...f, name: e.target.value } ) ) }
								disabled={ ! canInvite }
							/>
						</div>
						<div className="co-tm-field">
							<label className="co-tm-label">Email</label>
							<input
								className="co-tm-input"
								type="email"
								placeholder="jane@agency.com"
								value={ form.email }
								onChange={ e => setForm( f => ( { ...f, email: e.target.value } ) ) }
								disabled={ ! canInvite }
								required
							/>
						</div>
						<div className="co-tm-field">
							<label className="co-tm-label">Role</label>
							<select
								className="co-tm-select"
								value={ form.role }
								onChange={ e => setForm( f => ( { ...f, role: e.target.value } ) ) }
								disabled={ ! canInvite }
							>
								<option value="admin">Admin</option>
								<option value="editor">Editor</option>
								<option value="viewer">Viewer</option>
							</select>
						</div>
						<button
							className="co-tm-invite-btn"
							type="submit"
							disabled={ ! canInvite || ! form.email }
						>
							{ inviting ? <span className="co-tm-spinner" /> : 'Send Invite' }
						</button>
					</form>
					<p style={ { marginTop: 12, marginBottom: 0, fontSize: 12, color: '#94A3B8' } }>
						<strong>Admin</strong> — full access &nbsp;·&nbsp; <strong>Editor</strong> — create &amp; edit proposals and projects &nbsp;·&nbsp; <strong>Viewer</strong> — read-only
					</p>
				</div>
			) : (
				<div className="co-tm-upgrade">
					<div className="co-tm-upgrade-text">
						<strong>Team seats are an Agency feature</strong>
						<span>Upgrade to Agency to invite up to 5 team members with role-based access.</span>
					</div>
					<button
						className="co-tm-upgrade-btn"
						onClick={ () => window.location.href = window.coData?.adminUrl + 'admin.php?page=clientoctopus' }
					>
						Upgrade to Agency
					</button>
				</div>
			) }
		</div>
	);
}
