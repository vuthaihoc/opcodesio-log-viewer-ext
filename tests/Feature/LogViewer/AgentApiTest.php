<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\LogViewer;

use HocVT\LogViewerRemote\LogViewerRemote;
use HocVT\LogViewerRemote\Tests\Feature\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Opcodes\LogViewer\Facades\LogViewer;

/**
 * `{route_path}/api/agent/*`: token chỉ đọc, giới hạn channel, cursor, slot, cache, đọc entry.
 * File log nằm ở thư mục tạm; channel và include_files của Log Viewer trỏ vào đó.
 */
class AgentApiTest extends TestCase
{
    private const AGENT = 'agent-token-0123456789abcdef';

    private const SHARED = 'shared-secret-0123456789abcdef';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/lvr-agent-api-'.uniqid();
        File::ensureDirectoryExists($this->dir);

        config([
            'log-viewer.include_files' => [$this->dir.'/*.log'],
            'logging.channels.slow-log.path' => $this->dir.'/slow-log.log',
            'logging.channels.daily.path' => $this->dir.'/web.log',
            // Channel có dữ liệu nhạy cảm (nội dung request AI…): agent token không được đọc.
            'logging.channels.ai_request' => ['driver' => 'daily', 'path' => $this->dir.'/ai_request.log'],
            'log-viewer-ext.shared_secret' => self::SHARED,
            'log-viewer-ext.agent' => [
                'token' => self::AGENT,
                'channels' => 'slow-log,daily',
                'page_key' => ['graphql.name', 'req.route'],
            ],
            'app.timezone' => 'UTC',
            'log-viewer.timezone' => 'Asia/Ho_Chi_Minh',
        ]);

