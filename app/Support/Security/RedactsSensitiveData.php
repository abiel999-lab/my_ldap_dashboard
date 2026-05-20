<?php

namespace App\Support\Security;

class RedactsSensitiveData
{
    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEYWORDS = [
        'password',
        'passwd',
        'pwd',
        'bind_password',
        'client_secret',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'api_token',
        'private_key',
        'recovery_code',
        'otp',
        'otp_secret',
        'totp',
        'hotp',
        'authorization',
        'cookie',
        'set-cookie',
    ];

    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::redactArray($value);
        }

        return $value;
    }

    private static function redactArray(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = self::redactArray($value);

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
