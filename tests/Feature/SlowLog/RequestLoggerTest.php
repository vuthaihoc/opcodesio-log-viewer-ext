<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\SlowLog;

use HocVT\LogViewerRemote\SlowLog\RequestLogger;
use HocVT\LogViewerRemote\SlowLog\SlowLogServiceProvider;
use HocVT\LogViewerRemote\SlowLog\SqlLogger;
use HocVT\LogViewerRemote\Tests\Feature\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Request web chậm / response lớn theo prefix (log-viewer-ext.slow_log.requests).
 */
class RequestLoggerTest extends TestCase
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

    private function logger(string $timePrefixes = '', string $sizePrefixes = '', int $time = 2000, int $size = 524288): RequestLogger
    {
        return new RequestLogger(['requests' => [
            'time' => ['prefixes' => $timePrefixes, 'threshold' => $time],
            'size' => ['prefixes' => $sizePrefixes, 'threshold' => $size],
        ]]);
    }

    /** Request đã chạy $seconds giây (REQUEST_TIME_FLOAT lùi lại), có route để có req.route. */
    private function request(string $path, float $seconds = 0.0, string $uri = '{any}'): Request
    {
        $request = Request::create('https://vocamax.test/'.ltrim($path, '/'), 'GET', server: ['REQUEST_TIME_FLOAT' => microtime(true) - $seconds]);
        $route = (new Route('GET', $uri, fn () => null))->where('any', '.*')->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    public function test_slow_request_under_a_matching_prefix_is_logged_with_db_stats(): void
    {
        $this->logger('graphql,api')->handle($this->request('graphql', 2.5, 'graphql'), new Response('{}'), ['queries' => 63, 'query_ms' => 812.4]);

        [$entry] = $this->sink->entries;
        $this->assertSame('warning', $entry['level']);
        $this->assertMatchesRegularExpression('#^\[WEB\]\[127\.0\.0\.1\]\[https://vocamax\.test/graphql\] Request chậm \d{4}ms > 2000ms\n  GET 200 · \d{4}ms · 2 B · 63 query / 812ms DB$#', $entry['message']);
        $this->assertSame('request', $entry['context']['slow_log']);
        $this->assertSame(['time'], $entry['context']['reasons']);
        $this->assertGreaterThanOrEqual(2500, $entry['context']['ms']);
        $this->assertSame(2, $entry['context']['bytes']);
        $this->assertSame(63, $entry['context']['queries']);
        $this->assertSame('/graphql', $entry['context']['req.route']);
    }

    public function test_prefixes_match_whole_segments_wildcards_own_thresholds_and_exclusions(): void
    {
        $logger = $this->logger('graphql/upload:0, api:500, learn/*, graphql');
        $cases = [
            'api/videos' => 0.8,          // ngưỡng riêng 500ms
            'apix' => 9.0,                // không khớp đoạn `api`
            'learn/video/x' => 2.5,       // wildcard
            'graphql/upload' => 9.0,      // loại trừ, đứng trước luật `graphql`
            'graphql' => 1.0,             // dưới 2000ms
            'about' => 9.0,               // không prefix nào
        ];

        foreach ($cases as $path => $seconds) {
            $logger->handle($this->request($path, $seconds), new Response('x'));
        }

        $this->assertSame(
            ['https://vocamax.test/api/videos', 'https://vocamax.test/learn/video/x'],
            array_map(fn (array $e) => $e['context']['req.url'], $this->sink->entries),
        );
    }

    public function test_big_response_is_measured_before_compression_and_both_reasons_share_one_line(): void
    {
        $logger = $this->logger(timePrefixes: '*', sizePrefixes: 'graphql', time: 100, size: 1024);

        $logger->handle($this->request('graphql', 0.5), new Response(str_repeat('x', 3000)));

        [$entry] = $this->sink->entries;
        $this->assertSame(['time', 'size'], $entry['context']['reasons']);
        $this->assertStringContainsString('Request chậm', $entry['message']);
        $this->assertStringContainsString('Response lớn 3 KB > 1 KB', $entry['message']);
        $this->assertSame(3000, $entry['context']['bytes']);
    }

    public function test_size_of_files_and_streams(): void
    {
        $logger = $this->logger(sizePrefixes: '*', size: 10);

        $file = tempnam(sys_get_temp_dir(), 'rl');
        file_put_contents($file, str_repeat('y', 50));
        $logger->handle($this->request('download'), new BinaryFileResponse($file));
        unlink($file);

        // Không biết trước kích thước thì bỏ qua; có Content-Length thì dùng.
        $logger->handle($this->request('stream'), new StreamedResponse(fn () => print ('z')));
        $logger->handle($this->request('stream-known'), new StreamedResponse(fn () => print ('z'), 200, ['Content-Length' => '99']));

        $this->assertSame([50, 99], array_map(fn (array $e) => $e['context']['bytes'], $this->sink->entries));
    }

    public function test_empty_prefixes_or_zero_threshold_disable_a_rule(): void
    {
        $this->assertFalse($this->logger()->isEnabled());
        $this->assertFalse($this->logger('graphql', time: 0)->isEnabled());
        $this->assertTrue($this->logger(sizePrefixes: 'graphql')->isEnabled());
        $this->assertTrue((new RequestLogger(['requests' => ['time' => ['prefixes' => ['api', 'graphql']]]]))->isEnabled());
    }

    public function test_provider_logs_request_with_the_requests_own_query_count_before_it_is_reset(): void
    {
        config([
            'log-viewer-ext.slow_log.enabled' => true,
            'log-viewer-ext.slow_log.environments' => ['testing'],
            'log-viewer-ext.slow_log.total_to_log' => 0,
            'log-viewer-ext.slow_log.total_ms_to_log' => 0,
            'log-viewer-ext.slow_log.duplicate_to_log' => 0,
            'log-viewer-ext.slow_log.time_to_log' => 0,
            'log-viewer-ext.slow_log.time_to_log_cli' => 0,
            'log-viewer-ext.slow_log.requests.time' => ['prefixes' => '*', 'threshold' => 1],
        ]);
        $this->app->forgetInstance(SqlLogger::class);
        $this->app->forgetInstance(RequestLogger::class);

        (new SlowLogServiceProvider($this->app))->boot();

        foreach (range(1, 3) as $_) {
            event(new QueryExecuted('select 1', [], 2.0, DB::connection()));
        }
        event(new RequestHandled($this->request('learn', 0.2), new Response('ok')));

        $requests = array_values(array_filter($this->sink->entries, fn (array $e) => ($e['context']['slow_log'] ?? null) === 'request'));
        $this->assertCount(1, $requests);
        $this->assertSame(3, $requests[0]['context']['queries']);
        $this->assertSame(6.0, $requests[0]['context']['query_ms']);
    }
}
