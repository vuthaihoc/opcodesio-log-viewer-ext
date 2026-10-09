<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Console;

use HocVT\LogViewerRemote\Agent\AgentException;
use HocVT\LogViewerRemote\Agent\Client\AgentClients;
use HocVT\LogViewerRemote\Agent\Client\RemoteAgentClient;
use Illuminate\Console\Command;

/**
 * Gom số liệu log NGAY TRÊN host có file, không tải file về. Tự gọi tiếp theo cursor tới
 * khi xong, chờ rồi thử lại khi host bận (429). Xem docs/agent.md.
 */
class AgentAggregateCommand extends Command
{
    private const MAX_ROUNDS = 200;

    private const MAX_BUSY_RETRIES = 6;

    protected $signature = 'log-viewer-remote:aggregate
        {--host= : identifier trong log-viewer.hosts; bỏ trống = máy này}
        {--files= : tên file cách nhau dấu phẩy (xem log-viewer-remote:files)}
        {--channel= : channel log; mặc định channel của slow log}
        {--date= : cả một ngày theo giờ người hỏi (Y-m-d)}
        {--from= : ISO 8601, `Y-m-d H:i` (giờ người hỏi) hoặc `-2 hours`}
        {--to= : như --from}
        {--only= : levels,messages,pages,sql_waste,commands,slow_queries}
        {--top=20 : số hàng mỗi bảng}
        {--json : in JSON đầy đủ thay vì bảng}';

    protected $description = 'Gom số liệu log (level, thông điệp, trang, query thừa, command, query chậm) ngay trên host';

    public function handle(AgentClients $clients): int
    {
        try {
            $client = $clients->for($this->option('host'));
        } catch (AgentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($client instanceof RemoteAgentClient && $client->usesSharedSecret()) {
            $this->output->getErrorStyle()->warning('Máy này chưa có LOG_VIEWER_AGENT_TOKEN — đang gửi shared secret (toàn quyền Log Viewer) của host.');
        }

        $params = array_filter([
            'files' => $this->option('files'),
            'channel' => $this->option('channel'),
            'date' => $this->option('date'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'only' => $this->option('only'),
            'top' => (string) $this->option('top'),
        ], static fn ($v) => $v !== null && $v !== '');

        $rounds = 0;
        $elapsed = 0;
        $busy = 0;

        while (true) {
            try {
                $payload = $client->aggregate($params);
            } catch (AgentException $e) {
                if ($e->status === 429 && $busy++ < self::MAX_BUSY_RETRIES) {
                    sleep(max(1, (int) ($e->payload['retry_after'] ?? 5)));

                    continue;
                }

                return $this->failWith($e);
            }

            $rounds++;
            $elapsed += (int) ($payload['stats']['elapsed_ms'] ?? 0);

            if (($payload['complete'] ?? true) || $rounds >= self::MAX_ROUNDS) {
                break;
            }

            $params['cursor'] = (string) $payload['cursor'];
            $this->output->getErrorStyle()->writeln(sprintf('… %s%% (%d lượt)', $payload['stats']['percent_scanned'] ?? '?', $rounds));
        }

        $this->output->write($this->option('json')
            ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            : AgentOutput::aggregate($payload, $client->name(), ['rounds' => $rounds, 'elapsed_ms' => $elapsed]));

        return ($payload['complete'] ?? true) ? self::SUCCESS : self::FAILURE;
    }

    private function failWith(AgentException $e): int
    {
        $this->error("HTTP {$e->status}: {$e->getMessage()}");

        $extra = array_diff_key($e->payload, ['error' => true]);

        if ($extra !== []) {
            $this->line(json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return self::FAILURE;
    }
}
