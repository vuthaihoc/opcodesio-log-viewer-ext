<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Support;

use Illuminate\Contracts\Foundation\CachesConfiguration;

/**
 * mergeConfigFrom() của Laravel chỉ gộp CẤP MỘT: app khai `'agent' => ['token' => …]` là mất
 * mọi key khác của nhóm `agent` trong package, kể cả key đọc env. Bản này gộp đệ quy:
 *
 * - mảng có khoá chuỗi → gộp sâu, app chỉ cần ghi key mình đổi;
 * - danh sách (`page_key`, `environments`, `skip_vendors`, kể cả `[]`) và giá trị thường →
 *   app thay hẳn (gộp danh sách theo vị trí là sai nghĩa).
 *
 * `$legacy` đọc thêm config cũ của app (bản ≤ 1.2 có hai file riêng) và đặt nó dưới nhóm mới
 * tương ứng; thứ tự ưu tiên: file mới của app > file cũ của app > mặc định của package.
 *
 * Chạy lúc register() như mergeConfigFrom, bỏ qua khi config đã cache (`config:cache` đã
 * chụp kết quả gộp, kèm giá trị env lúc cache).
 */
trait MergesConfigRecursively
{
    /**
     * @param  array<string, string>  $legacy  key config cũ => nhóm trong config mới ('' = gốc)
     */
    protected function mergeConfigRecursivelyFrom(string $path, string $key, array $legacy = []): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app['config'];
        $merged = require $path;

        foreach ($legacy as $oldKey => $group) {
            $old = $config->get($oldKey);

            if (is_array($old)) {
                $merged = self::mergeRecursive($merged, $group === '' ? $old : [$group => $old]);
            }
        }

        $config->set($key, self::mergeRecursive($merged, (array) $config->get($key, [])));
    }

    /**
     * @param  array<mixed>  $base
     * @param  array<mixed>  $override
     * @return array<mixed>
     */
    private static function mergeRecursive(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $base[$key] = is_array($value) && is_array($base[$key] ?? null) && ! array_is_list($value) && ! array_is_list($base[$key])
                ? self::mergeRecursive($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
