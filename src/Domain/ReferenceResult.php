<?php
/**
 * Outcome of a reference price computation.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Domain;

/**
 * Immutable result. Prices are in the stored (as entered) form, before tax display conversion.
 */
final class ReferenceResult {

	public const KNOWN       = 'known';
	public const UNKNOWN     = 'unknown';
	public const NOT_ON_SALE = 'not_on_sale';
	public const EXEMPT      = 'exempt';

	public const REASON_NO_HISTORY          = 'no_history';
	public const REASON_GAP                 = 'gap_in_history';
	public const REASON_INCOMPLETE          = 'incomplete_history';
	public const REASON_NEW_PRODUCT         = 'new_product';
	public const REASON_START_UNKNOWN       = 'reduction_start_unknown';
	public const REASON_NO_PRIOR_PRICE      = 'no_prior_price';
	public const REASON_NOT_CAPTURED        = 'not_captured';
	public const REASON_PERISHABLE          = 'perishable';
	public const REASON_UNSUPPORTED_PRODUCT = 'unsupported_product';

	/**
	 * Constructor.
	 *
	 * @param string      $status       One of KNOWN, UNKNOWN, NOT_ON_SALE, EXEMPT.
	 * @param string|null $price        Lowest prior price when known.
	 * @param int|null    $anchor       Start of the current reduction (UTC).
	 * @param int|null    $window_start Start of the period that was searched (UTC).
	 * @param int         $period_days  Period length shown to shoppers.
	 * @param bool        $shortened    Whether a shorter period since launch was used.
	 * @param string      $reason       Machine reason for UNKNOWN/EXEMPT.
	 */
	public function __construct(
		public readonly string $status,
		public readonly ?string $price = null,
		public readonly ?int $anchor = null,
		public readonly ?int $window_start = null,
		public readonly int $period_days = 30,
		public readonly bool $shortened = false,
		public readonly string $reason = ''
	) {
	}

	/**
	 * Known reference price.
	 *
	 * @param string $price        Lowest price.
	 * @param int    $anchor       Reduction start.
	 * @param int    $window_start Period start.
	 * @param int    $period_days  Days.
	 * @param bool   $shortened    Period since launch.
	 */
	public static function known( string $price, int $anchor, int $window_start, int $period_days, bool $shortened = false ): self {
		return new self( self::KNOWN, $price, $anchor, $window_start, $period_days, $shortened );
	}

	/**
	 * Unknown reference price.
	 *
	 * @param string   $reason      Reason constant.
	 * @param int|null $anchor      Reduction start when found.
	 * @param int      $period_days Days.
	 */
	public static function unknown( string $reason, ?int $anchor = null, int $period_days = 30 ): self {
		return new self( self::UNKNOWN, null, $anchor, null, $period_days, false, $reason );
	}

	/**
	 * Product is not reduced right now.
	 */
	public static function not_on_sale(): self {
		return new self( self::NOT_ON_SALE );
	}

	/**
	 * Product is exempt.
	 *
	 * @param string $reason Reason constant.
	 */
	public static function exempt( string $reason ): self {
		return new self( self::EXEMPT, null, null, null, 30, false, $reason );
	}

	/**
	 * Whether a price is available.
	 */
	public function is_known(): bool {
		return self::KNOWN === $this->status && null !== $this->price;
	}

	/**
	 * Array form (for caching, REST, CLI).
	 *
	 * @return array{status: string, price: string|null, anchor: int|null, window_start: int|null, period_days: int, shortened: bool, reason: string}
	 */
	public function to_array(): array {
		return array(
			'status'       => $this->status,
			'price'        => $this->price,
			'anchor'       => $this->anchor,
			'window_start' => $this->window_start,
			'period_days'  => $this->period_days,
			'shortened'    => $this->shortened,
			'reason'       => $this->reason,
		);
	}

	/**
	 * Rebuild from array form.
	 *
	 * @param array<string, mixed> $data Data from to_array().
	 */
	public static function from_array( array $data ): self {
		return new self(
			is_string( $data['status'] ?? null ) ? $data['status'] : self::UNKNOWN,
			is_string( $data['price'] ?? null ) ? $data['price'] : null,
			is_int( $data['anchor'] ?? null ) ? $data['anchor'] : null,
			is_int( $data['window_start'] ?? null ) ? $data['window_start'] : null,
			is_int( $data['period_days'] ?? null ) ? $data['period_days'] : 30,
			true === ( $data['shortened'] ?? false ),
			is_string( $data['reason'] ?? null ) ? $data['reason'] : ''
		);
	}
}
