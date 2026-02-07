<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DotySync_Sync {

	public function __construct() {
		// Helpers if needed
	}

    /**
     * Run a batch sync for AJAX.
     */
    public function run_batch_sync( $offset = 0, $limit = 20 ) {
        $api = DotySync::get_instance()->get_api();
        
        // Fetch Categories Map First (if not already fetched in this request)
        if ( empty( $this->categories_map ) ) {
            $this->categories_map = $api->get_categories();
        }
        
        // Pagination Logic: API uses 'Page', Frontend uses 'Offset'.
        // Page 1 = offset 0, Page 2 = offset 20 (if limit 20)
        $page = floor( $offset / $limit ) + 1;
        
        $products_data = $api->get_products( $page, $limit );

        if ( is_wp_error( $products_data ) ) {
            return $products_data;
        }

        $products = isset( $products_data['data'] ) ? $products_data['data'] : $products_data;
        
        if ( ! is_array( $products ) ) {
             return new WP_Error( 'invalid_data', 'Invalid data received from API' );
        }

        $synced_count = 0;
        $log_messages = array();

        foreach ( $products as $product_data ) {
            // Check for deleted
            if ( isset( $product_data['deleted'] ) && $product_data['deleted'] == true ) {
                continue; // Skip deleted items
            }
            
            $result = $this->sync_product( $product_data );
            if ( is_wp_error( $result ) ) {
                 // Suppressing explicit ID and price errors from logs unless debug mode, to avoid spam if many unknown format items
                 if ( $result->get_error_code() !== 'missing_id' && $result->get_error_code() !== 'missing_price_with_vat' ) {
                    $log_messages[] = 'Error: ' . $result->get_error_message();
                 }
            } else {
                $synced_count++;
            }
        }

        // Determine if more products exist
        // If we received fewer items than limit, we are done.
        $has_more = count( $products ) >= $limit;
        
        // Double check against 'lastPage' if available to avoid infinite loop on exactly full pages
        if ( isset( $products_data['currentPage'] ) && isset( $products_data['lastPage'] ) ) {
            if ( $products_data['currentPage'] >= $products_data['lastPage'] ) {
                $has_more = false;
            }
        }
        
        $next_offset = $offset + count( $products ); // Use actual count of products received for next offset

        return array(
            'synced_count' => $synced_count,
            'log_messages' => $log_messages,
            'has_more'     => $has_more,
            'next_offset'  => $next_offset
        );
    }
    
    private $categories_map = array();

    /**
     * Sync single product by ID (Used by Webhook).
     */
    public function sync_single_product_by_id( $dotypos_id ) {
        $api = DotySync::get_instance()->get_api();
        // We need a method to fetch single product. Assuming get_product allows ID.
        // If not, we have to implement it in API class. 
        // For now, let's assume we add get_single_product to API class.
        $product_data = $api->get_single_product( $dotypos_id );
        
        if ( is_wp_error( $product_data ) ) {
            return $product_data;
        }
        
        // Fetch categories map if empty
        if ( empty( $this->categories_map ) ) {
            $this->categories_map = $api->get_categories();
        }
        
        return $this->sync_product( $product_data );
    }

    /**
     * Sync single product.
     */
    private function sync_product( $data ) {
        // ID Mapping: 'id' or 'productId' or 'productid'
        $sku = '';
        if ( ! empty( $data['id'] ) ) {
            $sku = (string) $data['id'];
        } elseif ( ! empty( $data['productId'] ) ) {
            $sku = (string) $data['productId'];
        } elseif ( ! empty( $data['productid'] ) ) {
            $sku = (string) $data['productid'];
        }
        
        if ( empty( $sku ) ) {
            return new WP_Error( 'missing_id', 'ID missing' );
        }
        
        $name = isset( $data['name'] ) ? $data['name'] : 'Unnamed Product';
        
        // Price Formatting (Check camelCase and lowercase)
        $raw_price = 0;
        if ( isset( $data['priceWithVat'] ) ) {
            $raw_price = $data['priceWithVat'];
        } elseif ( isset( $data['priceWithVAT'] ) ) {
            $raw_price = $data['priceWithVAT'];
        } elseif ( isset( $data['pricewithvat'] ) ) {
            $raw_price = $data['pricewithvat'];
        }
        
        // Ensure string
        $clean_price = (string) $raw_price;
        // If "PLN 10.00", keep digits, dot, comma.
        $clean_price = preg_replace( '/[^0-9.,]/', '', $clean_price ); 
        $clean_price = str_replace( ',', '.', $clean_price ); 
        $price = floatval( $clean_price );
        
        // Category Mappping (Check camelCase and lowercase)
        $cat_id_cloud = '';
        if ( isset( $data['_categoryId'] ) ) {
            $cat_id_cloud = $data['_categoryId'];
        } elseif ( isset( $data['categoryid'] ) ) {
            $cat_id_cloud = $data['categoryid'];
        }
        
        $category_name = '';
        if ( $cat_id_cloud && isset( $this->categories_map[ $cat_id_cloud ] ) ) {
            $category_name = $this->categories_map[ $cat_id_cloud ];
        }

        // Check if exists by SKU
        $product_id = wc_get_product_id_by_sku( $sku );
        
        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            $is_new = false;
        } else {
            $product = new WC_Product_Simple();
            $product->set_sku( $sku );
            $is_new = true;
        }

        // Apply Status Logic
        if ( $is_new ) {
            $status_setting = get_option( 'dotysync_status_new', 'draft' );
            $product->set_status( $status_setting );
        } else {
            $status_setting = get_option( 'dotysync_status_update', 'publish' );
            $product->set_status( $status_setting );
        }

        $product->set_name( $name );
        $product->set_regular_price( $price );
        
        // Stock Logic
        if ( $price == 0 ) {
             $product->set_stock_status( 'outofstock' );
        } else {
             $product->set_stock_status( 'instock' );
        }
        
        // IMAGES: STRICTLY IGNORED (Do not touch image logic)

        // Category Assignment
        if ( ! empty( $category_name ) ) {
             $wc_cat_id = $this->get_or_create_category( $category_name );
             if ( $wc_cat_id ) {
                 $product->set_category_ids( array( $wc_cat_id ) );
             }
        }

        $product->save();
        return $product->get_id();
    }

    /**
     * Get or create category.
     * Rule: If creating new, place under "Recently Stocked".
     */
    private function get_or_create_category( $name ) {
        $term = get_term_by( 'name', $name, 'product_cat' );
        if ( $term ) {
            return $term->term_id;
        }
        
        // Parent Category Logic
        $parent_term_id = 0;
        $parent_name = 'Recently Stocked';
        $parent_term = get_term_by( 'name', $parent_name, 'product_cat' );
        
        if ( $parent_term ) {
            $parent_term_id = $parent_term->term_id;
        } else {
            // Create Parent
            $new_parent = wp_insert_term( $parent_name, 'product_cat' );
            if ( ! is_wp_error( $new_parent ) ) {
                $parent_term_id = $new_parent['term_id'];
            }
        }

        // Create New Category under Parent
        $new_term = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent_term_id ) );
        
        if ( is_wp_error( $new_term ) ) {
            return 0;
        }
        
        return $new_term['term_id'];
    }
    
    /**
     * Run Full Sync (For Cron).
     * Loops through all pages safely.
     */
    public function run_full_sync_cron() {
        $offset = 0;
        $limit = 100; // Larger batch for server side
        
        do {
            $result = $this->run_batch_sync( $offset, $limit );
            
            if ( is_wp_error( $result ) ) {

                break;
            }
            
            $offset = $result['next_offset'];
            
            // Sleep briefly to be nice to server/API
            sleep(1);
            
        } while ( $result['has_more'] );
    }
}
