<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan\Aggregators;

use HocVT\LogViewerRemote\Agent\Scan\Aggregator;
use HocVT\LogViewerRemote\Agent\Scan\Entry;
use HocVT\LogViewerRemote\Agent\Scan\SlowLogRecord;
use HocVT\LogViewerRemote\Agent\Scan\Tally;

/**
 * Thao tác 7: thời gian DB theo job / command (slow log CLI). `runs`, `queries`, `ms`
 * tính từ dòng tổng kết; `slow_queries`, `slow_ms` từ dòng query chậm.
 */
final class Commands implements Aggregator
{
    private Tally $tally;

    public function __construct(int $cap = 2000)
    {
        $this->tally = new Tally(['ms', 'slow_ms'], $cap);
    }

    public function name(): string
    {
        return 'commands';
    }

    public function consume(Entry $entry, ?SlowLogRecord $record): void
    {
        if ($record === null || $record->scope !== 'CLI') {
            return;
        }

        $key = $record->command ?? '(không tên)';
        $once = ['sample' => $entry->file.'@'.$entry->offset];

        if ($record->kind === 'summary') {
            $this->tally->add(
                $key,
                sum: ['runs' => 1, 'queries' => (int) $record->total, 'ms' => (float) $record->totalMs],
                max: ['max_ms' => (float) $record->totalMs],
                once: $once,
            );

            return;
        }

        $this->tally->add(
            $key,
            sum: ['slow_queries' => 1, 'slow_ms' => (float) $record->ms],
            max: ['max_slow_ms' => (float) $record->ms],
            once: $once,
        );
    }

    public function state(): array
    {
        return $this->tally->state();
    }

    public function restore(array $state): void
    {
        $this->tally->restore($state);
    }

    public function result(int $top): array
    {
        return ['keys' => $this->tally->count(), 'pruned' => $this->tally->pruned(), 'rows' => $this->tally->top($top)];
    }
}
