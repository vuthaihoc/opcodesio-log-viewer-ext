<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Client;

use HocVT\LogViewerRemote\Agent\AgentException;
use HocVT\LogViewerRemote\Agent\AgentService;
use Opcodes\LogViewer\Facades\LogViewer;

/**
 * `--host=` → client: rỗng / `local` / host không có URL = chính máy này; còn lại tra
 * `log-viewer.hosts`. `--via=` = đi vòng qua host đó (`?host=` forward), `--host` khi ấy là
 * identifier trong log-viewer.hosts của host trung gian.
 */
final class AgentClients
{
    public function __construct(private readonly AgentService $service) {}

    public function for(?string $identifier, ?string $via = null): AgentClient
    {
        if ($via !== null && $via !== '') {
            $host = LogViewer::getHost($via);

            if ($host === null || ! $host->isRemote()) {
                throw new AgentException(404, "Host trung gian `{$via}` không có (hoặc không có URL) trong log-viewer.hosts.");
            }

            return new RemoteAgentClient($host, $identifier === null || $identifier === '' ? null : $identifier);
        }

        if ($identifier === null || $identifier === '' || $identifier === 'local') {
            return new LocalAgentClient($this->service);
        }

        $host = LogViewer::getHost($identifier);

        if ($host === null) {
            $known = LogViewer::getHosts()->map(fn ($h) => $h->identifier)->implode(', ');

            throw new AgentException(404, "Không có host `{$identifier}` trong log-viewer.hosts. Có: {$known}.");
        }

        return $host->isRemote() ? new RemoteAgentClient($host) : new LocalAgentClient($this->service);
    }
}