        File::put($this->dir.'/slow-log-2026-10-05.log', $this->slowLog('2026-10-05', 40));
        File::put($this->dir.'/web-2026-10-05.log', "[2026-10-05 01:00:00] production.ERROR: boom for a@b.co token aB3dE5fG7hJ9kL1mN3pQ5rS7tU9 user 42  \n");
        File::put($this->dir.'/ai_request-2026-10-05.log', "[2026-10-05 01:00:00] production.INFO: prompt chứa dữ liệu người dùng  \n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function api(string $path, array $query = [], ?string $token = self::AGENT): TestResponse
    {
        $url = '/'.trim((string) config('log-viewer.route_path'), '/').'/api/agent/'.$path.($query === [] ? '' : '?'.http_build_query($query));

        return $token === null ? $this->getJson($url) : $this->withToken($token)->getJson($url);
    }

    /** Slow log định dạng mới: mỗi giờ một tổng kết N+1 ở /video/{id}, một query chậm ở job. */
    private function slowLog(string $day, int $hours): string
    {
        $log = '';

        for ($i = 0; $i < $hours; $i++) {
            $time = sprintf('%s %02d:%02d:00', $day, intdiv($i, 2), ($i % 2) * 30);
            $context = json_encode([
                'slow_log' => 'summary', 'reasons' => ['duplicate'], 'total' => 12, 'total_ms' => 50.0, 'worst_duplicate' => 11,
                'req.url' => "https://vocamax.test/video/{$i}", 'req.route' => '/video/{id}', 'graphql' => ['operation' => 'query'],
            ]);
            $log .= "[{$time}] production.WARNING: [WEB][1.2.3.4][https://vocamax.test/video/{$i}] Nghi ngờ N+1: 1 query lặp 11x\n"
                ."  12 query / 50ms / 2 query khác nhau\n"
                ."    x11      44ms [crdb] select * from \"words\" where \"id\" = ?\n"
                ."              6ms [crdb] select * from \"videos\" where \"id\" = ? {$context} \n";
            $log .= "[{$time}] production.ALERT: [CLI][App\\Jobs\\Sync]\n"
                .(400 + $i)."ms [crdb] select pg_sleep(?)\n"
                .'  app/Jobs/Sync.php:10 '.json_encode(['slow_log' => 'query', 'ms' => 400.0 + $i, 'connection' => 'crdb', 'cli.context' => 'App\\Jobs\\Sync'])." \n";
        }

        return $log;
    }

    public function test_tokens(): void
    {
        $this->api('ping', token: null)->assertForbidden();
        $this->api('ping', token: 'wrong')->assertForbidden();
        $this->api('ping')->assertOk()->assertJsonPath('auth', 'agent')->assertJsonPath('version', LogViewerRemote::VERSION);
        $this->api('ping', token: self::SHARED)->assertOk()->assertJsonPath('auth', 'shared');
    }

    public function test_agent_token_is_useless_outside_agent_endpoints(): void
    {
        $lv = '/'.trim((string) config('log-viewer.route_path'), '/');

        $this->withToken(self::AGENT)->getJson($lv.'/api/files')->assertForbidden();
        $this->withToken(self::AGENT)->getJson($lv.'/api/hosts')->assertForbidden();
        $this->withToken(self::AGENT)->getJson($lv.'/api/files/x/download/request')->assertForbidden();
        $this->withToken(self::AGENT)->deleteJson($lv.'/api/files/x')->assertForbidden();
        $this->withToken(self::AGENT)->postJson($lv.'/api/clear-cache-all')->assertForbidden();
    }

    public function test_agent_token_sees_and_reads_only_allowed_channels(): void
    {
        $files = $this->api('files')->assertOk()->assertJsonPath('restricted', true);

        $this->assertSame(['slow-log', 'daily'], $files->json('channels'));
        $this->assertEqualsCanonicalizing(['slow-log-2026-10-05.log', 'web-2026-10-05.log'], array_column($files->json('files'), 'name'));

        $this->api('aggregate', ['files' => 'ai_request-2026-10-05.log'])->assertForbidden()
            ->assertJsonPath('allowed_channels', ['slow-log', 'daily']);
        $this->api('aggregate', ['channel' => 'ai_request'])->assertForbidden();
        $this->api('entries', ['at' => 'ai_request-2026-10-05.log@0'])->assertForbidden();
        $this->api('aggregate', ['files' => '../../.env'])->assertForbidden();
    }

    public function test_shared_secret_reads_every_file_log_viewer_lists(): void
    {
        $files = $this->api('files', token: self::SHARED)->assertOk()->assertJsonPath('restricted', false);

        $this->assertContains('ai_request-2026-10-05.log', array_column($files->json('files'), 'name'));
        $this->assertContains('ai_request', $files->json('channels'));

        $this->api('aggregate', ['channel' => 'ai_request', 'only' => 'levels'], self::SHARED)->assertOk()
            ->assertJsonPath('results.levels.entries', 1);
        $this->api('entries', ['at' => 'ai_request-2026-10-05.log@0'], self::SHARED)->assertOk();

        // Vẫn chỉ trong danh sách của Log Viewer: không mở đường dẫn tuỳ ý.
        $this->api('aggregate', ['files' => '../../.env'], self::SHARED)->assertNotFound();
        $this->api('aggregate', ['channel' => 'no-such-channel'], self::SHARED)->assertNotFound();
    }

    public function test_aggregate_by_channel_and_local_time_window(): void
    {
        // 07:00–12:00 giờ VN = 00:00–05:00 UTC trong log: 11 mốc nửa giờ (00:00 … 05:00).
        $response = $this->api('aggregate', ['channel' => 'slow-log', 'from' => '2026-10-05 07:00', 'to' => '2026-10-05 12:00'])->assertOk();

        $response->assertJsonPath('complete', true)
            ->assertJsonPath('window.from', '2026-10-05 00:00:00')
            ->assertJsonPath('window.to', '2026-10-05 05:00:00')
            ->assertJsonPath('results.levels.entries', 22);

        $pages = array_column($response->json('results.pages.rows'), null, 'key');
        $this->assertSame(11, $pages['/video/{id}']['summaries']);

        $waste = array_column($response->json('results.sql_waste.rows'), null, 'key');
        $this->assertSame(110, $waste['select * from "words" where "id" = ?']['waste']);

        $this->assertSame('App\\Jobs\\Sync', $response->json('results.commands.rows.0.key'));
        $this->assertSame(11, $response->json('results.commands.rows.0.slow_queries'));

        // File ngày cũ đã đóng: lần hai lấy từ cache.
        $this->api('aggregate', ['channel' => 'slow-log', 'from' => '2026-10-05 07:00', 'to' => '2026-10-05 12:00'])
            ->assertJsonPath('cached', true)
            ->assertJsonPath('results.levels.entries', 22);
    }

    public function test_cursor_continues_until_complete_and_matches_one_pass(): void
    {
        $once = $this->api('aggregate', ['files' => 'slow-log-2026-10-05.log', 'only' => 'pages,sql_waste,commands'])->json('results');

        config(['log-viewer-ext.agent.max_bytes' => 2000, 'log-viewer-ext.agent.cache_ttl' => 1]);
        Cache::flush();

        $query = ['files' => 'slow-log-2026-10-05.log', 'only' => 'pages,sql_waste,commands'];
        $rounds = 0;
        $used = null;

        do {
            $rounds++;
            $response = $this->api('aggregate', $query)->assertOk();
            $used = $query['cursor'] ?? null;
            $query['cursor'] = $response->json('cursor');
            // Chưa xong thì không kèm kết quả (partial=1 mới kèm).
            $this->assertSame($response->json('complete') ? 'array' : 'NULL', gettype($response->json('results')));
        } while ($response->json('complete') === false && $rounds < 50);

        $this->assertGreaterThan(3, $rounds);
        $this->assertSame($once, $response->json('results'));

        // Cursor dùng một lần.
        $this->api('aggregate', ['cursor' => $used] + $query)->assertStatus(410);
    }

    public function test_cursor_from_other_parameters_is_rejected(): void
    {
        config(['log-viewer-ext.agent.max_bytes' => 2000]);

        $cursor = $this->api('aggregate', ['files' => 'slow-log-2026-10-05.log'])->json('cursor');

        $this->api('aggregate', ['files' => 'slow-log-2026-10-05.log', 'only' => 'levels', 'cursor' => $cursor])->assertStatus(422);
    }

    public function test_busy_host_answers_429_with_retry_after(): void
    {
        config(['log-viewer-ext.agent.slots' => 1]);
        Cache::store(config('log-viewer.cache_driver'))->lock('lvr:agent:slot:0', 60)->get();

        $this->api('aggregate', ['channel' => 'slow-log'])
            ->assertStatus(429)
            ->assertHeader('Retry-After', '5')
            ->assertJsonPath('retry_after', 5);
    }

    public function test_filter_and_group_by_the_agents_own_regex(): void
    {
        $response = $this->api('aggregate', [
            'channel' => 'slow-log',
            'match' => 'pg_sleep',
            'in' => 'text',
            'group' => '\\[CLI\\]\\[(?<key>[^\\]]+)\\]\\s+(?<sum>\\d+)ms',
            'only' => 'levels,group,pages',
        ])->assertOk();

        // match lọc trước mọi bảng: chỉ 40 dòng ALERT của job, không còn dòng tổng kết WEB.
        $this->assertSame(40, $response->json('stats.entries'));
        $this->assertSame(80, $response->json('stats.scanned_entries'));
        $this->assertSame(['ALERT' => 40], $response->json('results.levels.levels'));
        $this->assertSame('slow_log', $response->json('results.pages.applies_to'));
        $this->assertSame([], $response->json('results.pages.rows'));

        $group = $response->json('results.group.rows.0');
        $this->assertSame('App\\Jobs\\Sync', $group['key']);
        $this->assertSame(40, $group['n']);
        $this->assertEquals(array_sum(range(400, 439)), $group['sum']);
        $this->assertEquals(439, $group['max']);
    }

    public function test_bad_parameters(): void
    {
        $this->api('aggregate', ['channel' => 'slow-log', 'only' => 'levels,nope'])->assertStatus(422);
        $this->api('aggregate', ['channel' => 'slow-log', 'from' => 'không phải ngày'])->assertStatus(422);
        $this->api('aggregate', ['channel' => 'slow-log', 'from' => '2026-10-06', 'to' => '2026-10-05'])->assertStatus(422);
        $this->api('entries', ['channel' => 'daily', 'regex' => '(unclosed'])->assertStatus(422);
        $this->api('aggregate', ['channel' => 'slow-log', 'match' => '(unclosed'])->assertStatus(422);
        $this->api('aggregate', ['channel' => 'slow-log', 'group' => '(unclosed'])->assertStatus(422);
        $this->api('aggregate', ['channel' => 'slow-log', 'only' => 'group'])->assertStatus(422);
    }

    public function test_entries_filter_mask_and_paginate(): void
    {
        $boom = $this->api('entries', ['channel' => 'daily', 'contains' => 'BOOM'])->assertOk();
        $this->assertSame('boom for <email> token <token> user 42', trim(substr($boom->json('entries.0.text'), strlen('[2026-10-05 01:00:00] production.ERROR: '))));

        $page1 = $this->api('entries', ['channel' => 'slow-log', 'level' => 'alert', 'limit' => 2])->assertOk();
        $this->assertCount(2, $page1->json('entries'));
        $this->assertFalse($page1->json('complete'));

        $page2 = $this->api('entries', ['channel' => 'slow-log', 'level' => 'alert', 'limit' => 2, 'cursor' => $page1->json('next')])->assertOk();
        $this->assertStringContainsString('402ms', $page2->json('entries.0.text'));

        $at = $page1->json('entries.1.at');
        $this->api('entries', ['at' => $at])->assertOk()->assertJsonPath('entries.0.at', $at);

        $regex = $this->api('entries', ['channel' => 'slow-log', 'regex' => 'pg_sleep\(\?\)\s+app/Jobs', 'limit' => 50])->assertOk();
        $this->assertCount(40, $regex->json('entries'));
    }

    public function test_old_urls_are_grouped_by_the_apps_routes(): void
    {
        Route::get('/agent-test/{id}', fn () => 'x');
        app('router')->getRoutes()->refreshNameLookups();

        File::put($this->dir.'/slow-log-2026-10-04.log', implode('', array_map(fn (int $i) => "[2026-10-04 01:00:0{$i}] production.WARNING: [WEB][1.2.3.4][https://vocamax.test/agent-test/{$i}] Quá nhiều query [gt30]: 40 query\n  40 query / 900ms / 1 query khác nhau\n    x40     900ms [crdb] select 1 ".json_encode(['total' => 40, 'total_ms' => 900.0])." \n", range(1, 3))));

        $rows = $this->api('aggregate', ['files' => 'slow-log-2026-10-04.log', 'only' => 'pages'])->json('results.pages.rows');

        $this->assertSame([['/agent-test/{id}', 3]], array_map(fn (array $r) => [$r['key'], $r['summaries']], $rows));
    }

    /** Host m1 nằm sau host đang xem: `?host=m1` chuyển request sang đó. */
    private function fakeM1(mixed $body = ['ok' => true], int $status = 200, array $headers = ['Content-Type' => 'application/json']): void
    {
        config(['log-viewer.hosts.m1' => [
            'name' => 'M1',
            'host' => 'https://m1.example.com/log-viewer',
            'auth' => ['token' => 'm1-host-credential'],
        ]]);

        Http::fake([
            'm1.example.com/*' => Http::response($body, $status, $headers),
            '*' => Http::response(''),
        ]);
    }

    /** @return list<Request> */
    private function m1Requests(): array
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'm1.example.com'))
            ->map(fn (array $pair) => $pair[0])->values()->all();
    }

    public function test_forward_with_agent_token_keeps_the_agent_token(): void
    {
        $this->fakeM1(['complete' => true, 'results' => ['levels' => ['entries' => 7]]]);

        $this->api('aggregate', ['host' => 'm1', 'channel' => 'slow-log', 'cursor' => 'abc'])
            ->assertOk()
            ->assertHeader('X-Log-Viewer-Agent-Host', 'm1')
            ->assertJsonPath('results.levels.entries', 7);

        [$sent] = $this->m1Requests();
        $this->assertSame('https://m1.example.com/log-viewer/api/agent/aggregate?channel=slow-log&cursor=abc', $sent->url());
        // Không nâng quyền qua bước forward: host xa nhận đúng token chỉ đọc, tự áp giới hạn channel.
        $this->assertSame('Bearer '.self::AGENT, $sent->header('Authorization')[0] ?? null);
    }

    public function test_forward_with_shared_secret_uses_the_host_credential(): void
    {
        $this->fakeM1();

        $this->api('ping', ['host' => 'm1'], self::SHARED)->assertOk();

        [$sent] = $this->m1Requests();
        $this->assertSame('Bearer m1-host-credential', $sent->header('Authorization')[0] ?? null);
    }

    public function test_forward_passes_errors_and_retry_after_through(): void
    {
        $this->fakeM1(['error' => 'busy', 'retry_after' => 5], 429, ['Content-Type' => 'application/json', 'Retry-After' => '5']);

        $this->api('aggregate', ['host' => 'm1'])->assertStatus(429)->assertHeader('Retry-After', '5')->assertJsonPath('error', 'busy');
    }

    public function test_forward_to_an_old_or_unknown_host(): void
    {
        $this->fakeM1('<html>Log Viewer</html>', 200, ['Content-Type' => 'text/html']);

        $this->api('ping', ['host' => 'm1'])->assertStatus(502);
        $this->api('ping', ['host' => 'nope'])->assertNotFound()->assertJsonPath('error', 'Không có host `nope` trong log-viewer.hosts của host này.');

        // `local` (host không có URL) xử lý tại chỗ.
        $this->api('ping', ['host' => 'local'])->assertOk()->assertJsonPath('auth', 'agent');
        $this->assertCount(1, $this->m1Requests());
    }

    public function test_only_laravel_logs_are_read_using_log_viewers_own_type_detection(): void
    {
        // Log Viewer nhận ra đây là log lỗi nginx (theo dòng đầu); bộ đọc agent thì chỉ hiểu Laravel.
        File::put($this->dir.'/nginx-error.log', "2026/10/05 01:00:00 [error] 123#0: *1 open() \"/x\" failed, client: 1.2.3.4, server: vocamax.test\n");

        $files = array_column($this->api('files', token: self::SHARED)->json('files'), null, 'name');
        $this->assertSame('http_error_nginx', $files['nginx-error.log']['type']);
        $this->assertSame('laravel', $files['web-2026-10-05.log']['type']);

        $this->api('aggregate', ['files' => 'nginx-error.log'], self::SHARED)
            ->assertStatus(422)
            ->assertJsonPath('readable_type', 'laravel');
        $this->api('entries', ['files' => 'nginx-error.log,web-2026-10-05.log'], self::SHARED)->assertStatus(422);

        // Agent token: file đó không thuộc channel nào được phép, không thấy luôn.
        $this->assertNotContains('nginx-error.log', array_column($this->api('files')->json('files'), 'name'));
    }

    public function test_samples_and_entries_carry_a_link_into_the_log_viewer_ui(): void
    {
        $lv = url('/'.trim((string) config('log-viewer.route_path'), '/'));

        $rows = $this->api('aggregate', ['files' => 'slow-log-2026-10-05.log', 'only' => 'pages,commands'])->json('results');

        // Test chạy trong console: identifier vendor ở CLI khác web nên link dùng tên file
        // (LogViewer::getFile() tra theo tên khi không thấy identifier).
        $this->assertSame($lv.'?file=slow-log-2026-10-05.log&query=2026-10-05+00%3A00%3A00', $rows['pages']['rows'][0]['ui_url']);
        $this->assertSame($rows['commands']['rows'][0]['first'], '2026-10-05 00:00:00');
        $this->assertStringStartsWith($lv.'?file=slow-log-2026-10-05.log&query=', $rows['commands']['rows'][0]['ui_url']);

        $entry = $this->api('entries', ['at' => 'slow-log-2026-10-05.log@0'])->json('entries.0');
        $this->assertSame($lv.'?file=slow-log-2026-10-05.log&query=2026-10-05+00%3A00%3A00', $entry['ui_url']);

        config(['log-viewer.exclude_ip_from_identifiers' => true]);
        $identifier = collect(LogViewer::getFiles())->firstWhere('name', 'slow-log-2026-10-05.log')->identifier;
        $this->assertStringContainsString('file='.$identifier, $this->api('entries', ['at' => 'slow-log-2026-10-05.log@0'])->json('entries.0.ui_url'));
    }

    public function test_forward_rewrites_ui_links_to_the_viewing_host(): void
    {
        $this->fakeM1(['results' => ['pages' => ['rows' => [['key' => '/x', 'ui_url' => 'https://m1.example.com/log-viewer?file=ab12-web.log&query=2026-10-05+01%3A00%3A00']]]]]);

        $url = $this->api('aggregate', ['host' => 'm1'])->assertOk()->json('results.pages.rows.0.ui_url');

        $lv = url('/'.trim((string) config('log-viewer.route_path'), '/'));
        $this->assertSame($lv.'?host=m1&file=ab12-web.log&query=2026-10-05+01%3A00%3A00', $url);
    }
}
