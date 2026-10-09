<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan\Aggregators;

use HocVT\LogViewerRemote\Agent\Scan\Aggregator;
use HocVT\LogViewerRemote\Agent\Scan\Entry;
use HocVT\LogViewerRemote\Agent\Scan\SlowLogRecord;

/** Thao tác 1: phân bố level, WEB / CLI, khoảng thời gian thật của các entry đã đọc. */
final class Levels implements Aggregator
{
    private int $entries = 0;

    /** @var array<string, int> */
    private array $levels = [];

    /** @var array<string, int> */
    private array $scopes = [];

    private ?string $first = null;

    private ?string $last = null;

    public function name(): string
    {
        return 'levels';
    }

    public function consume(Entry $entry, ?SlowLogRecord $record): void
    {
        $this->entries++;
        $this->levels[$entry->level] = ($this->levels[$entry->level] ?? 0) + 1;

        $scope = $record->scope ?? match (true) {
            str_starts_with($entry->firstLine, '[WEB]') => 'WEB',
            str_starts_with($entry->firstLine, '[CLI]') => 'CLI',
            default => 'other',
        };
        $this->scopes[$scope] = ($this->scopes[$scope] ?? 0) + 1;

        if ($this->first === null || $entry->datetime < $this->first) {
            $this->first = $entry->datetime;
        }

        if ($this->last === null || $entry->datetime > $this->last) {
            $this->last = $entry->datetime;
        }
    }

    public function state(): array
    {
        return get_object_vars($this);
    }

    public function restore(array $state): void
    {
        $this->entries = (int) ($state['entries'] ?? 0);
        $this->levels = (array) ($state['levels'] ?? []);
        $this->scopes = (array) ($state['scopes'] ?? []);
        $this->first = $state['first'] ?? null;
        $this->last = $state['last'] ?? null;
    }

    public function result(int $top): array
    {
        $levels = $this->levels;
        $scopes = $this->scopes;
        arsort($levels);
        arsort($scopes);

        return [
            'entries' => $this->entries,
            'first' => $this->first,
            'last' => $this->last,
            'levels' => $levels,
            'scopes' => $scopes,
        ];
    }
}
