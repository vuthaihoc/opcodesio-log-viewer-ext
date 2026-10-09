<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Console;

use HocVT\LogViewerRemote\Agent\AgentException;
use HocVT\LogViewerRemote\Agent\Client\AgentClients;
use Illuminate\Console\Command;

/**
 * Đọc trọn entry trên host: một entry theo `--at=file@offset` (cột `sample` của lệnh
 * aggregate), hoặc lọc `--contains` / `--regex` / `--level`. Email và token đã được che.
 */
class AgentEntriesCommand extends Command
{
    protected $signature = 'log-viewer-remote:entries
        {--host= : identifier trong log-viewer.hosts; bỏ trống = máy này}
        {--via= : đi vòng qua host này (nó forward bằng ?host=); --host khi ấy là host trong config của nó}
        {--at= : đúng một entry: file@offset}
        {--files= : tên file cách nhau dấu phẩy}
        {--channel= : channel log; mặc định channel của slow log}
        {--date= : cả một ngày theo giờ người hỏi (Y-m-d)}
        {--from=} {--to=}
        {--contains= : chuỗi con, không phân biệt hoa thường}
        {--regex= : regex PCRE (không cần dấu phân cách), không phân biệt hoa thường}
        {--level= : vd. error,alert}
        {--limit=10}
        {--max-bytes= : cắt chữ mỗi entry}
        {--cursor= : giá trị next của lần chạy trước}
        {--json : in JSON}';

    protected $description = 'Đọc trọn entry log trên host (theo vị trí hoặc theo bộ lọc)';

    public function handle(AgentClients $clients): int
    {
        $params = array_filter([
            'at' => $this->option('at'),
            'files' => $this->option('files'),
            'channel' => $this->option('channel'),
            'date' => $this->option('date'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'contains' => $this->option('contains'),
            'regex' => $this->option('regex'),
            'level' => $this->option('level'),
            'limit' => (string) $this->option('limit'),
            'max_bytes' => $this->option('max-bytes'),
            'cursor' => $this->option('cursor'),
        ], static fn ($v) => $v !== null && $v !== '');

        try {
            $client = $clients->for($this->option('host'), $this->option('via'));
            $payload = $client->entries($params);
        } catch (AgentException $e) {
            $this->error("HTTP {$e->status}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->output->write($this->option('json')
            ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            : AgentOutput::entries($payload, $client->name()));

        return self::SUCCESS;
    }
}
