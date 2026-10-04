<?php
/**
 * WooCommerce → PN Omnibus admin page (settings, coverage report, tools).
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Admin;

use Pnscripts\Omnibus\Display\NoticeRenderer;
use Pnscripts\Omnibus\Domain\ReferencePolicy;
use Pnscripts\Omnibus\Import\ImportRunner;
use Pnscripts\Omnibus\Import\Importers;
use Pnscripts\Omnibus\Jobs\Backfill;
use Pnscripts\Omnibus\Jobs\Queue;
use Pnscripts\Omnibus\Jobs\Retention;
use Pnscripts\Omnibus\Plugin;
use Pnscripts\Omnibus\Settings;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use WC_Product;

/**
 * Admin page with three tabs. Every action checks the manage_woocommerce capability and a nonce.
 */
final class SettingsPage {

	public const SLUG       = 'pnscripts-omnibus';
	public const CAPABILITY = 'manage_woocommerce';
	private const GROUP     = 'pnscripts_omnibus';
	private const TOOL      = 'pnscripts_omnibus_tool';
	private const PER_PAGE  = 25;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Services.
	 */
	public function __construct( private readonly Plugin $plugin ) {
	}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_post_' . self::TOOL, array( $this, 'handle_tool' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'capability' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settings_updated' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PNSCRIPTS_OMNIBUS_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Capability for saving the settings group.
	 */
	public function capability(): string {
		return self::CAPABILITY;
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'PN Omnibus: lowest price in the 30 days before a discount', 'pnscripts-omnibus' ),
			__( 'PN Omnibus', 'pnscripts-omnibus' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array<int|string, string> $links Links.
	 * @return array<int|string, string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'pnscripts-omnibus' ) )
		);
		return $links;
	}

	/**
	 * Register the option with its sanitizer.
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => static fn ( $input ): array => Settings::sanitize( is_array( $input ) ? $input : array(), true ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Settings changed: drop caches.
	 */
	public function settings_updated(): void {
		$this->plugin->settings->flush();
		HistoryRepository::bump_cache();
	}

	/**
	 * Page URL.
	 *
	 * @param string               $tab  Tab.
	 * @param array<string, mixed> $args Extra query args.
	 */
	public static function url( string $tab = 'settings', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'pnscripts-omnibus' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation only.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tabs = array(
			'settings' => __( 'Settings', 'pnscripts-omnibus' ),
			'report'   => __( 'Coverage report', 'pnscripts-omnibus' ),
			'tools'    => __( 'Import and tools', 'pnscripts-omnibus' ),
		);

		/**
		 * Filters the admin tabs (add-ons can add their own).
		 *
		 * @param array<string, string> $tabs Tab id => label.
		 */
		$tabs = (array) apply_filters( 'pnscripts_omnibus_admin_tabs', $tabs );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}

		echo '<div class="wrap pnscripts-omnibus-admin">';
		echo '<h1>' . esc_html__( 'PN Omnibus: lowest price before a discount', 'pnscripts-omnibus' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( self::url( (string) $id ) ),
				$id === $tab ? ' nav-tab-active' : '',
				esc_html( (string) $label )
			);
		}
		echo '</nav>';
		$this->render_notices();
		echo '<p class="pnscripts-omnibus-disclaimer">' . esc_html__( 'This plugin helps display prices; you remain responsible for compliance. It does not give legal advice. Check the rules that apply to your shop.', 'pnscripts-omnibus' ) . '</p>';

		switch ( $tab ) {
			case 'report':
				$this->render_report();
				break;
			case 'tools':
				$this->render_tools();
				break;
			case 'settings':
				$this->render_settings();
				break;
			default:
				// Add-on tabs render themselves on pnscripts_omnibus_admin_tab_{tab}.
				do_action( 'pnscripts_omnibus_admin_tab_' . $tab );
		}
		echo '</div>';
	}

	/**
	 * Result notices after tool actions.
	 */
	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		if ( '' === $done ) {
			return;
		}
		$message = 'error' === $done
			? __( 'The action could not be started. Action Scheduler (part of WooCommerce) is not available.', 'pnscripts-omnibus' )
			: __( 'Started in the background. Refresh this page to see the progress.', 'pnscripts-omnibus' );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $done ? 'error' : 'success', esc_html( $message ) );
	}

	/**
	 * Settings tab.
	 */
	private function render_settings(): void {
		$values = $this->plugin->settings->all();
		$name   = static fn ( string $key ): string => Settings::OPTION . '[' . $key . ']';

		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );

		echo '<h2>' . esc_html__( 'Rules', 'pnscripts-omnibus' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="pnscripts-omnibus-preset">' . esc_html__( 'Preset', 'pnscripts-omnibus' ) . '</label></th><td>';
		printf( '<select id="pnscripts-omnibus-preset" name="%s">', esc_attr( $name( 'preset' ) ) );
		foreach ( Settings::preset_labels() as $id => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $id ), selected( $values['preset'], $id, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'A preset fills the three rules below when you save. Presets are starting points, not legal advice: confirm the rules of the country you sell to. Choose "Custom rules" to set them yourself.', 'pnscripts-omnibus' ) . '</p></td></tr>';

		$disabled = 'custom' !== $values['preset'];

		echo '<tr><th scope="row"><label for="pnscripts-omnibus-period">' . esc_html__( 'Period before the discount (days)', 'pnscripts-omnibus' ) . '</label></th><td>';
		printf(
			'<input type="number" id="pnscripts-omnibus-period" name="%1$s" value="%2$d" min="%3$d" max="%4$d" class="small-text" %5$s />',
			esc_attr( $name( 'period_days' ) ),
			(int) $values['period_days'],
			(int) ReferencePolicy::MIN_PERIOD_DAYS,
			(int) ReferencePolicy::MAX_PERIOD_DAYS,
			$disabled ? 'readonly' : ''
		);
		echo '<p class="description">' . esc_html__( 'At least 30 days. The period ends when the current discount started and begins at midnight that many days earlier. For progressive discounts it ends when the first discount started.', 'pnscripts-omnibus' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Products on the market for less than the period', 'pnscripts-omnibus' ) . '</th><td><fieldset>';
		foreach ( array(
			ReferencePolicy::NEW_PRODUCT_HIDE         => __( 'Hide the notice (strict)', 'pnscripts-omnibus' ),
			ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH => __( 'Use the lowest price since the product was launched (only where your country allows a shorter period)', 'pnscripts-omnibus' ),
		) as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s" value="%2$s" %3$s %4$s /> %5$s</label><br />',
				esc_attr( $name( 'new_product_mode' ) ),
				esc_attr( $value ),
				checked( $values['new_product_mode'], $value, false ),
				$disabled && $values['new_product_mode'] !== $value ? 'disabled' : '',
				esc_html( $label )
			);
		}
		echo '</fieldset></td></tr>';

		$this->checkbox_row(
			$name( 'perishable_exemption' ),
			__( 'Perishable goods', 'pnscripts-omnibus' ),
			__( 'Do not show the notice for products (or categories) marked as perishable. Only where your country exempts perishable goods.', 'pnscripts-omnibus' ),
			$values['perishable_exemption'],
			$disabled
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Display', 'pnscripts-omnibus' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->checkbox_row( $name( 'show_single' ), __( 'Product page', 'pnscripts-omnibus' ), __( 'Show the notice under the price on product pages (classic templates and the Product Price block).', 'pnscripts-omnibus' ), $values['show_single'] );
		$this->checkbox_row( $name( 'show_loop' ), __( 'Shop and category pages', 'pnscripts-omnibus' ), __( 'Show the notice in product lists, related products and product blocks.', 'pnscripts-omnibus' ), $values['show_loop'] );
		$this->checkbox_row( $name( 'show_variations' ), __( 'Variations', 'pnscripts-omnibus' ), __( 'Show the notice for the selected variation of variable products.', 'pnscripts-omnibus' ), $values['show_variations'] );
		$this->checkbox_row( $name( 'hide_when_unknown' ), __( 'Incomplete history', 'pnscripts-omnibus' ), __( 'Hide the notice when the price history does not cover the whole period (recommended). When unchecked, the text below is shown instead.', 'pnscripts-omnibus' ), $values['hide_when_unknown'] );

		$this->text_row( $name( 'notice_text' ), __( 'Notice text', 'pnscripts-omnibus' ), $values['notice_text'], NoticeRenderer::default_text() );
		$this->text_row( $name( 'notice_text_short' ), __( 'Notice text (period since launch)', 'pnscripts-omnibus' ), $values['notice_text_short'], NoticeRenderer::default_text_short() );
		$this->text_row( $name( 'unknown_text' ), __( 'Text when the history is incomplete', 'pnscripts-omnibus' ), $values['unknown_text'], NoticeRenderer::default_unknown_text() );
		echo '<tr><th scope="row">' . esc_html__( 'Placeholders', 'pnscripts-omnibus' ) . '</th><td><p class="description">';
		echo esc_html__( '{price} the lowest price, {days} the number of days, {date} the day the discount started. Leave a text empty to use the translated default.', 'pnscripts-omnibus' );
		echo '</p><p class="description">';
		printf(
			/* translators: %s: shortcode */
			esc_html__( 'To place the notice elsewhere, use the shortcode %s.', 'pnscripts-omnibus' ),
			'<code>[pnscripts_omnibus_price]</code>'
		);
		echo '</p></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Data', 'pnscripts-omnibus' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pnscripts-omnibus-retention">' . esc_html__( 'Keep history for (days)', 'pnscripts-omnibus' ) . '</label></th><td>';
		printf(
			'<input type="number" id="pnscripts-omnibus-retention" name="%1$s" value="%2$d" min="%3$d" max="3650" class="small-text" />',
			esc_attr( $name( 'retention_days' ) ),
			(int) $values['retention_days'],
			(int) Settings::MIN_RETENTION_DAYS
		);
		echo '<p class="description">' . esc_html__( 'Older prices are deleted daily, except the ones still needed for a running discount. Minimum 31 days.', 'pnscripts-omnibus' ) . '</p></td></tr>';
		$this->checkbox_row(
			$name( 'trust_last_modified' ),
			__( 'First record', 'pnscripts-omnibus' ),
			__( 'When recording starts for an existing product, assume its current price has applied since the product was last modified. Leave unchecked if other software changes prices without updating products (strict).', 'pnscripts-omnibus' ),
			$values['trust_last_modified']
		);
		$this->checkbox_row(
			$name( 'delete_data_on_uninstall' ),
			__( 'Uninstall', 'pnscripts-omnibus' ),
			__( 'Delete the price history and settings when the plugin is deleted.', 'pnscripts-omnibus' ),
			$values['delete_data_on_uninstall']
		);
		echo '</tbody></table>';

		submit_button();
		echo '</form>';
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Row label.
	 * @param string $description Checkbox text.
	 * @param bool   $checked     State.
	 * @param bool   $locked      Read-only (preset).
	 */
	private function checkbox_row( string $name, string $label, string $description, bool $checked, bool $locked = false ): void {
		$id = sanitize_html_class( str_replace( array( '[', ']' ), '-', $name ) );
		printf(
			'<tr><th scope="row">%1$s</th><td><label for="%2$s"><input type="checkbox" id="%2$s" name="%3$s" value="1" %4$s %5$s /> %6$s</label>%7$s</td></tr>',
			esc_html( $label ),
			esc_attr( $id ),
			esc_attr( $name ),
			checked( $checked, true, false ),
			$locked ? 'disabled' : '',
			esc_html( $description ),
			$locked && $checked ? sprintf( '<input type="hidden" name="%s" value="1" />', esc_attr( $name ) ) : ''
		);
	}

	/**
	 * Text row.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label.
	 * @param string $value       Value.
	 * @param string $placeholder Default text.
	 */
	private function text_row( string $name, string $label, string $value, string $placeholder ): void {
		$id = sanitize_html_class( str_replace( array( '[', ']' ), '-', $name ) );
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" class="large-text" id="%1$s" name="%3$s" value="%4$s" placeholder="%5$s" maxlength="300" /></td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Coverage report tab: products and variations on sale with their reference status.
	 */
	private function render_report(): void {
		$ids = array_values( array_unique( array_map( 'intval', wc_get_product_ids_on_sale() ) ) );
		if ( array() !== $ids ) {
			// Variable and grouped parents have no own price; found with one query instead of loading every product.
			$parents = wc_get_products(
				array(
					'include' => $ids,
					'type'    => array( 'variable', 'grouped' ),
					'status'  => array_keys( get_post_statuses() ),
					'limit'   => -1,
					'return'  => 'ids',
				)
			);
			$ids     = array_values( array_diff( $ids, array_map( 'intval', is_array( $parents ) ? $parents : array() ) ) );
		}
		sort( $ids );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination only.
		$page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$total = count( $ids );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( $page, $pages );

		// Only the products of the current page are loaded.
		$slice = array();
		foreach ( array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product && ! $product->is_type( array( 'variable', 'grouped' ) ) ) {
				$slice[] = $product;
			}
		}

		$results = array();
		foreach ( $slice as $product ) {
			$results[ $product->get_id() ] = $this->plugin->reference->for_product( $product );
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of products and variations */
				_n( '%d product or variation is on sale.', '%d products and variations are on sale.', $total, 'pnscripts-omnibus' ),
				$total
			)
		) . ' ' . esc_html__( 'For each one: whether the lowest prior price is known and shown, and if not, why.', 'pnscripts-omnibus' ) . '</p>';

		if ( 0 === $total ) {
			return;
		}

		echo '<table class="widefat striped pnscripts-omnibus-report"><thead><tr>';
		foreach ( array(
			__( 'Product', 'pnscripts-omnibus' ),
			__( 'Current price', 'pnscripts-omnibus' ),
			__( 'Lowest prior price', 'pnscripts-omnibus' ),
			__( 'Discount started', 'pnscripts-omnibus' ),
			__( 'Status', 'pnscripts-omnibus' ),
		) as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $slice as $product ) {
			$result  = $results[ $product->get_id() ];
			$edit_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
			echo '<tr>';
			printf(
				'<td><a href="%1$s">%2$s</a></td>',
				esc_url( (string) get_edit_post_link( $edit_id ) ),
				esc_html( wp_strip_all_tags( $product->get_formatted_name() ) )
			);
			printf( '<td>%s</td>', wp_kses_post( wc_price( (float) wc_get_price_to_display( $product ) ) ) );
			printf( '<td>%s</td>', $result->is_known() ? wp_kses_post( wc_price( $this->plugin->reference->display_price( $product, $result ) ) ) : '&mdash;' );
			printf( '<td>%s</td>', null !== $result->anchor ? esc_html( (string) wp_date( (string) get_option( 'date_format' ), $result->anchor ) ) : '&mdash;' );
			printf(
				'<td><span class="pnscripts-omnibus-status pnscripts-omnibus-status--%1$s">%2$s</span><br /><small>%3$s</small></td>',
				esc_attr( $result->status ),
				esc_html( Labels::status( $result->status ) ),
				esc_html( Labels::explain( $result ) )
			);
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::url( 'report' ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Tools tab.
	 */
	private function render_tools(): void {
		$stats = $this->plugin->repository->stats();

		echo '<h2>' . esc_html__( 'Status', 'pnscripts-omnibus' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of stored price records, 2: number of products and variations */
				__( '%1$d price records for %2$d products and variations.', 'pnscripts-omnibus' ),
				$stats['rows'],
				$stats['products']
			)
		) . '</p>';

		$status = get_option( Backfill::STATUS_OPTION, array() );
		if ( is_array( $status ) && isset( $status['mode'] ) ) {
			$finished = (int) ( $status['finished'] ?? 0 );
			echo '<p>' . esc_html(
				$finished > 0
					? sprintf(
						/* translators: 1: date and time, 2: number of products checked, 3: number of records added */
						__( 'Last catalogue check finished %1$s: %2$d products checked, %3$d records added.', 'pnscripts-omnibus' ),
						(string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $finished ),
						(int) ( $status['processed'] ?? 0 ),
						(int) ( $status['recorded'] ?? 0 )
					)
					: sprintf(
						/* translators: %d: number of products checked so far */
						__( 'Catalogue check running: %d products checked so far.', 'pnscripts-omnibus' ),
						(int) ( $status['processed'] ?? 0 )
					)
			) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Check the catalogue', 'pnscripts-omnibus' ) . '</h2>';
		echo '<p>' . esc_html__( 'Records the current price of products that have no history yet, and marks products whose price changed without passing through WooCommerce. Runs in the background.', 'pnscripts-omnibus' ) . '</p>';
		$this->tool_button( 'repair', '', __( 'Check the catalogue now', 'pnscripts-omnibus' ) );

		echo '<h2>' . esc_html__( 'Import history from another plugin', 'pnscripts-omnibus' ) . '</h2>';
		echo '<p>' . esc_html__( 'Reads (never changes) the price history saved by another plugin and adds the prices from before PN Omnibus started recording each product. Only import from a plugin that was active until now; a plugin that was switched off long ago has gaps that cannot be detected. Running an import twice is safe.', 'pnscripts-omnibus' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Plugin', 'pnscripts-omnibus' ) . '</th><th scope="col">' . esc_html__( 'Data found', 'pnscripts-omnibus' ) . '</th><th scope="col">' . esc_html__( 'Last import', 'pnscripts-omnibus' ) . '</th><th scope="col"></th></tr></thead><tbody>';
		foreach ( Importers::labels() as $group => $label ) {
			$found  = $this->plugin->importers->count( $group );
			$status = ImportRunner::status( $group );
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $label ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $found ) ) );
			echo '<td>';
			if ( isset( $status['started'] ) ) {
				$finished = (int) ( $status['finished'] ?? 0 );
				echo esc_html(
					$finished > 0
						? sprintf(
							/* translators: 1: date and time, 2: imported records, 3: skipped entries */
							__( '%1$s: %2$d imported, %3$d skipped', 'pnscripts-omnibus' ),
							(string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $finished ),
							(int) ( $status['imported'] ?? 0 ),
							(int) ( $status['skipped'] ?? 0 )
						)
						: __( 'Running…', 'pnscripts-omnibus' )
				);
			} else {
				echo '&mdash;';
			}
			echo '</td><td>';
			if ( $found > 0 ) {
				$this->tool_button( 'import', $group, __( 'Import', 'pnscripts-omnibus' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Background tasks', 'pnscripts-omnibus' ) . '</h2>';
		printf(
			'<p>%s <a href="%s">%s</a></p>',
			esc_html(
				sprintf(
					/* translators: %d: number of pending background tasks */
					__( 'Pending tasks: %d.', 'pnscripts-omnibus' ),
					Queue::pending( Backfill::HOOK ) + Queue::pending( ImportRunner::HOOK ) + Queue::pending( Retention::HOOK )
				)
			),
			esc_url(
				add_query_arg(
					array(
						'page'   => 'wc-status',
						'tab'    => 'action-scheduler',
						's'      => 'pnscripts_omnibus',
						'status' => 'pending',
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html__( 'View in WooCommerce → Status → Scheduled Actions', 'pnscripts-omnibus' )
		);
	}

	/**
	 * Small POST form for a tool.
	 *
	 * @param string $tool  Tool id.
	 * @param string $group Import group.
	 * @param string $label Button label.
	 */
	private function tool_button( string $tool, string $group, string $label ): void {
		printf( '<form method="post" action="%s" class="pnscripts-omnibus-tool">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::TOOL ) );
		printf( '<input type="hidden" name="tool" value="%s" />', esc_attr( $tool ) );
		printf( '<input type="hidden" name="group" value="%s" />', esc_attr( $group ) );
		wp_nonce_field( self::TOOL . '_' . $tool );
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Handle a tool POST.
	 */
	public function handle_tool(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'pnscripts-omnibus' ), 403 );
		}
		$tool = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';
		check_admin_referer( self::TOOL . '_' . $tool );

		if ( ! Queue::available() ) {
			wp_safe_redirect( self::url( 'tools', array( 'done' => 'error' ) ) );
			exit;
		}

		if ( 'repair' === $tool ) {
			Backfill::start( Backfill::MODE_REPAIR );
		} elseif ( 'import' === $tool ) {
			$group = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : '';
			if ( isset( Importers::GROUPS[ $group ] ) ) {
				ImportRunner::start( $group );
			}
		}
		wp_safe_redirect( self::url( 'tools', array( 'done' => $tool ) ) );
		exit;
	}
}
