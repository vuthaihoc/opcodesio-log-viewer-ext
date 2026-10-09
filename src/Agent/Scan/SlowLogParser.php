<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

/**
 * Nhận dòng log do HocVT\LogViewerRemote\SlowLog\SqlLogger ghi.
 *
 * Bản mới mang context máy đọc (`slow_log`, `reasons`, `cli.context`, `req.route`…) nên
 * đọc context trước; phần chữ chỉ dùng cho log cũ chưa có context (định dạng cũ ở các app
 * tự viết SqlLogger cùng dòng, kể cả `[mysql]`). Format là hợp đồng: app dùng package có
 * test ghi bằng SqlLogger rồi đọc lại bằng parser này.
 */
final class SlowLogParser
{
    /** Dòng nhóm của summary: `    x41     402ms [crdb] select …` (bản cũ: `     x41   402ms`). */
    public const GROUP_LINE = '/^\s+(?:x(\d+)\s+)?(\d+(?:\.\d+)?)ms \[([^\]]+)\] (.+)$/';

    /** Dòng thứ hai của query chậm: `402ms [crdb] select …`. */
    private const QUERY_LINE = '/^(\d+(?:\.\d+)?)ms \[([^\]]+)\] (.*)$/';

    /** Dòng thứ hai của summary: `  63 query / 812ms / 9 query khác nhau`. */
    private const SUMMARY_LINE = '/^\s+(\d+) query \/ (\d+(?:\.\d+)?)ms \/ (\d+) query/';

    /** Frame trace in dưới query chậm: `  app/Models/User.php:42`. */
    private const TRACE_LINE = '/^  \S.*:(?:\d+|\?)$/';

    /** Nhãn web; URL có thể chứa `]` (query string đã urldecode) nên neo vào phần lý do phía sau. */
    private const WEB_LABEL = '/^\[WEB\]\[([^\]]*)\]\[(.*)\](?: (?:Quá nhiều query|Tổng thời gian query|Nghi ngờ N\+1).*)?$/';

    private const CLI_LABEL = '/^\[CLI\](?:\[([^\]]*)\])?/';

    /** Câu lý do (log cũ không có `reasons` trong context). */
    private const REASON_TEXT = [
        'total' => 'Quá nhiều query',
        'total_ms' => 'Tổng thời gian query',
        'duplicate' => 'Nghi ngờ N+1',
    ];

    /**
     * Lọc rẻ chỉ bằng dòng đầu: entry không bắt đầu bằng nhãn thì chắc chắn không phải slow
     * log. EntryReader dùng nó để khỏi giữ chữ của các entry khác (vd. 77 nghìn exception
     * có stack trace trong một file CLI 340 MB).
     */
    public static function isCandidate(string $firstLine): bool
    {
        return str_starts_with($firstLine, '[WEB]') || str_starts_with($firstLine, '[CLI]');
    }

    public function parse(Entry $entry): ?SlowLogRecord
    {
        $first = $entry->firstLine;

        if (! self::isCandidate($first) || ! $entry->hasText()) {
            return null;
        }

        $context = $entry->context() ?? [];
        $lines = $entry->lines();

        // Context JSON dính ở cuối dòng cuối (frame trace hoặc dòng SQL): bóc trước khi tách dòng.
        $lines[count($lines) - 1] = $this->withoutTrailingJson($lines[count($lines) - 1]);
        $kind = $context['slow_log'] ?? $this->guessKind($lines[1] ?? '');

        if ($kind !== 'query' && $kind !== 'summary') {
            return null;
        }

        $scope = str_starts_with($first, '[WEB]') ? 'WEB' : 'CLI';
        [$labelUrl, $labelCommand] = $this->label($first, $scope);

        return $kind === 'query'
            ? $this->query($scope, $lines, $context, $labelUrl, $labelCommand)
            : $this->summary($scope, $first, $lines, $context, $labelUrl, $labelCommand);
    }

    private function guessKind(string $second): ?string
    {
        return match (true) {
            preg_match(self::QUERY_LINE, $second) === 1 => 'query',
            preg_match(self::SUMMARY_LINE, $second) === 1 => 'summary',
            default => null,
        };
    }

