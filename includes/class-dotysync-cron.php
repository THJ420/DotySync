<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DotySync_Cron {

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
		add_action( 'dotysync_custom_sync_event', array( $this, 'execute_sync' ) );
        
        // Trigger rescheduling when interval option matches
        add_action( 'update_option_dotysync_sync_interval', array( $this, 'handle_schedule_change' ), 10, 3 );
	}

	public function add_cron_interval( $schedules ) {
        $interval_hours = (int) get_option( 'dotysync_sync_interval', 24 );
        if ( $interval_hours < 1 ) $interval_hours = 24;
        
		$schedules["dotysync_every_{$interval_hours}_hours"] = array(
			'interval' => $interval_hours * HOUR_IN_SECONDS,
			/* translators: %d: number of hours */
			'display'  => sprintf( esc_html__( 'Every %d Hours', 'dotysync-for-woocommerce' ), $interval_hours ),
		);
		return $schedules;
	}

    /**
     * Reschedule if interval changes
     */
	public function handle_schedule_change( $old_value, $value, $option ) {
        // Clear old
        $timestamp = wp_next_scheduled( 'dotysync_custom_sync_event' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'dotysync_custom_sync_event' );
        }
        
        $new_hours = (int)$value;
        if ( $new_hours <= 0 ) $new_hours = 24;
        
        // Schedule new
        wp_schedule_event( time(), "dotysync_every_{$new_hours}_hours", 'dotysync_custom_sync_event' );
	}

	public function execute_sync() {
        // Double check validity inside execution
        $interval = (int) get_option( 'dotysync_sync_interval', 24 );
        if ( $interval <= 0 ) return;
        
        $sync = new DotySync_Sync();
        $sync->run_full_sync_cron();
	}
}

new DotySync_Cron();
