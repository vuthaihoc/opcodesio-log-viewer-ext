<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\Host;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tải file/thư mục log của host ở xa qua chính host đang xem.
 *
 * Frontend Log Viewer gọi `GET {download_url}/request` bằng axios rồi mở URL nhận
 * được. Với host ở xa, cả hai bước đều hỏng nếu trỏ thẳng sang đó:
 *
 * 1. Bước `/request` là XHR khác origin → CORS chặn.
 * 2. Bước tải file: route download của package nằm trong group `api_middleware` nên
 *    ngoài ValidateSignature còn có AuthorizeLogViewer. Trình duyệt không có session
 *    trên host xa → 403, chữ ký hợp lệ cũng không qua được.
 *
 * Nên cả hai bước đều ở lại origin hiện tại: `/request` trả về URL local, còn
 * `download()` mới gọi sang host xa bằng shared secret rồi đẩy nội dung về client.
 *
 * @see ForwardRequestToHost — nơi viết lại `download_url` về đây.
 */
class RemoteDownloadController
{
    private const CHUNK = 8192;

    public function request(string $host, string $type, string $identifier): JsonResponse
    {
        $this->host($host);

        return response()->json([
            'url' => route('log-viewer.remote.download', [
                'host' => $host,
                'type' => $type,
                'identifier' => $identifier,
            ]),
        ]);
    }

    public function download(string $host, string $type, string $identifier): StreamedResponse
    {
        $remote = $this->host($host);

        // Đọc theo chunk thay vì ->body(): file log production có thể vài trăm MB,
        // nạp hết vào chuỗi PHP là chết memory_limit.
        $response = $this->client($remote)
            ->timeout((int) config('log-viewer-remote.timeout.download', 300))
            ->withOptions(['stream' => true])
            ->get($this->signedUrl($remote, $type, $identifier));

        $this->abortUnlessSuccessful($response, $remote);

        $body = $response->toPsrResponse()->getBody();

        return response()->streamDownload(
            function () use ($body): void {
                while (! $body->eof() && ! connection_aborted()) {
                    echo $body->read(self::CHUNK);
                    flush();
                }

                $body->close();
            },
            $this->filename($response, $identifier),
            ['Content-Type' => $response->header('Content-Type') ?: 'application/octet-stream'],
        );
    }

    /**
     * Xin link đã ký của host xa. Cố tình KHÔNG gửi X-Forwarded-Host như
     * ForwardRequestToHostMiddleware: host xa phải ký URL bằng chính domain của nó,
     * nếu không chữ ký sẽ hỏng khi mình gọi lại.
     */
    private function signedUrl(Host $host, string $type, string $identifier): string
    {
        $response = $this->client($host)
            ->acceptJson()
            ->get("{$host->host}/api/{$type}/{$identifier}/download/request");

        $this->abortUnlessSuccessful($response, $host);

        $url = (string) $response->json('url', '');

        abort_if($url === '', 502, "Host {$host->name} trả về link tải rỗng.");

        return $url;
    }

    private function host(string $identifier): Host
    {
        $host = LogViewer::getHost($identifier);

        abort_if($host === null || ! $host->isRemote(), 404);

        return $host;
    }

    private function client(Host $host): PendingRequest
    {
        $request = Http::withHeaders($host->headers ?? [])
            ->timeout((int) config('log-viewer-remote.timeout.request', 15));

        if (! $host->verifyServerCertificate) {
            $request = $request->withoutVerifying();
        }

        $auth = $host->auth ?? [];

        if (isset($auth['token'])) {
            return $request->withToken($auth['token']);
        }

        if (isset($auth['username'], $auth['password'])) {
            return $request->withBasicAuth($auth['username'], $auth['password']);
        }

        return $request;
    }

    private function abortUnlessSuccessful(ClientResponse $response, Host $host): void
    {
        abort_unless(
            $response->successful(),
            $response->status() >= 400 ? $response->status() : 502,
            "Host {$host->name} trả về lỗi {$response->status()} khi tải log.",
        );
    }

    private function filename(ClientResponse $response, string $identifier): string
    {
        $disposition = (string) $response->header('Content-Disposition');

        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches) === 1) {
            return basename(urldecode($matches[1]));
        }

        return basename($identifier);
    }
}
