<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\SlowLog;

use HocVT\LogViewerRemote\SlowLog\SqlLogger;
use HocVT\LogViewerRemote\Tests\Feature\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Định dạng dòng log của SqlLogger là hợp đồng với bộ đọc của agent: context mang
 * `slow_log` + trường máy đọc, dòng nhóm query luôn tách được `xN` khỏi số ms.
 */
class SqlLoggerFormatTest extends TestCase
{
    private object $sink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sink = new class
        {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $entries = [];

            public function __call(string $method, array $args): void
            {
                $this->entries[] = ['level' => $method, 'message' => (string) $args[0], 'context' => $args[1] ?? []];
            }
        };

        Log::shouldReceive('channel')->andReturn($this->sink);
    }

    public function test_group_line_keeps_count_and_ms_apart_at_large_values(): void
    {
        $logger = $this->logger(['duplicate_to_log' => 1]);

        // 10 000 lần × 10 ms = 100 000 ms: định dạng cũ `%4s%6s` in ra `x10000100000ms`.
        for ($i = 0; $i < 10000; $i++) {
            $logger->record($this->executed('select * from "users" where "id" = ?', 10.0));
        }
        $logger->record($this->executed('select 1', 1234567.0));
        $logger->flush('import:users');

        $message = $this->sink->entries[0]['message'];

        $this->assertMatchesRegularExpression('/^\s+x10000\s+100000ms \[\w+\] select \* from "users" where "id" = \?$/m', $message);
        $this->assertMatchesRegularExpression('/^\s+1234567ms \[\w+\] select 1$/m', $message);
    }

    public function test_summary_context_is_machine_readable(): void
    {
        $logger = $this->logger(['total_to_log' => 2, 'duplicate_to_log' => 3]);

        foreach (range(1, 4) as $_) {
            $logger->record($this->executed('select * from "videos" where "id" = ?', 2.0));
        }
        $logger->flush('report:daily');

        $context = $this->sink->entries[0]['context'];

        $this->assertSame('summary', $context['slow_log']);
        $this->assertSame(['total', 'duplicate'], $context['reasons']);
        $this->assertSame(4, $context['total']);
        $this->assertSame(4, $context['worst_duplicate']);
        $this->assertSame('report:daily', $context['cli.context']);
    }

    public function test_slow_query_in_a_job_carries_the_job_name(): void
    {
        $logger = $this->logger(['time_to_log_cli' => 1]);
        $logger->enterCommand('queue:work');
        $logger->enterJob('App\\Jobs\\SyncVideo');

        $logger->record($this->executed('select pg_sleep(1)', 25.0));

        $entry = $this->sink->entries[0];
        $this->assertSame('alert', $entry['level']);
        $this->assertStringStartsWith("[CLI][App\\Jobs\\SyncVideo]\n25ms [", $entry['message']);
        $this->assertSame('query', $entry['context']['slow_log']);
        $this->assertSame(25.0, $entry['context']['ms']);
        $this->assertSame('App\\Jobs\\SyncVideo', $entry['context']['cli.context']);

        // Hết job thì quay về tên command của worker.
        $logger->leaveJob();
        $logger->record($this->executed('select 2', 25.0));
        $this->assertStringStartsWith('[CLI][queue:work]', $this->sink->entries[1]['message']);
    }

    public function test_web_context_has_route_template(): void
    {
        $request = Request::create('https://vocamax.test/video/123/shape-of-you');
        $route = (new Route('GET', 'video/{id}/{slug?}', fn () => null))->bind($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);

        $logger = new class(['enabled' => true, 'time_to_log' => 1, 'time_to_log_cli' => 1, 'trace' => false]) extends SqlLogger
        {
            public function __construct(array $config)
            {
                parent::__construct($config);
                $this->console = false;
            }
        };

        $logger->record($this->executed('select 1', 5.0));

        $context = $this->sink->entries[0]['context'];
        $this->assertSame('/video/{id}/{slug?}', $context['req.route']);
        $this->assertSame('https://vocamax.test/video/123/shape-of-you', $context['req.url']);
        $this->assertArrayNotHasKey('cli.context', $context);
    }

    private function logger(array $config): SqlLogger
    {
        return new SqlLogger($config + [
            'enabled' => true,
            'total_to_log' => 0,
            'total_ms_to_log' => 0,
            'duplicate_to_log' => 0,
            'time_to_log_cli' => 0,
            'trace' => false,
        ]);
    }

    private function executed(string $sql, float $ms): QueryExecuted
    {
        return new QueryExecuted($sql, [], $ms, DB::connection());
    }
}
