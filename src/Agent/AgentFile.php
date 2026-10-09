<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

/**
 * Một file log agent được đọc: tên tương đối dưới thư mục log, channel, ngày (file daily).
 * Channel null = file Log Viewer liệt kê nhưng không thuộc channel nào (chỉ thấy khi không
 * bị giới hạn channel).
 *
 * `type` / `identifier` lấy nguyên của Log Viewer (LogFile::type(), LogFile::$identifier):
 * loại log vendor nhận diện được (laravel, http_error_nginx, php_fpm…) và định danh mà UI
 * dùng trong `?file=`.
 */
final class AgentFile
{
    /** Bộ đọc của agent chỉ hiểu định dạng log Laravel. */
    public const READABLE_TYPE = 'laravel';

    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly ?string $channel,
        public readonly ?string $date,
        public readonly int $size,
        public readonly int $mtime,
        public readonly string $type = self::READABLE_TYPE,
        public readonly string $identifier = '',
    ) {}

    public function readable(): bool
    {
        return $this->type === self::READABLE_TYPE;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'channel' => $this->channel,
            'type' => $this->type,
            'date' => $this->date,
            'size' => $this->size,
            'modified_at' => date(DATE_ATOM, $this->mtime),
        ];
    }
}
