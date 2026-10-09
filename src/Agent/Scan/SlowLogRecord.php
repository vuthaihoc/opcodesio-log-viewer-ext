<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

/**
 * Một dòng slow log đã tách trường: `query` (một query chậm) hoặc `summary` (tổng kết
 * một request / job / command).
 */
final class SlowLogRecord
{
    /**
     * @param  'query'|'summary'  $kind
     * @param  'WEB'|'CLI'  $scope
     * @param  list<string>  $reasons  mã lý do của summary: total | total_ms | duplicate
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
    ) {}
}
