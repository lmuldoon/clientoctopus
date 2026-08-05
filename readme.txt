=== Client Octopus ===
Contributors: codievolt
Tags: proposal, invoices, client portal, client management, crm
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional proposal, invoice, payment, and client management for WordPress freelancers and agencies.

== Description ==

Client Octopus gives freelancers and agencies everything they need to send proposals and invoices, collect payments, manage projects, and communicate with clients — all from the WordPress admin.

Create professional proposals and standalone invoices, collect e-signatures, automate client reminders, collect Stripe payments, and provide a branded client portal directly on your own website.

= Free Features =

* Unlimited proposals
* Unlimited clients
* Standalone invoices — create, send, and manually mark as paid
* Proposal templates
* Proposal status tracking (draft, sent, accepted, declined)
* Client e-signature on proposal acceptance
* Client-facing proposal signing and invoice pages
* Automated proposal reminder emails (not viewed, not accepted, expiring soon)
* REST API access
* Outbound webhooks

= Pro Features =

* Everything in Free plus...
* Stripe payment collection on proposals
* Stripe "Pay Now" button on client-facing invoices (auto-marks paid via webhook)
* Client portal with magic-link login
* AI writing tools for proposal content
* Revenue analytics dashboard
* Outbound webhooks — 12+ events covering the full proposal and invoice lifecycle (Zapier, Make, Slack, and 7,000+ tools)

= Agency Features =

* Everything in Pro plus...
* Projects & milestones
* Up to 5 team members with role-based access
* Client messaging
* File uploads & downloads
* Approval workflows

Client Octopus is designed specifically for WordPress freelancers and agencies who want to manage proposals, invoices, and client delivery without relying on external SaaS platforms.

== Installation ==

1. Upload the `clientoctopus` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins menu
3. The setup wizard launches automatically
4. Configure your branding and settings
5. Upgrade to Pro or Agency at any time via Client Octopus → Account

== FAQ ==

= Does Client Octopus support Stripe payments? =

Yes. Stripe payments are available on the Pro and Agency plans for both proposals and standalone invoices.

= Can clients access a portal? =

Yes. The Pro and Agency plans include a branded client portal with magic-link login.

= Does Client Octopus support teams? =

Yes. The Agency plan supports up to 5 team members with role-based access.

= Is multisite supported? =

No. Client Octopus licences are single-site only.

= Does Client Octopus require a third-party SaaS? =

No. Client Octopus runs directly inside WordPress.

= Are AI costs included? =

Yes. AI requests are included with Pro and Agency plans.

= Can clients e-sign proposals? =

Yes. When a client accepts a proposal, they are prompted to type their full legal name and confirm a checkbox in a signing modal. The signed certificate is saved to the proposal record and visible in the admin.

= Can I send invoices without a proposal? =

Yes. Standalone invoices are available on all plans. Create an invoice, assign a client, add line items with VAT and discount options, and send it directly. Free plan clients pay via bank transfer; Pro plan clients get a "Pay Now" Stripe button on their invoice page.

== Screenshots ==

1. Client Octopus plan and usage dashboard
2. Client Octopus proposal builder templates
3. Client Octopus proposal builder pricing setup
4. Client Octopus project milestones and approvals
5. Client Octopus client portal projects interface

== External Services ==

This plugin connects to the following third-party services:

**Stripe**

Client Octopus uses Stripe to process client payments on proposals and invoices. When a client pays, the plugin creates a Stripe Checkout Session on your configured Stripe account and redirects the client to complete payment on Stripe's hosted page. The client's payment details are entered directly on Stripe's servers and are never stored in WordPress. Your Stripe API keys (publishable and secret) are stored in the WordPress options table and transmitted only to Stripe's API.

- Service: Stripe, Inc.
- Data sent: payment amount, currency, project or invoice description, and client email when a payment session is created. Stripe webhook events (payment completion) are received and verified using your webhook secret.
- Terms of Service: https://stripe.com/legal/ssa
- Privacy Policy: https://stripe.com/privacy

**Client Octopus AI Relay**

Client Octopus's AI writing tools route requests through a relay service operated by the plugin author (clientoctopus.com). This relay authenticates your licence and forwards requests to an AI model. AI features are only triggered when you explicitly use an AI writing action in the admin.

- Service: clientoctopus.com (operated by codievolt)
- Data sent: the text prompt you submit (proposal content or instructions) and your licence key, which authenticates the request and is used to enforce your plan's monthly rate limit. No site URL or admin email is transmitted to this relay.
- Privacy Policy: https://clientoctopus.clientoctopus.com/privacy-policy/

**Freemius**

Client Octopus uses Freemius to manage plan licensing, activation, and upgrades. When you activate a licence key, the site URL, plugin version, and licence key are sent to Freemius to verify your plan and enable the correct feature set.

- Service: Freemius, Inc.
- Data sent: site URL, plugin version, licence key, and basic activation/deactivation events.
- Terms of Service: https://freemius.com/terms/
- Privacy Policy: https://freemius.com/privacy/

== Changelog ==

= 1.1.0 =

* New: Standalone invoices — create auto-numbered invoices (INV-0001…), assign clients, add line items, discounts, and VAT, then send to clients via email. Client-facing invoice page supports browser printing to a clean A4 layout.
* New: Stripe "Pay Now" button on client-facing invoices (Pro). Stripe Checkout is created on demand; invoice auto-marks as paid via webhook.
* New: E-signature on proposal acceptance — clients type their full legal name and confirm a checkbox in a signing modal; the signed certificate is recorded on the proposal and visible in the admin.
* New: Automated proposal reminder emails — three configurable triggers: proposal not viewed, not accepted, and expiring soon. Cron-based, runs daily.
* New: Expanded outbound webhook events — 12 events now covering the full proposal and invoice lifecycle, including invoice.sent, invoice.paid, invoice.overdue, and invoice.cancelled.

= 1.0.1 =

* Fix: Onboarding wizard failed on servers that redirect URLs to add a trailing slash, causing POST requests to be converted to GET requests.

= 1.0.0 =

* Initial public release.
