<?php
declare(strict_types=1);

namespace AstroMool;

final class Webhook
{
    /**
     * Verify a report webhook. $rawBody: the exact body received (file_get_contents('php://input')).
     * $signatureHeader: $_SERVER['HTTP_X_ASTROMOOL_SIGNATURE']. $secret: your webhook secret (dashboard > Keys).
     * Returns the decoded event; throws AstroMoolException if it is not genuine.
     */
    public static function verify(string $rawBody, string $signatureHeader, string $secret, int $toleranceSeconds = 300): array
    {
        $parts = [];
        foreach (explode(',', $signatureHeader) as $p) {
            $i = strpos($p, '=');
            if ($i !== false) $parts[trim(substr($p, 0, $i))] = trim(substr($p, $i + 1));
        }
        $fail = static function (string $d): void {
            throw new AstroMoolException(400, ['code' => 'webhook_signature_invalid', 'detail' => $d]);
        };
        if (!isset($parts['t'], $parts['v1'])) $fail('X-AstroMool-Signature header missing or malformed.');
        if (!ctype_digit($parts['t']) || abs(time() - (int)$parts['t']) > $toleranceSeconds) $fail('Webhook timestamp is outside the tolerance window.');
        $good = hash_hmac('sha256', $parts['t'] . '.' . $rawBody, $secret);
        if (!hash_equals($good, $parts['v1'])) $fail('Signature does not match.');
        return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    }
}
