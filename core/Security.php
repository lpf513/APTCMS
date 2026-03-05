<?php

declare(strict_types=1);

namespace core;

final class Security
{
    public static function xss(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function csrfToken(): string
    {
        Auth::start();
        $_SESSION['_csrf'] ??= bin2hex(random_bytes(16));
        return $_SESSION['_csrf'];
    }

    public static function rotateCsrfToken(): string
    {
        Auth::start();
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        return $_SESSION['_csrf'];
    }

    public static function validateCsrf(string $token): bool
    {
        Auth::start();
        return hash_equals($_SESSION['_csrf'] ?? '', $token);
    }

    public static function checkLoginAttempts(string $scope, int $maxAttempts = 5, int $windowSeconds = 300): bool
    {
        return (new RateLimiter())->check($scope, $windowSeconds, $maxAttempts);
    }

    public static function increaseLoginAttempts(string $scope, int $windowSeconds = 300, int $maxAttempts = 5): bool
    {
        return (new RateLimiter())->hit($scope, $windowSeconds, $maxAttempts);
    }

    public static function resetLoginAttempts(string $scope): void
    {
        (new RateLimiter())->reset($scope);
    }

    public static function hitRateLimit(string $scope, int $windowSeconds, int $maxAttempts): bool
    {
        return (new RateLimiter())->hit($scope, $windowSeconds, $maxAttempts);
    }

    /** @param array<int,string> $allowlist */
    public static function isAllowedIp(string $ip, array $allowlist): bool
    {
        if ($allowlist === []) {
            return true;
        }

        foreach ($allowlist as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if ($rule === '*') {
                return true;
            }
            if (str_contains($rule, '/')) {
                if (self::ipInCidr($ip, $rule)) {
                    return true;
                }
                continue;
            }
            if ($ip === $rule) {
                return true;
            }
        }

        return false;
    }

    public static function isAllowedUpload(string $filename, string $mime): bool
    {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'txt'];
        $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'text/plain'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($ext, $allowedExt, true) && in_array($mime, $allowedMime, true);
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $maskBits] = array_pad(explode('/', $cidr, 2), 2, '');
        if ($subnet === '' || $maskBits === '') {
            return false;
        }

        $maskBits = (int) $maskBits;
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $length = strlen($ipBin);
        $maxBits = $length * 8;
        if ($maskBits < 0 || $maskBits > $maxBits) {
            return false;
        }

        $bytes = intdiv($maskBits, 8);
        $remainder = $maskBits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (~((1 << (8 - $remainder)) - 1)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
