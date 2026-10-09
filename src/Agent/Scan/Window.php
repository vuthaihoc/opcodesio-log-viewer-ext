<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Cửa sổ thời gian `[from, to]`, cùng múi giờ với timestamp ghi trong log
 * (`Y-m-d H:i:s`, không kèm offset) nên so sánh bằng chuỗi, không dựng DateTime mỗi entry.
 *
 * Nhiều worker ghi chung một file nên thời gian không tăng đều tuyệt đối: chỉ dừng đọc khi
 * gặp entry quá `to` + slack, và seek tới `from` − slack rồi lọc tuần tự.
 */
final class Window
{
    private ?string $stopAfter = null;

    private ?string $seekTarget = null;

    public function __construct(
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly int $slack = 120,
    ) {
        if ($to !== null) {
            $this->stopAfter = self::shift($to, $slack);
        }

        if ($from !== null) {
            $this->seekTarget = self::shift($from, -$slack);
        }
    }

    public static function all(): self
    {
        return new self;
    }

    public function contains(string $datetime): bool
    {
        return ($this->from === null || $datetime >= $this->from)
            && ($this->to === null || $datetime <= $this->to);
    }

    public function pastEnd(string $datetime): bool
    {
        return $this->stopAfter !== null && $datetime > $this->stopAfter;
    }

    public function seekTarget(): ?string
    {
        return $this->seekTarget;
    }

    private static function shift(string $datetime, int $seconds): string
    {
        return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))
            ->modify(sprintf('%+d seconds', $seconds))
            ->format('Y-m-d H:i:s');
    }
}
