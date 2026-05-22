<?php
/**
 * Uninstall handler for Anker Dev.
 *
 * Runs only when the user explicitly chooses "Delete" from the Plugins screen.
 *
 * @package Anker_Dev
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove our single options blob.
delete_option( 'anker_dev_settings' );

// Best-effort: clear any background jobs we may have scheduled.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'anker_dev_cancel_pending_order', array(), 'anker-dev' );
	as_unschedule_all_actions( 'anker_dev_cancel_pending_orders_sweep', array(), 'anker-dev' );
}
