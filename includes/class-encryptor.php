<?php
/**
 * Encryption utilities for VoxManager.
 *
 * @package VoxManager
 */

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Encryptor
 *
 * Provides WordPress-safe AES-GCM encryption for secrets stored in options.
 */
final class Encryptor {
	/**
	 * Salt name to derive the encryption key.
	 *
	 * @var string
	 */
	private static string $salt = 'secure_auth';

	/**
	 * Length of the initialization vector for AES-GCM.
	 */
	private const IV_LENGTH = 12;

	/**
	 * Length of the authentication tag for AES-GCM.
	 */
	private const TAG_LENGTH = 16;
	private const CBC_PREFIX = 'cbc:';
	private const CBC_HMAC_LENGTH = 32;

	/**
	 * Last encryption/decryption error.
	 *
	 * @var string
	 */
	private static string $last_error = '';

	/**
	 * Encrypt a value using WordPress salts.
	 *
	 * @param string $value Value to encrypt.
	 * @return string|false Encrypted payload or false on failure.
	 */
	public static function encrypt( string $value ) {
		$key = self::derive_key();
		self::$last_error = '';

		if ( self::cipher_supported( 'aes-256-gcm' ) ) {
			$iv = random_bytes( self::IV_LENGTH );

			$tag = '';
			$ciphertext = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $ciphertext ) {
				return base64_encode( $iv . $tag . $ciphertext );
			}

			self::$last_error = __( 'AES-256-GCM unavailable; attempting fallback encryption.', 'voxmanager' );
		}

		if ( self::cipher_supported( 'aes-256-cbc' ) ) {
			$fallback = self::encrypt_cbc( $value, $key );
			if ( false !== $fallback ) {
				return $fallback;
			}
		}

		if ( self::$last_error === '' ) {
			self::$last_error = __( 'Encryption failed; OpenSSL cipher support unavailable.', 'voxmanager' );
		}

		return false;
	}

	/**
	 * Decrypt a value using WordPress salts.
	 *
	 * @param string $value Encrypted payload.
	 * @return string|false Decrypted value or false on failure.
	 */
	public static function decrypt( string $value ) {
		self::$last_error = '';
		if ( strpos( $value, self::CBC_PREFIX ) === 0 ) {
			$payload = substr( $value, strlen( self::CBC_PREFIX ) );
			return self::decrypt_cbc( $payload, self::derive_key() );
		}

		$decoded = base64_decode( $value, true );
		if ( false === $decoded || strlen( $decoded ) < self::IV_LENGTH + self::TAG_LENGTH ) {
			return false;
		}

		$key = self::derive_key();
		$iv = substr( $decoded, 0, self::IV_LENGTH );
		$tag = substr( $decoded, self::IV_LENGTH, self::TAG_LENGTH );
		$ciphertext = substr( $decoded, self::IV_LENGTH + self::TAG_LENGTH );

		$plaintext = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $plaintext ) {
			self::$last_error = __( 'Unable to decrypt AES-256-GCM payload.', 'voxmanager' );
			return false;
		}

		return $plaintext;
	}

	public static function get_last_error(): string {
		return self::$last_error;
	}

	/**
	 * Derive a consistent 256-bit key from WordPress salts.
	 */
	private static function derive_key(): string {
		static $key = null;

		if ( null === $key ) {
			$key_material = wp_salt( self::$salt );
			$key = hash( 'sha256', $key_material, true );
		}

		return $key;
	}

	private static function cipher_supported( string $cipher ): bool {
		static $ciphers = null;

		if ( null === $ciphers ) {
			$ciphers = openssl_get_cipher_methods( true );
		}

		return is_array( $ciphers ) && in_array( strtolower( $cipher ), array_map( 'strtolower', $ciphers ), true );
	}

	private static function encrypt_cbc( string $value, string $key ) {
		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv = random_bytes( $iv_length );

		$ciphertext = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			self::$last_error = __( 'AES-256-CBC encryption failed.', 'voxmanager' );
			return false;
		}

		$hmac = hash_hmac( 'sha256', $iv . $ciphertext, $key, true );
		return self::CBC_PREFIX . base64_encode( $iv . $hmac . $ciphertext );
	}

	private static function decrypt_cbc( string $payload, string $key ) {
		$decoded = base64_decode( $payload, true );
		if ( false === $decoded ) {
			return false;
		}

		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( strlen( $decoded ) < $iv_length + self::CBC_HMAC_LENGTH ) {
			return false;
		}

		$iv = substr( $decoded, 0, $iv_length );
		$hmac = substr( $decoded, $iv_length, self::CBC_HMAC_LENGTH );
		$ciphertext = substr( $decoded, $iv_length + self::CBC_HMAC_LENGTH );

		$calc_hmac = hash_hmac( 'sha256', $iv . $ciphertext, $key, true );
		if ( ! hash_equals( $hmac, $calc_hmac ) ) {
			self::$last_error = __( 'Encryption integrity check failed.', 'voxmanager' );
			return false;
		}

		$plaintext = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $plaintext ) {
			self::$last_error = __( 'Unable to decrypt AES-256-CBC payload.', 'voxmanager' );
			return false;
		}

		return $plaintext;
	}
}
