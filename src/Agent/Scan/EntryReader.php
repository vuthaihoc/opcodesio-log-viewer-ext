<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

use Generator;
use RuntimeException;

/**
 * Đọc file log Laravel theo stream, tách entry theo DÒNG HEADER chứ không theo ký tự
 * xuống dòng: một entry có thể dài hàng chục dòng (slow log, stack trace).
 *
 * Hai bước tách nhau để vòng đọc chính rẻ nhất có thể:
 * 1. Quét tìm header: fgets + một regex cho dòng bắt đầu bằng `[`, không làm gì khác với
 *    các dòng còn lại (file CLI 342 MB có 2,2 triệu dòng mà chỉ 77 nghìn header).
 * 2. Entry nào cần phần chữ (`$keepText`) mới `fread` lại đúng đoạn byte của nó — biết
 *    được nhờ offset của header kế tiếp.
 *
 * Reader của vendor dựng một object LaravelLog cho mỗi entry (đổi encoding, parse ngày,
 * tách context…) — hợp lý cho 25 dòng một trang UI, chậm hơn nhiều lần khi đi hết file.
 *
 * Giới hạn có chủ đích:
 * - Entry quá dài chỉ giữ `headBytes` đầu + `tailBytes` cuối: context JSON nằm ở cuối.
 * - Dòng dài hơn CHUNK được đọc thành nhiều mảnh; chỉ mảnh đầu dòng mới có thể là header.
 * - Entry cuối file đang được ghi dở thì được đọc như đã xong.
 */
final class EntryReader
{
    /**
     * `[2026-10-06 07:12:07] production.WARNING: …` — nhận cả dạng ISO (`T`, phần lẻ giây,
     * múi giờ). Level giới hạn trong 8 mức của Monolog (luôn viết hoa) để dòng lạ bắt đầu
     * bằng `[` trong thân entry không bị nhận nhầm là header.
     */
    public const HEADER = '/^\[(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})[^\]]*\]\s+(\S+)\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):[ ]?(.*)$/';

    private const CHUNK = 65536;

    private const FIRST_LINE_MAX = 2000;

    /** Seek nhị phân dừng khi khoảng còn lại nhỏ hơn mức này rồi đọc tuần tự. */
    private const SEEK_MIN_SPAN = 65536;

    /** Tìm header kế tiếp khi seek: quá mức này coi như không có. */
    private const SEEK_MAX_SCAN = 8 * 1024 * 1024;

    /** Offset của entry chưa xử lý sau lần read() gần nhất (= vị trí cuối file nếu đã hết). */
    private int $nextOffset = 0;

    /** Vị trí cuối cùng bước quét header đã đi tới. */
    private int $scannedTo = 0;

    /** read() dừng vì đã qua `to` của cửa sổ thời gian (không cần đọc tiếp file này). */
    private bool $pastWindow = false;

    /** read() dừng vì hết Budget — còn phần chưa đọc, phải quét tiếp từ nextOffset(). */
    private bool $outOfBudget = false;

    public function __construct(
        private readonly string $path,
        private readonly string $name,
        private readonly int $headBytes = 65536,
        private readonly int $tailBytes = 8192,
    ) {}

    /**
     * `$keepText` nhận dòng đầu, trả false thì entry chỉ mang dòng đầu, không đọc phần chữ
     * còn lại. null = giữ hết.
     *
     * @param  (callable(string): bool)|null  $keepText
     * @return Generator<int, Entry>
     */
    public function read(int $offset, Window $window, Budget $budget, ?callable $keepText = null): Generator
    {
        $this->nextOffset = -1;
        $this->pastWindow = false;
        $this->outOfBudget = false;
        $this->scannedTo = $offset;

        $slices = $this->open();
        $previous = null;
        $charged = $offset;

        try {
            foreach ($this->headers($offset) as $header) {
                if ($previous !== null) {
                    $entry = $this->entry($slices, $previous, $header[0], $keepText);

                    if ($window->contains($entry->datetime)) {
                        yield $entry;
                    }
                }

                $budget->charge($header[0] - $charged);
                $charged = $header[0];
                $previous = null;

                if ($window->pastEnd($header[1])) {
                    $this->pastWindow = true;
                    $this->nextOffset = $header[0];

                    return;
                }

                if ($budget->exhausted()) {
                    $this->outOfBudget = true;
                    $this->nextOffset = $header[0];

                    return;
                }

                $previous = $header;
            }

            $budget->charge($this->scannedTo - $charged);

            if ($previous !== null) {
                $entry = $this->entry($slices, $previous, $this->scannedTo, $keepText);

                if ($window->contains($entry->datetime)) {
                    yield $entry;
                }
            }

            $this->nextOffset = $this->scannedTo;
        } finally {
            fclose($slices);
        }
    }

