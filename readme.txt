=== Client Octopus ===
Contributors: codievolt, freemius
Tags: proposals, invoices, client portal, booking, payments
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Capture leads, book calls, send proposals and invoices, collect payments, and manage projects — all from WordPress.

== Description ==

Client Octopus is an all-in-one client workflow plugin for WordPress freelancers and agencies.

Capture leads from your website, let prospects book a call with your calendar synced automatically, create professional proposals with e-signatures and flexible pricing, send standalone or recurring invoices, collect payments through Stripe or PayPal, and manage projects and client delivery through a branded client portal.

Instead of juggling separate tools for lead capture, scheduling, proposals, invoicing, payments, and client delivery, Client Octopus brings the whole workflow together inside the WordPress site you already own.

== Everything you need to manage client work ==

Client Octopus connects the key stages of your client workflow in one WordPress plugin.

* Capture leads directly from your website
* Let prospects book calls, with your calendar synced automatically
* Send professional proposals with e-signatures
* Offer packages, pricing tiers, and optional add-ons
* Create standalone and recurring invoices
* Accept payments through Stripe or PayPal
* Give clients a branded portal
* Manage projects, milestones, and approvals
* Share files and communicate with clients
* Automate reminders and recurring payments
* Connect to thousands of other tools with webhooks

= Free Features =

* Lead Capture — embed a lead capture form on any page via shortcode to collect inquiries directly into Client Octopus; customise which fields show and are required, add a consent line, and track, contact, archive, or convert leads to clients from a dedicated Leads admin screen. Includes optional Cloudflare Turnstile CAPTCHA, configurable submission rate limits, and an automatic reply email
* Unlimited clients
* Unlimited proposals
* Proposal templates
* Package Selector pricing — offer multiple pricing tiers with optional add-ons on a single proposal; the client picks a tier, toggles add-ons, and sees the total update live before accepting
* Client e-signature on proposal acceptance
* Proposal status tracking (draft, sent, viewed, accepted, declined, expired, completed)
* Client-facing proposal signing and invoice pages
* Standalone invoices — create, send, and manually mark as paid
* Recurring invoices — create recurring billing profiles for weekly, monthly, quarterly, or yearly invoices; a profile can be set up manually or created automatically the moment a client accepts a Recurring-type proposal (Pro/Agency can also enable automatic payment collection — see Pro Features)
* Automated proposal reminder emails (not viewed, not accepted, expiring soon)
* REST API access

= Pro Features =

* Everything in Free plus...
* Call Booking — add a booking form to any page via shortcode and let leads and clients book a call directly, based on your configured weekly availability, buffer time, minimum notice, and maximum booking window. Confirmation and 1-hour-reminder emails include a calendar invite and a one-click "Add to Google Calendar" link
* Calendar Sync — connect Google Calendar, Microsoft 365/Outlook, or Apple iCloud. Existing events on any connected calendar automatically block your booking availability (shown with their real event title), and every confirmed booking is pushed out as a real event, including your meeting link, on every calendar you've connected
* Stripe or PayPal payment collection on proposals
* Stripe or PayPal "Pay Now" button on client-facing invoices (auto-marks paid via webhook)
* Auto-charge for recurring invoices — automatically charge the client's saved Stripe or PayPal payment method each cycle instead of sending a "Pay Now" link (see FAQ for how the first payment and failed charges are handled)
* Client portal with magic-link login
* AI writing tools for proposal content
* Revenue analytics dashboard
* Outbound webhooks — 18 events covering the full proposal, invoice, and lead lifecycle (Zapier, Make, Slack, and 7,000+ apps)

= Agency Features =

* Everything in Pro plus...
* Projects & Milestones — every accepted proposal automatically becomes a project with the milestones you define; submit a milestone for the client to review, and they approve it from their portal to move it into progress. Overall project progress is calculated automatically from milestones completed
* Team Members — invite up to 5 team members to work inside the same Client Octopus account, each assigned a role (Admin, Editor, or Viewer)
* Client Messaging — a threaded conversation on every project between you and your client, with read/unread tracking and an email notification the moment either side replies — no separate login required for the client
* File Sharing — upload files to a project and share them with your client through the portal, with 1 GB of storage included and every download served securely rather than as a public link
* Approval Workflows — request client sign-off on a design, piece of content, or deliverable directly from a project; the client approves or rejects with a comment from their portal, and you're notified the instant they respond

