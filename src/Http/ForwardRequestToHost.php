<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Http;

use Closure;
use Illuminate\Http\Request;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\Http\Middleware\ForwardRequestToHostMiddleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log Viewer proxy danh sách file/thư mục của host ở xa server-to-server, nhưng
 * `download_url` trong JSON trả về là URL do chính host đó sinh — tức khác origin.
 * Nút Download gọi `axios.get(download_url + '/request')` nên request chết vì CORS.
 *
 * Middleware này viết lại `download_url` về origin hiện tại, trỏ vào
 * {@see RemoteDownloadController}.
 *
 * Vendor gắn cứng ForwardRequestToHostMiddleware trong routes của package, nên bản
 * kế thừa này được thay bằng container binding trong LogViewerRemoteServiceProvider.
 */
class ForwardRequestToHost extends ForwardRequestToHostMiddleware
{
    private const DOWNLOAD_PATH = '#/api/(files|folders)/([^/]+)/download$#';

    public function handle(Request $request, Closure $next)
    {
        $hostIdentifier = (string) $request->query('host', '');
        $host = LogViewer::getHost($hostIdentifier);

        $response = parent::handle($request, $next);

        if ($host === null || ! $host->isRemote() || ! $this->isJson($response)) {
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
