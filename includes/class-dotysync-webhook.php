<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DotySync_Webhook {

    private $namespace = 'dotysync/v1';
    private $route = 'webhook';

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->route, array(
            'methods'  => 'POST, GET', // Allow GET for verification probes
            'callback' => array( $this, 'handle_webhook' ),
            'permission_callback' => '__return_true', 
        ) );
    }

    public function handle_webhook( $request ) {
        // Handle Verification Probes (GET)
        if ( $request->get_method() === 'GET' ) {
            return new WP_REST_Response( array( 'code' => 'dotysync_webhook_ok', 'message' => 'DotySync Webhook Listener Ready' ), 200 );
        }
        
        $params = $request->get_json_params();
        $this->log( "Webhook Received. Payload: " . json_encode( $params ) );
        
        // Security: Verify Secret
        $webhook_secret = get_option( 'dotysync_webhook_secret' );
        if ( ! empty( $webhook_secret ) ) {
             $signature = $request->get_header( 'x-dotysync-secret' );
             if ( $signature !== $webhook_secret ) {
                 $this->log( "Security Fail: Invalid Secret." );
             }
        }
        
        if ( get_option( 'dotysync_webhook_enabled' ) !== 'yes' ) {
             $this->log( "Ignored: Webhook Disabled." );
             return new WP_REST_Response( array( 'message' => 'Webhook Disabled' ), 200 );
        }

        // Parse Payload
        // Payload is an array of items: [ { "productid": 123, ... }, { ... } ]
        
        // Ensure it's an array to loop over. If it's a single object (assoc array), wrap it.
        // check if index 0 exists, otherwise wrap.
        $items = array();
        if ( isset( $params[0] ) ) {
            $items = $params;
        } else {
            $items = array( $params );
        }
        
        $sync = DotySync::get_instance()->get_sync();
        $triggered_count = 0;
        
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            
            $product_id = '';
            
            // Try keys (Lowercase based on logs, but checking camelCase too just in case)
            if ( isset( $item['productid'] ) ) {
                $product_id = $item['productid'];
            } elseif ( isset( $item['productId'] ) ) {
                $product_id = $item['productId'];
            } elseif ( isset( $item['id'] ) ) {
                $product_id = $item['id'];
            } elseif ( isset( $item['data']['id'] ) ) {
                $product_id = $item['data']['id']; // Nested structure fallback
            }
            
            if ( ! empty( $product_id ) ) {
                $this->log( "Triggering Sync for ID: " . $product_id );
                $result = $sync->sync_single_product_by_id( $product_id );
                
                if ( is_wp_error( $result ) ) {
                    $this->log( "Sync Failed ID $product_id: " . $result->get_error_message() );
                } else {
                    $this->log( "Sync Success ID $product_id. WC Product ID: " . $result );
                    $triggered_count++;
                }
            }
        }
        
        if ( $triggered_count === 0 ) {
             $this->log( "Error: No valid Product IDs found in payload items." );
             return new WP_REST_Response( array( 'message' => 'No IDs found' ), 200 );
        }

        return new WP_REST_Response( array( 'message' => "Sync Triggered for $triggered_count items" ), 200 );
    }
    
    /**
     * persistent log for the last 20 events
     */
    private function log( $message ) {
        $logs = get_option( 'dotysync_webhook_logs', array() );
        if ( ! is_array( $logs ) ) $logs = array();
        
        $timestamp = current_time( 'mysql' );
        $entry = "[$timestamp] $message";
        
        // Prepend
        array_unshift( $logs, $entry );
        
        // Keep logs short (last 1 line)
        $logs = array_slice( $logs, 0, 1 );
        
        update_option( 'dotysync_webhook_logs', $logs );
    }
}
