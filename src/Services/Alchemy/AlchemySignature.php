<?php

namespace ItHealer\LaravelEvm\Services\Alchemy;

/**
 * Verifies the HMAC-SHA256 signature Alchemy sends in the X-Alchemy-Signature header.
 * The digest must be computed over the RAW request body using the webhook signing key.
 *
 * https://www.alchemy.com/docs/reference/webhook-signature-verification
 */
class AlchemySignature
{
    public static function isValid(string $rawPayload, ?string $signature, string $signingKey): bool
    {
        if (!$signature) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, $signingKey);

        return hash_equals($expected, $signature);
    }
}
