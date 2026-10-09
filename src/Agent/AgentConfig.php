<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

/**
 * Đọc nhóm `log-viewer-ext.agent`. Provider đã gộp sâu nhóm này với config của app
 * (MergesConfigRecursively); mặc định ở đây chỉ là lưới cuối khi app set tay cả nhóm lúc chạy.
 */
final class AgentConfig
{
    private const DEFAULTS = [
        'token' => null,
        'channels' => 'slow-log',
        'max_seconds' => 20.0,
        'max_bytes' => 0,
        'slots' => 2,
        'head_bytes' => 65536,
        'tail_bytes' => 8192,
        'slack' => 120,
        'cap' => 2000,
        'page_key' => ['req.route'],
        'url_groups' => [],
        'cache_ttl' => 86400,
        'cursor_ttl' => 600,
        'entries_limit' => 50,
        'entry_max_bytes' => 16384,
    ];

    public static function get(string $key): mixed
    {
        $value = ((array) config('log-viewer-ext.agent', []))[$key] ?? null;

        return $value ?? self::DEFAULTS[$key];
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function token(): string
    {
        return (string) self::get('token');
    }

    /**
     * Channel được đọc; bỏ trống = channel slow log đang ghi (`log-viewer-ext.slow_log.channel`, null thì
     * kênh mặc định của app).
     *
     * @return list<string>
     */
    public static function channels(): array
    {
        $value = self::get('channels');
        $names = is_array($value) ? $value : explode(',', (string) $value);
        $names = array_values(array_filter(array_map(static fn (mixed $n): string => trim((string) $n), $names)));

        return $names !== [] ? $names : [self::slowLogChannel()];
    }

    public static function slowLogChannel(): string
    {
        return (string) (config('log-viewer-ext.slow_log.channel') ?: config('logging.default'));
    }

    /** Múi giờ của timestamp trong file log (Monolog dùng timezone mặc định của PHP = app.timezone). */
    public static function logTimezone(): string
    {
        return (string) (config('app.timezone') ?: 'UTC');
    }

    /** Múi giờ hiểu `from` / `to` không kèm offset: múi giờ hiển thị của Log Viewer, rồi tới múi giờ log. */
    public static function inputTimezone(): string
    {
        return (string) (config('log-viewer.timezone') ?: self::logTimezone());
    }
}