Client Octopus is designed specifically for WordPress freelancers and agencies who want to manage proposals, invoices, and client delivery without relying on external SaaS platforms.

= Why Client Octopus? =

Most freelancers and agencies juggle several separate tools to run their client workflow — a lead form, a scheduling app, a proposal tool, an invoicing app, and a payment link. Client Octopus replaces all of them with a single WordPress plugin.

Your core client data — leads, proposals, invoices, bookings, and projects — is stored in your own WordPress database rather than locked inside a separate SaaS platform. Optional integrations, like payment processing, calendar sync, or AI writing tools, only send the specific data required for the feature you've chosen to use.

== Installation ==

1. Upload the `clientoctopus` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins menu
3. The setup wizard launches automatically
4. Configure your branding and settings
5. Upgrade to Pro or Agency at any time via Client Octopus → Account

== FAQ ==

= Does Client Octopus support payments? =

Yes. On the Pro and Agency plans, you can accept payments on proposals and standalone invoices via either Stripe or PayPal — choose whichever gateway you prefer in Settings. Clients always see a single "Pay Now" button that routes to whichever gateway you've configured. The exception is a proposal set to Recurring billing — it has no direct payment option; billing happens through the recurring invoice created when the client accepts, which can itself be set to auto-charge (see "Can I set up automatic recurring invoices?" below) or paid manually each cycle.

= Can clients access a portal? =

Yes. The Pro and Agency plans include a branded client portal with magic-link login.

= Does Client Octopus support teams? =

Yes. The Agency plan supports up to 5 team members, each assigned a role (Admin, Editor, or Viewer).

= Can I collect leads before they become clients? =

Yes, on all plans. Embed the lead capture form on any page with the [clientoctopus_lead_form] shortcode. You choose which fields to show and require, add a consent line, and optionally enable Cloudflare Turnstile CAPTCHA and submission rate limits to help prevent automated spam. Every submission lands in a dedicated Leads admin screen where you can track status, mark as contacted, archive, or convert a lead straight into a client record.

= Can I let clients or visitors book a call with me? =

Yes, on the Pro and Agency plans. Add the [clientoctopus_booking_form] shortcode to any page and visitors can book a call based on your configured weekly availability, buffer time, minimum notice, and maximum booking window. Both you and the client get a confirmation email with a calendar invite, followed by a 1-hour reminder.

= Does Client Octopus sync with my calendar? =

Yes, on the Pro and Agency plans. Connect Google Calendar, Microsoft 365/Outlook, or Apple iCloud in Settings. Once connected, existing events on that calendar automatically block matching slots in your booking availability, shown with their real event title — and every call booked through Client Octopus is pushed out as a real event (including your meeting link) on every calendar you've connected, so you never have to add it yourself.

= Is multisite supported? =

No. Client Octopus licences are single-site only.

= Does Client Octopus require a third-party SaaS? =

No. Client Octopus runs directly inside WordPress and doesn't require a separate SaaS platform to work. Optional integrations — Stripe or PayPal for payments, Google Calendar, Microsoft 365, or Apple iCloud for calendar sync, and AI writing tools — only connect when you explicitly enable and use that feature.

= Are AI costs included? =

Yes. AI requests are included with Pro and Agency plans.

= Can clients e-sign proposals? =

Yes. When a client accepts a proposal, they are prompted to enter their name and confirm a checkbox in a signing modal. A signature certificate is saved to the proposal record and visible in the admin.

= How is this self-hosted, and how is pricing different from SaaS alternatives? =

Client Octopus is a self-hosted WordPress plugin, not a SaaS platform — your proposals, invoices, and client data are stored in your own WordPress database. Pro is $9.99/month or $89/year, and Agency is $24.99/month or $229/year, with no per-user charges and no limits on the number of clients or proposals you manage.

= Can I send invoices without a proposal? =

Yes. Standalone invoices are available on all plans. Create an invoice, assign a client, add line items with VAT and discount options, and send it directly. Free plan invoices can include your own payment instructions (e.g. bank transfer details) in the Notes field; Pro and Agency invoices can additionally show a "Pay Now" button (Stripe or PayPal, whichever is configured) on the client's invoice page.

