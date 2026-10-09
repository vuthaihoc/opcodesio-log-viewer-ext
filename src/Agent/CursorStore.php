<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Trạng thái quét dở (vị trí + mọi aggregator) giữ ở server, client chỉ cầm một id. Bảng
 * top-N đã cắt thì không gộp lại được ở phía client, nên không thể "trả offset rồi client
 * tự cộng". Mỗi cursor dùng một lần, và gắn với đúng bộ tham số đã tạo ra nó.
 */
final class CursorStore
{
    private const PREFIX = 'lvr:agent:cursor:';

    /** @param array<string, mixed> $state */
    public static function put(array $state, string $fingerprint): string
    {
        $id = Str::random(32);
        self::store()->put(self::PREFIX.$id, ['fp' => $fingerprint, 'state' => $state], AgentConfig::int('cursor_ttl'));

        return $id;
    }

    /** @return array<string, mixed> */
    public static function pull(string $id, string $fingerprint): array
    {
        $saved = preg_match('/^[A-Za-z0-9]{32}$/', $id) === 1 ? self::store()->pull(self::PREFIX.$id) : null;

        if (! is_array($saved)) {
            throw new AgentException(410, 'Cursor không còn (hết hạn hoặc đã dùng). Chạy lại từ đầu, không truyền cursor.');
        }

        if (($saved['fp'] ?? null) !== $fingerprint) {
            throw new AgentException(422, 'Cursor thuộc một bộ tham số khác (files / channel / from / to / only). Gửi lại đúng tham số của lượt đầu.');
        }

        return (array) $saved['state'];
    }

    public static function store(): Repository
    {
        return Cache::store(config('log-viewer.cache_driver'));
    }
}
