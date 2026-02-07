<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DotySync_Api {

	private $api_url = 'https://api.dotykacka.cz/v2'; // Correct V2 URL
	private $token_transient_key = 'dotysync_access_token';

	/**
	 * Get Access Token.
     * Uses the "Sign In" endpoint with Refresh Token (mapped from Client Secret).
     * Client ID is used as Cloud ID.
	 */
	public function get_token() {
		$token = get_transient( $this->token_transient_key );

		if ( $token ) {
			return $token;
		}

        $cloud_id = get_option( 'dotysync_client_id' );
        $encrypted_refresh_token = get_option( 'dotysync_refresh_token' );
        
        if ( ! $cloud_id ) {
             return new WP_Error( 'missing_config', 'Client ID (Cloud ID) is missing.' );
        }

		if ( ! $encrypted_refresh_token ) {
			return new WP_Error( 'missing_auth', 'Plugin is not connected. Please click "Connect with DotySync" in settings.' );
		}

		$refresh_token = DotySync_Security::decrypt( $encrypted_refresh_token );

		if ( ! $refresh_token ) {
			return new WP_Error( 'decryption_failed', 'Failed to decrypt Refresh Token.' );
		}

        // Endpoint: https://api.dotykacka.cz/v2/signin/token
        // Auth Scheme: "User <refresh_token>" (Based on docs)
		$response = wp_remote_post( $this->api_url . '/signin/token', array(
			'headers' => array(
				'Authorization' => 'User ' . $refresh_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => json_encode( array(
                '_cloudId' => intval( $cloud_id ) // Cloud ID is required in body for full access
            ) ),
            'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {

			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

		if ( 200 !== $code && 201 !== $code ) {
            // Log full error for debugging
			return new WP_Error( 'api_error', 'API Error: ' . $code . ' - ' . substr( $body, 0, 200 ) ); // Truncate for display
		}

		if ( isset( $data['accessToken'] ) ) {
            // Cache token.
            $expires_in = isset( $data['expires_in'] ) ? intval( $data['expires_in'] ) : 3600;
			set_transient( $this->token_transient_key, $data['accessToken'], $expires_in - 60 );
			return $data['accessToken'];
		}


		return new WP_Error( 'invalid_response', 'Invalid response from Token API.' );
	}

	/**
	 * Fetch products from Dotypos API.
     * 
     * @param int $offset Pagination offset.
     * @param int $limit Items per page.
	 */
	public function get_products( $page = 1, $limit = 100 ) {
		$token = $this->get_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

        $cloud_id = get_option( 'dotysync_client_id' );
        
        // Pagination: Use 'page' and 'limit'
        $endpoint = $this->api_url . '/clouds/' . $cloud_id . '/products?limit=' . $limit . '&page=' . $page;
        
        // Debug
        // error_log( 'Dotypos Fetch URL: ' . $endpoint );

		$response = wp_remote_get( $endpoint, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
            'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

		if ( 200 !== $code ) {
			return new WP_Error( 'api_error', 'Failed to fetch products. Code: ' . $code . ' Response: ' . substr($body, 0, 100) );
		}

		return $data; 
	}
    
    /**
     * Fetch single product by ID.
     */
    public function get_single_product( $product_id ) {
        $token = $this->get_token();
		if ( is_wp_error( $token ) ) { return $token; }
        
        $cloud_id = get_option( 'dotypos_client_id' );
        // Endpoint: /clouds/{cloudId}/products/{productId}
        $endpoint = $this->api_url . '/clouds/' . $cloud_id . '/products/' . $product_id;
        
        $response = wp_remote_get( $endpoint, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
            'timeout' => 20,
		) );
        
        if ( is_wp_error( $response ) ) { return $response; }
        
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( 200 !== $code ) {
            return new WP_Error( 'api_error', 'Failed to fetch product ' . $product_id );
        }
        
        return $data;
    }
    
    /**
     * Fetch all categories (handles pagination).
     * Cached for 5 minutes.
     */
    public function get_categories() {
        $cache_key = 'dotysync_categories_cache';
        $cached = get_transient( $cache_key );
        if ( $cached ) {
            return $cached;
        }

        $token = $this->get_token();
        if ( is_wp_error( $token ) ) { return $token; }
        
        $cloud_id = get_option( 'dotysync_client_id' );
        
        $all_categories = array();
        $page = 1;
        $limit = 100;
        $has_more = true;
        
        while ( $has_more ) {
            $endpoint = $this->api_url . '/clouds/' . $cloud_id . '/categories?limit=' . $limit . '&page=' . $page;
            
            $response = wp_remote_get( $endpoint, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'timeout' => 20,
            ) );
            
            if ( is_wp_error( $response ) ) { break; }
            $code = wp_remote_retrieve_response_code( $response );
            if ( 200 !== $code ) { break; }
            
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            
            $batch_count = 0;
            
            // Extract categories
            if ( is_array( $data ) && isset( $data['data'] ) ) {
                 foreach ( $data['data'] as $cat ) {
                     if ( isset( $cat['id'] ) && isset( $cat['name'] ) ) {
                         $all_categories[ $cat['id'] ] = $cat['name'];
                     }
                     $batch_count++;
                 }
            } elseif ( is_array( $data ) ) {
                 foreach ( $data as $cat ) {
                     if ( isset( $cat['id'] ) && isset( $cat['name'] ) ) {
                         $all_categories[ $cat['id'] ] = $cat['name'];
                     }
                     $batch_count++;
                 }
            }
            
            // Check if we need next page
            if ( $batch_count < $limit ) {
                $has_more = false;
            } else {
                $page++;
                // Safety break
                if ( $page > 20 ) $has_more = false;
            }
        }
        
        if ( ! empty( $all_categories ) ) {
            set_transient( $cache_key, $all_categories, 300 );
        }
        
        return $all_categories;
    }
    
    /**
     * Check connection status.
     */
    public function check_connection() {
        $token = $this->get_token();
        if ( is_wp_error( $token ) ) {
            return false;
        }
        return true;
    }
}
