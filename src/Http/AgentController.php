<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Http;

use Closure;
use HocVT\LogViewerRemote\Agent\AgentException;
use HocVT\LogViewerRemote\Agent\AgentForwarder;
use HocVT\LogViewerRemote\Agent\AgentService;
use HocVT\LogViewerRemote\Http\Middleware\AuthorizeAgent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `{route_path}/api/agent/*` — xem docs/agent.md. Việc thật nằm ở AgentService (dùng chung
 * với lệnh artisan chạy in-process); `?host=<id>` thì AgentForwarder chuyển sang host đó.
 */
class AgentController
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private readonly AgentService $service,
        private readonly AgentForwarder $forwarder,
    ) {}

    public function ping(Request $request): Response
    {
        return $this->respond($request, 'ping', fn (string $auth) => $this->service->ping($auth));
    }

    public function files(Request $request): Response
    {
        return $this->respond($request, 'files', fn (string $auth) => $this->service->files($auth));
    }

    public function aggregate(Request $request): Response
    {
        return $this->respond($request, 'aggregate', fn (string $auth) => $this->service->aggregate($request->query(), $auth));
    }

    public function entries(Request $request): Response
    {
        return $this->respond($request, 'entries', fn (string $auth) => $this->service->entries($request->query(), $auth));
    }

    /** @param Closure(string): array<string, mixed> $action */
    private function respond(Request $request, string $name, Closure $action): Response
    {
        $auth = (string) $request->attributes->get(AuthorizeAgent::ATTRIBUTE);

        try {
            return $this->forwarder->forward($request, $name, $auth)
                ?? response()->json($action($auth), 200, [], self::JSON_FLAGS);
        } catch (AgentException $e) {
            return response()->json(['error' => $e->getMessage()] + $e->payload, $e->status, $e->headers, self::JSON_FLAGS);
        }
    }
}
