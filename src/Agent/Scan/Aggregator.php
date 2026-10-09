<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

/**
 * Một phép gom chạy chung lượt đọc với các phép khác. Trạng thái là mảng thuần để lưu
 * vào cursor (quét tiếp ở request sau) — quét chia nhỏ phải ra đúng kết quả quét một lượt.
 */
interface Aggregator
{
    /** Tên dùng trong tham số `only` và khoá của kết quả. */
    public function name(): string;

    public function consume(Entry $entry, ?SlowLogRecord $record): void;

    /** @return array<string, mixed> */
    public function state(): array;

    /** @param array<string, mixed> $state */
    public function restore(array $state): void;

    /** @return array<string, mixed> */
    public function result(int $top): array;
}
