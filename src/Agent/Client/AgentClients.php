<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Client;

use HocVT\LogViewerRemote\Agent\AgentException;
use HocVT\LogViewerRemote\Agent\AgentService;
use Opcodes\LogViewer\Facades\LogViewer;

/** `--host=` → client: rỗng / `local` / host không có URL = chính máy này; còn lại tra `log-viewer.hosts`. */
final class AgentClients
{
    public function __construct(private readonly AgentService $service) {}

    public function for(?string $identifier): AgentClient
    {
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
