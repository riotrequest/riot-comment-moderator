<?php
defined( 'ABSPATH' ) || exit;

function rcm_valid_key( $key ) {
	return is_string( $key ) && preg_match( '/\A[A-Za-z0-9._-]{12,220}\z/', $key );
}

function rcm_credential_master_key() {
	if ( ! defined( 'RIOT_COMMENT_MODERATOR_CREDENTIAL_KEY' ) || ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
		return false;
	}
	$key = base64_decode( (string) RIOT_COMMENT_MODERATOR_CREDENTIAL_KEY, true );
	return false !== $key && SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES === strlen( $key ) ? $key : false;
}

function rcm_encrypt_key( $provider, $key ) {
	$master = rcm_credential_master_key();
	if ( false === $master ) {
		return false;
	}
	$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
	return 'v1:' . base64_encode( $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $key, $provider, $nonce, $master ) );
}

function rcm_key( $provider ) {
	$provider = rcm_providers()[ $provider ]['key'] ?? '';
	if ( '' === $provider ) {
		return '';
	}
	$constant = 'openrouter' === $provider ? 'RIOT_COMMENT_MODERATOR_OPENROUTER_KEY' : 'RIOT_COMMENT_MODERATOR_OPENAI_KEY';
	if ( defined( $constant ) ) {
		return trim( (string) constant( $constant ) );
	}
	$value = get_option( 'rcm_key_' . $provider, '' );
	if ( ! is_string( $value ) || 0 !== strpos( $value, 'v1:' ) ) {
		return rcm_valid_key( $value ) ? $value : '';
	}
	$master = rcm_credential_master_key();
	if ( false === $master ) {
		return '';
	}
	$bytes  = base64_decode( substr( $value, 3 ), true );
	$length = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
	if ( false === $bytes || strlen( $bytes ) <= $length + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES ) {
		return '';
	}
	$key = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $bytes, $length ), $provider, substr( $bytes, 0, $length ), $master );
	return rcm_valid_key( $key ) ? $key : '';
}

function rcm_migrate_legacy_keys() {
	if ( false === rcm_credential_master_key() ) {
		return;
	}
	global $wpdb;
	foreach ( array( 'openai', 'openrouter' ) as $provider ) {
		$option = 'rcm_key_' . $provider;
		$key    = get_option( $option, '' );
		if ( ! rcm_valid_key( $key ) ) {
			continue;
		}
		// Match the old value without sending the plaintext to MySQL or overwriting a concurrent save.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value=%s, autoload='no' WHERE option_name=%s AND SHA2(option_value, 256)=%s",
			rcm_encrypt_key( $provider, $key ), $option, hash( 'sha256', $key )
		) );
		if ( $updated ) {
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}
}
