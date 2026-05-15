<?php
/**
 * Security helpers for encryption, API key hashing, and common response headers.
 */

if (!function_exists('securityBinaryKey')) {
    function securityBinaryKey($config) {
        $rawKey = $config['security']['encryption_key'] ?? '';

        if (!is_string($rawKey) || trim($rawKey) === '') {
            throw new RuntimeException('Missing security.encryption_key configuration');
        }

        $rawKey = trim($rawKey);

        if (preg_match('/^[A-Fa-f0-9]{64}$/', $rawKey)) {
            return hex2bin($rawKey);
        }

        $decoded = base64_decode($rawKey, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }

        if (function_exists('sodium_crypto_generichash')) {
            return sodium_crypto_generichash($rawKey, '', 32);
        }

        return hash('sha256', $rawKey, true);
    }
}

if (!function_exists('generateApiKeyPlaintext')) {
    function generateApiKeyPlaintext($config) {
        $prefix = $config['security']['api_key_prefix'] ?? 'svcapp_';
        return $prefix . bin2hex(random_bytes(32));
    }
}

if (!function_exists('hashApiKeyValue')) {
    function hashApiKeyValue($apiKey, $config) {
        $algo = $config['security']['api_key_hash_algo'] ?? 'sha256';
        return hash_hmac($algo, $apiKey, securityBinaryKey($config));
    }
}

if (!function_exists('encryptSensitiveValue')) {
    function encryptSensitiveValue($plaintext, $config) {
        $key = securityBinaryKey($config);

        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $key);
            return 'sod1:' . base64_encode($nonce . $ciphertext);
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed');
        }

        return 'gcm1:' . base64_encode($nonce . $tag . $ciphertext);
    }
}

if (!function_exists('decryptSensitiveValue')) {
    function decryptSensitiveValue($payload, $config) {
        $key = securityBinaryKey($config);

        if (strpos($payload, 'sod1:') === 0) {
            $decoded = base64_decode(substr($payload, 5), true);
            if ($decoded === false) {
                throw new RuntimeException('Invalid encrypted payload');
            }

            $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            $nonce = substr($decoded, 0, $nonceLength);
            $ciphertext = substr($decoded, $nonceLength);
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, '', $nonce, $key);
            if ($plaintext === false) {
                throw new RuntimeException('Decryption failed');
            }

            return $plaintext;
        }

        if (strpos($payload, 'gcm1:') === 0) {
            $decoded = base64_decode(substr($payload, 5), true);
            if ($decoded === false) {
                throw new RuntimeException('Invalid encrypted payload');
            }

            $nonce = substr($decoded, 0, 12);
            $tag = substr($decoded, 12, 16);
            $ciphertext = substr($decoded, 28);
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            if ($plaintext === false) {
                throw new RuntimeException('Decryption failed');
            }

            return $plaintext;
        }

        throw new RuntimeException('Unknown encrypted payload format');
    }
}

if (!function_exists('sendSecurityHeaders')) {
    function sendSecurityHeaders($config, $isApiRequest = false) {
        $headers = $config['security']['headers'] ?? [];
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($isApiRequest) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
    }
}