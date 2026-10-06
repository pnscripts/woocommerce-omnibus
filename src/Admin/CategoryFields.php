<?php
/**
 * Perishable flag on product categories.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Admin;

use Pnscripts\Omnibus\Reference\ReferenceService;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a checkbox to the product category add/edit forms.
 */
final class CategoryFields {

	private const NONCE = 'pnscripts_omnibus_term_nonce';

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'product_cat_add_form_fields', array( $this, 'add_field' ) );
		add_action( 'product_cat_edit_form_fields', array( $this, 'edit_field' ) );
		add_action( 'created_product_cat', array( $this, 'save' ) );
		add_action( 'edited_product_cat', array( $this, 'save' ) );
	}

	/**
	 * Field on the "Add new category" form.
	 */
	public function add_field(): void {
		wp_nonce_field( 'pnscripts_omnibus_term', self::NONCE );
		printf(
			'<div class="form-field"><label><input type="checkbox" name="%1$s" value="yes" /> %2$s</label><p>%3$s</p></div>',
			esc_attr( ReferenceService::PERISHABLE_TERM ),
			esc_html__( 'Perishable goods', 'pnscripts-pricetrail' ),
			esc_html__( 'Products in this category perish or expire quickly. Only used when the perishable-goods exemption is enabled in Pricetrail settings.', 'pnscripts-pricetrail' )
		);
	}

	/**
	 * Field on the "Edit category" form.
	 *
	 * @param WP_Term $term Term.
	 */
	public function edit_field( $term ): void {
		$checked = 'yes' === get_term_meta( $term->term_id, ReferenceService::PERISHABLE_TERM, true );
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Perishable goods', 'pnscripts-pricetrail' ) . '</th><td>';
		wp_nonce_field( 'pnscripts_omnibus_term', self::NONCE );
		printf(
			'<label><input type="checkbox" name="%1$s" value="yes" %2$s /> %3$s</label>',
			esc_attr( ReferenceService::PERISHABLE_TERM ),
			checked( $checked, true, false ),
			esc_html__( 'Products in this category perish or expire quickly.', 'pnscripts-pricetrail' )
		);
		echo '</td></tr>';
	}

	/**
	 * Save the flag.
	 *
	 * @param int $term_id Term id.
	 */
	public function save( $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'pnscripts_omnibus_term' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}
		$checked = isset( $_POST[ ReferenceService::PERISHABLE_TERM ] ) && 'yes' === sanitize_key( wp_unslash( $_POST[ ReferenceService::PERISHABLE_TERM ] ) );
		update_term_meta( (int) $term_id, ReferenceService::PERISHABLE_TERM, $checked ? 'yes' : 'no' );
		HistoryRepository::bump_cache();
	}
}
