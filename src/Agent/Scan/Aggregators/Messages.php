<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan\Aggregators;

use HocVT\LogViewerRemote\Agent\Scan\Aggregator;
use HocVT\LogViewerRemote\Agent\Scan\Entry;
use HocVT\LogViewerRemote\Agent\Scan\Normalizer;
use HocVT\LogViewerRemote\Agent\Scan\SlowLogRecord;
use HocVT\LogViewerRemote\Agent\Scan\Tally;

/**
 * Thao tác 2: thông điệp hay gặp nhất sau khi chuẩn hoá. `sample` = `file@offset` của lần
 * gặp đầu, để đọc trọn entry đó bằng endpoint `entries`.
 */
final class Messages implements Aggregator
{
    private Tally $tally;

    public function __construct(private readonly Normalizer $normalizer, int $cap = 2000)
    {
        $this->tally = new Tally(['n'], $cap);
    }

    public function name(): string
    {
        return 'messages';
    }

    public function consume(Entry $entry, ?SlowLogRecord $record): void
    {
        $this->tally->add(
            $entry->level.' | '.$this->normalizer->message($entry, $record),
            sum: ['n' => 1],
            once: ['level' => $entry->level, 'first' => $entry->datetime, 'sample' => $entry->file.'@'.$entry->offset],
            set: ['last' => $entry->datetime],
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
