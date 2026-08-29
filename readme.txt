=== Client Octopus ===
Contributors: codievolt, freemius
Tags: proposal, invoices, client portal, client management, payments
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional proposal, invoice, payment, and client management for WordPress freelancers and agencies.

== Description ==

Client Octopus gives freelancers and agencies everything they need to send proposals and invoices, collect payments, manage projects, and communicate with clients — all from the WordPress admin.

Create professional proposals and standalone invoices, collect e-signatures, automate client reminders, collect payments via Stripe or PayPal, and provide a branded client portal directly on your own website.

= Free Features =

* Unlimited proposals
* Unlimited clients
* Standalone invoices — create, send, and manually mark as paid
* Recurring invoices — set up a weekly, monthly, quarterly, or yearly schedule (manually, or automatically from an accepted proposal) and Client Octopus generates and sends a fresh invoice each cycle
* Package Selector pricing — offer multiple pricing tiers with optional add-ons on a single proposal; the client picks a tier, toggles add-ons, and sees the total update live before accepting
* Proposal templates
* Proposal status tracking (draft, sent, viewed, accepted, declined, expired, completed)
* Client e-signature on proposal acceptance
* Client-facing proposal signing and invoice pages
* Automated proposal reminder emails (not viewed, not accepted, expiring soon)
* REST API access

= Pro Features =

* Everything in Free plus...
* Stripe or PayPal payment collection on proposals
* Stripe or PayPal "Pay Now" button on client-facing invoices (auto-marks paid via webhook)
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

= Why Client Octopus? =

Most freelancers juggle 4–5 tools to run their client workflow — a proposal tool, an invoicing app, a payment link, and email threads to hold it all together. Client Octopus replaces all of them with a single WordPress plugin.

Unlike Proposify, HoneyBook, or Dubsado, your data never leaves your own server. No monthly SaaS subscription for a platform you don't control. Everything lives inside the WordPress site you already own.

== Installation ==

1. Upload the `clientoctopus` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins menu
3. The setup wizard launches automatically
4. Configure your branding and settings
5. Upgrade to Pro or Agency at any time via Client Octopus → Account

== FAQ ==

= Does Client Octopus support payments? =

Yes. On the Pro and Agency plans, you can accept payments on proposals and standalone invoices via either Stripe or PayPal — choose whichever gateway you prefer in Settings. Clients always see a single "Pay Now" button that routes to whichever gateway you've configured. The exception is a proposal set to Recurring billing — it has no direct payment option; billing happens automatically through the recurring invoice created when the client accepts.

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

= How is this different from Proposify or HoneyBook? =

Client Octopus is a self-hosted WordPress plugin, not a SaaS platform. Your proposals, invoices, and client data are stored in your own WordPress database. You pay once per year rather than a monthly SaaS fee, and there are no per-user charges or storage limits imposed by a third party.

= Can I send invoices without a proposal? =

Yes. Standalone invoices are available on all plans. Create an invoice, assign a client, add line items with VAT and discount options, and send it directly. Free plan clients pay via bank transfer; Pro plan clients get a "Pay Now" button (Stripe or PayPal, whichever is configured) on their invoice page.

= Can I set up automatic recurring invoices? =

Yes, on all plans. Create a recurring invoice profile for a client, choose weekly, monthly, quarterly, or yearly billing, and Client Octopus automatically generates and sends a fresh invoice each cycle — the client still pays each one manually via the same Pay Now flow as any other invoice. You can also mark a proposal itself as Recurring — accepting it automatically creates the recurring profile for you, with no manual setup.

== Screenshots ==

1. Client-facing proposal — branded, with pricing breakdown and e-signature acceptance
2. Client portal dashboard — proposals, invoices, projects, and payments at a glance
3. Branded client invoice with Print / Save as PDF
4. Creating a new proposal — guided template picker
5. Client portal payment history — proposal deposits and invoice payments
6. Creating a new invoice — client, line items, discounts, and VAT
7. Project detail — milestones, approvals, and payment status
8. Client portal login — magic link or password sign-in
9. Plan & usage dashboard — feature access and monthly limits
10. Webhooks — connect Zapier, Make, or your own systems

