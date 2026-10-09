<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Opcodes\LogViewer\Host;

/**
 * HTTP client gọi sang host ở xa, đã gắn credential lấy từ {@see HostCredentials}.
 * Dùng chung cho forward API, tải file, lệnh check và lệnh agent.
 */
final class RemoteHttp
{
    /**
     * @param  array<string, string>  $headers  header nền; header khai trong config host được ưu tiên
     * @param  bool  $withAuth  false = chỉ gắn header của host, người gọi tự gắn token (agent token)
     */
    public static function client(Host $host, int $timeout, array $headers = [], bool $withAuth = true): PendingRequest
    {
        $credentials = HostCredentials::for($host);

        $request = Http::withHeaders(array_merge($headers, $credentials['headers']))->timeout($timeout);

        if (! $host->verifyServerCertificate) {
            $request = $request->withoutVerifying();
        }

        $auth = $withAuth ? $credentials['auth'] : [];

        return match (true) {
            isset($auth['token']) && $auth['token'] !== '' => $request->withToken((string) $auth['token']),
            isset($auth['username'], $auth['password'], $auth['digest']) => $request->withDigestAuth((string) $auth['username'], (string) $auth['password']),
            isset($auth['username'], $auth['password']) => $request->withBasicAuth((string) $auth['username'], (string) $auth['password']),
            default => $request,
        };
    }
}
