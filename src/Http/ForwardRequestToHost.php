<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Http;

use Closure;
use HocVT\LogViewerRemote\Support\RemoteHttp;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\Host;
use Opcodes\LogViewer\Http\Middleware\ForwardRequestToHostMiddleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thay ForwardRequestToHostMiddleware của vendor (qua container binding trong
 * LogViewerRemoteServiceProvider, vì vendor gắn cứng middleware trong routes).
 *
 * 1. Tự forward, không gọi parent::handle(): bản vendor đọc credential từ `$host->auth`,
 *    mà Host trả ra ngoài đã bị bỏ credential để khỏi lộ secret ({@see HostCredentials}).
 *    Credential gắn qua {@see RemoteHttp}. Đường forward giữ nguyên như vendor: cùng URL,
 *    cùng header X-Forwarded-*, không gửi body.
 * 2. `download_url` trong JSON trả về là URL do chính host xa sinh, tức khác origin. Nút
 *    Download gọi `axios.get(download_url + '/request')` nên request chết vì CORS. Bản này
 *    viết lại `download_url` về origin hiện tại, trỏ vào {@see RemoteDownloadController}.
 */
class ForwardRequestToHost extends ForwardRequestToHostMiddleware
{
    private const DOWNLOAD_PATH = '#/api/(files|folders)/([^/]+)/download$#';

    /** Header của host xa được chép lại cho client, ngoài Content-Type. */
    private const PASS_HEADERS = ['Content-Type', 'Retry-After'];

    public function handle(Request $request, Closure $next)
    {
        $query = $request->query();
        $hostIdentifier = (string) ($query['host'] ?? '');
        unset($query['host']);

        $host = LogViewer::getHost($hostIdentifier);

        if ($host === null || ! $host->isRemote()) {
            return $next($request);
        }

        $response = $this->forward($request, $host, $query);

        if (! $this->isJson($response)) {
            return $response;
        }

        $payload = json_decode((string) $response->getContent(), true);

        if (! is_array($payload)) {
            return $response;
        }

        return $response->setContent(
            (string) json_encode($this->rewriteDownloadUrls($payload, $hostIdentifier))
        );
    }

    private function forward(Request $request, Host $host, array $query): Response
    {
        $actionPath = Str::replaceFirst((string) config('log-viewer.route_path'), '', $request->path());
        $url = $host->host.$actionPath.($query !== [] ? '?'.http_build_query($query) : '');

        $remote = RemoteHttp::client($host, (int) config('log-viewer-ext.timeout.forward', 30), [
            'X-Forwarded-Host' => $request->getHost(),
            'X-Forwarded-Port' => (string) $request->getPort(),
            'X-Forwarded-Proto' => $request->getScheme(),
        ])->acceptJson()->send($request->method(), $url);

        $headers = [];

        foreach (self::PASS_HEADERS as $name) {
            if ($remote->header($name) !== '') {
                $headers[$name] = $remote->header($name);
            }
        }

        return response($remote->body(), $remote->status(), $headers);
    }

    private function isJson(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'json');
    }

    private function rewriteDownloadUrls(array $payload, string $hostIdentifier): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->rewriteDownloadUrls($value, $hostIdentifier);

                continue;
            }

            if ($key !== 'download_url' || ! is_string($value)) {
                continue;
            }

            $path = (string) parse_url($value, PHP_URL_PATH);

            if (preg_match(self::DOWNLOAD_PATH, $path, $matches) === 1) {
                $payload[$key] = url(sprintf(
                    '%s/remote/%s/api/%s/%s/download',
                    config('log-viewer.route_path'),
                    $hostIdentifier,
                    $matches[1],
                    $matches[2],
                ));
            }
        }

        return $payload;
    }
}
