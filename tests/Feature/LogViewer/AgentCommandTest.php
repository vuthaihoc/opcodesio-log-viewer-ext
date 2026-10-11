<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\LogViewer;

use HocVT\LogViewerRemote\Tests\Feature\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Lệnh agent chạy trên máy dev: in-process cho host này, HTTP cho host xa (Http::fake).
 */
class AgentCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/lvr-agent-cmd-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/slow-log-2026-10-05.log', implode('', array_map(
            fn (int $i) => "[2026-10-05 01:00:0{$i}] production.ALERT: [CLI][App\\Jobs\\Sync]\n".(400 + $i).'ms [crdb] select pg_sleep(?) '
                .json_encode(['slow_log' => 'query', 'ms' => 400.0 + $i, 'connection' => 'crdb', 'cli.context' => 'App\\Jobs\\Sync'])." \n",
            range(0, 4),
        )));

        config([
            'log-viewer.include_files' => [$this->dir.'/*.log'],
            'logging.channels.slow-log.path' => $this->dir.'/slow-log.log',
            'log-viewer.hosts.fake' => [
                'name' => 'Fake',
                'host' => 'https://logs.example.com/log-viewer',
                'auth' => ['token' => 'shared-secret'],
            ],
            'log-viewer-ext.agent' => ['token' => 'agent-token'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** @return list<Request> */
    private function remoteRequests(): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'logs.example.com'))
            ->map(fn (array $pair): Request => $pair[0])->values()->all();
    }

    public function test_local_aggregate_prints_tables_and_json(): void
    {
        // Cả bảng ghi trong MỘT lần write: expectsOutputToContain chỉ khớp được một kỳ vọng / lần ghi.
        $this->assertSame(0, Artisan::call('log-viewer-remote:aggregate', ['--files' => 'slow-log-2026-10-05.log', '--only' => 'commands,slow_queries']));
        $output = Artisan::output();
        $this->assertStringContainsString('## commands (1 khoá · chỉ dòng slow log)', $output);
        $this->assertStringContainsString('| App\Jobs\Sync | 5 | 2010 | 404 | 2026-10-05 01:00:00 | slow-log-2026-10-05.log@0 |', $output);
        $this->assertStringContainsString('| select pg_sleep(?) | 5 | 404 | CLI App\Jobs\Sync=5 | crdb |', $output);

        Artisan::call('log-viewer-remote:aggregate', ['--files' => 'slow-log-2026-10-05.log', '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertTrue($payload['complete']);
        $this->assertSame(5, $payload['results']['slow_queries']['rows'][0]['n']);
    }

    public function test_local_entries_by_position(): void
    {
        $this->assertSame(0, Artisan::call('log-viewer-remote:entries', ['--at' => 'slow-log-2026-10-05.log@0']));
        $output = Artisan::output();
        $this->assertStringContainsString('400ms [crdb] select pg_sleep(?)', $output);
        $this->assertStringContainsString('UI: '.url('/'.trim((string) config('log-viewer.route_path'), '/')).'?file=slow-log-2026-10-05.log&query=2026-10-05+01%3A00%3A00', $output);
    }

    public function test_remote_aggregate_follows_the_cursor_with_the_agent_token(): void
    {
        Http::fake([
            'logs.example.com/*' => Http::sequence()
                ->push(['complete' => false, 'cursor' => 'c1', 'stats' => ['elapsed_ms' => 10, 'percent_scanned' => 50], 'results' => null])
                ->push(['complete' => true, 'cursor' => null, 'window' => ['log_timezone' => 'UTC'], 'stats' => ['elapsed_ms' => 10, 'entries' => 3, 'total_bytes' => 10, 'files' => []], 'results' => ['levels' => ['entries' => 3, 'levels' => ['ERROR' => 3], 'scopes' => []]]]),
            '*' => Http::response(''),
        ]);

        $this->assertSame(0, Artisan::call('log-viewer-remote:aggregate', ['--host' => 'fake', '--channel' => 'daily']));
        $this->assertStringContainsString('levels: ERROR 3', Artisan::output());

        [$first, $second] = $this->remoteRequests();

        $this->assertSame('https://logs.example.com/log-viewer/api/agent/aggregate?channel=daily&top=20', $first->url());
        $this->assertSame('Bearer agent-token', $first->header('Authorization')[0] ?? null);
        $this->assertStringContainsString('cursor=c1', $second->url());
    }

    public function test_via_goes_through_the_viewing_host_with_host_param(): void
    {
        Http::fake([
            'logs.example.com/*' => Http::response(['channels' => ['slow-log'], 'log_timezone' => 'UTC', 'files' => []]),
            '*' => Http::response(''),
        ]);

        $this->assertSame(0, Artisan::call('log-viewer-remote:files', ['--via' => 'fake', '--host' => 'm1']));
        $this->assertStringContainsString('# m1 (qua fake)', Artisan::output());

        [$sent] = $this->remoteRequests();
        $this->assertSame('https://logs.example.com/log-viewer/api/agent/files?host=m1', $sent->url());
        $this->assertSame('Bearer agent-token', $sent->header('Authorization')[0] ?? null);
    }

    public function test_remote_host_without_agent_endpoints_is_reported(): void
    {
        // Host cũ: /api/agent/* rơi vào route bắt-tất của Log Viewer, trả trang HTML.
        Http::fake([
            'logs.example.com/*' => Http::response('<html>Log Viewer</html>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response(''),
        ]);

        $this->artisan('log-viewer-remote:aggregate', ['--host' => 'fake'])
            ->expectsOutputToContain('chưa nâng cấp hocvt/log-viewer-remote')
            ->assertFailed();
    }

    public function test_remote_errors_are_shown_with_their_payload(): void
    {
        Http::fake([
            'logs.example.com/*' => Http::response(['error' => 'Channel `ai_request` không được phép.', 'allowed_channels' => ['slow-log']], 403),
            '*' => Http::response(''),
        ]);

        $this->artisan('log-viewer-remote:aggregate', ['--host' => 'fake', '--channel' => 'ai_request'])
            ->expectsOutputToContain('HTTP 403: Channel `ai_request` không được phép.')
            ->expectsOutputToContain('"allowed_channels":["slow-log"]')
            ->assertFailed();
    }

    public function test_without_agent_token_falls_back_to_the_shared_secret(): void
    {
        config(['log-viewer-ext.agent' => []]);
        Http::fake([
            'logs.example.com/*' => Http::response(['channels' => ['slow-log'], 'log_timezone' => 'UTC', 'files' => []]),
            '*' => Http::response(''),
        ]);

        $this->artisan('log-viewer-remote:files', ['--host' => 'fake'])->assertSuccessful();

        [$sent] = $this->remoteRequests();
        $this->assertSame('Bearer shared-secret', $sent->header('Authorization')[0] ?? null);
    }

    public function test_check_reports_the_agent_endpoint(): void
    {
        Http::fake([
            'logs.example.com/log-viewer/api/hosts' => Http::response(['data' => []]),
            'logs.example.com/log-viewer/api/agent/ping' => Http::response(['ok' => true, 'version' => '1.2.0', 'auth' => 'agent', 'channels' => ['slow-log']]),
            '*' => Http::response(''),
        ]);

        $this->artisan('log-viewer-remote:check', ['host' => 'fake'])
            ->expectsOutputToContain('v1.2.0 · agent · slow-log')
            ->assertSuccessful();
    }

    public function test_secret_command_makes_an_agent_token(): void
    {
        Artisan::call('log-viewer-remote:secret', ['--agent' => true, '--show' => true]);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', trim(Artisan::output()));
    }
}
