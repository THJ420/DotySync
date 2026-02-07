<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DotySync_Security {

	private static $method = 'AES-256-CBC';

	/**
	 * Get the encryption key.
	 * Uses AUTH_KEY from wp-config.php if available, otherwise generates a fallback.
	 */
	private static function get_key() {
		if ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) {
			return AUTH_KEY;
		}
		// Fallback key - in production, this should ideally be more persistent/secure if AUTH_KEY is missing.
        // For now, we rely on the site having standard WP security keys.
		return 'dotysync_fallback_secret_key_change_me'; 
	}

	/**
	 * Encrypt data.
	 *
	 * @param string $data The data to encrypt.
	 * @return string|false Encrypted data (base64 encoded) or false on failure.
	 */
	public static function encrypt( $data ) {
		if ( empty( $data ) ) {
			return false;
		}

		$key = substr( hash( 'sha256', self::get_key() ), 0, 32 );
		$iv_length = openssl_cipher_iv_length( self::$method );
		$iv = openssl_random_pseudo_bytes( $iv_length );

		$encrypted = openssl_encrypt( $data, self::$method, $key, 0, $iv );
        
        if ( false === $encrypted ) {
            return false;
        }

		// Store IV with the encrypted data (needed for decryption)
		return base64_encode( $encrypted . '::' . $iv );
	}

	/**
	 * Decrypt data.
	 *
	 * @param string $data The encrypted data (base64 encoded).
	 * @return string|false Decrypted data or false on failure.
	 */
	public static function decrypt( $data ) {
		if ( empty( $data ) ) {
			return false;
		}

		$data = base64_decode( $data );
        if ( ! strpos( $data, '::' ) ) {
            return false;
        }

		list( $encrypted_data, $iv ) = explode( '::', $data, 2 );

		$key = substr( hash( 'sha256', self::get_key() ), 0, 32 );
        
		return openssl_decrypt( $encrypted_data, self::$method, $key, 0, $iv );
	}
}
