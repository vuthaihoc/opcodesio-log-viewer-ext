<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Console;

/**
 * In kết quả agent dạng markdown gọn — đọc được bằng mắt, và rẻ token khi đưa cho agent AI
 * (JSON đầy đủ thì dùng `--json`).
 */
final class AgentOutput
{
    /** Trường không phải số liệu, đứng cuối bảng. */
    private const TRAILING = ['level', 'connection', 'scope', 'first', 'last', 'sample', 'ui_url'];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{rounds: int, elapsed_ms: int}  $run
     * @param  bool  $links  hiện cột `ui_url` (link mở entry mẫu trong UI Log Viewer)
     */
    public static function aggregate(array $payload, string $host, array $run, bool $links = false): string
    {
        $stats = (array) ($payload['stats'] ?? []);
        $window = (array) ($payload['window'] ?? []);
        $lines = [sprintf(
            '# %s · %s → %s (%s) · %s entry · %s MB · %s ms · %d lượt%s',
            $host,
            $window['from'] ?? 'đầu',
            $window['to'] ?? 'cuối',
            $window['log_timezone'] ?? '?',
            $stats['entries'] ?? '?',
            round(((int) ($stats['total_bytes'] ?? 0)) / 1048576, 1),
            $run['elapsed_ms'],
            $run['rounds'],
            ($payload['cached'] ?? false) ? ' · cache' : '',
        )];

        $lines[] = 'files: '.implode(', ', array_map(
            static fn (array $f): string => sprintf('%s (%s MB)', $f['name'], round($f['size'] / 1048576, 1)),
            (array) ($stats['files'] ?? []),
        ));

        if (($stats['scanned_entries'] ?? null) !== null && $stats['scanned_entries'] !== ($stats['entries'] ?? null)) {
            $lines[] = "lọc: {$stats['entries']} / {$stats['scanned_entries']} entry khớp";
        }

        foreach ((array) ($payload['results'] ?? []) as $name => $result) {
            $lines[] = '';
            $lines = array_merge($lines, $name === 'levels' ? self::levels((array) $result) : self::tally((string) $name, (array) $result, $links));
        }

        return implode("\n", $lines)."\n";
    }

    /** @param array<string, mixed> $payload */
    public static function entries(array $payload, string $host): string
    {
        $lines = [];

        foreach ((array) ($payload['entries'] ?? []) as $entry) {
            $lines[] = sprintf('## %s · %s · %s · %d byte%s', $entry['at'], $entry['datetime'], $entry['level'], $entry['length'], $entry['truncated'] ? ' (đã cắt)' : '');

            if (($entry['ui_url'] ?? null) !== null) {
                $lines[] = 'UI: '.$entry['ui_url'];
            }

            $lines[] = '```';
            $lines[] = rtrim((string) $entry['text']);
            $lines[] = '```';
        }

        if ($lines === []) {
            $lines[] = "# {$host}: không có entry nào khớp.";
        }

        if (($payload['next'] ?? null) !== null) {
            $lines[] = '';
            $lines[] = 'Còn nữa — chạy lại với --cursor='.$payload['next'];
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private static function levels(array $result): array
    {
        $pairs = static fn (array $counts): string => implode(', ', array_map(static fn ($k, $v) => "{$k} {$v}", array_keys($counts), $counts));

        return [
            '## levels',
            sprintf('%s entry · %s → %s', $result['entries'] ?? 0, $result['first'] ?? '?', $result['last'] ?? '?'),
            'levels: '.$pairs((array) ($result['levels'] ?? [])),
            'scopes: '.$pairs((array) ($result['scopes'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private static function tally(string $name, array $result, bool $links): array
    {
        $rows = (array) ($result['rows'] ?? []);
        $slowLogOnly = ($result['applies_to'] ?? 'all') === 'slow_log';
        $head = sprintf(
            '## %s (%d khoá%s%s)',
            $name,
            $result['keys'] ?? count($rows),
            ($result['pruned'] ?? 0) > 0 ? ', bỏ '.$result['pruned'].' khoá nhỏ' : '',
            $slowLogOnly ? ' · chỉ dòng slow log' : '',
        );

        if ($rows === []) {
            return [$head, $slowLogOnly ? '(trống — các file này không có dòng slow log)' : '(trống)'];
        }

        $columns = ['key'];
        $trailing = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (in_array($column, $columns, true) || in_array($column, $trailing, true) || ($column === 'ui_url' && ! $links)) {
                    continue;
                }

                if (in_array($column, self::TRAILING, true) || is_array($row[$column])) {
                    $trailing[] = $column;
                } else {
                    $columns[] = $column;
                }
            }
        }

        $rank = static fn (string $c): int => ($i = array_search($c, self::TRAILING, true)) === false ? -1 : (int) $i;
        usort($trailing, static fn (string $a, string $b): int => $rank($a) <=> $rank($b));
        $columns = array_merge($columns, $trailing);

        $lines = [$head, '| '.implode(' | ', $columns).' |', '|'.str_repeat(' --- |', count($columns))];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map(static fn (string $c): string => self::cell($row[$c] ?? ''), $columns)).' |';
        }

        return $lines;
    }

    private static function cell(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode('; ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($value), $value));
        }

        return str_replace(['|', "\n"], ['\|', ' '], (string) $value);
    }
}
