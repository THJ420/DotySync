<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DotySync_Settings_Page {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
        add_action( 'admin_init', array( $this, 'handle_connect_request' ) ); // Handle outbound connect
        add_action( 'admin_init', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_dotysync_manual_sync', array( $this, 'ajax_sync_batch' ) );
        add_action( 'wp_ajax_dotysync_debug_fetch', array( $this, 'ajax_debug_fetch' ) );
        add_action( 'wp_ajax_dotysync_test_webhook', array( $this, 'ajax_test_webhook' ) );
        add_action( 'wp_ajax_dotysync_refresh_webhook_logs', array( $this, 'ajax_refresh_webhook_logs' ) );
        
        // Add Settings Link to Plugins Page
        $plugin_basename = plugin_basename( DOTYSYNC_DIR . 'dotysync-for-woocommerce.php' );
        add_filter( 'plugin_action_links_' . $plugin_basename, array( $this, 'add_settings_link' ) );
	}
    
    public function add_settings_link( $links ) {
        $settings_link = '<a href="admin.php?page=dotysync-for-woocommerce">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
    
    public function ajax_refresh_webhook_logs() {
        check_ajax_referer( 'dotysync_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        
        $logs = get_option( 'dotysync_webhook_logs', array() );
        wp_send_json_success( $logs );
    }
    
    public function ajax_test_webhook() {
        check_ajax_referer( 'dotysync_sync_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        $webhook_url = get_rest_url( null, 'dotysync/v1/webhook' );
        
        // Retrieve a valid product ID if possible, otherwise use a dummy ID
        // Let's try to get a random product ID from the DB to make it a "real" test if possible,
        // but for connectivity test a dummy is fine.
        // We will send a dummy ID 'TEST-123'
        $payload = array(
            'id' => 'TEST-WEBHOOK-SIMULATION',
            'event' => 'UPDATE',
            'timestamp' => time()
        );
        
        $args = array(
            'body'    => json_encode( $payload ),
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'timeout' => 5,
        );
        
        // Add secret header if set
        $secret = get_option( 'dotysync_webhook_secret' );
        if ( $secret ) {
            $args['headers']['x-dotysync-secret'] = $secret;
        }
        
        $response = wp_remote_post( $webhook_url, $args );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Request Failed: ' . $response->get_error_message() );
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        
        if ( $code === 200 ) {
            wp_send_json_success( array( 'message' => 'Webhook received successfully!', 'response' => wp_strip_all_tags( $body ) ) );
        } else {
            wp_send_json_error( 'Webhook endpoint returned error ' . $code . ': ' . wp_strip_all_tags( $body ) );
        }
    }
    
    public function ajax_debug_fetch() {
        check_ajax_referer( 'dotysync_sync_nonce', 'nonce' );
        $api = DotySync::get_instance()->get_api();
        $products = $api->get_products( 1, 1 ); // Fetch 1 product from Page 1
        wp_send_json( $products );
    }

	public function add_menu_page() {
		add_submenu_page(
			'woocommerce',
			'DotySync',
			'DotySync',
			'manage_options',
			'dotysync-for-woocommerce',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( 'dotysync_settings_group', 'dotysync_client_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        // Removed boolean toggle, replaced with interval. If interval > 0, it's enabled.
        register_setting( 'dotysync_settings_group', 'dotysync_sync_interval', array(
            'type' => 'integer',
            'default' => 24,
            'sanitize_callback' => 'absint'
        )); 
        
        register_setting( 'dotysync_settings_group', 'dotysync_client_secret', array(
            'sanitize_callback' => array( $this, 'sanitize_secret' )
        ));
        
        // Hidden field for refresh token
        register_setting( 'dotysync_settings_group', 'dotysync_refresh_token', array( 'sanitize_callback' => 'sanitize_text_field' ) ); 

        // Webhook Settings
        register_setting( 'dotysync_webhook_group', 'dotysync_webhook_enabled', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'dotysync_webhook_group', 'dotysync_webhook_secret', array( 'sanitize_callback' => 'sanitize_text_field' ) );

        // Sync Status Settings
        register_setting( 'dotysync_settings_group', 'dotysync_status_new', array( 'default' => 'draft', 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'dotysync_settings_group', 'dotysync_status_update', array( 'default' => 'publish', 'sanitize_callback' => 'sanitize_text_field' ) );
	}
    
    public function sanitize_secret( $input ) {
        if ( ! empty( $input ) && strpos( $input, '***' ) === false ) {
            return DotySync_Security::encrypt( trim( $input ) );
        }
        return get_option( 'dotysync_client_secret' );
    }

    /**
     * Handle the return from DotySync Auth via Redirect URI
     */
    public function handle_oauth_callback() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification happens via state param in OAuth flow
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'dotysync-for-woocommerce' || ! isset( $_GET['action'] ) || $_GET['action'] !== 'oauth_callback' ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Correct parameter names based on user debug data: 'token' and 'cloudid'
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $refresh_token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $cloud_id = isset( $_GET['cloudid'] ) ? sanitize_text_field( wp_unslash( $_GET['cloudid'] ) ) : '';
        
        if ( $refresh_token ) {
            // Save Refresh Token
            $encrypted = DotySync_Security::encrypt( $refresh_token );
            update_option( 'dotysync_refresh_token', $encrypted );
            
            // Save Cloud ID if provided, as it's critical for API calls
            if ( $cloud_id ) {
                update_option( 'dotysync_client_id', sanitize_text_field( $cloud_id ) );
            }
            
            wp_safe_redirect( esc_url( admin_url( 'admin.php?page=dotysync-for-woocommerce&status=connected' ) ) );
            exit;
        }
        
        // Error handling if 'error' param exists
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['error'] ) ) {
             // phpcs:ignore WordPress.Security.NonceVerification.Recommended
             add_settings_error( 'dotysync_settings_group', 'oauth_error', 'DotySync Auth Error: ' . sanitize_text_field( wp_unslash( $_GET['error'] ) ) );
        }
    }
    
    /**
     * Handle Disconnect action
     */
    public function handle_disconnect() {
        if ( isset( $_POST['dotysync_disconnect'] ) && check_admin_referer( 'dotysync_disconnect_action' ) ) {
             delete_option( 'dotysync_refresh_token' );
             delete_transient( 'dotysync_access_token' );
             add_settings_error( 'dotysync_settings_group', 'disconnected', 'Disconnected from DotySync.' );
        }
    }

    /**
     * Handle "Connect" button click -> Generates POST form to Dotypos
     */
    public function handle_connect_request() {
        if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'dotysync_connect_request' ) {
            return;
        }
        
        check_admin_referer( 'dotysync_connect_request_action' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $client_id = get_option( 'dotysync_client_id' );
        $encrypted_secret = get_option( 'dotysync_client_secret' );
        
        if ( ! $encrypted_secret ) {
            wp_die( 'Please save Client Secret first.' );
        }
        
        $client_secret = DotySync_Security::decrypt( $encrypted_secret );
        
        $timestamp = time(); // Unix timestamp
        $message = (string) $timestamp;
        
        // HMAC-SHA256 Signature
        $signature = hash_hmac( 'sha256', $message, $client_secret );
        
        $redirect_uri = admin_url( 'admin.php?page=dotysync-for-woocommerce&action=oauth_callback' );
        // State is optional but good practice
        $state = wp_create_nonce( 'dotysync_oauth_state' );

        // Render Auto-submit Form
        ?>
        <!DOCTYPE html>
        <html>
        <head><title>Redirecting to DotySync...</title></head>
        <body>
            <p>Redirecting to DotySync Authorization...</p>
            <form id="dotysync_auth_form" method="POST" action="https://admin.dotykacka.cz/client/connect/v2">
                <input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>">
                <input type="hidden" name="timestamp" value="<?php echo esc_attr( $timestamp ); ?>">
                <input type="hidden" name="signature" value="<?php echo esc_attr( $signature ); ?>">
                <input type="hidden" name="scope" value="*">
                <input type="hidden" name="redirect_uri" value="<?php echo esc_url( $redirect_uri ); ?>">
                <input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
            </form>
            <script>
                document.getElementById('dotysync_auth_form').submit();
            </script>
        </body>
        </html>
        <?php
        exit;
    }

	public function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_dotysync-for-woocommerce' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'dotysync-admin-js', DOTYSYNC_URL . 'assets/admin.js', array( 'jquery' ), DOTYSYNC_VERSION, true );
        wp_enqueue_style( 'dotysync-admin-css', DOTYSYNC_URL . 'assets/admin.css', array(), DOTYSYNC_VERSION );
        wp_localize_script( 'dotysync-admin-js', 'dotysyncParams', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'dotysync_sync_nonce' )
        ));
	}

    public function ajax_sync_batch() {
        check_ajax_referer( 'dotysync_sync_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $limit = 20;
        
        $sync = new DotySync_Sync();
        $result = $sync->run_batch_sync( $offset, $limit );
        
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        
        wp_send_json_success( $result );
    }
    
	public function render_page() {
        $api = DotySync::get_instance()->get_api();
        $connected = $api->check_connection(); 
        
        $refresh_token_exists = get_option( 'dotysync_refresh_token' );
        
        $status_color = $connected ? '#46b450' : '#dc3232'; // Green : Red
        $status_text = $connected ? 'Connected' : 'Disconnected';
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
        
        // CSS Style for "Good" Look
        ?>
        <style>
            .dotysync-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                padding: 20px;
                margin-top: 20px;
                max-width: 800px;
                border-radius: 4px;
            }
            .dotysync-card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
                font-size: 1.3em;
            }
            .dotysync-status-badge {
                display: inline-block;
                padding: 5px 10px;
                border-radius: 4px;
                color: #fff;
                font-weight: 500;
                font-size: 0.9em;
            }
            .form-table th { width: 220px; }
        </style>

		<div class="wrap">
            <h1 style="margin-bottom: 20px;">DotySync Settings (v3.1)</h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=dotysync-for-woocommerce&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">General API</a>
                <a href="?page=dotysync-for-woocommerce&tab=realtime" class="nav-tab <?php echo $active_tab == 'realtime' ? 'nav-tab-active' : ''; ?>">Real-Time Sync</a>
                <a href="?page=dotysync-for-woocommerce&tab=style" class="nav-tab <?php echo $active_tab == 'style' ? 'nav-tab-active' : ''; ?>">Sync Style</a>
            </h2>
            
            <?php settings_errors(); ?>
            
            <?php if ( $active_tab == 'general' ) : ?>
            
                <!-- Connection Status Card -->
                <div class="dotysync-card">
                    <h2>Connection Status</h2>
                    
                    <p>
                        <strong>Status:</strong> 
                        <span class="dotysync-status-badge" style="background-color: <?php echo esc_attr( $status_color ); ?>;">
                            <?php echo esc_html( $status_text ); ?>
                        </span>
                    </p>
                    
                    <?php 
                    if ( ! $connected ) {
                        $token_error = $api->get_token();
                        if ( is_wp_error( $token_error ) ) {
                            echo '<p style="color:#d63638;"><strong>Error Details:</strong> ' . esc_html( $token_error->get_error_message() ) . '</p>';
                        }
                    }
                    ?>
                    
                    <?php if ( ! $connected && get_option( 'dotysync_client_id' ) && get_option( 'dotysync_client_secret' ) ) : ?>
                        <p>Click below to authorize this plugin to access your Dotypos cloud.</p>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dotysync-for-woocommerce&action=dotysync_connect_request' ), 'dotysync_connect_request_action' ) ); ?>" class="button button-primary">Connect with DotySync</a>
                    <?php endif; ?>

                    <?php if ( $refresh_token_exists ) : ?>
                        <div style="margin-top: 15px;">
                            <form method="post" style="display:inline-block;">
                                <?php wp_nonce_field( 'dotysync_disconnect_action' ); ?>
                                <input type="hidden" name="dotysync_disconnect" value="1">
                                <button type="submit" class="button button-secondary" onclick="return confirm('Are you sure?');">Disconnect Account</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Configuration Card -->
                <div class="dotysync-card"> 
                    <h2>Configuration</h2>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields( 'dotysync_settings_group' );
                        do_settings_sections( 'dotysync-for-woocommerce' );
                        ?>
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row">Client ID (Cloud ID)</th>
                                <td><input type="text" name="dotysync_client_id" value="<?php echo esc_attr( get_option( 'dotysync_client_id' ) ); ?>" class="regular-text" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Client Secret</th>
                                <td>
                                    <?php 
                                    $secret = get_option( 'dotysync_client_secret' );
                                    $placeholder = $secret ? '********' : '';
                                    ?>
                                    <input type="password" name="dotysync_client_secret" value="<?php echo esc_attr( $placeholder ); ?>" class="regular-text" placeholder="Enter Client Secret to update" />
                                    <p class="description">Used to sign the connection request.</p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button( 'Save Changes' ); ?>
                    </form>
                </div>
                
                <?php if ( $connected ) : ?>
                <div class="dotysync-card">
                    <h2>Manual Sync</h2>
                    <p>Click below to start a full sync manually. This process runs in batches of 20.</p>
                    <div style="margin-bottom: 10px;">
                        <button id="dotysync-sync-btn" class="button button-primary button-large">Sync Now</button>
                        <button id="dotysync-stop-btn" class="button button-secondary button-large" disabled>Stop Sync</button>
                        <button id="dotysync-debug-btn" class="button button-secondary button-large">Debug: Fetch 1 Product</button>
                    </div>
                    <textarea id="dotysync-logs" style="width: 100%; height: 200px; background: #f0f0f1; font-family: monospace; margin-top: 10px; border:1px solid #ddd;" readonly></textarea>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        $('#dotysync-debug-btn').on('click', function(e) {
                            e.preventDefault();
                            var $log = $('#dotysync-logs');
                            $log.val('Fetching 1 product for debug...\n');
                            
                            $.post(dotysyncParams.ajaxurl, {
                                action: 'dotysync_debug_fetch',
                                nonce: dotysyncParams.nonce
                            }, function(response) {
                                $log.val($log.val() + JSON.stringify(response, null, 2));
                            });
                        });
                    });
                    </script>
                </div>
                <?php else : ?>
                    <!-- <p>Please connect...</p> inside config card implies flow -->
                <?php endif; ?>
                
            <?php elseif ( $active_tab == 'realtime' ) : ?>
            
                <div class="dotysync-card">
                    <h2>Webhook Settings</h2>
                    <p>Use Webhooks to instantly sync product changes from Dotypos to WooCommerce.</p>
                    
                    <form method="post" action="options.php">
                        <?php 
                            settings_fields( 'dotysync_webhook_group' ); 
                        ?>
                        
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row">Webhook Endpoint URL</th>
                                <td>
                                    <input type="text" class="regular-text" value="<?php echo esc_url( get_rest_url( null, 'dotysync/v1/webhook' ) ); ?>" readonly />
                                    <p class="description">Copy this URL and paste it into your Dotypos Webhook settings.</p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Enable Webhook Listener</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="dotysync_webhook_enabled" value="yes" <?php checked( get_option( 'dotysync_webhook_enabled' ), 'yes' ); ?> />
                                        Enable Real-Time Sync
                                    </label>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Webhook Signing Secret</th>
                                <td>
                                    <input type="text" name="dotysync_webhook_secret" value="<?php echo esc_attr( get_option( 'dotysync_webhook_secret' ) ); ?>" class="regular-text" />
                                    <p class="description">Optional: If DotySync (Dotypos) allows setting a secret, verify it here.</p>
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button(); ?>
                    </form>
                    
                    <hr>
                    <h3>Test Webhook Connectivity</h3>
                    <p>Click below to simulate a webhook event originating from this server properly.</p>
                    <button id="dotysync-test-webhook-btn" class="button button-secondary">Simulate Webhook (Internal Test)</button>
                    <div id="webhook-test-result" style="margin-top: 10px; background: #fff; border: 1px solid #ddd; padding: 10px; display: none;"></div>
                    
                    <hr>
                    <h3>Recent Webhook Activity</h3>
                    <p>View the raw logs of the last 50 webhook events received. Useful for debugging payload issues.</p>
                    <p>
                        <button id="dotysync-refresh-logs-btn" class="button button-secondary">Refresh Logs</button>
                    </p>
                    <textarea id="dotysync-webhook-logs" style="width: 100%; height: 300px; background: #f0f0f1; font-family: monospace;" readonly>Loading logs...</textarea>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        // Test Button Logic
                        $('#dotysync-test-webhook-btn').on('click', function(e) {
                            e.preventDefault();
                            var $result = $('#webhook-test-result');
                            $result.show().html('Testing connection to ' + '<?php echo esc_js( get_rest_url( null, 'dotysync/v1/webhook' ) ); ?>' + '...');
                            
                            $.post(dotysyncParams.ajaxurl, {
                                action: 'dotysync_test_webhook',
                                nonce: dotysyncParams.nonce
                            }, function(response) {
                                if ( response.success ) {
                                    $result.html('<strong style="color:green;">Success:</strong> ' + response.data.message + '<br><small>Response: ' + JSON.stringify(response.data.response) + '</small>');
                                    fetchLogs(); // Refresh logs after test
                                } else {
                                    $result.html('<strong style="color:red;">Failed:</strong> ' + response.data);
                                }
                            }).fail(function() {
                                $result.html('<strong style="color:red;">AJAX Error:</strong> Could not reach server.');
                            });
                        });
                        
                        // Log Fetching Logic
                        function fetchLogs() {
                            $('#dotysync-webhook-logs').val('Refreshing...');
                             $.post(dotysyncParams.ajaxurl, {
                                action: 'dotysync_refresh_webhook_logs',
                                nonce: dotysyncParams.nonce
                            }, function(response) {
                                if ( response.success ) {
                                     $('#dotysync-webhook-logs').val( response.data.join('\n') );
                                } else {
                                     $('#dotysync-webhook-logs').val( 'Failed to fetch logs.' );
                                }
                            });
                        }
                        
                        $('#dotysync-refresh-logs-btn').on('click', function(e) {
                            e.preventDefault();
                            fetchLogs();
                        });
                        
                        // Auto fetch on load
                        fetchLogs();
                    });
                    </script>
                </div>
            
            <?php elseif ( $active_tab == 'style' ) : ?>

                <div class="dotysync-card">
                    <h2>Sync Logic & Style</h2>
                    <p>Control how and when your products are synced from Dotypos.</p>
                    
                    <form method="post" action="options.php">
                        <?php 
                            settings_fields( 'dotysync_settings_group' ); 
                        ?>
                        
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row">Sync Interval (Hours)</th>
                                <td>
                                    <input type="number" name="dotysync_sync_interval" min="1" max="168" value="<?php echo esc_attr( get_option( 'dotysync_sync_interval', 24 ) ); ?>" class="small-text" />
                                    <p class="description">How many hours between automatic syncs? Set to regular number (e.g. 1, 12, 24).</p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Status for NEW Products</th>
                                <td>
                                    <select name="dotysync_status_new">
                                        <option value="draft" <?php selected( get_option( 'dotysync_status_new', 'draft' ), 'draft' ); ?>>Draft</option>
                                        <option value="publish" <?php selected( get_option( 'dotysync_status_new', 'draft' ), 'publish' ); ?>>Publish</option>
                                    </select>
                                    <p class="description">Default status when a product is created for the first time.</p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Status for UPDATED Products</th>
                                <td>
                                    <select name="dotysync_status_update">
                                        <option value="draft" <?php selected( get_option( 'dotysync_status_update', 'publish' ), 'draft' ); ?>>Set to Draft</option>
                                        <option value="publish" <?php selected( get_option( 'dotysync_status_update', 'publish' ), 'publish' ); ?>>Keep Live (Publish)</option>
                                    </select>
                                    <p class="description">What happens when an existing product is updated?</p>
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button( 'Save Sync Settings' ); ?>
                    </form>
                </div>
            
            <?php endif; ?>
		</div>
		<?php
	}
}

new DotySync_Settings_Page();
