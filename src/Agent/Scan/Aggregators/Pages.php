<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan\Aggregators;

use HocVT\LogViewerRemote\Agent\Scan\Aggregator;
use HocVT\LogViewerRemote\Agent\Scan\Entry;
use HocVT\LogViewerRemote\Agent\Scan\Normalizer;
use HocVT\LogViewerRemote\Agent\Scan\SlowLogRecord;
use HocVT\LogViewerRemote\Agent\Scan\Tally;

/**
 * Thao tác 4 + 6: slow log WEB gộp theo NHÓM TRANG (route / tên operation GraphQL), không
 * theo URL lẻ — `/khoa-hoc/<slug>` là hàng nghìn URL, xếp theo URL lẻ thì không nhóm nào
 * vào nổi top.
 *
 * - `summaries`, `queries`, `ms`: số lượt tổng kết, Σtotal, Σtotal_ms;
 * - `slow_queries`, `slow_ms`: số dòng query chậm và tổng ms của chúng.
 */
final class Pages implements Aggregator
{
    private Tally $tally;

    public function __construct(private readonly Normalizer $normalizer, int $cap = 2000)
    {
        $this->tally = new Tally(['queries', 'slow_queries'], $cap);
    }

    public function name(): string
    {
        return 'pages';
    }

    public function consume(Entry $entry, ?SlowLogRecord $record): void
    {
        if ($record === null || $record->scope !== 'WEB') {
            return;
        }

        $once = ['sample' => $entry->file.'@'.$entry->offset];

        if ($record->kind === 'summary') {
            $this->tally->add(
                $this->normalizer->page($record),
                sum: ['summaries' => 1, 'queries' => (int) $record->total, 'ms' => (float) $record->totalMs],
                max: ['max_queries' => (int) $record->total, 'worst_duplicate' => (int) $record->worstDuplicate],
                once: $once,
            );

            return;
        }

        $this->tally->add(
            $this->normalizer->page($record),
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
