=== Client Octopus ===
Contributors: codievolt
Tags: proposal, client portal, client management, freelancer, agency
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional proposal, payment, and client management for WordPress freelancers and agencies.

== Description ==

Client Octopus gives freelancers and agencies everything they need to send proposals, collect payments, manage projects, and communicate with clients — all from the WordPress admin.

Create professional proposals, collect Stripe payments, manage milestones, automate client communication, and provide a branded client portal directly on your own website.

= Free Features =

* Unlimited proposals
* Unlimited clients
* Proposal templates
* Proposal status tracking (draft, sent, accepted, declined)
* Client-facing proposal signing page
* REST API access

= Pro Features =

* Everything in Free plus...
* Stripe payment collection on proposals
* Client portal with magic-link login
* AI writing tools for proposal content
* Revenue analytics dashboard
* Outbound webhooks (Zapier, Make, Slack, and 7,000+ tools)

= Agency Features =

* Everything in Pro plus...
* Projects & milestones
* Up to 5 team members with role-based access
* Client messaging
* File uploads & downloads
* Approval workflows

Client Octopus is designed specifically for WordPress freelancers and agencies who want to manage proposals and client delivery without relying on external SaaS platforms.

== Installation ==

1. Upload the `clientoctopus` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins menu
3. The setup wizard launches automatically
4. Configure your branding and settings
5. Upgrade to Pro or Agency at any time via Client Octopus → Account

== FAQ ==

= Does Client Octopus support Stripe payments? =

Yes. Stripe payments are available on the Pro and Agency plans.

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

== Screenshots ==

1. Client Octopus plan and usage dashboard
2. Client Octopus proposal builder templates
3. Client Octopus proposal builder pricing setup
4. Client Octopus project milestones and approvals
5. Client Octopus client portal projects interface

== External Services ==

This plugin connects to the following third-party services:

**Stripe**

Client Octopus uses Stripe to process client payments. When a client pays an invoice, the plugin creates a Stripe Checkout Session on your configured Stripe account and redirects the client to complete payment on Stripe's hosted page. The client's payment details are entered directly on Stripe's servers and are never stored in WordPress. Your Stripe API keys (publishable and secret) are stored in the WordPress options table and transmitted only to Stripe's API.

- Service: Stripe, Inc.
- Data sent: payment amount, currency, project description, and client email when a payment session is created. Stripe webhook events (payment completion) are received and verified using your webhook secret.
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

= 1.0.1 =

* Fix: Onboarding wizard failed on servers that redirect URLs to add a trailing slash, causing POST requests to be converted to GET requests.

= 1.0.0 =

* Initial public release.
