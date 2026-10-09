<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;

/**
 * URL an toàn để ghi log: che giá trị tham số route và query string có tên nhạy cảm,
 * giữ nguyên phần còn lại (id, slug) để còn tái hiện được.
 *
 *   /affiliate/s/aB3…(40 ký tự)                  -> /affiliate/s/{stats_token}
 *   /auth?tab=reset_password_confirm&token=…&email=… -> /auth?tab=reset_password_confirm&token=***&email=***
 */
final class LoggableUrl
{
    public const MASK = '***';

    /** Tên key chứa một trong các từ này (tách theo snake_case) là nhạy cảm: `stats_token`, `userEmail`. */
    private const SENSITIVE_WORDS = [
        'token', 'secret', 'password', 'passwd', 'pwd', 'signature', 'checksum', 'otp',
        'email', 'credential', 'credentials', 'jwt', 'cookie', 'session', 'apikey',
    ];

    /** Từ quá chung chung để khớp theo đoạn (`language_code`, `{key?}` của /stream/) nên chỉ khớp nguyên tên. */
    private const SENSITIVE_KEYS = [
        'code', 'sig', 'pass', 'auth', 'hmac', 'api_key', 'access_key', 'private_key',
    ];

    /**
     * Chuỗi trông như token ngẫu nhiên (Str::random, hex hash, session id): chỉ gồm chữ + số,
     * có cả hai, dài từ 24 ký tự. Slug có gạch nối và id toàn số không khớp.
     */
    public static function looksLikeToken(string $value): bool
    {
        return preg_match('/^(?=.*\d)(?=.*[a-z])[a-z\d]{24,}$/i', $value) === 1;
    }

    public static function isSensitiveKey(string $key): bool
    {
        // Tự tách camelCase thay vì Str::snake: `API_KEY` qua Str::snake thành `a_p_i__k_e_y`,
        // và Str::snake cache mọi key người dùng gửi lên vào mảng static.
        $snake = strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key);

        if (in_array($snake, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        $words = preg_split('/[^a-z0-9]+/', $snake, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_intersect($words, self::SENSITIVE_WORDS) !== [];
    }

    public static function fromRequest(Request $request): string
    {
        $route = $request->route();

        $path = $route instanceof Route
            ? self::routePath($route)
            : self::heuristicPath($request->path());

        return $request->getSchemeAndHttpHost().$path.self::queryString($request->query());
    }

    /**
     * Cho URL không gắn với route hiện tại (Referer). Không dò route vì
     * RouteCollection::match() bind tham số vào chính object Route dùng chung —
     * dò referer trùng route đang chạy sẽ ghi đè tham số của request hiện tại.
     */
    public static function fromString(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $origin = isset($parts['host'])
            ? ($parts['scheme'] ?? 'http').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : '';

        parse_str($parts['query'] ?? '', $query);

        // Bỏ fragment: có thể chứa `#access_token=` của OAuth implicit flow.
        return $origin.self::heuristicPath($parts['path'] ?? '').self::queryString($query);
    }

    private static function routePath(Route $route): string
    {
        try {
            $values = $route->originalParameters();
        } catch (\LogicException) {
            // Route chưa bind: chỉ còn template, vẫn an toàn.
            $values = [];
        }

        $path = preg_replace_callback('/\{(\w+)(\?)?\}/', static function (array $m) use ($values): string {
            [, $name] = $m;
            $optional = ($m[2] ?? '') === '?';

            if (! array_key_exists($name, $values)) {
                return $optional ? '' : '{'.$name.'}';
            }

            if ($values[$name] === null || $values[$name] === '') {
                return '';
            }

            return self::isSensitiveKey($name) ? '{'.$name.'}' : (string) $values[$name];
        }, $route->uri()) ?? $route->uri();

        return self::normalizePath($path);
    }

    /** Không có route để biết tên tham số: che các đoạn path trông như token. */
    private static function heuristicPath(string $path): string
    {
        $segments = array_map(
            static fn (string $segment): string => self::looksLikeToken($segment) ? self::MASK : $segment,
            explode('/', $path)
        );

        return self::normalizePath(implode('/', $segments));
    }

    private static function normalizePath(string $path): string
    {
        $path = preg_replace('#/{2,}#', '/', '/'.$path) ?? $path;

        return $path === '/' ? $path : rtrim($path, '/');
    }

    private static function queryString(array $query): string
    {
        return $query === [] ? '' : '?'.urldecode(Arr::query(self::maskQuery($query)));
    }

    private static function maskQuery(array $query): array
    {
        foreach ($query as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $query[$key] = self::MASK;
            } elseif (is_array($value)) {
                $query[$key] = self::maskQuery($value);
            }
        }

        return $query;
    }
}
