<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Client;

use HocVT\LogViewerRemote\Agent\AgentService;

/** Host đang chạy lệnh: gọi thẳng AgentService, không qua HTTP, không cần token. */
final class LocalAgentClient implements AgentClient
{
    public function __construct(private readonly AgentService $service) {}

    public function name(): string
    {
        return 'local';
    }

    public function ping(): array
    {
        return $this->service->ping('local');
    }

    public function files(): array
    {
        return $this->service->files();
    }

    public function aggregate(array $params): array
    {
        return $this->service->aggregate($params);
    }

    public function entries(array $params): array
    {
        return $this->service->entries($params);
    }
}
