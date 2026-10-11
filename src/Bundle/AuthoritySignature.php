<?php

namespace LBHurtado\ElectionCeremonies\Bundle;

use RuntimeException;

final class AuthoritySignature
{
    public function keyId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 16);
    }

    /** @return array{algorithm: string, key_id: string, signature: string} */
    public function sign(string $bytes, string $secretKey): array
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Authority signing key has an invalid length.');
        }

        return [
            'algorithm' => 'Ed25519',
            'key_id' => $this->keyId(sodium_crypto_sign_publickey_from_secretkey($secretKey)),
            'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secretKey)),
        ];
    }

    /** @param array<string, mixed> $signature
     * @param  array<string, string>  $trustedKeys  key ID to raw public key
     */
    public function verify(string $bytes, array $signature, array $trustedKeys): void
    {
        $keyId = $signature['key_id'] ?? null;
        $publicKey = is_string($keyId) ? ($trustedKeys[$keyId] ?? null) : null;
        $detached = is_string($signature['signature'] ?? null) ? base64_decode($signature['signature'], true) : false;

        if (($signature['algorithm'] ?? null) !== 'Ed25519' || ! is_string($publicKey)
            || ! hash_equals($keyId, $this->keyId($publicKey))
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $detached === false || strlen($detached) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($detached, $bytes, $publicKey)) {
            throw new RuntimeException('Election authority signature is invalid or untrusted.');
        }
    }
}