== External Services ==

This plugin connects to the following third-party services:

**Stripe**

Client Octopus uses Stripe to process client payments on proposals and invoices. When a client pays, the plugin creates a Stripe Checkout Session on your configured Stripe account and redirects the client to complete payment on Stripe's hosted page. The client's payment details are entered directly on Stripe's servers and are never stored in WordPress. Your Stripe API keys (publishable and secret) are stored in the WordPress options table and transmitted only to Stripe's API.

- Service: Stripe, Inc.
- Data sent: payment amount, currency, project or invoice description, and client email when a payment session is created. Stripe webhook events (payment completion) are received and verified using your webhook secret.
- Terms of Service: https://stripe.com/legal/ssa
- Privacy Policy: https://stripe.com/privacy

**PayPal**

Client Octopus uses PayPal as an alternative to Stripe for processing client payments on proposals and invoices, if you choose it as your active payment provider. When a client pays, the plugin creates a PayPal order on your configured PayPal account and redirects the client to complete and approve payment on PayPal's hosted page. The client's payment details are entered directly on PayPal's servers and are never stored in WordPress. Your PayPal API credentials (Client ID and Client Secret) are stored in the WordPress options table and transmitted only to PayPal's API.

- Service: PayPal, Inc.
- Data sent: payment amount, currency, and a proposal or invoice reference when an order is created. PayPal webhook events (order approval and payment capture) are received and verified using PayPal's own signature-verification API.
- Terms of Service: https://www.paypal.com/us/legalhub/useragreement-full
- Privacy Policy: https://www.paypal.com/us/legalhub/paypal/privacy-full

**Client Octopus AI Relay**

Client Octopus's AI writing tools route requests through a relay service operated by the plugin author (clientoctopus.com). This relay authenticates your licence and forwards requests to an AI model. AI features are only triggered when you explicitly use an AI writing action in the admin.

- Service: clientoctopus.com (operated by codievolt)
- Data sent (AI writing requests): the text prompt you submit (proposal content or instructions) and your licence key, which authenticates the request and is used to enforce your plan's monthly rate limit. No site URL or admin email is transmitted for AI requests.
- Data sent (licence sync): separately, once per day, the plugin sends your licence key and account email to the same relay to keep your plan status in sync. This happens automatically in the background on Pro/Agency plans and is not tied to any explicit AI action.
- Privacy Policy: https://clientoctopus.clientoctopus.com/privacy-policy/

**Freemius**

Client Octopus uses Freemius to manage plan licensing, activation, and upgrades. When you activate a licence key, the site URL, plugin version, and licence key are sent to Freemius to verify your plan and enable the correct feature set.

- Service: Freemius, Inc.
- Data sent: site URL, plugin version, licence key, and basic activation/deactivation events.
- Terms of Service: https://freemius.com/terms/
- Privacy Policy: https://freemius.com/privacy/

== Changelog ==

= 1.2.0 =

* New: Package Selector pricing mode for proposals — toggle between Flat Pricing and Package Selector when building a proposal; define unlimited pricing tiers (each with its own independent line items) plus optional add-ons. The client picks a tier, toggles any add-ons, and sees the total recalculate live before accepting; their selection is resolved and recorded at acceptance.
* New: Recurring Billing for proposals — toggle a proposal to Recurring billing instead of one-off/deposit pricing, set the frequency, start date, and end condition, and Client Octopus automatically creates a real Recurring Invoice profile the moment the client accepts — fully editable afterward just like a manually-created one. Recurring proposals never take a deposit or direct payment; billing runs exclusively through the generated invoice.
* Improved: The Pricing block in the proposal Content Editor can now be moved up and down like any other section, and renders on the client-facing proposal in the position it's assigned instead of always appearing at the end.
* Improved: The Marketing Campaign proposal template is now available on all plans (previously Pro-only).
* Improved: The proposal expiry date is now a required field when creating or editing a proposal, with no pre-filled placeholder value — previously it could be left blank, which silently excluded that proposal from the "expiring soon" reminder email and the automatic expiry check.
* Fix: Form fields (text, email, number, date, select, textarea) across the admin could render with the wrong border colour or square corners instead of the plugin's intended styling, because WordPress's own default admin styles were unintentionally overriding them in most places.
* Fix: A recurring invoice profile created with a future start date would incorrectly bill immediately instead of waiting for that date.
* Fix: The payment confirmation popup could show a proposal's full total instead of the deposit or remaining-balance amount that was actually about to be charged.
* Fix: Sending or duplicating a proposal could show a JavaScript error immediately afterward, even though the action itself completed successfully.

