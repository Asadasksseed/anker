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
	 * Sweep interval in seconds (recurring + inline throttle).
	 */
	const SWEEP_INTERVAL = 60;

	/**
	 * Transient name used to throttle the inline page-load sweep.
	 */
	const SWEEP_THROTTLE_TRANSIENT = 'anker_dev_last_sweep_run';

	/**
	 * Option name used to persist the last sweep run timestamp (survives transient flushes).
	 */
	const LAST_SWEEP_OPTION = 'anker_dev_last_sweep_run_ts';

	/**
	 * Option name used to persist the last sweep stats.
	 */
	const LAST_SWEEP_STATS_OPTION = 'anker_dev_last_sweep_stats';

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
	 * Maximum number of orders processed per sweep call.
	 */
	const SWEEP_BATCH_SIZE = 100;

	/**
	 * Logger source name (for WC logger).
	 */
	const LOG_SOURCE = 'anker-dev-cancel-pending-orders';

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
	 *
	 * Hooks are always registered. Each handler self-checks `is_enabled()` so
	 * that previously-scheduled Action Scheduler actions don't error out when
	 * the feature is disabled.
	 */
	public function init_hooks() {
		// Schedule auto-cancel when an order is created in pending status.
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 10, 2 );

		// Schedule / unschedule when the order status changes.
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );

		// Per-order deadline handler (Action Scheduler).
		add_action( self::ACTION_SINGLE, array( $this, 'cancel_order_if_still_pending' ), 10, 1 );

		// Recurring safety-net sweep handler (Action Scheduler).
		add_action( self::ACTION_SWEEP, array( $this, 'run_sweep' ) );

		// Make sure the recurring sweep is scheduled on every load.
		add_action( 'init', array( $this, 'maybe_schedule_sweep' ), 20 );

		// Cron-independent fallback: sweep on every page load, throttled.
		add_action( 'wp_loaded', array( $this, 'maybe_run_inline_sweep' ), 99 );

		// React to settings changes (after the option is actually persisted).
		add_action( 'anker_dev_settings_updated', array( $this, 'on_settings_updated' ) );
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
	public function get_minutes() {
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
		if ( ! $this->is_enabled() ) {
			return;
		}

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
		$this->log( sprintf( 'scheduled cancel for order #%d in %d minute(s)', $order_id, $this->get_minutes() ) );
	}

	/**
	 * Unschedule the auto-cancel job when an order leaves the `pending` status,
	 * or schedule one when an order moves into pending.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Previous status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order object.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, $order = null ) {
		unset( $order );

		if ( ! $this->is_enabled() ) {
			// Clean up any scheduled action so AS doesn't keep firing for a disabled feature.
			$this->unschedule_for_order( absint( $order_id ) );
			return;
		}

		if ( 'pending' === $to ) {
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
	 * Action Scheduler handler: cancel an order if it is still in pending status
	 * AND old enough (defends against stale actions running early after timeout changes).
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

		$this->cancel_order( $order );
	}

	/**
	 * Cancel a single pending order. Caller is responsible for ensuring the order
	 * is actually pending and old enough.
	 *
	 * @param WC_Order $order Order to cancel.
	 * @return bool Whether the order was cancelled.
	 */
	protected function cancel_order( WC_Order $order ) {
		/**
		 * Filter whether a specific pending order should be auto-cancelled.
		 *
		 * @param bool     $should_cancel Default true.
		 * @param WC_Order $order         The order being evaluated.
		 */
		if ( ! apply_filters( 'anker_dev_should_cancel_pending_order', true, $order ) ) {
			$this->log( sprintf( 'skipped order #%d (filter returned false)', $order->get_id() ) );
			return false;
		}

		$minutes = $this->get_minutes();
		$note    = sprintf(
			/* translators: %s: number of minutes */
			__( 'سفارش به دلیل عدم پرداخت در مهلت %s دقیقه‌ای، توسط افزونهٔ Anker Dev به‌صورت خودکار لغو شد.', 'anker-dev' ),
			number_format_i18n( $minutes )
		);

		$result = $order->update_status( 'cancelled', $note );

		// Clean up the per-order scheduled action so AS doesn't try to run it again.
		$this->unschedule_for_order( $order->get_id() );

		if ( $result ) {
			$this->log( sprintf( 'cancelled order #%d (was pending for >= %d minutes)', $order->get_id(), $minutes ) );
		} else {
			$this->log( sprintf( 'failed to cancel order #%d', $order->get_id() ), 'warning' );
		}

		return (bool) $result;
	}

	/**
	 * Make sure the recurring safety-net sweep is scheduled.
	 *
	 * Runs every minute and catches any pending order older than the configured
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
				time() + self::SWEEP_INTERVAL,
				self::SWEEP_INTERVAL,
				self::ACTION_SWEEP,
				array(),
				self::AS_GROUP,
				true
			);
		}
	}

	/**
	 * React to settings changes: clean up or reschedule the recurring sweep.
	 *
	 * @return void
	 */
	public function on_settings_updated() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_SWEEP, array(), self::AS_GROUP );
		}

		if ( $this->is_enabled() ) {
			$this->maybe_schedule_sweep();
		} else {
			// Feature was just disabled — also drop any pending per-order cancellations.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::ACTION_SINGLE, array(), self::AS_GROUP );
			}
		}
	}

	/**
	 * Cron-independent fallback: run a sweep inline at most once every SWEEP_INTERVAL seconds.
	 *
	 * This guarantees that even if WP-Cron / Action Scheduler aren't running on
	 * the host, every visit to the site will trigger a fresh check.
	 *
	 * @return void
	 */
	public function maybe_run_inline_sweep() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Don't run during AJAX, REST, or cron contexts that are mid-request — be conservative.
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Throttle via transient (preferred) AND option (survives transient cache flushes).
		$last = (int) get_transient( self::SWEEP_THROTTLE_TRANSIENT );
		if ( ! $last ) {
			$last = (int) get_option( self::LAST_SWEEP_OPTION, 0 );
		}

		if ( $last && ( time() - $last ) < self::SWEEP_INTERVAL ) {
			return;
		}

		$this->run_sweep( 'inline' );
	}

	/**
	 * Recurring sweep: cancel any pending order older than the configured threshold.
	 *
	 * Called both by Action Scheduler (recurring) and inline from `wp_loaded`.
	 *
	 * @param string $source Tag describing where the sweep was triggered from
	 *                       (`as`, `inline`, `manual`). Default `as`.
	 * @return array{cancelled:int,scanned:int} Stats for the sweep.
	 */
	public function run_sweep( $source = 'as' ) {
		$stats = array(
			'cancelled' => 0,
			'scanned'   => 0,
			'source'    => is_string( $source ) ? $source : 'as',
			'time'      => time(),
		);

		// Always update the throttle markers so we don't re-enter rapidly even on disabled.
		set_transient( self::SWEEP_THROTTLE_TRANSIENT, time(), self::SWEEP_INTERVAL );
		update_option( self::LAST_SWEEP_OPTION, time(), false );

		if ( ! $this->is_enabled() ) {
			update_option( self::LAST_SWEEP_STATS_OPTION, $stats, false );
			return $stats;
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			update_option( self::LAST_SWEEP_STATS_OPTION, $stats, false );
			return $stats;
		}

		$minutes = $this->get_minutes();
		$cutoff  = time() - ( $minutes * MINUTE_IN_SECONDS );
		$orders  = wc_get_orders(
			array(
				'limit'        => self::SWEEP_BATCH_SIZE,
				'status'       => array( 'pending' ),
				'date_created' => '<' . gmdate( 'Y-m-d H:i:s', $cutoff ),
				'return'       => 'objects',
				'orderby'      => 'date',
				'order'        => 'ASC',
			)
		);

		if ( ! is_array( $orders ) ) {
			$orders = array();
		}

		$stats['scanned'] = count( $orders );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( 'pending' !== $order->get_status() ) {
				continue;
			}
			if ( $this->cancel_order( $order ) ) {
				++$stats['cancelled'];
			}
		}

		if ( $stats['cancelled'] > 0 || $stats['scanned'] > 0 ) {
			$this->log(
				sprintf(
					'sweep [%s] scanned=%d cancelled=%d (cutoff: orders older than %d min)',
					$stats['source'],
					$stats['scanned'],
					$stats['cancelled'],
					$minutes
				)
			);
		}

		update_option( self::LAST_SWEEP_STATS_OPTION, $stats, false );

		return $stats;
	}

	/**
	 * Build a snapshot of diagnostic information for the admin UI.
	 *
	 * @return array<string, mixed>
	 */
	public function get_diagnostics() {
		$minutes = $this->get_minutes();
		$cutoff  = time() - ( $minutes * MINUTE_IN_SECONDS );

		$pending_total    = 0;
		$pending_overdue  = 0;
		$oldest_pending   = null;
		$can_query_orders = function_exists( 'wc_get_orders' );

		if ( function_exists( 'wc_orders_count' ) ) {
			$pending_total = (int) wc_orders_count( 'pending' );
		}

		if ( $can_query_orders ) {
			$overdue_ids = wc_get_orders(
				array(
					'limit'        => -1,
					'status'       => array( 'pending' ),
					'date_created' => '<' . gmdate( 'Y-m-d H:i:s', $cutoff ),
					'return'       => 'ids',
					'paginate'     => false,
				)
			);
			$pending_overdue = is_array( $overdue_ids ) ? count( $overdue_ids ) : 0;

			$oldest = wc_get_orders(
				array(
					'limit'    => 1,
					'status'   => array( 'pending' ),
					'orderby'  => 'date',
					'order'    => 'ASC',
					'return'   => 'objects',
					'paginate' => false,
				)
			);
			if ( ! empty( $oldest ) && $oldest[0] instanceof WC_Order ) {
				$created = $oldest[0]->get_date_created();
				if ( $created ) {
					$oldest_pending = array(
						'id'   => $oldest[0]->get_id(),
						'time' => $created->getTimestamp(),
					);
				}
			}
		}

		$as_available  = function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_next_scheduled_action' )
			&& function_exists( 'as_unschedule_all_actions' );
		$wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		// Pending Action Scheduler counts.
		$single_pending = 0;
		$next_sweep_ts  = false;
		if ( $as_available ) {
			$single_pending = $this->count_pending_actions( self::ACTION_SINGLE );
			$next_sweep_ts  = as_next_scheduled_action( self::ACTION_SWEEP, array(), self::AS_GROUP );
		}

		$last_sweep  = (int) get_option( self::LAST_SWEEP_OPTION, 0 );
		$last_stats  = get_option( self::LAST_SWEEP_STATS_OPTION, array() );

		return array(
			'enabled'           => $this->is_enabled(),
			'minutes'           => $minutes,
			'pending_total'     => $pending_total,
			'pending_overdue'   => $pending_overdue,
			'oldest_pending'    => $oldest_pending,
			'as_available'      => $as_available,
			'wp_cron_disabled'  => $wp_cron_disabled,
			'single_scheduled'  => $single_pending,
			'next_sweep'        => is_int( $next_sweep_ts ) ? $next_sweep_ts : false,
			'last_sweep_time'   => $last_sweep,
			'last_sweep_stats'  => is_array( $last_stats ) ? $last_stats : array(),
		);
	}

	/**
	 * Count Action Scheduler pending actions for a given hook in our group.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	protected function count_pending_actions( $hook ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => self::AS_GROUP,
				'status'   => 'pending',
				'per_page' => 1,
				'return'   => 'ids',
			),
			'ids'
		);

		// Some AS versions return an array of ids, others return an object — be tolerant.
		if ( is_array( $actions ) ) {
			return count( $actions );
		}

		return 0;
	}

	/**
	 * Render diagnostics + manual run button for this feature.
	 *
	 * Called by the admin page after the form table.
	 *
	 * @return void
	 */
	public function render_diagnostics() {
		$d = $this->get_diagnostics();
		?>
		<h3 class="anker-dev-diag-title"><?php esc_html_e( 'وضعیت و عیب‌یابی', 'anker-dev' ); ?></h3>
		<table class="widefat striped anker-dev-diag-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'مدت زمان فعلی', 'anker-dev' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: %s: minutes */
							esc_html__( '%s دقیقه', 'anker-dev' ),
							esc_html( number_format_i18n( $d['minutes'] ) )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'سفارش‌های در انتظار پرداخت', 'anker-dev' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $d['pending_total'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'سفارش‌های قدیمی‌تر از مهلت (آمادهٔ لغو)', 'anker-dev' ); ?></th>
					<td>
						<strong><?php echo esc_html( number_format_i18n( $d['pending_overdue'] ) ); ?></strong>
						<?php if ( $d['pending_overdue'] > 0 ) : ?>
							<span class="anker-dev-status anker-dev-status--off" style="margin-inline-start:8px">
								<?php esc_html_e( 'نیاز به اجرا', 'anker-dev' ); ?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $d['oldest_pending'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'قدیمی‌ترین سفارش در انتظار پرداخت', 'anker-dev' ); ?></th>
						<td>
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $d['oldest_pending']['id'] . '&action=edit' ) ); ?>">
								#<?php echo esc_html( (string) $d['oldest_pending']['id'] ); ?>
							</a>
							<span class="description">
								(<?php echo esc_html( $this->format_relative_past( $d['oldest_pending']['time'] ) ); ?>)
							</span>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'وضعیت Action Scheduler', 'anker-dev' ); ?></th>
					<td>
						<?php if ( $d['as_available'] ) : ?>
							<span class="anker-dev-status anker-dev-status--on"><?php esc_html_e( 'در دسترس', 'anker-dev' ); ?></span>
						<?php else : ?>
							<span class="anker-dev-status anker-dev-status--off"><?php esc_html_e( 'در دسترس نیست', 'anker-dev' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'وضعیت WP-Cron', 'anker-dev' ); ?></th>
					<td>
						<?php if ( $d['wp_cron_disabled'] ) : ?>
							<span class="anker-dev-status anker-dev-status--off"><?php esc_html_e( 'غیرفعال (DISABLE_WP_CRON)', 'anker-dev' ); ?></span>
							<p class="description">
								<?php esc_html_e( 'نگران نباشید: حتی بدون WP-Cron هم بررسی fallback روی هر بازدید از سایت انجام می‌شود.', 'anker-dev' ); ?>
							</p>
						<?php else : ?>
							<span class="anker-dev-status anker-dev-status--on"><?php esc_html_e( 'فعال', 'anker-dev' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'تعداد action‌های زمان‌بندی‌شدهٔ تک‌سفارشی', 'anker-dev' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $d['single_scheduled'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'اجرای بعدی sweep زمان‌بندی‌شده', 'anker-dev' ); ?></th>
					<td>
						<?php if ( $d['next_sweep'] ) : ?>
							<?php echo esc_html( $this->format_relative_future( $d['next_sweep'] ) ); ?>
						<?php else : ?>
							<em><?php esc_html_e( 'هنوز زمان‌بندی نشده', 'anker-dev' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'آخرین اجرای sweep', 'anker-dev' ); ?></th>
					<td>
						<?php if ( $d['last_sweep_time'] ) : ?>
							<?php echo esc_html( $this->format_relative_past( $d['last_sweep_time'] ) ); ?>
							<?php if ( ! empty( $d['last_sweep_stats'] ) ) : ?>
								<span class="description">
									—
									<?php
									printf(
										/* translators: 1: source tag, 2: scanned count, 3: cancelled count */
										esc_html__( 'منبع: %1$s، بررسی‌شده: %2$s، لغوشده: %3$s', 'anker-dev' ),
										esc_html( isset( $d['last_sweep_stats']['source'] ) ? (string) $d['last_sweep_stats']['source'] : '—' ),
										esc_html( number_format_i18n( isset( $d['last_sweep_stats']['scanned'] ) ? (int) $d['last_sweep_stats']['scanned'] : 0 ) ),
										esc_html( number_format_i18n( isset( $d['last_sweep_stats']['cancelled'] ) ? (int) $d['last_sweep_stats']['cancelled'] : 0 ) )
									);
									?>
								</span>
							<?php endif; ?>
						<?php else : ?>
							<em><?php esc_html_e( 'هرگز اجرا نشده', 'anker-dev' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<p class="anker-dev-diag-actions">
			<?php
			$run_now_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'              => Anker_Dev_Admin::PAGE_SLUG,
						'anker_dev_action'  => 'run_sweep',
						'anker_dev_feature' => $this->id(),
					),
					admin_url( 'admin.php' )
				),
				'anker_dev_run_sweep_' . $this->id()
			);
			?>
			<a href="<?php echo esc_url( $run_now_url ); ?>" class="button button-secondary">
				<?php esc_html_e( 'اجرای فوری بررسی سفارش‌های قدیمی', 'anker-dev' ); ?>
			</a>
			<?php if ( function_exists( 'as_get_scheduled_actions' ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=anker_dev' ) ); ?>" class="button-link" style="margin-inline-start:8px">
					<?php esc_html_e( 'مشاهدهٔ صف Action Scheduler', 'anker-dev' ); ?>
				</a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Manually trigger a sweep (called by the admin UI's "Run now" button).
	 * Returns the sweep stats so the UI can render a notice.
	 *
	 * @return array<string, int|string>
	 */
	public function run_manual_sweep() {
		return $this->run_sweep( 'manual' );
	}

	/**
	 * Format a past timestamp as "N minutes ago" using WP's human_time_diff.
	 *
	 * @param int $ts Past timestamp.
	 * @return string
	 */
	protected function format_relative_past( $ts ) {
		$ts = (int) $ts;
		if ( $ts <= 0 ) {
			return '';
		}
		return sprintf(
			/* translators: %s: human readable time difference like "5 mins" */
			esc_html__( '%s پیش', 'anker-dev' ),
			human_time_diff( $ts, time() )
		);
	}

	/**
	 * Format a future timestamp as "in N minutes" using WP's human_time_diff.
	 *
	 * @param int $ts Future timestamp.
	 * @return string
	 */
	protected function format_relative_future( $ts ) {
		$ts = (int) $ts;
		if ( $ts <= 0 ) {
			return '';
		}
		if ( $ts <= time() ) {
			return esc_html__( 'به زودی', 'anker-dev' );
		}
		return sprintf(
			/* translators: %s: human readable time difference like "5 mins" */
			esc_html__( 'در %s آینده', 'anker-dev' ),
			human_time_diff( time(), $ts )
		);
	}

	/**
	 * Write a debug-level log line via WC's logger (visible at WooCommerce → Status → Logs).
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (info, warning, error). Default 'info'.
	 * @return void
	 */
	protected function log( $message, $level = 'info' ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		try {
			$logger = wc_get_logger();
			$logger->log( $level, (string) $message, array( 'source' => self::LOG_SOURCE ) );
		} catch ( \Exception $e ) {
			// Logger should never break the request — swallow.
			unset( $e );
		}
	}
}
