<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

use Illuminate\Support\Str;
use Opcodes\LogViewer\Facades\LogViewer;

/**
 * File được đọc = file Log Viewer liệt kê (tôn trọng include / exclude của nó). Với agent
 * token (`restricted`) còn phải thuộc channel cho phép; shared secret và lệnh chạy ngay trên
 * máy thì đọc được hết như UI Log Viewer. Tham số `files` chỉ được chọn trong danh sách này —
 * chặn luôn path traversal, vì không bao giờ mở đường dẫn do client gửi.
 *
 * Định danh là tên tương đối dưới thư mục log, không dùng identifier của vendor: cái đó là
 * md5(IP:path), mà IP lấy từ SERVER_ADDR ở web và `hostname -I` ở CLI — agent không tự tính ra.
 */
final class FileResolver
{
    /** @var list<AgentFile>|null */
    private ?array $allowed = null;

    public function __construct(
        private readonly ChannelFiles $channels,
        private readonly bool $restricted = true,
    ) {}

    /**
     * @param  bool  $restricted  true = agent token: chỉ channel trong `agent.channels`
     */
    public static function fromConfig(bool $restricted = true): self
    {
        $channels = (array) config('logging.channels', []);

        return new self(
            new ChannelFiles($channels, $restricted ? AgentConfig::channels() : array_map('strval', array_keys($channels))),
            $restricted,
        );
    }

    public function restricted(): bool
    {
        return $this->restricted;
    }

    /** @return list<string> */
    public function channels(): array
    {
        return $this->channels->channels();
    }

    /** @return list<AgentFile> theo channel, rồi ngày, rồi tên */
    public function allowed(): array
    {
        if ($this->allowed !== null) {
            return $this->allowed;
        }

        $base = LogViewer::basePathForLogs();
        $files = [];

        foreach (LogViewer::getFiles() as $file) {
            $match = $this->channels->match($file->path);

            if ($match === null && $this->restricted) {
                continue;
            }

            clearstatcache(true, $file->path);

            $files[] = new AgentFile(
                path: $file->path,
                name: str_starts_with($file->path, $base) ? Str::after($file->path, $base) : basename($file->path),
                channel: $match['channel'] ?? null,
                date: $match['date'] ?? null,
                size: (int) @filesize($file->path),
                mtime: (int) @filemtime($file->path),
                type: (string) $file->type()->value,
                identifier: $file->identifier,
            );
        }

        usort($files, static fn (AgentFile $a, AgentFile $b): int => [$a->channel ?? '', $a->date ?? '', $a->name] <=> [$b->channel ?? '', $b->date ?? '', $b->name]);

        return $this->allowed = $files;
    }

    /**
     * Theo tên (`a.log,b.log`), hoặc theo channel + khoảng ngày (ngày theo múi giờ log, chính
     * là ngày trong tên file daily). Không truyền gì = channel của slow log.
     *
     * @return list<AgentFile> cũ → mới
     */
    public function select(?string $names, ?string $channel, ?string $fromDate = null, ?string $toDate = null): array
    {
        if ($names !== null && trim($names) !== '') {
            return self::onlyReadable($this->byName($names), explicit: true);
        }

        $channel = $channel !== null && $channel !== '' ? $channel : AgentConfig::slowLogChannel();

        if (! in_array($channel, $this->channels(), true)) {
            throw $this->restricted
                ? new AgentException(403, "Channel `{$channel}` không được phép.", ['allowed_channels' => $this->channels()])
                : new AgentException(404, "Không có channel `{$channel}` ghi ra file.", ['channels' => $this->channels()]);
        }

        $files = array_values(array_filter(
            $this->allowed(),
            static fn (AgentFile $f): bool => $f->channel === $channel
                && ($f->date === null || (($fromDate === null || $f->date >= $fromDate) && ($toDate === null || $f->date <= $toDate))),
        ));

        if ($files === []) {
            throw new AgentException(404, "Channel `{$channel}` không có file nào trong khoảng đã chọn.", ['channel' => $channel, 'from_date' => $fromDate, 'to_date' => $toDate]);
        }

        return self::onlyReadable($files, explicit: false);
    }

    /**
     * Bộ đọc của agent chỉ hiểu log Laravel; loại khác (Log Viewer nhận diện: nginx, php-fpm…)
     * đọc vào sẽ ra 0 entry mà không báo gì. Chọn đích danh thì báo lỗi; chọn theo channel thì
     * bỏ qua, chỉ báo khi không còn file nào.
     *
     * @param  list<AgentFile>  $files
     * @return list<AgentFile>
     */
    private static function onlyReadable(array $files, bool $explicit): array
    {
        $other = array_values(array_filter($files, static fn (AgentFile $f): bool => ! $f->readable()));

        if ($other === []) {
            return $files;
        }

        $readable = array_values(array_filter($files, static fn (AgentFile $f): bool => $f->readable()));

        if ($explicit || $readable === []) {
            throw new AgentException(422, 'Bộ đọc của agent chỉ hiểu log Laravel; không đọc được: '.implode(', ', array_map(
                static fn (AgentFile $f): string => "{$f->name} ({$f->type})",
                $other,
            )).'. Xem file đó trong UI Log Viewer.', ['readable_type' => AgentFile::READABLE_TYPE]);
        }

        return $readable;
    }

    /** @return list<AgentFile> */
    private function byName(string $names): array
    {
        $byName = [];

        foreach ($this->allowed() as $file) {
            $byName[$file->name] = $file;
        }

        $selected = [];
        $missing = [];

        foreach (array_unique(array_filter(array_map('trim', explode(',', $names)))) as $name) {
            if (isset($byName[$name])) {
                $selected[] = $byName[$name];
            } else {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            throw $this->restricted
                ? new AgentException(403, 'File không có, hoặc không thuộc channel được phép: '.implode(', ', $missing).'.', ['allowed_channels' => $this->channels()])
                : new AgentException(404, 'Log Viewer không liệt kê file: '.implode(', ', $missing).'.');
        }

        usort($selected, static fn (AgentFile $a, AgentFile $b): int => [$a->date ?? '', $a->name] <=> [$b->date ?? '', $b->name]);

        return $selected;
    }
}