    public function nextOffset(): int
    {
        return $this->nextOffset;
    }

    public function stoppedPastWindow(): bool
    {
        return $this->pastWindow;
    }

    public function stoppedByBudget(): bool
    {
        return $this->outOfBudget;
    }

    /**
     * Offset của một header mà mọi entry đứng trước nó có datetime < $target (giả định file
     * gần như theo thứ tự thời gian; người gọi tự lùi $target một khoảng trễ cho phép).
     */
    public function seek(string $target): int
    {
        $low = 0;
        $high = (int) filesize($this->path);

        while ($high - $low > self::SEEK_MIN_SPAN) {
            $middle = intdiv($low + $high, 2);
            $found = $this->headerAtOrAfter($middle);

            if ($found === null || $found[1] >= $target) {
                $high = $middle;
            } else {
                $low = $found[0];
            }
        }

        return $this->headerAtOrAfter($low)[0] ?? $low;
    }

    /**
     * Vòng đọc nóng: không gọi method, không ghép chuỗi cho dòng thường. Mỗi header là
     * [offset, datetime, env, LEVEL, phần message của dòng đầu].
     *
     * @return Generator<int, array{0: int, 1: string, 2: string, 3: string, 4: string}>
     */
    private function headers(int $offset): Generator
    {
        $handle = $this->open();
        fseek($handle, $offset);

        $position = $offset;
        $atLineStart = true;

        try {
            while (($chunk = fgets($handle, self::CHUNK)) !== false) {
                if ($atLineStart && $chunk[0] === '[' && preg_match(self::HEADER, $chunk, $m) === 1) {
                    yield [$position, $m[1].' '.$m[2], $m[3], $m[4], substr(rtrim($m[5], "\r"), 0, self::FIRST_LINE_MAX)];
                }

                $atLineStart = $chunk[-1] === "\n";
                $position += strlen($chunk);
            }

            $this->scannedTo = $position;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @param  array{0: int, 1: string, 2: string, 3: string, 4: string}  $header
     * @param  (callable(string): bool)|null  $keepText
     */
    private function entry($handle, array $header, int $end, ?callable $keepText): Entry
    {
        [$offset, $datetime, $env, $level, $first] = $header;
        $length = $end - $offset;
        $keep = $keepText === null || $keepText($first);
        $dropped = 0;

        if (! $keep) {
            $text = $first;
        } elseif ($length <= $this->headBytes + $this->tailBytes) {
            $text = $this->slice($handle, $offset, $length);
        } else {
            $dropped = $length - $this->headBytes - $this->tailBytes;
            $text = $this->slice($handle, $offset, $this->headBytes)
                ."\n…[cắt {$dropped} byte]…\n"
                .$this->slice($handle, $end - $this->tailBytes, $this->tailBytes);
        }

        return new Entry(
            file: $this->name,
            offset: $offset,
            length: $length,
            datetime: $datetime,
            env: $env,
            level: $level,
            firstLine: $first,
            text: rtrim($text, "\r\n"),
            truncated: $dropped > 0,
            textKept: $keep,
        );
    }

    /** @param resource $handle */
    private function slice($handle, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        fseek($handle, $offset);

        return (string) fread($handle, $length);
    }

    /**
     * @return array{0: int, 1: string}|null [offset, datetime]
     */
    private function headerAtOrAfter(int $position): ?array
    {
        $handle = $this->open();

        try {
            fseek($handle, $position);

            // Giữa dòng thì bỏ phần còn lại của dòng đó.
            $atLineStart = $position === 0;
            $scanned = 0;

            while ($scanned < self::SEEK_MAX_SCAN && ($chunk = fgets($handle, self::CHUNK)) !== false) {
                if ($atLineStart && $chunk[0] === '[' && preg_match(self::HEADER, $chunk, $m) === 1) {
                    return [$position + $scanned, $m[1].' '.$m[2]];
                }

                $scanned += strlen($chunk);
                $atLineStart = $chunk[-1] === "\n";
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function open()
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Không mở được file log {$this->name}.");
        }

        return $handle;
    }
}
