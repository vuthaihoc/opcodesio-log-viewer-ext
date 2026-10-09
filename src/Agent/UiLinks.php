<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

/**
 * Link mở một entry trong UI Log Viewer: `{route_path}?file=<identifier>&query=<datetime>`.
 *
 * UI vendor nhận `file` + `query` (tìm theo regex trên cả chữ của entry, kể cả dòng header),
 * nên lọc theo đúng giây của entry là ra nó, cùng vài entry cùng giây. Không dùng
 * `query=log-index:N` (nhảy thẳng tới entry thứ N): vendor đếm entry bằng regex riêng của
 * nó, lệch với bộ đọc của agent một dòng là link trỏ sai entry.
 *
 * `file` là identifier vendor tính trong tiến trình web (md5(SERVER_ADDR:path)) — khớp UI.
 * Lệnh chạy ở CLI tính ra identifier khác (IP lấy bằng `hostname -I`), nên ở CLI dùng tên
 * file: LogViewer::getFile() tra theo tên khi không thấy identifier.
 */
final class UiLinks
{
    /** @var array<string, AgentFile> */
    private array $files = [];

    private string $base;

    /** @param list<AgentFile> $files */
    public function __construct(array $files)
    {
        foreach ($files as $file) {
            $this->files[$file->name] = $file;
        }

        $this->base = url((string) config('log-viewer.route_path', 'log-viewer'));
    }

    /** `$sample` dạng `file@offset`. */
    public function for(string $sample, ?string $datetime): ?string
    {
        $name = substr($sample, 0, (int) strrpos($sample, '@'));
        $file = $this->files[$name] ?? null;

        if ($file === null || $datetime === null || $datetime === '') {
            return null;
        }

        return $this->base.'?'.http_build_query(['file' => self::fileParam($file), 'query' => $datetime]);
    }

    /**
     * Gắn `ui_url` cho mọi hàng có `sample` + `first` (thời điểm của entry mẫu).
     *
     * @param  array<string, array<string, mixed>>  $results
     * @return array<string, array<string, mixed>>
     */
    public function addTo(array $results): array
    {
        foreach ($results as $name => $table) {
            foreach ((array) ($table['rows'] ?? []) as $i => $row) {
                if (isset($row['sample'], $row['first']) && ($url = $this->for((string) $row['sample'], (string) $row['first'])) !== null) {
                    $results[$name]['rows'][$i]['ui_url'] = $url;
                }
            }
        }

        return $results;
    }

    private static function fileParam(AgentFile $file): string
    {
        $cliIdentifierDiffers = app()->runningInConsole() && ! config('log-viewer.exclude_ip_from_identifiers', false);

        return $cliIdentifierDiffers || $file->identifier === '' ? $file->name : $file->identifier;
    }
}
