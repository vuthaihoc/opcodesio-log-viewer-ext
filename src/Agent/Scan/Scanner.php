<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

use HocVT\LogViewerRemote\Agent\Scan\Aggregators\Commands;
use HocVT\LogViewerRemote\Agent\Scan\Aggregators\Grouped;
use HocVT\LogViewerRemote\Agent\Scan\Aggregators\Levels;
use HocVT\LogViewerRemote\Agent\Scan\Aggregators\Messages;
use HocVT\LogViewerRemote\Agent\Scan\Aggregators\Pages;
use HocVT\LogViewerRemote\Agent\Scan\Aggregators\SlowQueries;
use HocVT\LogViewerRemote\Agent\Scan\Aggregators\SqlWaste;
use InvalidArgumentException;

/**
 * Một lượt đọc qua các file, mọi aggregator gom chung lượt đó.
 *
 * Hết Budget thì dừng ở đầu entry chưa xử lý và trả cursor (vị trí + trạng thái mọi
 * aggregator); chạy lại với cursor đó ra đúng kết quả như quét một lượt. Bảng top-N
 * không gộp được ở phía client nên trạng thái phải đi theo cursor, không chỉ offset.
 */
final class Scanner
{
    /** Thứ tự cũng là thứ tự trong kết quả. `group` chỉ có khi truyền regex gom. */
    public const AGGREGATORS = ['levels', 'messages', 'pages', 'sql_waste', 'commands', 'slow_queries'];

    /**
     * Mỗi bảng lấy số từ đâu: `all` = mọi entry Laravel (log lỗi cũng được), `slow_log` = chỉ
     * dòng do SqlLogger ghi — chạy trên channel không có slow log thì bảng đó trống.
     */
    public const APPLIES_TO = [
        'levels' => 'all',
        'messages' => 'all',
        'group' => 'all',
        'pages' => 'slow_log',
        'sql_waste' => 'slow_log',
        'commands' => 'slow_log',
        'slow_queries' => 'slow_log',
    ];

    /** @var array<string, Aggregator> */
    private array $aggregators = [];

    /**
     * @param  list<Aggregator>  $aggregators
     * @param  EntryFilter|null  $filter  entry không khớp thì không đi vào aggregator nào
     * @param  bool  $keepAllText  phải đọc chữ của mọi entry (lọc / gom regex trên cả entry)
     */
    public function __construct(
        array $aggregators,
        private readonly SlowLogParser $parser = new SlowLogParser,
        private readonly int $headBytes = 65536,
        private readonly int $tailBytes = 8192,
        private readonly ?EntryFilter $filter = null,
        private readonly bool $keepAllText = false,
    ) {
        foreach ($aggregators as $aggregator) {
            $this->aggregators[$aggregator->name()] = $aggregator;
        }
    }

    /**
     * @param  list<string>|null  $only  tên trong AGGREGATORS (+ `group`); null = tất cả
     * @param  string|null  $group  regex gom tự do → bảng `group`
     * @param  bool  $groupWholeText  regex gom so trên cả entry thay vì dòng đầu
     */
    public static function make(
        Normalizer $normalizer,
        ?array $only = null,
        int $cap = 2000,
        int $headBytes = 65536,
        int $tailBytes = 8192,
        ?EntryFilter $filter = null,
        ?string $group = null,
        bool $groupWholeText = false,
    ): self {
        $available = $group === null ? self::AGGREGATORS : [...self::AGGREGATORS, 'group'];
        $only ??= $available;
        $unknown = array_diff($only, $available);

        if ($unknown !== []) {
            throw new InvalidArgumentException(in_array('group', $unknown, true)
                ? 'only=group cần tham số group (regex gom).'
                : 'Không có phép gom: '.implode(', ', $unknown).'. Có: '.implode(', ', $available).'.');
        }

        $all = [
            'levels' => fn () => new Levels,
            'messages' => fn () => new Messages($normalizer, $cap),
            'pages' => fn () => new Pages($normalizer, $cap),
            'sql_waste' => fn () => new SqlWaste($normalizer, $cap),
            'commands' => fn () => new Commands($cap),
            'slow_queries' => fn () => new SlowQueries($normalizer, $cap),
            'group' => fn () => new Grouped((string) $group, $groupWholeText, $cap),
        ];

        $selected = array_values(array_intersect($available, $only));

        return new self(
            array_map(static fn (string $name): Aggregator => $all[$name](), $selected),
            headBytes: $headBytes,
            tailBytes: $tailBytes,
            filter: $filter !== null && ! $filter->isEmpty() ? $filter : null,
            keepAllText: ($filter?->needsText() ?? false) || (in_array('group', $selected, true) && $groupWholeText),
        );
    }

