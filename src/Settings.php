<?php
/**
 * Plugin settings: defaults, presets and sanitising.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus;

use Pnscripts\Omnibus\Domain\ReferencePolicy;

/**
 * Settings stored in the pnscripts_omnibus_settings option.
 *
 * @phpstan-type SettingsArray array{
 *     preset: string,
 *     period_days: int,
 *     new_product_mode: string,
 *     perishable_exemption: bool,
 *     show_single: bool,
 *     show_loop: bool,
 *     show_variations: bool,
 *     hide_when_unknown: bool,
 *     notice_text: string,
 *     notice_text_short: string,
 *     unknown_text: string,
 *     trust_last_modified: bool,
 *     retention_days: int,
 *     delete_data_on_uninstall: bool
 * }
 */
final class Settings {

	public const OPTION = 'pnscripts_omnibus_settings';

	public const MIN_RETENTION_DAYS = 31;

	/**
	 * Cached values.
	 *
	 * @var SettingsArray|null
	 */
	private ?array $values = null;

	/**
	 * Default values (strict).
	 *
	 * @return SettingsArray
	 */
	public static function defaults(): array {
		return array(
			'preset'                   => 'eu_strict',
			'period_days'              => 30,
			'new_product_mode'         => ReferencePolicy::NEW_PRODUCT_HIDE,
			'perishable_exemption'     => false,
			'show_single'              => true,
			'show_loop'                => true,
			'show_variations'          => true,
			'hide_when_unknown'        => true,
			'notice_text'              => '',
			'notice_text_short'        => '',
			'unknown_text'             => '',
			'trust_last_modified'      => false,
			'retention_days'           => 90,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Rule presets. Values are starting points only; shops must check the rules that apply to them.
	 *
	 * Keys are preset ids; "rules" holds the values the preset enforces.
	 *
	 * @return array<string, array{rules: array{period_days: int, new_product_mode: string, perishable_exemption: bool}}>
	 */
	public static function preset_rules(): array {
		$presets = array(
			'eu_strict' => array(
				'rules' => array(
					'period_days'          => 30,
					'new_product_mode'     => ReferencePolicy::NEW_PRODUCT_HIDE,
					'perishable_exemption' => false,
				),
			),
			'bg'        => array(
				'rules' => array(
					'period_days'          => 30,
					'new_product_mode'     => ReferencePolicy::NEW_PRODUCT_HIDE,
					'perishable_exemption' => false,
				),
			),
			'de'        => array(
				'rules' => array(
					'period_days'          => 30,
					'new_product_mode'     => ReferencePolicy::NEW_PRODUCT_HIDE,
					'perishable_exemption' => true,
				),
			),
			'pl'        => array(
				'rules' => array(
					'period_days'          => 30,
					'new_product_mode'     => ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH,
					'perishable_exemption' => false,
				),
			),
		);

		/**
		 * Filters the rule presets.
		 *
		 * @param array<string, array{rules: array{period_days: int, new_product_mode: string, perishable_exemption: bool}}> $presets Presets.
		 */
		$filtered = apply_filters( 'pnscripts_omnibus_presets', $presets );
		return is_array( $filtered ) ? $filtered : $presets;
	}

	/**
	 * Human labels for presets (call at init or later).
	 *
	 * @return array<string, string>
	 */
	public static function preset_labels(): array {
		return array(
			'eu_strict' => __( 'EU, strict: 30 days, no optional exemptions', 'pnscripts-omnibus' ),
			'bg'        => __( 'Bulgaria: 30 days, no optional exemptions', 'pnscripts-omnibus' ),
			'de'        => __( 'Germany: 30 days, perishable goods may be excluded', 'pnscripts-omnibus' ),
			'pl'        => __( 'Poland: 30 days, products offered for less than 30 days use the period since launch', 'pnscripts-omnibus' ),
			'custom'    => __( 'Custom rules', 'pnscripts-omnibus' ),
		);
	}

	/**
	 * All values.
	 *
	 * @return SettingsArray
	 */
	public function all(): array {
		if ( null === $this->values ) {
			$stored       = get_option( self::OPTION, array() );
			$this->values = self::sanitize( is_array( $stored ) ? $stored : array() );
		}
		return $this->values;
	}

	/**
	 * Drop cached values (after an update).
	 */
	public function flush(): void {
		$this->values = null;
	}

	/**
	 * Boolean setting.
	 *
	 * @param string $key Key.
	 */
	public function bool( string $key ): bool {
		$values = $this->all();
		return true === ( $values[ $key ] ?? false );
	}

	/**
	 * Integer setting.
	 *
	 * @param string $key Key.
	 */
	public function int( string $key ): int {
		$values = $this->all();
		$value  = $values[ $key ] ?? 0;
		return is_int( $value ) ? $value : 0;
	}

	/**
	 * String setting.
	 *
	 * @param string $key Key.
	 */
	public function string( string $key ): string {
		$values = $this->all();
		$value  = $values[ $key ] ?? '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Rules for the reference price.
	 */
	public function policy(): ReferencePolicy {
		return new ReferencePolicy( $this->int( 'period_days' ), $this->string( 'new_product_mode' ) );
	}

	/**
	 * Sanitize raw input (settings form or stored option) into a complete settings array.
	 *
	 * Missing checkboxes count as "off" only when $from_form is true.
	 *
	 * @param array<mixed> $input     Raw values.
	 * @param bool         $from_form Whether the input comes from the settings form.
	 * @return SettingsArray
	 */
	public static function sanitize( array $input, bool $from_form = false ): array {
		$defaults = self::defaults();
		$out      = $defaults;

		$presets = self::preset_rules();
		$preset  = isset( $input['preset'] ) && is_scalar( $input['preset'] ) ? sanitize_key( (string) $input['preset'] ) : $defaults['preset'];
		if ( 'custom' !== $preset && ! isset( $presets[ $preset ] ) ) {
			$preset = $defaults['preset'];
		}
		$out['preset'] = $preset;

		$period             = isset( $input['period_days'] ) && is_numeric( $input['period_days'] ) ? (int) $input['period_days'] : $defaults['period_days'];
		$out['period_days'] = max( ReferencePolicy::MIN_PERIOD_DAYS, min( ReferencePolicy::MAX_PERIOD_DAYS, $period ) );

		$mode                    = isset( $input['new_product_mode'] ) && is_scalar( $input['new_product_mode'] ) ? (string) $input['new_product_mode'] : '';
		$out['new_product_mode'] = in_array( $mode, array( ReferencePolicy::NEW_PRODUCT_HIDE, ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH ), true )
			? $mode
			: ReferencePolicy::NEW_PRODUCT_HIDE;

		foreach ( array( 'perishable_exemption', 'show_single', 'show_loop', 'show_variations', 'hide_when_unknown', 'trust_last_modified', 'delete_data_on_uninstall' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = self::to_bool( $input[ $key ] );
			} elseif ( $from_form ) {
				$out[ $key ] = false;
			}
		}

		foreach ( array( 'notice_text', 'notice_text_short', 'unknown_text' ) as $key ) {
			$value       = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
			$out[ $key ] = mb_substr( sanitize_text_field( $value ), 0, 300 );
		}

		$retention             = isset( $input['retention_days'] ) && is_numeric( $input['retention_days'] ) ? (int) $input['retention_days'] : $defaults['retention_days'];
		$out['retention_days'] = max( self::MIN_RETENTION_DAYS, $out['period_days'] + 1, min( 3650, $retention ) );

		if ( 'custom' !== $preset ) {
			$rules                       = $presets[ $preset ]['rules'];
			$out['period_days']          = max( ReferencePolicy::MIN_PERIOD_DAYS, (int) $rules['period_days'] );
			$out['new_product_mode']     = $rules['new_product_mode'];
			$out['perishable_exemption'] = (bool) $rules['perishable_exemption'];
			$out['retention_days']       = max( $out['retention_days'], $out['period_days'] + 1 );
		}

		return $out;
	}

	/**
	 * Loose boolean.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			return in_array( strtolower( (string) $value ), array( '1', 'yes', 'on', 'true' ), true );
		}
		return false;
	}
}
