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

	/**
	 * Encrypt a value using WordPress salts.
	 *
	 * @param string $value Value to encrypt.
	 * @return string|false Encrypted payload or false on failure.
	 */
	public static function encrypt( string $value ) {
		$key = self::derive_key();
		$iv = random_bytes( self::IV_LENGTH );

		$tag = '';
		$ciphertext = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			return false;
		}

		return base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a value using WordPress salts.
	 *
	 * @param string $value Encrypted payload.
	 * @return string|false Decrypted value or false on failure.
	 */
	public static function decrypt( string $value ) {
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
			return false;
		}

		return $plaintext;
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
}
