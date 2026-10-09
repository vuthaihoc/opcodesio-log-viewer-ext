<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Http;

use Closure;
use HocVT\LogViewerRemote\Agent\AgentException;
use HocVT\LogViewerRemote\Agent\AgentService;
use HocVT\LogViewerRemote\Http\Middleware\AuthorizeAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `{route_path}/api/agent/*` — xem docs/agent.md. Việc thật nằm ở AgentService (dùng chung
 * với lệnh artisan chạy in-process).
 */
class AgentController
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(private readonly AgentService $service) {}

    public function ping(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->ping((string) $request->attributes->get(AuthorizeAgent::ATTRIBUTE)));
    }

    public function files(): JsonResponse
    {
        return $this->respond(fn () => $this->service->files());
    }

    public function aggregate(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->aggregate($request->query()));
    }

    public function entries(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->entries($request->query()));
    }

    /** @param Closure(): array<string, mixed> $action */
    private function respond(Closure $action): JsonResponse
    {
        try {
            return response()->json($action(), 200, [], self::JSON_FLAGS);
        } catch (AgentException $e) {
            return response()->json(['error' => $e->getMessage()] + $e->payload, $e->status, $e->headers, self::JSON_FLAGS);
        }
    }
}