= Can I set up automatic recurring invoices? =

Yes, on all plans. Create a recurring invoice profile for a client, choose weekly, monthly, quarterly, or yearly billing, and Client Octopus automatically generates and sends a fresh invoice each cycle. By default the client pays each one manually via the same Pay Now flow as any other invoice. You can also mark a proposal itself as Recurring — accepting it automatically creates the recurring profile for you, with no manual setup.

On the Pro and Agency plans, you can additionally turn on auto-charge for a profile: the client's first invoice is still paid manually (which securely saves their Stripe or PayPal payment method), and every invoice after that is charged automatically with no action needed from the client. If a charge is declined, both you and the client are notified and it's retried a few times before the profile pauses itself so you can follow up.

== Screenshots ==

1. Send branded proposals with pricing breakdowns and e-signature acceptance
2. Give clients a branded portal with proposals, invoices, projects, and payments at a glance
3. Branded client invoices with one-click Print / Save as PDF
4. Create professional proposals fast with guided templates
5. Let clients track every payment in one place — proposal deposits and invoice payments
6. Create invoices in minutes — client, line items, discounts, and VAT
7. Manage projects, milestones, approvals, and payment status together
8. Give clients simple, secure access — magic link or password sign-in
9. See feature access and monthly limits at a glance on the Plan & Usage dashboard
10. Connect Client Octopus to thousands of apps with webhooks

== External Services ==

Client Octopus is designed to run primarily within WordPress. Some optional features connect to third-party services, and these connections are only used when the relevant feature is enabled or used:

**Stripe**

Client Octopus uses Stripe to process client payments on proposals and invoices. When a client pays, the plugin creates a Stripe Checkout Session on your configured Stripe account and redirects the client to complete payment on Stripe's hosted page. The client's card details are entered directly on Stripe's servers and are never stored in WordPress. Your Stripe API keys (publishable and secret) are stored in the WordPress options table and transmitted only to Stripe's API.

If a recurring invoice profile has auto-charge enabled, the client's first payment additionally saves a reference to their payment method with Stripe (a customer ID and payment method ID — never the underlying card number) so future invoices on that profile can be charged automatically without the client re-entering their card. This reference is stored in your WordPress database and used only to request further charges via Stripe's API.

- Service: Stripe, Inc.
- Data sent: payment amount, currency, project or invoice description, and client email when a payment session is created. If auto-charge is enabled, a Stripe customer ID and payment method ID are stored after the first payment and sent back to Stripe to request each subsequent automatic charge. Stripe webhook events (payment completion) are received and verified using your webhook secret.
- Terms of Service: https://stripe.com/legal/ssa
- Privacy Policy: https://stripe.com/privacy

**PayPal**

Client Octopus uses PayPal as an alternative to Stripe for processing client payments on proposals and invoices, if you choose it as your active payment provider. When a client pays, the plugin creates a PayPal order on your configured PayPal account and redirects the client to complete and approve payment on PayPal's hosted page. The client's payment details are entered directly on PayPal's servers and are never stored in WordPress. Your PayPal API credentials (Client ID and Client Secret) are stored in the WordPress options table and transmitted only to PayPal's API.

