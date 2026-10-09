<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

/**
 * Đổi tên channel log (config `logging.channels`) thành các file nó ghi ra, để giới hạn
 * agent theo channel. Laravel không ghi tên channel vào từng dòng log (`production.ERROR` là
 * tên môi trường), nên đường dẫn file là thứ duy nhất phân biệt được.
 *
 * - `single` → đúng `path`;
 * - `daily` → `{dir}/{tên}-YYYY-MM-DD.{ext}` (RotatingFileHandler), tách được ngày;
 * - `stack` → các channel con (đệ quy, có chặn vòng lặp);
 * - `monolog` có `with.stream` / `handler_with.stream` là đường dẫn → file đó;
 * - driver khác (slack, syslog, stderr, papertrail…) → không có file.
 *
 * So thư mục bằng realpath: storage hay được symlink sang thư mục dùng chung khi deploy.
 * PHP thuần, không phụ thuộc Laravel.
 */
final class ChannelFiles
{
    /** @var list<array{channel: string, dir: string, pattern: string, daily: bool}> */
    private array $patterns = [];

    /**
     * @param  array<string, array<string, mixed>>  $channels  `config('logging.channels')`
     * @param  list<string>  $allowed  tên channel được phép
     */
    public function __construct(array $channels, array $allowed)
    {
        foreach ($allowed as $name) {
            $this->collect($channels, $name, $name, []);
        }
    }

    /**
     * Channel + ngày (nếu là file daily) của một file, null nếu không thuộc channel nào được phép.
     *
     * @return array{channel: string, date: ?string}|null
     */
    public function match(string $path): ?array
    {
        $dir = self::realDir(dirname($path));
        $base = basename($path);

        foreach ($this->patterns as $pattern) {
            if ($pattern['dir'] === $dir && preg_match($pattern['pattern'], $base, $m) === 1) {
                return ['channel' => $pattern['channel'], 'date' => $pattern['daily'] ? $m[1] : null];
            }
        }

        return null;
    }

    /** @return list<string> tên channel thật sự ra file (đã bỏ channel không có file) */
    public function channels(): array
    {
        return array_values(array_unique(array_column($this->patterns, 'channel')));
    }

    /**
     * @param  array<string, array<string, mixed>>  $channels
     * @param  list<string>  $seen
     */
    private function collect(array $channels, string $name, string $label, array $seen): void
    {
        $config = $channels[$name] ?? null;

        if (! is_array($config) || in_array($name, $seen, true)) {
            return;
        }

        $seen[] = $name;
        $driver = (string) ($config['driver'] ?? '');

        if ($driver === 'stack') {
            foreach ((array) ($config['channels'] ?? []) as $child) {
                // File của channel con được tính cho channel con; stack chỉ là đường đi tới đó.
                $this->collect($channels, (string) $child, (string) $child, $seen);
            }

            return;
        }

        $path = match ($driver) {
            'single', 'daily' => $config['path'] ?? null,
            'monolog' => $config['with']['stream'] ?? $config['handler_with']['stream'] ?? null,
            default => null,
        };

        if (! is_string($path) || $path === '' || str_starts_with($path, 'php://')) {
            return;
        }

        $info = pathinfo($path);
        $file = preg_quote($info['filename'], '/');
        $ext = isset($info['extension']) ? '\.'.preg_quote($info['extension'], '/') : '';

        $this->patterns[] = [
            'channel' => $label,
            'dir' => self::realDir($info['dirname'] ?? '.'),
            'pattern' => $driver === 'daily'
                ? '/^'.$file.'-(\d{4}-\d{2}-\d{2})'.$ext.'$/'
                : '/^'.preg_quote($info['basename'], '/').'$/',
            'daily' => $driver === 'daily',
        ];
    }

    private static function realDir(string $dir): string
    {
        return rtrim(realpath($dir) ?: $dir, '/\\');
    }
}
