<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

/** Một file cần quét: đường dẫn thật + tên hiển thị (tương đối, đi vào kết quả và `sample`). */
final class ScanFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly int $size,
    ) {}

    public static function at(string $path, ?string $name = null): self
    {
        return new self($path, $name ?? basename($path), (int) @filesize($path));
    }
}
