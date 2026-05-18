<?php
/**
 * Proposal Template System
 *
 * Defines the 4 built-in proposal templates and resolves which ones
 * are available for a given user's plan.
 *
 * Template tiers per Pricing.docx:
 *   Free   — 3 basic templates (web-design, retainer, blank)
 *   Pro    — All 4 templates (adds marketing)
 *   Agency — All 4 templates
 *
 * @package ClientOctopus\Proposals
 * @since   0.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClientOctopus_Proposal_Template
 */
class ClientOctopus_Proposal_Template {

	/**
	 * Return all template definitions.
	 *
	 * Each template provides:
	 *   - id         : Machine-readable slug.
	 *   - label      : Display name.
	 *   - description: Short description.
	 *   - tier       : Minimum tier required ('free' | 'pro').
	 *   - sections   : Default content blocks (JSON structure for the proposal editor).
	 *
	 * @return array[]
	 */
	public static function all(): array {
		return [

			// ── Web Design ───────────────────────────────────────────────────
			[
				'id'          => 'web-design',
				'label'       => __( 'Web Design', 'clientoctopus' ),
				'description' => __( 'Full website project scope with design, development & launch phases.', 'clientoctopus' ),
				'tier'        => 'free',
				'sections'    => [
					[
						'type'    => 'heading',
						'content' => 'Project Overview',
					],
					[
						'type'    => 'text',
						'content' => 'We are pleased to present this proposal for the design and development of your new website. This document outlines the scope of work, deliverables, timeline, and investment.',
					],
					[
						'type'    => 'heading',
						'content' => 'Scope of Work',
					],
					[
						'type'    => 'list',
						'items'   => [
							'Discovery and strategy session',
							'Wireframes and UX design',
							'Visual design (up to 5 pages)',
							'Responsive development',
							'CMS setup and training',
							'Launch and post-launch support (30 days)',
						],
					],
					[
						'type'    => 'heading',
						'content' => 'Investment',
					],
					[
						'type'    => 'pricing',
						'note'    => 'Pricing is detailed in the line items below.',
					],
					[
						'type'    => 'heading',
						'content' => 'Next Steps',
					],
					[
						'type'    => 'text',
						'content' => 'To proceed, please review this proposal and click Accept below. A deposit invoice will be issued upon acceptance.',
					],
				],
			],

			// ── Retainer ─────────────────────────────────────────────────────
			[
				'id'          => 'retainer',
				'label'       => __( 'Retainer', 'clientoctopus' ),
				'description' => __( 'Monthly ongoing services with recurring scope and deliverables.', 'clientoctopus' ),
				'tier'        => 'free',
				'sections'    => [
					[
						'type'    => 'heading',
						'content' => 'Retainer Agreement',
					],
					[
						'type'    => 'text',
						'content' => 'This proposal outlines the terms of an ongoing monthly retainer arrangement. Services are billed on the 1st of each month and are non-refundable.',
					],
					[
						'type'    => 'heading',
						'content' => 'Monthly Deliverables',
					],
					[
						'type'    => 'list',
						'items'   => [
							'Up to X hours of service (adjust as needed)',
							'Monthly progress report',
							'Dedicated Slack/email support',
							'Monthly strategy call (30 min)',
						],
					],
					[
						'type'    => 'heading',
						'content' => 'Monthly Investment',
					],
					[
						'type'    => 'pricing',
						'note'    => 'Billed monthly. Cancel with 30 days written notice.',
					],
					[
						'type'    => 'heading',
						'content' => 'Terms',
					],
					[
						'type'    => 'text',
						'content' => 'This retainer renews automatically each month. Either party may terminate with 30 days written notice.',
					],
				],
			],

			// ── Marketing Campaign ────────────────────────────────────────────
			[
				'id'          => 'marketing',
				'label'       => __( 'Marketing Campaign', 'clientoctopus' ),
				'description' => __( 'SEO, paid ads & content strategy scope for growth campaigns.', 'clientoctopus' ),
				'tier'        => 'pro',   // Locked for free users.
				'sections'    => [
					[
						'type'    => 'heading',
						'content' => 'Campaign Overview',
					],
					[
						'type'    => 'text',
						'content' => 'This proposal outlines a comprehensive digital marketing campaign designed to increase your online visibility, drive qualified traffic, and generate measurable results.',
					],
					[
						'type'    => 'heading',
						'content' => 'Services Included',
					],
					[
						'type'    => 'list',
						'items'   => [
							'SEO audit and keyword research',
							'On-page and technical SEO optimisation',
							'Paid advertising setup (Google/Meta)',
							'Content strategy and calendar',
							'Monthly reporting and analytics review',
						],
					],
					[
						'type'    => 'heading',
						'content' => 'Campaign Duration',
					],
					[
						'type'    => 'text',
						'content' => 'Initial campaign runs for 3 months, with monthly reviews and adjustments based on performance data.',
					],
					[
						'type'    => 'heading',
						'content' => 'Investment',
					],
					[
						'type'    => 'pricing',
						'note'    => '',
					],
				],
			],

			// ── Blank ─────────────────────────────────────────────────────────
			[
				'id'          => 'blank',
				'label'       => __( 'Blank Proposal', 'clientoctopus' ),
				'description' => __( 'Start from scratch with a fully customisable empty canvas.', 'clientoctopus' ),
				'tier'        => 'free',
				'sections'    => [
					[
						'type'    => 'heading',
						'content' => 'Proposal Title',
					],
					[
						'type'    => 'text',
						'content' => 'Add your proposal introduction here.',
					],
					[
						'type'    => 'pricing',
						'note'    => '',
					],
				],
			],

		];
	}

	/**
	 * Get a single template by ID.
	 *
	 * @param string $id Template slug.
	 *
	 * @return array|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::all() as $tpl ) {
			if ( $tpl['id'] === $id ) {
				return $tpl;
			}
		}

		return null;
	}

	/**
	 * Get templates available for a given plan.
	 *
	 * @param string $plan 'free' | 'pro' | 'agency'
	 *
	 * @return array[] Templates accessible on this plan.
	 */
	public static function for_plan( string $plan ): array {
		$all = self::all();

		if ( in_array( $plan, [ 'pro', 'agency' ], true ) ) {
			return $all;
		}

		// Free: only 'free' tier templates.
		return array_values( array_filter( $all, fn( $t ) => $t['tier'] === 'free' ) );
	}

	/**
	 * Check whether a given user can access a specific template.
	 *
	 * @param int    $user_id
	 * @param string $template_id
	 *
	 * @return bool
	 */
	public static function user_can_access( int $user_id, string $template_id ): bool {
		$tpl = self::get( $template_id );

		if ( ! $tpl ) {
			return false;
		}

		if ( 'free' === $tpl['tier'] ) {
			return true;
		}

		// Pro templates require pro or agency plan.
		$plan = ClientOctopus_Entitlements::get_user_plan( $user_id );

		return in_array( $plan, [ 'pro', 'agency' ], true );
	}

	/**
	 * Return a template's default content as a JSON string.
	 *
	 * Used to populate the proposal's content column on creation.
	 *
	 * @param string $template_id
	 *
	 * @return string JSON
	 */
	public static function default_content( string $template_id ): string {
		$tpl = self::get( $template_id );

		if ( ! $tpl ) {
			return wp_json_encode( [] );
		}

		return wp_json_encode( [
			'template_id' => $template_id,
			'sections'    => $tpl['sections'],
		] );
	}
}
