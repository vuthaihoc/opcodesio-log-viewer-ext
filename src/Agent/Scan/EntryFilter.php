<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

use InvalidArgumentException;

/**
 * Lọc entry trước khi gom / trả về: level, chuỗi con, regex. So trên dòng đầu (nhanh: entry
 * thường không phải đọc lại chữ) hoặc trên cả entry (`wholeText`: phải đọc chữ của mọi entry).
 *
 * Regex do người gọi gửi: viết không cần dấu phân cách, không phân biệt hoa thường. Lỗi biên
 * dịch → InvalidArgumentException; lỗi lúc chạy (vượt backtrack limit…) coi như không khớp,
 * nên một regex tồi chỉ làm chậm tới trần Budget chứ không treo worker.
 */
final class EntryFilter
{
    private ?string $pattern;

    /** @var list<string>|null */
    private ?array $levels;

    /**
     * @param  list<string>|null  $levels
     */
    public function __construct(
        private readonly ?string $contains = null,
        ?string $regex = null,
        ?array $levels = null,
        private readonly bool $wholeText = false,
    ) {
        $this->pattern = $regex === null ? null : self::compile($regex);
        $this->levels = $levels === null ? null : array_values(array_map('strtoupper', $levels));
    }

    /** `~…~i` từ chuỗi regex người gọi gửi; ném InvalidArgumentException nếu không biên dịch được. */
    public static function compile(string $regex): string
    {
        $pattern = '~'.str_replace('~', '\~', $regex).'~i';

        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException("Regex không hợp lệ: {$regex}");
        }

        return $pattern;
    }

    public function isEmpty(): bool
    {
        return $this->contains === null && $this->pattern === null && $this->levels === null;
    }

    /** Có phải đọc chữ của mọi entry không (lọc chuỗi / regex trên cả entry). */
    public function needsText(): bool
    {
        return $this->wholeText && ($this->contains !== null || $this->pattern !== null);
    }

    public function matches(Entry $entry): bool
    {
        if ($this->levels !== null && ! in_array($entry->level, $this->levels, true)) {
            return false;
        }

        $subject = $this->wholeText ? $entry->text : $entry->firstLine;

        if ($this->contains !== null && stripos($subject, $this->contains) === false) {
            return false;
        }

        return $this->pattern === null || preg_match($this->pattern, $subject) === 1;
    }
}
