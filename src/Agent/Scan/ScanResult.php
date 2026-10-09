<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

final class ScanResult
{
    /**
     * @param  array<string, mixed>|null  $cursor  null khi đã quét xong
     * @param  list<array{name: string, size: int, reached: int}>  $files
     * @param  array<string, Aggregator>  $aggregators
     */
    public function __construct(
        public readonly bool $complete,
        public readonly ?array $cursor,
        public readonly int $entries,
        public readonly int $scannedBytes,
        public readonly int $totalBytes,
        public readonly float $percent,
        public readonly int $elapsedMs,
        public readonly array $files,
        private readonly array $aggregators,
    ) {}

    /**
     * Kết quả của từng aggregator (cả khi chưa xong: số liệu tới vị trí đã đọc).
     *
     * @return array<string, array<string, mixed>>
     */
    public function results(int $top): array
    {
        return array_map(static fn (Aggregator $a): array => $a->result($top), $this->aggregators);
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return [
            'complete' => $this->complete,
            'entries' => $this->entries,
            'scanned_bytes' => $this->scannedBytes,
            'total_bytes' => $this->totalBytes,
            'percent_scanned' => $this->percent,
            'elapsed_ms' => $this->elapsedMs,
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'files' => $this->files,
        ];
    }
}
