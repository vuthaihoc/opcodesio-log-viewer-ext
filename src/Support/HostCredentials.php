<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Support;

use Opcodes\LogViewer\Host;
use Opcodes\LogViewer\Utils\Utils;

/**
 * Credential của host ở xa (`auth`, `headers`) chỉ đọc qua đây, thẳng từ
 * `config('log-viewer.hosts')`.
 *
 * Host trả ra ngoài thì đã bị bỏ hai trường này ({@see self::strip()}). Lý do: vendor
 * đưa nguyên `LogViewer::getHosts()` vào `window.LogViewer` ở trang chính, và trả
 * `LogViewerHostResource` (có `auth`) ở `/api/hosts`. Như vậy ai xem được Log Viewer
 * cũng đọc được shared secret trong mã nguồn trang. Frontend vendor không dùng tới hai
 * trường đó.
 */
final class HostCredentials
{
    /**
     * @return array{auth: array<string, mixed>, headers: array<string, string>}
     */
    public static function for(Host $host): array
    {
        foreach ((array) config('log-viewer.hosts', []) as $key => $config) {
            if (! is_array($config) || self::identifier($key, $config) !== $host->identifier) {
                continue;
            }

            return [
                'auth' => (array) ($config['auth'] ?? []),
                'headers' => (array) ($config['headers'] ?? []),
            ];
        }

        return ['auth' => [], 'headers' => []];
    }

    public static function strip(Host $host): Host
    {
        return new Host(
            $host->identifier,
            $host->name,
            $host->host,
            [],
            [],
            $host->verifyServerCertificate,
        );
    }

    /** Cùng luật đặt identifier với Host::fromConfig(). */
    private static function identifier(string|int $key, array $config): string
    {
        return is_string($key) ? $key : Utils::shortMd5((string) ($config['host'] ?? ''));
    }
}
