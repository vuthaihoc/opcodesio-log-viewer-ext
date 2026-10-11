<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\SlowLog;

use HocVT\LogViewerRemote\Agent\Scan\Budget;
use HocVT\LogViewerRemote\Agent\Scan\Normalizer;
use HocVT\LogViewerRemote\Agent\Scan\ScanFile;
use HocVT\LogViewerRemote\Agent\Scan\Scanner;
use HocVT\LogViewerRemote\Agent\Scan\Window;
use HocVT\LogViewerRemote\SlowLog\RequestLogger;
use HocVT\LogViewerRemote\SlowLog\SqlLogger;
use HocVT\LogViewerRemote\Tests\Feature\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Hợp đồng định dạng: SqlLogger THẬT ghi qua Monolog của Laravel ra file, bộ đọc của agent
 * đọc lại. Đổi format ở một phía mà quên phía kia thì test này đỏ.
 */
class SlowLogRoundTripTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/slow-log-roundtrip-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['logging.channels.roundtrip' => ['driver' => 'single', 'path' => $this->dir.'/slow.log', 'level' => 'debug']]);
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('roundtrip');
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_what_sql_logger_writes_is_what_the_agent_reads(): void
    {
        // Web: một trang có N+1 và một query chậm; context request dùng chung có object lồng
        // (như ShareRequestToLogContext của app).
        Log::shareContext(['graphql' => ['operation' => 'query', 'name' => 'GetVideo']]);
        $this->bindRequest('video/{id}/{slug?}', '/video/123/shape-of-you');

        $web = $this->logger(console: false);
        foreach (range(1, 12) as $_) {
            $web->record($this->executed('select * from "words" where "id" = ?', 3.0));
        }
        $web->record($this->executed("select *\n  from \"videos\"\n  where \"id\" = ?", 450.0));
        $web->flush();

        // Request chậm + response lớn của chính trang đó (RequestLogger), kèm số query của request.
        $requests = new RequestLogger(['channel' => 'roundtrip', 'requests' => [
            'time' => ['prefixes' => 'video', 'threshold' => 100],
            'size' => ['prefixes' => 'video', 'threshold' => 10],
        ]]);
        $slow = request()->duplicate(server: ['REQUEST_TIME_FLOAT' => microtime(true) - 1.5] + request()->server->all());
        $slow->setRouteResolver(request()->getRouteResolver());
        $requests->handle($slow, new Response(str_repeat('x', 2048)), ['queries' => 13, 'query_ms' => 486.0]);

        // CLI: query chậm trong job mang tên job.
        $cli = $this->logger(console: true);
        $cli->enterCommand('queue:work');
        $cli->enterJob('App\\Jobs\\SyncVideo');
        $cli->record($this->executed('select pg_sleep(?)', 12000.0));
        $cli->flush();

        $results = Scanner::make(new Normalizer(pageKeys: ['req.route']))
            ->run([ScanFile::at($this->dir.'/slow.log')], Window::all(), Budget::unlimited())
            ->results(10);

        $pages = array_column($results['pages']['rows'], null, 'key');
        $this->assertSame(1, $pages['/video/{id}/{slug?}']['summaries']);
        $this->assertSame(13, $pages['/video/{id}/{slug?}']['queries']);
        $this->assertSame(1, $pages['/video/{id}/{slug?}']['slow_queries']);
        $this->assertSame(12, $pages['/video/{id}/{slug?}']['worst_duplicate']);

        $waste = array_column($results['sql_waste']['rows'], null, 'key');
        $this->assertSame(11, $waste['select * from "words" where "id" = ?']['waste']);

        $slow = array_column($results['slow_queries']['rows'], null, 'key');
        $this->assertSame(1, $slow['select * from "videos" where "id" = ?']['n']);
        $this->assertSame(450.0, $slow['select * from "videos" where "id" = ?']['max_ms']);

        $this->assertSame(1, $pages['/video/{id}/{slug?}']['requests']);
        $this->assertSame(1, $pages['/video/{id}/{slug?}']['slow_requests']);
        $this->assertSame(1, $pages['/video/{id}/{slug?}']['big_responses']);
        $this->assertSame(2048, $pages['/video/{id}/{slug?}']['max_bytes']);
        $this->assertGreaterThanOrEqual(1500, $pages['/video/{id}/{slug?}']['max_request_ms']);
        $this->assertEquals(486, $pages['/video/{id}/{slug?}']['request_db_ms']);
        $this->assertContains('WARNING | slow request: time+size', array_column($results['messages']['rows'], 'key'));

        $commands = array_column($results['commands']['rows'], null, 'key');
        $this->assertSame(1, $commands['App\\Jobs\\SyncVideo']['slow_queries']);
        $this->assertSame(12000.0, $commands['App\\Jobs\\SyncVideo']['max_slow_ms']);
    }

    private function logger(bool $console): SqlLogger
    {
        $config = [
            'enabled' => true, 'channel' => 'roundtrip', 'time_to_log' => 100, 'time_to_log_cli' => 100,
            'total_to_log' => 5, 'total_ms_to_log' => 0, 'duplicate_to_log' => 10, 'trace' => true,
        ];

        return new class($config, $console) extends SqlLogger
        {
            public function __construct(array $config, bool $console)
            {
                parent::__construct($config);
                $this->console = $console;
            }
        };
    }

    private function executed(string $sql, float $ms): QueryExecuted
    {
        return new QueryExecuted($sql, [], $ms, DB::connection());
    }

    private function bindRequest(string $uri, string $path): void
    {
        $request = Request::create('https://vocamax.test'.$path);
        $route = (new Route('GET', $uri, fn () => null))->bind($request);
        $request->setRouteResolver(fn () => $route);

        $this->app->instance('request', $request);
    }
}