= 1.1.3 =

* New: Recurring Invoices — set up a profile for a client (weekly, monthly, quarterly, or yearly) and Client Octopus automatically generates and sends a fresh invoice on schedule; clients still pay each one manually via the existing Pay Now flow. Supports its own Payment Terms and Notes & Payment Instructions, matching standalone invoices.
* New: PayPal as an alternate payment provider (Pro & Agency) — choose either Stripe or PayPal as your active gateway in Settings; clients always see a single "Pay Now" button that routes to whichever gateway is configured, on both proposals and standalone invoices.
* New: Payment Provider settings section with PayPal API credentials, Sandbox/Live mode toggle, and webhook configuration, alongside the existing Stripe settings.
* New: "+ Add New Client" button in the Invoices and Recurring Invoices client picker for adding a client without leaving the form.
* New: Pagination on the Proposals, Projects, Invoices, Recurring Invoices, and Clients admin screens.
* Improved: Client search — in the client picker and the main Clients screen — now correctly filters by name, email, or company instead of always showing the full list.
* Improved: Faster plugin activation, particularly on sites that have been through several updates.
* Fix: Webhook "Copy" buttons in Settings could silently fail to copy on non-HTTPS local development sites; now falls back to a compatible copy method.

= 1.1.2 =

* New: Portal Button Colour setting — buttons across the client portal and public proposal/invoice/payment pages can use a dedicated colour distinct from the Brand Colour, with automatically chosen contrast-safe text (falls back to Brand Colour when left unset).
* New: Login Background Image setting — upload a background image for the client portal login screen via the media library; the login card automatically becomes a frosted glass panel with the logo moved inside it for legibility over a photo.
* Improved: Client portal now uses Archivo (matching the admin) across every screen instead of a mixed font set, and the generic template-style coloured left-border accents have been removed from cards, navigation, and headings across the portal and admin in favour of cleaner treatments.
* Improved: Admin Proposals, Invoices, and Projects screens now share consistent filter tabs (with result counts), empty-state design, table layout and styling, and horizontal scrolling on smaller screens instead of each screen having its own inconsistent pattern.
* Improved: Clicking an invoice in the portal's Payment History now opens it in a new tab instead of navigating away from the page.
* Improved: Removed the redundant plan badge and coloured top-border accents from the Plan & Usage screen's usage cards, and added a quick-access "All Invoices" link.
* New: Print / Save as PDF button on the client-facing invoice page.
* Improved: Client invoice page redesigned to match the payment receipt's look — logo moved into a branded header band inside the card, matching card/section styling, and a new footer message.
* Improved: Pricing and line-item tables on proposals, receipts, and invoices now share the same table styling for full visual consistency; invoice totals and card width now match the receipt.
* Fix: Proposal and invoice total amounts, and the proposal header's "Proposal" label, could become unreadable when a tenant's Brand Colour was too light — these now automatically fall back to a readable colour.

= 1.1.1 =

* New: Client portal Invoices tab — authenticated clients can view all their sent, paid, and overdue invoices with status badges, amounts, and a direct link to the invoice page.
* New: Invoice payments in portal Payment History — paid invoices now appear in a dedicated Invoice Payments table alongside proposal payments.
* Fix: Invoice status not updating after Stripe payment — the invoice success page now triggers the paid status write-through immediately on return from Stripe Checkout.
* Fix: Invoice client name showing as "—" in the admin invoices list — the query now JOINs the clients table correctly.
* Fix: Re-send option for sent and overdue invoices — invoices can now be re-sent without being restricted to draft status only.

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
