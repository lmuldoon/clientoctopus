# Client Octopus

**Professional proposal, payment, and client management for WordPress.**

Client Octopus gives freelancers and agencies everything they need to send proposals, collect payments, manage projects, and communicate with clients — all from the WordPress admin.

---

## Features by Plan

| Feature | Free | Pro | Agency |
|---|---|---|---|
| Proposals | Unlimited | Unlimited | Unlimited |
| Clients | ✓ | ✓ | ✓ |
| Client Portal | — | ✓ | ✓ |
| Stripe Payments | — | ✓ | ✓ |
| AI Writing Tools | — | 100 req/mo | 500 req/mo |
| Webhooks | — | ✓ | ✓ |
| Analytics | — | ✓ | ✓ |
| Projects & Milestones | — | — | ✓ |
| Team Members | 1 | 1 | 5 |
| Messaging & Files | — | — | ✓ |
| File Storage | — | — | 1 GB |

---

## Requirements

- **PHP** 8.0+
- **WordPress** 6.0+
- **Stripe account** (for payments, Pro/Agency)

---

## Installation

1. Upload the `clientoctopus` folder to `wp-content/plugins/` and activate the plugin.
2. The setup wizard launches automatically on first activation.
3. Complete the wizard: connect Stripe, add your brand details.
4. Activate your Pro or Agency licence via **Client Octopus → Account** (powered by Freemius). Your plan upgrades automatically — no key entry required.

---

## Licensing

