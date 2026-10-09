<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Http\Middleware;

use Closure;
use HocVT\LogViewerRemote\Agent\AgentConfig;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cửa riêng của `api/agent/*`: Bearer = agent token (chỉ đọc) HOẶC shared secret.
 *
 * Không đi qua callback `LogViewer::auth()`: kiểm theo tên route trong đó dễ vỡ (vendor thêm
 * route GET mới là token chỉ đọc lọt sang), và app có thể gọi lại `LogViewer::auth()` ghi
 * đè. Agent token cũng chỉ hợp lệ ở đây — UI và API của vendor không nhận nó.
 */
class AuthorizeAgent
{
    public const ATTRIBUTE = 'log-viewer-ext.agent-auth';

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) $request->bearerToken();
        $auth = match (true) {
            $bearer === '' => null,
            self::matches(AgentConfig::token(), $bearer) => 'agent',
            self::matches((string) config('log-viewer-ext.shared_secret'), $bearer) => 'shared',
            default => null,
        };

        if ($auth === null) {
            return response()->json(['error' => 'Cần Bearer token: LOG_VIEWER_AGENT_TOKEN hoặc shared secret.'], 403, [], JSON_UNESCAPED_UNICODE);
        }

        $request->attributes->set(self::ATTRIBUTE, $auth);

        return $next($request);
    }

    private static function matches(string $secret, string $bearer): bool
    {
        return $secret !== '' && hash_equals($secret, $bearer);
    }
}
