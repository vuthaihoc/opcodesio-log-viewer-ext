<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan\Aggregators;

use HocVT\LogViewerRemote\Agent\Scan\Aggregator;
use HocVT\LogViewerRemote\Agent\Scan\Entry;
use HocVT\LogViewerRemote\Agent\Scan\Normalizer;
use HocVT\LogViewerRemote\Agent\Scan\SlowLogRecord;
use HocVT\LogViewerRemote\Agent\Scan\Tally;

/**
 * Thao tác 5: query thừa = Σ(xN − 1) theo SQL đã chuẩn hoá, lấy từ các dòng nhóm của
 * slow log tổng kết. Một query lặp 41 lần trong một request là 40 lượt thừa (N+1).
 *
 * Chỉ đếm được query nằm trong top `top_queries` dòng của mỗi tổng kết — log không ghi
 * phần còn lại. `pages` = trang / command sinh ra số thừa đó nhiều nhất.
 */
final class SqlWaste implements Aggregator
{
    private Tally $tally;

    public function __construct(private readonly Normalizer $normalizer, int $cap = 2000)
    {
        $this->tally = new Tally(['waste', 'count'], $cap);
    }

    public function name(): string
    {
        return 'sql_waste';
    }

    public function consume(Entry $entry, ?SlowLogRecord $record): void
    {
        if ($record === null || $record->kind !== 'summary' || $record->groups === []) {
            return;
        }

        $where = $record->scope === 'WEB' ? $this->normalizer->page($record) : 'CLI '.($record->command ?? '?');

        foreach ($record->groups as $group) {
            $waste = $group['count'] - 1;

            $this->tally->add(
                $this->normalizer->sql($group['sql']),
                sum: ['waste' => $waste, 'count' => $group['count'], 'ms' => $group['ms'], 'contexts' => 1],
                max: ['max_repeat' => $group['count']],
                once: ['connection' => $group['connection'], 'first' => $entry->datetime, 'sample' => $entry->file.'@'.$entry->offset],
                spread: $waste > 0 ? ['pages' => [$where, $waste]] : [],
            );
        }
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
