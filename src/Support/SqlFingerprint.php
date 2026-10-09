<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Support;

/**
 * Chuẩn hoá SQL để gom nhóm: gộp danh sách placeholder dài và bỏ khoảng trắng thừa.
 *
 * Dùng chung cho SqlLogger (lúc ghi) và bộ đọc log của agent (lúc gom), để cùng một câu
 * query luôn ra cùng một khoá ở cả hai phía. PHP thuần, không phụ thuộc Laravel.
 */
final class SqlFingerprint
{
    public static function of(string $sql): string
    {
        $sql = preg_replace('/\?(\s*,\s*\?)+/', '?, ...', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        return trim($sql);
    }
}
