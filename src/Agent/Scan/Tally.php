<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

/**
 * Bộ đếm theo khoá có trần bộ nhớ. Vượt 2× trần thì giữ lại `cap` khoá lớn nhất theo
 * `metrics` và đếm số khoá bị bỏ vào `pruned` — không "đầy thì thôi nhận khoá mới" (khoá
 * nóng xuất hiện muộn trong file sẽ mất).
 */
final class Tally
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    private int $pruned = 0;

    /** Số khoá con giữ trong mỗi trường `spread`; vượt 2× thì giữ top, phần còn lại dồn vào `…`. */
    public const SPREAD_CAP = 5;

    /**
     * @param  list<string>  $metrics  trường để xếp hạng, theo thứ tự ưu tiên
     */
    public function __construct(
        private readonly array $metrics,
        private readonly int $cap = 2000,
    ) {}

    /**
     * @param  array<string, int|float>  $sum  cộng dồn
     * @param  array<string, int|float>  $max  giữ giá trị lớn nhất
     * @param  array<string, mixed>  $once  chỉ ghi lần đầu gặp khoá
     * @param  array<string, mixed>  $set  ghi đè mỗi lần
     * @param  array<string, array{0: string, 1: int|float}>  $spread  trường => [khoá con, giá trị cộng dồn],
     *                                                                 vd. một câu SQL thừa ở những trang nào
     */
    public function add(string $key, array $sum = [], array $max = [], array $once = [], array $set = [], array $spread = []): void
    {
        $row = $this->rows[$key] ?? $once;

        foreach ($sum as $field => $value) {
            $row[$field] = ($row[$field] ?? 0) + $value;
        }

        foreach ($max as $field => $value) {
            if (! isset($row[$field]) || $value > $row[$field]) {
                $row[$field] = $value;
            }
        }

        foreach ($set as $field => $value) {
            $row[$field] = $value;
        }

        foreach ($spread as $field => [$sub, $value]) {
            $bucket = $row[$field] ?? [];
            $bucket[$sub] = ($bucket[$sub] ?? 0) + $value;

            if (count($bucket) > 2 * self::SPREAD_CAP) {
                $bucket = self::foldSpread($bucket);
            }

            $row[$field] = $bucket;
        }

        $this->rows[$key] = $row;

        if (count($this->rows) > 2 * $this->cap) {
            $this->prune();
        }
    }

    /**
     * @return list<array<string, mixed>> mỗi hàng có thêm `key`
     */
    public function top(int $limit): array
    {
        $rows = $this->sorted();
        $out = [];

        foreach (array_slice($rows, 0, max(0, $limit), true) as $key => $row) {
            $out[] = ['key' => (string) $key] + self::rounded($row);
        }

        return $out;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function pruned(): int
    {
        return $this->pruned;
    }

    /** @return array{rows: array<string, array<string, mixed>>, pruned: int} */
    public function state(): array
    {
        return ['rows' => $this->rows, 'pruned' => $this->pruned];
    }

    /** @param array<string, mixed> $state */
    public function restore(array $state): void
    {
        $this->rows = (array) ($state['rows'] ?? []);
        $this->pruned = (int) ($state['pruned'] ?? 0);
    }

    /** @return array<string, array<string, mixed>> */
    private function sorted(): array
    {
        $rows = $this->rows;

        uksort($rows, function (string|int $a, string|int $b) use ($rows): int {
            foreach ($this->metrics as $metric) {
                $cmp = ($rows[$b][$metric] ?? 0) <=> ($rows[$a][$metric] ?? 0);

                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            // Hoà thì theo khoá cho kết quả ổn định giữa các lần chạy.
            return strcmp((string) $a, (string) $b);
        });

        return $rows;
    }

    private function prune(): void
    {
        $rows = $this->sorted();
        $this->pruned += count($rows) - $this->cap;
        $this->rows = array_slice($rows, 0, $this->cap, true);
    }

    /**
     * Giữ SPREAD_CAP khoá con lớn nhất, cộng phần còn lại vào `…`.
     *
     * @param  array<string, int|float>  $bucket
     * @return array<string, int|float>
     */
    private static function foldSpread(array $bucket): array
    {
        $rest = $bucket['…'] ?? 0;
        unset($bucket['…']);
        arsort($bucket);

        foreach (array_slice($bucket, self::SPREAD_CAP, null, true) as $value) {
            $rest += $value;
        }

        return array_slice($bucket, 0, self::SPREAD_CAP, true) + ['…' => $rest];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function rounded(array $row): array
    {
        foreach ($row as $field => $value) {
            if (is_float($value)) {
                $row[$field] = round($value, 1);
            } elseif (is_array($value)) {
                $value = count($value) > self::SPREAD_CAP + 1 ? self::foldSpread($value) : $value;
                arsort($value);
                $row[$field] = array_map(static fn (mixed $v): mixed => is_float($v) ? round($v, 1) : $v, $value);
            }
        }

        return $row;
    }
}
