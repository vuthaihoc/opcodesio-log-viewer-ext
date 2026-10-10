<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\SlowLog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Log request web xử lý quá lâu, hoặc trả về quá nhiều dữ liệu — chỉ với path khớp prefix đã
 * khai (config `log-viewer-ext.slow_log.requests`). Hai luật độc lập, mỗi luật một danh sách
 * prefix + một ngưỡng.
 *
 * Mỗi mục prefix:
 * - `api` khớp `api` và `api/…` (theo đoạn path, không khớp `apix`); có `*` thì so như
 *   Str::is (`livewire-*`, `*` = mọi request);
 * - `graphql:3000` = ngưỡng riêng cho prefix đó; `graphql/upload:0` = loại trừ — luật đầu
 *   tiên khớp thắng, nên đặt loại trừ trước luật rộng hơn.
 *
 * Đo lúc RequestHandled (đã có response, chưa gửi đi):
 * - thời gian tính từ REQUEST_TIME_FLOAT (lúc PHP nhận request), gồm cả bootstrap + middleware
 *   + controller; KHÔNG gồm thời gian gửi response và terminate;
 * - kích thước là body app trả ra, TRƯỚC khi nginx gzip; file tải về tính theo kích thước file,
 *   StreamedResponse không biết trước kích thước thì bỏ qua (trừ khi có Content-Length).
 *
 * Dòng log mang số query + tổng ms DB của chính request đó (SqlLogger::stats()): chậm vì DB
 * hay vì việc khác nhìn là thấy.
 */
class RequestLogger
{
    public const DEFAULT_TIME = 2000;

    public const DEFAULT_SIZE = 524288;

    /** @var list<array{0: string, 1: int}> [prefix, ngưỡng ms] */
    private array $timeRules;

    /** @var list<array{0: string, 1: int}> [prefix, ngưỡng byte] */
    private array $sizeRules;

    private ?string $channel;

    private string $level;

    public function __construct(array $config = [])
    {
        $config += (array) config('log-viewer-ext.slow_log', []);
        $requests = (array) ($config['requests'] ?? []);

        $channel = (string) ($config['channel'] ?? '');
        $this->channel = $channel !== '' ? $channel : null;
        $this->level = (string) ($requests['level'] ?? 'warning');
        $this->timeRules = self::rules((array) ($requests['time'] ?? []), self::DEFAULT_TIME);
        $this->sizeRules = self::rules((array) ($requests['size'] ?? []), self::DEFAULT_SIZE);
    }

    public function isEnabled(): bool
    {
        foreach ([...$this->timeRules, ...$this->sizeRules] as [, $limit]) {
            if ($limit > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{queries: int, query_ms: float}|null  $db  số liệu DB của request (SqlLogger::stats())
     */
    public function handle(Request $request, Response $response, ?array $db = null): void
    {
        try {
            $path = trim($request->path(), '/');
            $timeLimit = self::limitFor($this->timeRules, $path);
            $sizeLimit = self::limitFor($this->sizeRules, $path);

            if ($timeLimit === null && $sizeLimit === null) {
                return;
            }

            $ms = self::elapsedMs($request);
            $bytes = self::bytes($response);
            $reasons = [];

            if ($timeLimit !== null && $ms !== null && $ms > $timeLimit) {
                $reasons['time'] = "Request chậm {$ms}ms > {$timeLimit}ms";
            }

            if ($sizeLimit !== null && $bytes !== null && $bytes > $sizeLimit) {
                $reasons['size'] = 'Response lớn '.self::human($bytes).' > '.self::human($sizeLimit);
            }

            if ($reasons === []) {
                return;
            }

            Log::channel($this->channel)->{$this->level}(
                WebContext::label($request).' '.implode(' + ', $reasons)."\n".sprintf(
                    '  %s %d · %s · %s%s',
                    $request->getMethod(),
                    $response->getStatusCode(),
                    $ms === null ? '?ms' : $ms.'ms',
                    $bytes === null ? '? byte' : self::human($bytes),
                    $db === null ? '' : " · {$db['queries']} query / ".round($db['query_ms']).'ms DB',
                ),
                [
                    'slow_log' => 'request',
                    'reasons' => array_keys($reasons),
                    'ms' => $ms,
                    'bytes' => $bytes,
                    'status' => $response->getStatusCode(),
                    'method' => $request->getMethod(),
                ] + ($db ?? []) + WebContext::context($request),
            );
        } catch (\Throwable $e) {
            Log::error('RequestLogger error: '.$e->getMessage());
        }
    }

    /**
     * `prefixes` là chuỗi cách nhau bằng dấu phẩy (env) hoặc mảng; mỗi mục `prefix` hoặc
     * `prefix:ngưỡng`.
     *
     * @param  array<string, mixed>  $group
     * @return list<array{0: string, 1: int}>
     */
    private static function rules(array $group, int $default): array
    {
        $threshold = (int) ($group['threshold'] ?? $default);
        $prefixes = $group['prefixes'] ?? [];
        $rules = [];

        foreach (is_array($prefixes) ? $prefixes : explode(',', (string) $prefixes) as $entry) {
            $entry = trim((string) $entry);

            if ($entry === '') {
                continue;
            }

            $colon = strrpos($entry, ':');
            $own = $colon === false ? null : substr($entry, $colon + 1);

            $rules[] = is_string($own) && ctype_digit($own)
                ? [trim(substr($entry, 0, (int) $colon), '/'), (int) $own]
                : [trim($entry, '/'), $threshold];
        }

        return $rules;
    }

    /**
     * Ngưỡng của luật đầu tiên khớp path; null = không luật nào khớp, hoặc luật khớp là loại trừ (0).
     *
     * @param  list<array{0: string, 1: int}>  $rules
     */
    private static function limitFor(array $rules, string $path): ?int
    {
        foreach ($rules as [$prefix, $limit]) {
            $matches = match (true) {
                $prefix === '*' => true,
                str_contains($prefix, '*') => Str::is($prefix, $path),
                default => $path === $prefix || str_starts_with($path, $prefix.'/'),
            };

            if ($matches) {
                return $limit > 0 ? $limit : null;
            }
        }

        return null;
    }

    private static function elapsedMs(Request $request): ?int
    {
        $start = $request->server('REQUEST_TIME_FLOAT') ?: (defined('LARAVEL_START') ? LARAVEL_START : null);

        return is_numeric($start) ? (int) round((microtime(true) - (float) $start) * 1000) : null;
    }

    private static function bytes(Response $response): ?int
    {
        $length = $response->headers->get('Content-Length');

        if (is_numeric($length)) {
            return (int) $length;
        }

        if ($response instanceof BinaryFileResponse) {
            return (int) $response->getFile()->getSize();
        }

        if ($response instanceof StreamedResponse) {
            return null;
        }

        $content = $response->getContent();

        return $content === false ? null : strlen($content);
    }

    private static function human(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
