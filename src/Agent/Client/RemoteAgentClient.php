<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Client;

use HocVT\LogViewerRemote\Agent\AgentConfig;
use HocVT\LogViewerRemote\Agent\AgentException;
use HocVT\LogViewerRemote\Support\RemoteHttp;
use Illuminate\Http\Client\ConnectionException;
use Opcodes\LogViewer\Host;

/**
 * Gọi `api/agent/*` của host xa. Ưu tiên agent token (chỉ đọc); máy này chưa khai thì
 * dùng credential của host (shared secret) và báo cho lệnh biết để in cảnh báo.
 */
final class RemoteAgentClient implements AgentClient
{
    public function __construct(private readonly Host $host) {}

    public function name(): string
    {
        return (string) $this->host->identifier;
    }

    /** true khi máy này không có agent token, phải gửi shared secret. */
    public function usesSharedSecret(): bool
    {
        return AgentConfig::token() === '';
    }

    public function ping(): array
    {
        return $this->get('ping');
    }

    public function files(): array
    {
        return $this->get('files');
    }

    public function aggregate(array $params): array
    {
        return $this->get('aggregate', $params);
    }

    public function entries(array $params): array
    {
        return $this->get('entries', $params);
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function get(string $action, array $params = []): array
    {
        $request = RemoteHttp::client($this->host, (int) config('log-viewer-remote.timeout.agent', 40))->acceptJson();

        if (! $this->usesSharedSecret()) {
            $request = $request->withToken(AgentConfig::token());
        }

        try {
            $response = $request->get(rtrim((string) $this->host->host, '/').'/api/agent/'.$action, array_filter($params, static fn ($v) => $v !== null && $v !== ''));
        } catch (ConnectionException $e) {
            throw new AgentException(502, "Không kết nối được host {$this->name()}: {$e->getMessage()}");
        }

        $json = str_contains((string) $response->header('Content-Type'), 'json') ? $response->json() : null;

        if (! is_array($json)) {
            // Host chưa có endpoint agent: request rơi vào route bắt-tất của Log Viewer, ra HTML 200.
            throw new AgentException(
                $response->successful() ? 501 : $response->status(),
                "Host {$this->name()} trả về không phải JSON (HTTP {$response->status()}) — host chưa nâng cấp hocvt/log-viewer-remote ≥ 1.2, hoặc sai route_path trong log-viewer.hosts.",
            );
        }

        if ($response->failed()) {
            throw new AgentException($response->status(), (string) ($json['error'] ?? "HTTP {$response->status()}"), $json, array_filter(['Retry-After' => (string) $response->header('Retry-After')]));
        }

        return $json;
    }
}