If a recurring invoice profile has auto-charge enabled, the client's first payment additionally saves their payment method with PayPal's Vault (a vault ID, PayPal customer ID, and the payer's email address) so future invoices on that profile can be charged automatically without the client approving each one. This reference is stored in your WordPress database and used only to request further charges via PayPal's API.

- Service: PayPal, Inc.
- Data sent: payment amount, currency, and a proposal or invoice reference when an order is created. If auto-charge is enabled, a PayPal vault ID, customer ID, and the payer's email address are stored after the first payment and sent back to PayPal to request each subsequent automatic charge. PayPal webhook events (order approval and payment capture) are received and verified using PayPal's own signature-verification API.
- Terms of Service: https://www.paypal.com/us/legalhub/useragreement-full
- Privacy Policy: https://www.paypal.com/us/legalhub/paypal/privacy-full

**Client Octopus Relay**

Client Octopus's AI writing tools and Google/Microsoft Calendar Sync both route through a relay service operated by the plugin author (clientoctopus.com). The relay authenticates your licence and, depending on the feature, either forwards a request to an AI model or manages your connected Google/Microsoft calendar on your behalf — a plugin installed on thousands of sites can't each register its own Google/Microsoft OAuth application, so a single relay-owned OAuth app is used for every install, and your calendar's access tokens are stored on the relay rather than in your WordPress database. These features are only triggered when you explicitly use an AI writing action, or explicitly connect Google Calendar or Microsoft 365 in Settings.

- Service: clientoctopus.com (operated by codievolt)
- Data sent (AI writing requests): the text prompt you submit (proposal content or instructions) and your licence key, which authenticates the request and is used to enforce your plan's monthly rate limit. No site URL or admin email is transmitted for AI requests.
- Data sent (licence sync): separately, once per day, the plugin sends your licence key and account email to the same relay to keep your plan status in sync. This happens automatically in the background on Pro/Agency plans and is not tied to any explicit AI action.
- Data sent (Calendar Sync — Google/Microsoft only): connecting Google Calendar or Microsoft 365 in Settings authorises the relay to store the resulting OAuth access/refresh tokens on your behalf. On each sync, the relay uses those tokens to read busy time (start/end and event title) from your connected calendar, and to create or delete a calendar event when a booking is made or cancelled. This only happens for a provider you've explicitly connected.
- Privacy Policy: https://clientoctopus.clientoctopus.com/privacy-policy/

**Apple iCloud (CalDAV)**

If you connect Apple iCloud as a calendar provider in Settings, Client Octopus connects directly from your WordPress site to Apple's iCloud calendar servers using the Apple ID and app-specific password you provide — unlike Google/Microsoft, this connection does not go through the Client Octopus relay. Your app-specific password is encrypted before being stored in your WordPress database. On each sync, the plugin reads busy time (start/end and event title) from your chosen iCloud calendar, and creates or deletes a calendar event when a booking is made or cancelled. This only happens if you've explicitly connected an Apple iCloud account in Settings.

- Service: Apple Inc.
- Data sent: your Apple ID and app-specific password, sent only to caldav.icloud.com to authenticate each request, plus calendar event data (times and titles) read from or written to your connected iCloud calendar.
- Terms of Service: https://www.apple.com/legal/internet-services/icloud/
- Privacy Policy: https://www.apple.com/legal/privacy/

**Cloudflare Turnstile**

If you enable Turnstile as the CAPTCHA provider for the lead capture form ([clientoctopus_lead_form] shortcode), Client Octopus verifies each form submission with Cloudflare before saving it, to block automated spam. This only runs when Turnstile is configured in Settings — it is off by default.

- Service: Cloudflare, Inc.
- Data sent: the Turnstile response token, the visitor's IP address, and your Turnstile secret key, sent server-side to Cloudflare's siteverify endpoint when a lead capture form is submitted.
- Terms of Service: https://www.cloudflare.com/website-terms/
- Privacy Policy: https://www.cloudflare.com/privacypolicy/

**Freemius**

Client Octopus uses Freemius to manage plan licensing, activation, and upgrades. When you activate a licence key, the site URL, plugin version, and licence key are sent to Freemius to verify your plan and enable the correct feature set.

- Service: Freemius, Inc.
- Data sent: site URL, plugin version, licence key, and basic activation/deactivation events.
- Terms of Service: https://freemius.com/terms/
- Privacy Policy: https://freemius.com/privacy/

== Changelog ==

= 1.3.1 =

* New: Lead Capture — embed a [clientoctopus_lead_form] shortcode on any page to collect inquiries directly into Client Octopus instead of a separate form plugin. Choose which fields to show and require, customise labels, and add a consent line. Available on all plans.
* New: Leads admin screen — view, filter by status, mark as contacted, archive, or convert a lead straight into a client record with one click.
* New: lead.captured webhook event for connecting new leads to your CRM, Zapier, or Slack (Pro & Agency).
* New: The lead capture confirmation email can include a "Pick a Time to Talk" link to your Booking Page when Booking is enabled.
* New: Optional Cloudflare Turnstile CAPTCHA and configurable submission-rate limits to help prevent automated spam on the lead capture form.
* New: Optional automatic reply email to anyone who submits the lead capture form.
* New: Call Booking (Pro & Agency) — add the [clientoctopus_booking_form] shortcode to a page and let leads and visitors book a call directly, based on your configured weekly availability. Includes buffer time, minimum notice, and a maximum booking window.
* New: Bookings admin screen — view, search, and cancel booked calls.
* New: Booking confirmation and 1-hour reminder emails, with a calendar invite (.ics) attachment and a one-click "Add to Google Calendar" link.
* New: Calendar Sync (Pro & Agency) — connect Google Calendar, Microsoft 365/Outlook, or Apple iCloud in Settings. Existing events on any connected calendar automatically block matching slots in your booking availability, shown with their real event title.
* New: Confirmed bookings are automatically pushed out to every connected calendar as a real event, including your configured meeting link — no more manually adding calls to your calendar.
* New: "Sync now" and "Sync existing bookings" buttons in Settings to push or pull immediately instead of waiting for the automatic background sync.
* New: Apple iCloud calendar picker — if more than one calendar is found on the connected account, choose which one to sync instead of the plugin guessing.
* Fix: A recurring invoice profile with no payment method on file (e.g. after a client's data was erased via a privacy request) would previously regenerate unpaid invoices indefinitely with no notification — it now correctly pauses and notifies the owner after repeated attempts, same as a real card decline.
* Fix: Testimonial request emails were never sent for clients on recurring/retainer billing, even once they were fully paid up.
* Fix: The "View Project" link in the project-completion email pointed at the portal's login page instead of the client's actual invoices.
* Fix: A completed project's status could still be freely changed back to Active or On Hold, and completing a project gave no indication if the client still had an active recurring billing profile attached — completing a project now offers to pause any active recurring profile for that client, and a fully-settled completed project is locked from further status changes.
* Improved: The Settings page is now organised into tabs (Branding, Payments, Leads, Automations, Advanced) instead of one long scrolling page.
* Improved: Various security hardening across client data access, file handling, and API rate limiting.

= 1.2.0 =

* New: Package Selector pricing mode for proposals — toggle between Flat Pricing and Package Selector when building a proposal; define unlimited pricing tiers (each with its own independent line items) plus optional add-ons. The client picks a tier, toggles any add-ons, and sees the total recalculate live before accepting; their selection is resolved and recorded at acceptance.
* New: Recurring Billing for proposals — toggle a proposal to Recurring billing instead of one-off/deposit pricing, set the frequency, start date, and end condition, and Client Octopus automatically creates a real Recurring Invoice profile the moment the client accepts — fully editable afterward just like a manually-created one. Recurring proposals never take a deposit or direct payment; billing runs exclusively through the generated invoice.
* Improved: The Pricing block in the proposal Content Editor can now be moved up and down like any other section, and renders on the client-facing proposal in the position it's assigned instead of always appearing at the end.
* Improved: The Marketing Campaign proposal template is now available on all plans (previously Pro-only).
* Improved: The proposal expiry date is now a required field when creating or editing a proposal, with no pre-filled placeholder value — previously it could be left blank, which silently excluded that proposal from the "expiring soon" reminder email and the automatic expiry check.
* New: Auto-charge for recurring invoices (Pro & Agency) — opt a recurring profile into automatically charging the client's saved Stripe or PayPal payment method each cycle instead of sending a "Pay Now" link. The client's first invoice is still paid manually, which securely saves their payment method for reuse. A failed charge is retried a few times with the client notified each time, then the profile pauses itself so you can follow up — it can be resumed once the client updates their payment details.
* New: Payment failure notifications — you and the client are now both emailed when a payment attempt on an invoice or proposal is declined, expired, cancelled, or needs additional verification from the client's bank; previously this happened silently with no notification to either party.
* Fix: Form fields (text, email, number, date, select, textarea) across the admin could render with the wrong border colour or square corners instead of the plugin's intended styling, because WordPress's own default admin styles were unintentionally overriding them in most places.
* Fix: Currency and frequency selects in the proposal wizard and recurring invoice editor could render taller than other fields, and some fields in the same forms didn't share a consistent height.
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
