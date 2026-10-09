<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Support;

use Illuminate\Contracts\Foundation\CachesConfiguration;

/**
 * mergeConfigFrom() chỉ gộp CẤP MỘT: app khai `'agent' => ['token' => …]` là mất mọi key
 * khác của nhóm `agent` trong package, kể cả key đọc env (`LOG_VIEWER_AGENT_MAX_SECONDS`).
 * Bản này gộp thêm một cấp cho các nhóm chỉ định, để app chỉ ghi đúng key mình đổi.
 *
 * Chạy lúc register() như mergeConfigFrom, và bỏ qua khi config đã cache (`config:cache`
 * đã chụp kết quả gộp, kèm giá trị env lúc cache).
 */
trait MergesNestedConfig
{
    /**
     * @param  list<string>  $groups  key cấp một là mảng, gộp sâu thêm một cấp
     */
    protected function mergeNestedConfigFrom(string $path, string $key, array $groups): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $package = require $path;
        $app = (array) $this->app['config']->get($key, []);
        $merged = array_merge($package, $app);

        foreach ($groups as $group) {
            if (is_array($package[$group] ?? null) && is_array($app[$group] ?? null)) {
                $merged[$group] = array_merge($package[$group], $app[$group]);
            }
        }

        $this->app['config']->set($key, $merged);
    }
}
