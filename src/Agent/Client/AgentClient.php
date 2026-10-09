<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Client;

use HocVT\LogViewerRemote\Agent\AgentException;

/**
 * Cách lệnh artisan nói chuyện với một host: in-process (host này) hoặc HTTP (host xa).
 * Lỗi đều ném AgentException mang mã HTTP + payload JSON của host.
 */
interface AgentClient
{
    /** Tên hiển thị của host. */
    public function name(): string;

    /** @return array<string, mixed> */
    public function ping(): array;

    /** @return array<string, mixed> */
    public function files(): array;

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function aggregate(array $params): array;

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function entries(array $params): array;
}
