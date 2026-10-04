<?php
/**
 * Activation and deactivation on multisite.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Unit;

use Brain\Monkey\Functions;
use Pnscripts\Omnibus\Capture\PriceRecorder;
use Pnscripts\Omnibus\Jobs\Backfill;
use Pnscripts\Omnibus\Lifecycle;

/**
 * Network-wide (de)activation pauses and resumes tracking on every site.
 */
final class LifecycleTest extends TestCase {

	/**
	 * Options per site.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $options = array();

	private int $blog = 1;

	/**
	 * Sites whose actions were cleared.
	 *
	 * @var list<int>
	 */
	private array $cleared = array();

	protected function setUp(): void {
		parent::setUp();
		$this->options = array(
			1 => array(),
			2 => array(),
			3 => array(),
		);
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_network_id' )->justReturn( 1 );
		Functions\when( 'get_sites' )->justReturn( array( 1, 2, 3 ) );
		Functions\when( 'switch_to_blog' )->alias(
			function ( $id ): bool {
				$this->blog = (int) $id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function (): bool {
				$this->blog = 1;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias( fn ( $name, $fallback = false ) => $this->options[ $this->blog ][ $name ] ?? $fallback );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ): bool {
				$this->options[ $this->blog ][ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ): bool {
				unset( $this->options[ $this->blog ][ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_cache_set_last_changed' )->justReturn( '1' );
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function (): void {
				$this->cleared[] = $this->blog;
			}
		);
	}

	public function test_network_deactivation_pauses_every_site(): void {
		Lifecycle::deactivate( true );

		foreach ( array( 1, 2, 3 ) as $site ) {
			$this->assertIsInt( $this->options[ $site ][ Lifecycle::PAUSED_OPTION ] ?? null, 'Site ' . $site );
		}
		$this->assertSame( array( 1, 2, 3 ), $this->cleared );
	}

	public function test_network_activation_resumes_every_paused_site(): void {
		$this->options[2][ Lifecycle::PAUSED_OPTION ] = time() - 3600;
		$this->options[3][ Lifecycle::PAUSED_OPTION ] = time() - 7200;

		Lifecycle::activate( true );

		foreach ( array( 2, 3 ) as $site ) {
			$this->blog = $site;
			$window     = PriceRecorder::resume_window();
			$this->assertNotNull( $window, 'Site ' . $site );
			$this->assertSame( Backfill::MODE_RESUME, $this->options[ $site ][ Lifecycle::PENDING_OPTION ] ?? null );
			$this->assertArrayNotHasKey( Lifecycle::PAUSED_OPTION, $this->options[ $site ] );
		}
		$this->assertSame( time() - 7200, $this->options[3][ PriceRecorder::RESUME_OPTION ]['from'] );
		$this->assertArrayNotHasKey( PriceRecorder::RESUME_OPTION, $this->options[1], 'A site that was never paused has no gap.' );
	}
}