Client Octopus uses [Freemius](https://freemius.com) for licence management.

- Purchase a Pro or Agency licence at [clientoctopus.com](https://clientoctopus.com).
- Activate the licence via **Client Octopus → Account** in the WordPress admin.
- Your plan tier updates immediately — payments, portal, AI, and other Pro/Agency features unlock without any manual key entry.
- Downgrading or cancelling reverts your site to the Free tier automatically.

---

## Proposals

Create professional proposals from the **Proposals** admin page. Choose a template, add your line items and client details, then send directly from WordPress. Clients receive a branded email with a unique link — no login needed to view or respond.

**Client actions on a proposal:**
- Accept (triggers payment request if enabled)
- Decline (with optional reason)
- Request revisions

**Proposal statuses:** `draft → sent → viewed → accepted / declined / revision_requested → completed`

**Payments on proposals (Pro/Agency):**
Set a deposit percentage to collect upfront, with the balance charged when the project completes. Or require full payment at acceptance. Client Octopus creates Stripe Checkout sessions automatically — you never handle card details.

---

## Client Portal (Pro/Agency)

Accepted clients get access to a branded portal at `/clientoctopus/` on your site. They log in via magic link (emailed automatically on acceptance) or a password they set themselves.

**Clients can:**
- View all their proposals and payment history
- Download payment receipts
- See project milestones and approve submitted work (Agency)
- Download project files (Agency)
- Message the agency team (Agency)

---

## Projects & Milestones (Agency)

When a proposal is accepted, a project is created automatically. Use the **Projects** admin page to manage delivery:

- Add milestones, set due dates, and track status
- Submit milestones to the client for approval via the portal
- Upload deliverable files for client download
- Message the client in a threaded conversation
- Send approval requests for sign-off on specific items
- Reorder milestones by drag and drop

Projects lock automatically once marked complete and fully paid.

---

## AI Writing Tools (Pro/Agency)

In the proposal editor, highlight any text and choose an AI action:

| Action | What it does |
|---|---|
| **Improve** | Rewrites for clarity and professionalism |
| **Shorten** | Trims without losing key points |
| **Persuasive** | Makes the copy more compelling |
| **Generate** | Writes a new section from a brief |

AI requests are proxied through the Client Octopus relay server — your OpenAI costs are included in the licence. Monthly limits: 100 requests (Pro), 500 requests (Agency). The counter resets on the 1st of each month.

---

## Webhooks (Pro/Agency)

Connect Client Octopus to Zapier, Make, or any other automation tool using outgoing webhooks.

**Available events:**

| Event | Fires when |
|---|---|
| `proposal.sent` | A proposal is emailed to a client |
| `proposal.accepted` | A client accepts a proposal |
| `proposal.declined` | A client declines a proposal |
| `proposal.revision_requested` | A client requests changes |
| `payment.completed` | A Stripe payment is received |
| `project.created` | A project is created from an accepted proposal |
| `project.completed` | A project is marked complete |

**Setting up a webhook:**

1. Go to **Client Octopus → Webhooks**.
2. Click **Add Webhook**, enter your endpoint URL, and select the events to subscribe to.
3. Save. A secret key is generated automatically.

**Payload format:**

```json
{
  "event": "proposal.accepted",
  "timestamp": "2026-05-07T14:32:00+00:00",
  "data": { ... }
}
```

Every request includes two headers for verification:

```
X-Client Octopus-Event: proposal.accepted
X-Client Octopus-Signature: sha256=<hmac>
```

Verify the signature using your webhook's secret key and `hash_hmac('sha256', $raw_body, $secret)`. The last three delivery attempts (status code, success/fail, timestamp) are visible in the Webhooks admin page.

**Connecting to Zapier:**
1. In Zapier, create a new Zap with trigger **Webhooks by Zapier → Catch Hook**.
2. Copy the Zapier endpoint URL into Client Octopus → Webhooks.
3. Subscribe to the events you want to act on.
4. Test the webhook from the Client Octopus admin to confirm delivery.

---

## Analytics (Pro/Agency)

**Client Octopus → Analytics** shows revenue KPIs, a revenue-over-time chart, proposal performance breakdown (sent / viewed / accepted / declined), and a live activity feed. Export to CSV for any date range up to one year.

---

## Testimonial Emails

Client Octopus can automatically send a review request email when a project is completed and fully paid. Configure the message, review link (Google, Trustpilot, etc.), and button label under **Settings**.

---

## Team Members (Agency)

Invite team members under **Client Octopus → Team**. Assign one of three roles:

| Role | Access |
|---|---|
| **Admin** | Full access including settings and team management |
| **Editor** | Create and edit proposals and projects |
| **Viewer** | Read-only |

Up to 5 seats on the Agency plan.

---

## REST API

All endpoints are under `/wp-json/clientoctopus/v1/` and require a logged-in WordPress user (`X-WP-Nonce` header) unless noted.

### Key endpoints

| Method | Route | Description |
|---|---|---|
| `GET` | `/proposals` | List proposals |
| `POST` | `/proposals/create` | Create a proposal |
| `POST` | `/proposals/{id}/send` | Send to client |
| `POST` | `/proposals/{id}/duplicate` | Clone a proposal |
| `GET` | `/projects` | List projects (Agency) |
| `GET` | `/projects/{id}` | Project + milestones |
| `POST` | `/ai/process` | AI text action (improve / shorten / persuasive / generate) |
| `GET` | `/analytics/overview` | Revenue KPIs + chart data |
| `GET` | `/webhooks` | List configured webhooks |
| `POST` | `/webhooks` | Register a webhook |
| `POST` | `/webhooks/{id}/test` | Send a test ping |
| `GET` | `/user/usage` | Live usage stats (AI, proposals, storage, team) |

Client-facing portal routes (token or cookie auth, no WP login required):

| Method | Route | Description |
|---|---|---|
| `GET` | `/client/proposals/{token}` | View a proposal |
| `POST` | `/client/proposals/{token}/accept` | Accept |
| `POST` | `/portal/send-magic-link` | Request magic link |
| `POST` | `/portal/verify` | Verify token and log in |
| `GET` | `/portal/projects/{id}` | Client project view |

---

## Architecture

### Permission engine

All feature access routes through a single function:

```php
clientoctopus_can_user( int $user_id, string $feature, array $options = [] ): bool|string
```

Never add `if ($plan === 'pro')` checks in module code — always call `clientoctopus_can_user()` or `ClientOctopus_Entitlements::can_user()`.

### Feature slugs

| Slug | Returns |
|---|---|
| `create_proposal` | `bool` |
| `use_payments` | `bool` |
| `use_portal` | `false` \| `'basic'` \| `'full'` |
| `use_ai` | `bool` |
| `use_webhooks` | `bool` |
| `use_projects` | `bool` |
| `use_messaging` | `bool` |
| `use_files` | `bool` |
| `team_access` | `bool` |

### Database tables

| Table | Purpose |
|---|---|
| `clientoctopus_user_meta` | Plan, usage counters, billing metadata |
| `clientoctopus_clients` | Client records |
| `clientoctopus_proposals` | Proposal content and status |
| `clientoctopus_payments` | Stripe payment records |
| `clientoctopus_projects` | Post-acceptance project tracking |
| `clientoctopus_milestones` | Per-project milestones |
| `clientoctopus_messages` | Client ↔ agency message threads |
| `clientoctopus_files` | Project file uploads |
| `clientoctopus_approvals` | Client approval requests |
| `clientoctopus_webhooks` | Registered webhook endpoints |
| `clientoctopus_webhook_logs` | Delivery history (last 3 per webhook) |
| `clientoctopus_events` | Analytics event log |
| `clientoctopus_ai_usage_logs` | AI request audit trail |
| `clientoctopus_team_members` | Team member roles |

---

## Local Development

```bash
npm install
npm run build
```

The plugin is designed for use with [Local by Flywheel](https://localwp.com). After activating the plugin locally, the setup wizard launches automatically.

To watch for JS changes during development:

```bash
npm run start
```

---

## Changelog

### 0.1.2
- Plan tier now syncs automatically from Freemius licence activation/deactivation
- Licence key populated automatically from Freemius; manual key entry removed
- Upgrade buttons now link to Freemius pricing page
- Setup wizard reduced to 4 steps (licence step removed)
- Mark as Complete button restricted to accepted proposals only
- AI relay: atomic quota reservation (race condition fix), token usage tracking, OpenAI retry logic, strict webhook signature enforcement

### 0.1.1
- Freemius SDK integration (free/paid auto-deactivation wrapper)
- Fixed Pro plan proposal completion blocked by inverted status transition check
- Fixed testimonial email not sending when payment arrives after project completion
- Fixed portal magic link sent on every payment (now only on first acceptance)
- AI relay event names updated to Freemius dot-notation format

### 0.1.0
- Initial release: entitlements engine, proposals, payments, client portal, projects, analytics, webhooks, AI tools, team management
