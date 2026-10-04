<?php
/**
 * Settings sanitising and presets.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Unit;

use Brain\Monkey\Functions;
use Pnscripts\Omnibus\Domain\ReferencePolicy;
use Pnscripts\Omnibus\Settings;

/**
 * Settings::sanitize().
 */
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_key' )->alias( static fn ( $key ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ) );
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( $text ) => trim( strip_tags( (string) $text ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub.
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	public function test_defaults_are_strict(): void {
		$values = Settings::sanitize( array() );

		$this->assertSame( Settings::defaults(), $values );
		$this->assertSame( 'eu_strict', $values['preset'] );
		$this->assertSame( 30, $values['period_days'] );
		$this->assertSame( ReferencePolicy::NEW_PRODUCT_HIDE, $values['new_product_mode'] );
		$this->assertFalse( $values['perishable_exemption'] );
		$this->assertTrue( $values['hide_when_unknown'] );
		$this->assertFalse( $values['trust_last_modified'] );
		$this->assertFalse( $values['delete_data_on_uninstall'] );
		$this->assertSame( 90, $values['retention_days'] );
	}

	public function test_preset_overrides_rule_fields(): void {
		$values = Settings::sanitize(
			array(
				'preset'               => 'pl',
				'period_days'          => '60',
				'new_product_mode'     => ReferencePolicy::NEW_PRODUCT_HIDE,
				'perishable_exemption' => '1',
			),
			true
		);

		$this->assertSame( 30, $values['period_days'] );
		$this->assertSame( ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH, $values['new_product_mode'] );
		$this->assertFalse( $values['perishable_exemption'] );
	}

	public function test_german_preset_allows_perishable_exemption(): void {
		$this->assertTrue( Settings::sanitize( array( 'preset' => 'de' ) )['perishable_exemption'] );
	}

	public function test_custom_rules_are_kept_and_clamped(): void {
		$values = Settings::sanitize(
			array(
				'preset'           => 'custom',
				'period_days'      => '10',
				'new_product_mode' => ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH,
				'retention_days'   => '5',
			),
			true
		);

		$this->assertSame( 30, $values['period_days'] );
		$this->assertSame( ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH, $values['new_product_mode'] );
		$this->assertSame( 31, $values['retention_days'] );
	}

	public function test_retention_is_longer_than_period(): void {
		$values = Settings::sanitize(
			array(
				'preset'         => 'custom',
				'period_days'    => '60',
				'retention_days' => '40',
			)
		);

		$this->assertSame( 61, $values['retention_days'] );
	}

	public function test_unknown_preset_and_mode_fall_back(): void {
		$values = Settings::sanitize(
			array(
				'preset'           => 'xx',
				'new_product_mode' => 'nonsense',
			)
		);

		$this->assertSame( 'eu_strict', $values['preset'] );
		$this->assertSame( ReferencePolicy::NEW_PRODUCT_HIDE, $values['new_product_mode'] );
	}

	public function test_missing_checkboxes_from_form_are_off(): void {
		$values = Settings::sanitize( array( 'show_single' => '1' ), true );

		$this->assertTrue( $values['show_single'] );
		$this->assertFalse( $values['show_loop'] );
		$this->assertFalse( $values['hide_when_unknown'] );
	}

	public function test_missing_checkboxes_in_stored_option_keep_defaults(): void {
		$values = Settings::sanitize( array( 'show_single' => false ) );

		$this->assertFalse( $values['show_single'] );
		$this->assertTrue( $values['show_loop'] );
	}

	public function test_texts_are_sanitized_and_limited(): void {
		$values = Settings::sanitize(
			array(
				'notice_text'  => '<b>Lowest</b> {price}',
				'unknown_text' => str_repeat( 'a', 500 ),
			)
		);

		$this->assertSame( 'Lowest {price}', $values['notice_text'] );
		$this->assertSame( 300, mb_strlen( $values['unknown_text'] ) );
	}
}
