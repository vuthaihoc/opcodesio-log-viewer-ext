<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

use HocVT\LogViewerRemote\Support\RemoteHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\Host;
use Symfony\Component\HttpFoundation\Response;

/**
 * `?host=<id>` trên endpoint agent của host đang xem: chuyển nguyên request sang host đó
 * (giống `?host=` của API Log Viewer). Dùng khi máy gọi chỉ với tới host đang xem, còn các
 * host khác nằm sau nó.
 *
 * - Chỉ forward tới host có trong `log-viewer.hosts` — không nhận URL tuỳ ý (không thành SSRF).
 * - Credential đi theo LOẠI của người gọi, để quyền không bị nâng lên qua bước forward:
 *   gọi bằng agent token thì forward bằng agent token (host xa tự áp giới hạn channel);
 *   gọi bằng shared secret thì forward bằng credential của host, như forward của vendor.
 * - Body, mã trạng thái, Retry-After chuyển nguyên; cursor nằm ở host xa nên các lượt sau
 *   chỉ cần gửi lại cùng `host`.
 */
final class AgentForwarder
{
    /** null = không forward, xử lý tại chỗ (không có `host`, `host=local`, hoặc host không có URL). */
    public function forward(Request $request, string $action, string $auth): ?Response
    {
        $identifier = trim((string) $request->query('host', ''));

        if ($identifier === '' || $identifier === 'local') {
            return null;
        }

        $host = LogViewer::getHost($identifier);

        if ($host === null) {
            throw new AgentException(404, "Không có host `{$identifier}` trong log-viewer.hosts của host này.", [
                'hosts' => LogViewer::getHosts()->map(fn (Host $h) => $h->identifier)->values()->all(),
            ]);
        }

        if (! $host->isRemote()) {
            return null;
        }

        $timeout = (int) config('log-viewer-remote.timeout.agent', 40);
        @set_time_limit($timeout + 10);

        $client = RemoteHttp::client($host, $timeout, withAuth: $auth !== 'agent')->acceptJson();

        if ($auth === 'agent') {
            $client = $client->withToken(AgentConfig::token());
        }

        try {
            $remote = $client->get(rtrim((string) $host->host, '/').'/api/agent/'.$action, Arr::except($request->query(), ['host']));
        } catch (ConnectionException $e) {
            throw new AgentException(502, "Không kết nối được host {$identifier}: {$e->getMessage()}");
        }

        if (! str_contains((string) $remote->header('Content-Type'), 'json')) {
            throw new AgentException(502, "Host {$identifier} trả về không phải JSON (HTTP {$remote->status()}) — host chưa nâng cấp hocvt/log-viewer-remote ≥ 1.2, hoặc sai route_path trong log-viewer.hosts.");
        }

        return response($remote->body(), $remote->status(), array_filter([
            'Content-Type' => $remote->header('Content-Type'),
            'Retry-After' => $remote->header('Retry-After'),
            'X-Log-Viewer-Agent-Host' => $identifier,
        ]));
    }
}