    /**
     * @return array{0: ?string, 1: ?string} [url, command]
     */
    private function label(string $first, string $scope): array
    {
        if ($scope === 'WEB') {
            return preg_match(self::WEB_LABEL, $first, $m) === 1 ? [$m[2], null] : [null, null];
        }

        return preg_match(self::CLI_LABEL, $first, $m) === 1 && ($m[1] ?? '') !== '' ? [null, $m[1]] : [null, null];
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $context
     */
    private function query(string $scope, array $lines, array $context, ?string $labelUrl, ?string $labelCommand): ?SlowLogRecord
    {
        if (preg_match(self::QUERY_LINE, $lines[1] ?? '', $m) !== 1) {
            return null;
        }

        // SQL viết nhiều dòng (heredoc) kéo dài tới frame trace đầu tiên.
        $sql = [$m[3]];

        for ($i = 2, $count = count($lines); $i < $count && preg_match(self::TRACE_LINE, $lines[$i]) !== 1; $i++) {
            $sql[] = $lines[$i];
        }

        return $this->record('query', $scope, $context, $labelUrl, $labelCommand,
            ms: isset($context['ms']) ? (float) $context['ms'] : (float) $m[1],
            connection: isset($context['connection']) ? (string) $context['connection'] : $m[2],
            sql: $this->withoutTrailingJson(implode("\n", $sql)),
        );
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $context
     */
    private function summary(string $scope, string $first, array $lines, array $context, ?string $labelUrl, ?string $labelCommand): SlowLogRecord
    {
        $groups = [];

        foreach (array_slice($lines, 2) as $line) {
            if (preg_match(self::GROUP_LINE, $line, $m) === 1) {
                $groups[] = [
                    'count' => $m[1] !== '' ? (int) $m[1] : 1,
                    'ms' => (float) $m[2],
                    'connection' => $m[3],
                    'sql' => $this->withoutTrailingJson($m[4]),
                ];
            }
        }

        $line = preg_match(self::SUMMARY_LINE, $lines[1] ?? '', $s) === 1 ? $s : null;

        $reasons = isset($context['reasons']) && is_array($context['reasons'])
            ? array_values(array_map('strval', $context['reasons']))
            : array_keys(array_filter(self::REASON_TEXT, static fn (string $text): bool => str_contains($first, $text)));

        $worst = $groups === [] ? null : max(array_column($groups, 'count'));

        return $this->record('summary', $scope, $context, $labelUrl, $labelCommand,
            reasons: $reasons,
            total: isset($context['total']) ? (int) $context['total'] : ($line !== null ? (int) $line[1] : null),
            totalMs: isset($context['total_ms']) ? (float) $context['total_ms'] : ($line !== null ? (float) $line[2] : null),
            worstDuplicate: isset($context['worst_duplicate']) ? (int) $context['worst_duplicate'] : $worst,
            groups: $groups,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $reasons
     * @param  list<array{count: int, ms: float, connection: string, sql: string}>  $groups
     */
    private function record(
        string $kind,
        string $scope,
        array $context,
        ?string $labelUrl,
        ?string $labelCommand,
        array $reasons = [],
        ?int $total = null,
        ?float $totalMs = null,
        ?int $worstDuplicate = null,
        array $groups = [],
        ?float $ms = null,
        ?string $connection = null,
        ?string $sql = null,
    ): SlowLogRecord {
        return new SlowLogRecord(
            kind: $kind,
            scope: $scope,
            url: self::string($context, 'req.url') ?? $labelUrl,
            route: self::string($context, 'req.route'),
            referer: self::string($context, 'req.referer'),
            command: self::string($context, 'cli.context') ?? $labelCommand,
            reasons: $reasons,
            total: $total,
            totalMs: $totalMs,
            worstDuplicate: $worstDuplicate,
            groups: $groups,
            ms: $ms,
            connection: $connection,
            sql: $sql,
            context: $context,
        );
    }

    /**
     * Dòng cuối của entry mang context JSON phía sau; SQL không được dính phần đó. Context
     * luôn là object, nên SQL kết thúc bằng `]` (`ARRAY[1,2]`) không bị cắt nhầm. Monolog
     * để lại một dấu cách cuối dòng (chỗ của `%extra%` rỗng) nên phải rtrim trước.
     */
    private function withoutTrailingJson(string $text): string
    {
        $text = rtrim($text);

        if (! str_ends_with($text, '}')) {
            return $text;
        }

        return rtrim(substr($text, 0, TrailingJson::split($text)[2]));
    }

    /** @param array<string, mixed> $context */
    private static function string(array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
