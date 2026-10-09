<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

use RuntimeException;

/** Lỗi trả thẳng cho agent dạng JSON `{error, …}` với mã HTTP tương ứng. */
final class AgentException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $payload = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
