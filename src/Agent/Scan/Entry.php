<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

/**
 * Một entry log Laravel (`[datetime] env.LEVEL: message …`), có thể dài nhiều dòng.
 *
 * `text` chỉ giữ phần đầu + phần cuối của entry quá dài (xem EntryReader): context JSON
 * nằm ở cuối, sau stack trace, nên cắt kiểu "chỉ giữ đầu" là mất context.
 */
final class Entry
{
    /** @var array<string, mixed>|false|null false = đã thử, không có context */
    private array|false|null $context = null;

    /** @var list<string>|null */
    private ?array $lines = null;

    public function __construct(
        public readonly string $file,
        public readonly int $offset,
        public readonly int $length,
        public readonly string $datetime,
        public readonly string $env,
        public readonly string $level,
        public readonly string $firstLine,
        public readonly string $text,
        public readonly bool $truncated,
        private readonly bool $textKept = true,
    ) {}

    /**
     * false khi EntryReader được bảo bỏ qua phần chữ của entry này (chỉ còn dòng đầu):
     * không có context, không có các dòng sau.
     */
    public function hasText(): bool
    {
        return $this->textKept;
    }

    /**
     * Context JSON Laravel ghi ở cuối entry; null nếu không có hoặc không đọc được
     * (vd. bị cắt giữa chừng ở entry quá dài).
     *
     * @return array<string, mixed>|null
     */
    public function context(): ?array
    {
        if ($this->context === null) {
            $this->context = $this->textKept ? (TrailingJson::split($this->text)[0] ?? false) : false;
        }

        return $this->context === false ? null : $this->context;
    }

    /**
     * Các dòng của entry, dòng đầu là phần message sau `LEVEL: `.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        if ($this->lines === null) {
            $lines = preg_split('/\r?\n/', $this->text) ?: [];
            $lines[0] = $this->firstLine;
            $this->lines = $lines;
        }

        return $this->lines;
    }

    /** Dòng thứ hai trở đi tồn tại không — entry một dòng thì bỏ qua được nhiều việc. */
    public function isMultiline(): bool
    {
        return str_contains($this->text, "\n");
    }
}