    /**
     * @param  list<ScanFile>  $files  theo thứ tự đọc (cũ → mới)
     * @param  array<string, mixed>|null  $cursor  trả về từ lượt trước (`ScanResult::$cursor`)
     */
    public function run(array $files, Window $window, Budget $budget, ?array $cursor = null): ScanResult
    {
        $fileIndex = (int) ($cursor['file'] ?? 0);
        $resumeAt = isset($cursor['offset']) ? (int) $cursor['offset'] : null;
        $entries = (int) ($cursor['entries'] ?? 0);
        $scanned = (int) ($cursor['scanned'] ?? 0);
        $progress = (array) ($cursor['progress'] ?? []);

        foreach ((array) ($cursor['state'] ?? []) as $name => $state) {
            if (isset($this->aggregators[$name])) {
                $this->aggregators[$name]->restore((array) $state);
            }
        }

        for ($i = $fileIndex, $count = count($files); $i < $count; $i++) {
            $file = $files[$i];
            $reader = new EntryReader($file->path, $file->name, $this->headBytes, $this->tailBytes);

            $start = $resumeAt ?? ($window->seekTarget() !== null ? $reader->seek($window->seekTarget()) : 0);
            $resumeAt = null;

            // Phép gom có sẵn chỉ cần chữ của entry slow log; entry khác chỉ cần dòng đầu.
            $keepText = $this->keepAllText ? null : SlowLogParser::isCandidate(...);

            foreach ($reader->read($start, $window, $budget, $keepText) as $entry) {
                $scanned++;

                if ($this->filter !== null && ! $this->filter->matches($entry)) {
                    continue;
                }

                $entries++;
                $record = $this->parser->parse($entry);

                foreach ($this->aggregators as $aggregator) {
                    $aggregator->consume($entry, $record);
                }
            }

            $done = $reader->stoppedPastWindow() || ! $reader->stoppedByBudget();
            $progress[$file->name] = $done ? $file->size : min($file->size, $reader->nextOffset());

            if (! $done) {
                return $this->result($files, $budget, $entries, $scanned, $progress, [
                    'file' => $i,
                    'offset' => $reader->nextOffset(),
                    'entries' => $entries,
                    'scanned' => $scanned,
                    'progress' => $progress,
                    'state' => array_map(static fn (Aggregator $a): array => $a->state(), $this->aggregators),
                ]);
            }
        }

        return $this->result($files, $budget, $entries, $scanned, $progress, null);
    }

    /**
     * @param  list<ScanFile>  $files
     * @param  array<string, int>  $progress
     * @param  array<string, mixed>|null  $cursor
     */
    private function result(array $files, Budget $budget, int $entries, int $scanned, array $progress, ?array $cursor): ScanResult
    {
        $total = array_sum(array_map(static fn (ScanFile $f): int => $f->size, $files));
        $reached = array_sum($progress);

        return new ScanResult(
            complete: $cursor === null,
            cursor: $cursor,
            entries: $entries,
            scannedEntries: $scanned,
            scannedBytes: $budget->spentBytes(),
            totalBytes: $total,
            percent: $total > 0 ? round(min(100, $reached / $total * 100), 1) : 100.0,
            elapsedMs: $budget->elapsedMs(),
            files: array_map(static fn (ScanFile $f): array => [
                'name' => $f->name,
                'size' => $f->size,
                'reached' => $progress[$f->name] ?? 0,
            ], $files),
            aggregators: $this->aggregators,
        );
    }
}
