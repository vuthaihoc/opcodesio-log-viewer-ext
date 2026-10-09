<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan\Aggregators;

use HocVT\LogViewerRemote\Agent\Scan\Aggregator;
use HocVT\LogViewerRemote\Agent\Scan\Entry;
use HocVT\LogViewerRemote\Agent\Scan\EntryFilter;
use HocVT\LogViewerRemote\Agent\Scan\Normalizer;
use HocVT\LogViewerRemote\Agent\Scan\SlowLogRecord;
use HocVT\LogViewerRemote\Agent\Scan\Tally;

/**
 * Gom theo regex do người gọi gửi (`group=`), cho câu hỏi mà 6 bảng có sẵn không trả lời được.
 * Mỗi entry tính một lần, theo lần khớp đầu tiên:
 *
 * - khoá = nhóm có tên `key`; không có thì nối các nhóm bắt không tên bằng ` | `; không có
 *   nhóm nào thì cả đoạn khớp;
 * - nhóm có tên `sum` là số thì cộng dồn (`sum`) và lấy lớn nhất (`max`);
 * - `levels` = entry khớp chia theo level.
 *
 * Vd. `Job (?<key>[\w\\]+) failed after (?<sum>\d+)ms` → mỗi job một hàng, tổng ms.
 * Khoá đã che email / token và cắt 200 byte.
 */
final class Grouped implements Aggregator
{
    private const KEY_MAX = 200;

    private string $pattern;

    private Tally $tally;

    public function __construct(string $regex, private readonly bool $wholeText = false, int $cap = 2000)
    {
        $this->pattern = EntryFilter::compile($regex);
        $this->tally = new Tally(['n', 'sum'], $cap);
    }

    public function name(): string
    {
        return 'group';
    }

    public function consume(Entry $entry, ?SlowLogRecord $record): void
    {
        if (preg_match($this->pattern, $this->wholeText ? $entry->text : $entry->firstLine, $m) !== 1) {
            return;
        }

        $sum = isset($m['sum']) && is_numeric($m['sum']) ? (float) $m['sum'] : null;

        $this->tally->add(
            self::key($m),
            sum: ['n' => 1] + ($sum === null ? [] : ['sum' => $sum]),
            max: $sum === null ? [] : ['max' => $sum],
            once: ['first' => $entry->datetime, 'sample' => $entry->file.'@'.$entry->offset],
            set: ['last' => $entry->datetime],
            spread: ['levels' => [$entry->level, 1]],
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

    /** @param array<int|string, string> $m */
    private static function key(array $m): string
    {
        if (($m['key'] ?? '') !== '') {
            $key = $m['key'];
        } else {
            // preg_match ghi nhóm có tên hai lần (theo tên rồi theo số ngay sau): bỏ bản theo số đó.
            $parts = [];
            $skipNext = false;

            foreach ($m as $index => $value) {
                if (is_string($index)) {
                    $skipNext = true;

                    continue;
                }

                if ($index !== 0 && ! $skipNext) {
                    $parts[] = $value;
                }

                $skipNext = false;
            }

            $key = $parts === [] ? $m[0] : implode(' | ', $parts);
        }

        $key = Normalizer::maskSecrets(trim($key));

        return mb_scrub(strlen($key) > self::KEY_MAX ? substr($key, 0, self::KEY_MAX).'…' : $key, 'UTF-8');
    }
}
