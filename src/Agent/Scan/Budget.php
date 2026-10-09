<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

/**
 * Trần cho một lượt quét: hết giờ hoặc hết số byte thì dừng ở đầu entry kế tiếp, trả
 * cursor để lượt sau đọc tiếp. Không có trần thì một request có thể giữ worker
 * PHP-FPM hàng phút trên file vài trăm MB.
 */
final class Budget
{
    private float $started;

    private int $spent = 0;

    /**
     * @param  float  $maxSeconds  0 = không giới hạn
     * @param  int  $maxBytes  0 = không giới hạn
     */
    public function __construct(
        private readonly float $maxSeconds = 0,
        private readonly int $maxBytes = 0,
    ) {
        $this->started = microtime(true);
    }

    public static function unlimited(): self
    {
        return new self;
    }

    public function charge(int $bytes): void
    {
        $this->spent += $bytes;
    }

    public function exhausted(): bool
    {
        return ($this->maxBytes > 0 && $this->spent >= $this->maxBytes)
            || ($this->maxSeconds > 0 && microtime(true) - $this->started >= $this->maxSeconds);
    }

    public function spentBytes(): int
    {
        return $this->spent;
    }

    public function elapsedMs(): int
    {
        return (int) round((microtime(true) - $this->started) * 1000);
    }
}
