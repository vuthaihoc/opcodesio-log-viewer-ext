<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

/**
 * Một file log agent được đọc: tên tương đối dưới thư mục log, channel, ngày (file daily).
 * Channel null = file Log Viewer liệt kê nhưng không thuộc channel nào (chỉ thấy khi không
 * bị giới hạn channel).
 */
final class AgentFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly ?string $channel,
        public readonly ?string $date,
        public readonly int $size,
        public readonly int $mtime,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'channel' => $this->channel,
            'date' => $this->date,
            'size' => $this->size,
            'modified_at' => date(DATE_ATOM, $this->mtime),
        ];
    }
}
