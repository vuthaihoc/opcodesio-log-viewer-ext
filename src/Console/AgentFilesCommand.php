<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Console;

use HocVT\LogViewerRemote\Agent\AgentException;
use HocVT\LogViewerRemote\Agent\Client\AgentClients;
use Illuminate\Console\Command;

/** File log agent được đọc trên host (chỉ các channel cho phép). */
class AgentFilesCommand extends Command
{
    protected $signature = 'log-viewer-remote:files
        {--host= : identifier trong log-viewer.hosts; bỏ trống = máy này}
        {--via= : đi vòng qua host này (nó forward bằng ?host=); --host khi ấy là host trong config của nó}
        {--channel= : chỉ channel này}
        {--json : in JSON}';

    protected $description = 'Liệt kê file log agent được đọc trên host';

    public function handle(AgentClients $clients): int
    {
        try {
            $client = $clients->for($this->option('host'), $this->option('via'));
            $payload = $client->files();
        } catch (AgentException $e) {
            $this->error("HTTP {$e->status}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $channel = $this->option('channel');
        $files = array_values(array_filter((array) $payload['files'], static fn (array $f) => $channel === null || $f['channel'] === $channel));

        if ($this->option('json')) {
            $this->output->write(json_encode(['files' => $files] + $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

            return self::SUCCESS;
        }

        $this->line(sprintf('# %s · channel cho phép: %s · giờ trong log: %s', $client->name(), implode(', ', (array) $payload['channels']), $payload['log_timezone'] ?? '?'));
        $this->table(['name', 'channel', 'date', 'MB', 'modified_at'], array_map(
            static fn (array $f) => [$f['name'], $f['channel'], $f['date'] ?? '', round($f['size'] / 1048576, 2), $f['modified_at']],
            $files,
        ));

        return self::SUCCESS;
    }
}
