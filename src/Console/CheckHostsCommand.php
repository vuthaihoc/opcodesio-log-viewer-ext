<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\Host;

/**
 * Gọi `/api/hosts` của từng host ở xa bằng shared secret. Nhìn một lần biết host nào
 * lệch token, host nào chưa cài package, host nào bị chặn hoặc timeout.
 */
class CheckHostsCommand extends Command
{
    protected $signature = 'log-viewer-remote:check {host? : Chỉ kiểm một host theo identifier}';

    protected $description = 'Kiểm tra kết nối server-to-server tới các host trong config(log-viewer.hosts)';

    public function handle(): int
    {
        $only = $this->argument('host');
        $hosts = LogViewer::getHosts()
            ->filter(fn (Host $host) => $host->isRemote() && ($only === null || $host->identifier === $only));

        if ($hosts->isEmpty()) {
            $this->warn($only ? "Không có host ở xa tên `{$only}`." : 'Chưa khai host ở xa nào (config log-viewer.hosts hoặc LOG_VIEWER_HOSTS).');

            return self::INVALID;
        }

        if ((string) config('log-viewer-remote.shared_secret') === '') {
            $this->warn('LOG_VIEWER_SHARED_SECRET đang rỗng — mọi host sẽ trả 403.');
        }

        $rows = $hosts->map(fn (Host $host) => $this->probe($host))->values()->all();

        $this->table(['Host', 'Tên', 'URL', 'Kết quả', 'ms'], $rows);

        $failed = collect($rows)->contains(fn (array $row) => ! str_starts_with($row[3], 'OK'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} */
    private function probe(Host $host): array
    {
        $started = microtime(true);

        try {
            $request = Http::withHeaders($host->headers ?? [])->acceptJson()->timeout(10);

            if (! $host->verifyServerCertificate) {
                $request = $request->withoutVerifying();
            }

            $token = $host->auth['token'] ?? null;

            $response = ($token ? $request->withToken($token) : $request)->get("{$host->host}/api/hosts");

            $result = match (true) {
                $response->successful() && is_array($response->json()) => 'OK',
                $response->successful() => 'Lỗi: 200 nhưng không phải JSON (URL sai, nhận về trang HTML?)',
                $response->status() === 403 => 'Lỗi: 403 — token lệch hoặc host chưa cài package',
                $response->status() === 404 => 'Lỗi: 404 — sai route_path hoặc Log Viewer bị tắt',
                default => "Lỗi: HTTP {$response->status()}",
            };
        } catch (ConnectionException $e) {
            $result = 'Lỗi: không kết nối được — '.$e->getMessage();
        }

        return [
            (string) $host->identifier,
            $host->name,
            (string) $host->host,
            $result,
            (string) (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
