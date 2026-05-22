<?php
/**
 * Feature: Auto-cancel pending (unpaid) WooCommerce orders after a configurable timeout.
 *
 * @package Anker_Dev
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Anker_Dev_Cancel_Pending_Orders
 */
class Anker_Dev_Cancel_Pending_Orders extends Anker_Dev_Feature {

	/**
	 * Action Scheduler hook fired for a single order's auto-cancel deadline.
	 */
	const ACTION_SINGLE = 'anker_dev_cancel_pending_order';

	/**
	 * Action Scheduler hook for the recurring safety-net sweep.
	 */
	const ACTION_SWEEP = 'anker_dev_cancel_pending_orders_sweep';

	/**
	 * Action Scheduler group used by this feature.
	 */
	const AS_GROUP = 'anker-dev';

	/**
	 * Minimum allowed timeout, in minutes.
	 */
	const MIN_MINUTES = 1;

	/**
	 * Maximum allowed timeout, in minutes (1 day).
	 */
	const MAX_MINUTES = 1440;

	/**
	 * Default timeout, in minutes.
	 */
	const DEFAULT_MINUTES = 15;

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'cancel_pending_orders';
	}

	/**
	 * {@inheritdoc}
	 */
	public function title() {
		return __( 'لغو خودکار سفارش‌های پرداخت‌نشده', 'anker-dev' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function description() {
		return __( 'اگر سفارشی در ووکامرس ثبت شود و در وضعیت «در انتظار پرداخت» باقی بماند، پس از مدت زمان مشخص‌شده به‌صورت خودکار لغو خواهد شد.', 'anker-dev' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function default_settings() {
		return array(
			'enabled' => 1,
			'minutes' => self::DEFAULT_MINUTES,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function sanitize_settings( $input ) {
		$output            = parent::sanitize_settings( $input );
		$minutes           = isset( $input['minutes'] ) ? absint( $input['minutes'] ) : self::DEFAULT_MINUTES;
		$output['minutes'] = max( self::MIN_MINUTES, min( self::MAX_MINUTES, $minutes ) );

		return $output;
	}

	/**
	 * {@inheritdoc}
	 */
	public function render_fields() {
		$minutes = absint( $this->get_setting( 'minutes', self::DEFAULT_MINUTES ) );
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $this->field_id( 'minutes' ) ); ?>">
					<?php esc_html_e( 'مدت زمان (دقیقه)', 'anker-dev' ); ?>
				</label>
			</th>
			<td>
				<input
					type="number"
					min="<?php echo esc_attr( (string) self::MIN_MINUTES ); ?>"
					max="<?php echo esc_attr( (string) self::MAX_MINUTES ); ?>"
					step="1"
					class="small-text"
					id="<?php echo esc_attr( $this->field_id( 'minutes' ) ); ?>"
					name="<?php echo esc_attr( $this->field_name( 'minutes' ) ); ?>"
					value="<?php echo esc_attr( (string) $minutes ); ?>"
				/>
				<p class="description">
					<?php
					printf(
						/* translators: 1: min minutes, 2: max minutes */
						esc_html__( 'مقدار مجاز بین %1$s تا %2$s دقیقه است. مقدار پیش‌فرض ۱۵ دقیقه است.', 'anker-dev' ),
						esc_html( number_format_i18n( self::MIN_MINUTES ) ),
						esc_html( number_format_i18n( self::MAX_MINUTES ) )
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * {@inheritdoc}
	 */
	public function init_hooks() {
		// Schedule auto-cancel when an order is created in pending status.
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 10, 2 );

		// Unschedule when the order leaves pending (paid, cancelled manually, etc.).
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );

		// Per-order deadline handler.
		add_action( self::ACTION_SINGLE, array( $this, 'cancel_order_if_still_pending' ), 10, 1 );

		// Recurring safety-net sweep handler.
		add_action( self::ACTION_SWEEP, array( $this, 'run_sweep' ) );

		// Make sure the recurring sweep is scheduled.
		add_action( 'init', array( $this, 'maybe_schedule_sweep' ) );

		// Reschedule sweep when settings change.
		add_action( 'anker_dev_settings_updated', array( $this, 'reschedule_sweep' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function on_activate() {
		$this->maybe_schedule_sweep();
	}

	/**
	 * {@inheritdoc}
	 */
	public function on_deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_SINGLE, array(), self::AS_GROUP );
			as_unschedule_all_actions( self::ACTION_SWEEP, array(), self::AS_GROUP );
		}
	}

	/**
	 * Get the configured timeout in minutes (clamped to safe bounds).
	 *
	 * @return int
	 */
	protected function get_minutes() {
		$minutes = absint( $this->get_setting( 'minutes', self::DEFAULT_MINUTES ) );
		return max( self::MIN_MINUTES, min( self::MAX_MINUTES, $minutes ) );
	}

	/**
	 * When a new order is created, schedule its auto-cancel deadline if it is pending.
	 *
	 * @param int      $order_id Order id.
	 * @param WC_Order $order    Order object.
	 * @return void
	 */
	public function on_new_order( $order_id, $order = null ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'pending' !== $order->get_status() ) {
			return;
		}

		$this->schedule_for_order( $order_id );
	}

	/**
	 * Unschedule the auto-cancel job when an order leaves the `pending` status.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Previous status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order object.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, $order = null ) {
		unset( $order );

		if ( 'pending' === $to ) {
			// Order moved (back) into pending — make sure a deadline is scheduled.
			$this->schedule_for_order( absint( $order_id ) );
			return;
		}

		if ( 'pending' === $from ) {
			$this->unschedule_for_order( absint( $order_id ) );
		}
	}

	/**
	 * Schedule a single auto-cancel deadline for an order.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	protected function schedule_for_order( $order_id ) {
		if ( ! $order_id || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$minutes   = $this->get_minutes();
		$timestamp = time() + ( $minutes * MINUTE_IN_SECONDS );
		$args      = array( 'order_id' => $order_id );

		// Drop any existing deadline first to avoid duplicates if minutes changed.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_SINGLE, $args, self::AS_GROUP );
		}

		as_schedule_single_action( $timestamp, self::ACTION_SINGLE, $args, self::AS_GROUP, true );
	}

	/**
	 * Remove any scheduled auto-cancel deadline for an order.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	protected function unschedule_for_order( $order_id ) {
		if ( ! $order_id || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( self::ACTION_SINGLE, array( 'order_id' => $order_id ), self::AS_GROUP );
	}

	/**
	 * Action Scheduler handler: cancel an order if it is still in pending status.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function cancel_order_if_still_pending( $order_id ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'pending' !== $order->get_status() ) {
			return;
		}

		/**
		 * Filter whether a specific pending order should be auto-cancelled.
		 *
		 * @param bool     $should_cancel Default true.
		 * @param WC_Order $order         The order being evaluated.
		 */
		if ( ! apply_filters( 'anker_dev_should_cancel_pending_order', true, $order ) ) {
			return;
		}

		$minutes = $this->get_minutes();
		$note    = sprintf(
			/* translators: %s: number of minutes */
			__( 'سفارش به دلیل عدم پرداخت در مهلت %s دقیقه‌ای، توسط افزونهٔ Anker Dev به‌صورت خودکار لغو شد.', 'anker-dev' ),
			number_format_i18n( $minutes )
		);

		$order->update_status( 'cancelled', $note );
	}

	/**
	 * Make sure the recurring safety-net sweep is scheduled.
	 *
	 * Runs every 5 minutes and catches any pending order older than the configured
	 * threshold whose single-action deadline was lost (e.g. AS table truncated).
	 *
	 * @return void
	 */
	public function maybe_schedule_sweep() {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( false === as_next_scheduled_action( self::ACTION_SWEEP, array(), self::AS_GROUP ) ) {
			as_schedule_recurring_action(
				time() + ( 5 * MINUTE_IN_SECONDS ),
				5 * MINUTE_IN_SECONDS,
				self::ACTION_SWEEP,
				array(),
				self::AS_GROUP,
				true
			);
		}
	}

	/**
	 * Reschedule the sweep, e.g. after settings change.
	 *
	 * @return void
	 */
	public function reschedule_sweep() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_SWEEP, array(), self::AS_GROUP );
		}
		$this->maybe_schedule_sweep();
	}

	/**
	 * Recurring sweep: cancel any pending order older than the configured threshold.
	 *
	 * @return void
	 */
	public function run_sweep() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$minutes      = $this->get_minutes();
		$cutoff       = time() - ( $minutes * MINUTE_IN_SECONDS );
		$orders       = wc_get_orders(
			array(
				'limit'        => 50,
				'status'       => array( 'pending' ),
				'date_created' => '<' . $cutoff,
				'return'       => 'ids',
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		foreach ( $orders as $order_id ) {
			$this->cancel_order_if_still_pending( (int) $order_id );
		}
	}
}
