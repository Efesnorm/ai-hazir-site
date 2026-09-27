<?php
/**
 * The aihs_listing post type and its meta.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Catalog;

/**
 * Private storage for listings: no front-end URL, archive, search, REST route or core edit
 * screen. Listings are edited only through our admin forms, which go through CatalogService.
 * Meta keys start with "_" so they are protected (is_protected_meta) and hidden from Custom Fields.
 */
final class PostType {

	public const NAME       = 'aihs_listing';
	public const CAPABILITY = 'manage_options';

	/**
	 * Listing field → meta key (title and description live in post_title / post_content).
	 */
	public const META = array(
		'type'           => '_aihs_type',
		'category'       => '_aihs_category',
		'quantity'       => '_aihs_quantity',
		'unit'           => '_aihs_unit',
		'price_min'      => '_aihs_price_min',
		'price_max'      => '_aihs_price_max',
		'currency'       => '_aihs_currency',
		'region'         => '_aihs_region',
		'lead_time_days' => '_aihs_lead_time_days',
		'valid_until'    => '_aihs_valid_until',
		'attributes'     => '_aihs_attributes',
		'template'       => '_aihs_template',
	);

	/**
	 * Registers on init.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_now' ) );
	}

	/**
	 * Registers the post type and meta keys (idempotent).
	 */
	public static function register_now(): void {
		if ( post_type_exists( self::NAME ) ) {
			return;
		}

		register_post_type(
			self::NAME,
			array(
				'labels'              => array(
					'name'          => __( 'AI Katalog ilanları', 'ai-hazir-site' ),
					'singular_name' => __( 'AI Katalog ilanı', 'ai-hazir-site' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => true,
				'supports'            => array( 'title', 'editor' ),
				'map_meta_cap'        => true,
			)
		);

		foreach ( self::META as $field => $key ) {
			$type = 'string';
			if ( 'lead_time_days' === $field ) {
				$type = 'integer';
			} elseif ( 'attributes' === $field ) {
				$type = 'array';
			}
			register_post_meta(
				self::NAME,
				$key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static fn(): bool => current_user_can( self::CAPABILITY ),
				)
			);
		}
	}
}
