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
 * Không ghi DB, chỉ cần connection để escape binding như toRawSql().
 */
class SqlLoggerRedactionTest extends TestCase
{
    private object $sink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sink = new class
        {
            /** @var list<string> */
            public array $lines = [];

            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            public function __call(string $method, array $args): void
            {
                $this->lines[] = (string) $args[0];
                $this->contexts[] = $args[1] ?? [];
            }
        };

        Log::shouldReceive('channel')->andReturn($this->sink);
    }

    public function test_web_logs_carry_masked_url_and_referer_in_context(): void
    {
        $token = 'aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5bC7d';
        $this->bindRequest('affiliate/s/{stats_token}', '/affiliate/s/'.$token.'?email=a@b.c', [
            'HTTP_REFERER' => 'https://vocamax.test/auth?token=abc&tab=login',
        ]);

        $logger = $this->webLogger();
        // 1 query chậm (log ngay) + tổng kết khi request kết thúc: cả hai dòng đều phải có context.
        $logger->record(new QueryExecuted('select * from "users" where "id" = ?', [1], 5.0, DB::connection()));
        $logger->flush();

        $this->assertCount(2, $this->sink->contexts);

        foreach ($this->sink->contexts as $context) {
            $this->assertSame('https://vocamax.test/affiliate/s/{stats_token}?email=***', $context['req.url']);
            $this->assertSame('https://vocamax.test/auth?token=***&tab=login', $context['req.referer']);
        }

        $this->assertStringNotContainsString($token, implode("\n", $this->sink->lines));
    }

    public function test_web_request_without_referer_has_no_referer_key(): void
    {
        $this->bindRequest('video/{id}', '/video/123');

        $logger = $this->webLogger();
        $logger->record(new QueryExecuted('select 1', [], 5.0, DB::connection()));

        $this->assertSame('https://vocamax.test/video/123', $this->sink->contexts[0]['req.url']);
        $this->assertArrayNotHasKey('req.referer', $this->sink->contexts[0]);
    }

    public function test_cli_logs_have_no_request_context(): void
    {
        $this->logQuery('select * from "users" where "id" = ?', [1]);

        $this->assertArrayNotHasKey('req.url', $this->sink->contexts[0]);
        $this->assertArrayNotHasKey('req.referer', $this->sink->contexts[0]);
    }

    public function test_raw_bindings_redact_secrets_by_value_shape(): void
    {
        $this->app['env'] = 'local';

        $output = $this->logQuery(
            'select * from "users" where "id" = ? and "email" = ? and "password" = ? and "remember_token" = ? and "bio" = ?',
            [42, 'a@b.c', '$2y$12$abcdefghijklmnopqrstuuYvF0tz1qIYdS6s6T6Q5z3Vt1b2Q9hC6', str_repeat('aB3', 20), str_repeat('long text ', 10)]
        );

        $this->assertStringContainsString(
            "-> select * from \"users\" where \"id\" = 42 and \"email\" = 'a@b.c' and \"password\" = '[hash]'"
            ." and \"remember_token\" = '[token 60 chars]' and \"bio\" = '[100 chars]'",
            $output
        );
        $this->assertStringNotContainsString(str_repeat('aB3', 20), $output);
    }

    public function test_raw_bindings_are_ignored_outside_local(): void
    {
        $this->assertFalse($this->app->environment('local'));

        $output = $this->logQuery('select * from "users" where "email" = ?', ['a@b.c']);

        $this->assertStringContainsString('where "email" = ?', $output);
        $this->assertStringNotContainsString('a@b.c', $output);
        $this->assertStringNotContainsString('->', $output);
    }

    public function test_sensitive_tables_never_log_values(): void
    {
        $this->app['env'] = 'local';

        $output = $this->logQuery('insert into "jobs" ("queue", "payload") values (?, ?)', ['default', '{"email":"a@b.c"}']);

        $this->assertStringNotContainsString('a@b.c', $output);
        $this->assertStringNotContainsString('->', $output);
    }

    public function test_binary_binding_does_not_break_the_query_listener(): void
    {
        $this->app['env'] = 'local';

        $output = $this->logQuery('update "files" set "content" = ? where "id" = ?', ["\x00\xFF\xFEbinary", 7]);

        $this->assertStringContainsString("set \"content\" = '[binary 9 bytes]' where \"id\" = 7", $output);
    }

    private function logQuery(string $sql, array $bindings): string
    {
        $logger = new SqlLogger([
            'enabled' => true,
            'raw_bindings' => true,
            'duplicate_to_log' => 1,
            'total_to_log' => 0,
            'total_ms_to_log' => 0,
            'time_to_log_cli' => 0,
            'trace' => false,
        ]);

        $logger->record(new QueryExecuted($sql, $bindings, 1.0, DB::connection()));
        $logger->flush('test');

        $this->assertNotEmpty($this->sink->lines, 'SqlLogger không log tổng kết');

        return implode("\n", $this->sink->lines);
    }

    /** Test luôn chạy trong console, nên ép logger về nhánh WEB. Mọi query >= 1ms tính là chậm. */
    private function webLogger(): SqlLogger
    {
        return new class(['enabled' => true, 'time_to_log' => 1, 'time_to_log_cli' => 1, 'duplicate_to_log' => 1, 'trace' => false]) extends SqlLogger
        {
            public function __construct(array $config)
            {
                parent::__construct($config);
                $this->console = false;
            }
        };
    }

    private function bindRequest(string $uri, string $path, array $server = []): void
    {
        $request = Request::create('https://vocamax.test'.$path, 'GET', server: $server);
        $route = (new Route('GET', $uri, fn () => null))->bind($request);
        $request->setRouteResolver(fn () => $route);

        $this->app->instance('request', $request);
    }
}
