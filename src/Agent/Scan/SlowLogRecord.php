<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

/**
 * Một dòng slow log đã tách trường: `query` (một query chậm), `summary` (tổng kết một
 * request / job / command) hoặc `request` (request web chậm / response lớn — RequestLogger).
 */
final class SlowLogRecord
{
    /**
     * @param  'query'|'summary'|'request'  $kind
     * @param  'WEB'|'CLI'  $scope
     * @param  list<string>  $reasons  mã lý do — summary: total | total_ms | duplicate; request: time | size
     * @param  list<array{count: int, ms: float, connection: string, sql: string}>  $groups  nhóm query của summary
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $scope,
        public readonly ?string $url,
        public readonly ?string $route,
        public readonly ?string $referer,
        public readonly ?string $command,
        public readonly array $reasons,
        public readonly ?int $total,
        public readonly ?float $totalMs,
        public readonly ?int $worstDuplicate,
        public readonly array $groups,
        public readonly ?float $ms,
        public readonly ?string $connection,
        public readonly ?string $sql,
        public readonly array $context,
        public readonly ?int $bytes = null,
        public readonly ?int $status = null,
    ) {}
}
