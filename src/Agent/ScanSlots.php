<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Giới hạn số lượt quét chạy cùng lúc trên một host. Mỗi lượt giữ một worker PHP-FPM và
 * đọc đĩa liên tục tới `max_seconds`; host API mà bị vài agent quét song song là tự DoS.
 * Hết chỗ thì trả 429 + `retry_after`, không xếp hàng.
 */
final class ScanSlots
{
    private const PREFIX = 'lvr:agent:slot:';

    /**
     * @template T
     *
     * @param  callable(): T  $scan
     * @return T
     */
    public static function run(callable $scan): mixed
    {
        $lock = self::acquire();

        @set_time_limit((int) ceil((float) AgentConfig::get('max_seconds')) + 10);

        try {
            return $scan();
        } finally {
            $lock?->release();
        }
    }

    private static function acquire(): ?Lock
    {
        $store = CursorStore::store()->getStore();

        // Store không có khoá (vd. `null`) thì không giới hạn được — vẫn cho quét.
        if (! $store instanceof LockProvider) {
            return null;
        }

        $ttl = (int) ceil((float) AgentConfig::get('max_seconds')) + 15;

        for ($i = 0, $slots = max(1, AgentConfig::int('slots')); $i < $slots; $i++) {
            $lock = $store->lock(self::PREFIX.$i, $ttl);

            if ($lock->get()) {
                return $lock;
            }
        }

        throw new AgentException(429, "Host đang chạy đủ {$slots} lượt quét. Thử lại sau.", ['retry_after' => 5], ['Retry-After' => '5']);
    }
}
