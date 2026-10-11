<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\SlowLog;

use HocVT\LogViewerRemote\SlowLog\SlowLogServiceProvider;
use HocVT\LogViewerRemote\SlowLog\SqlLogger;
use HocVT\LogViewerRemote\Tests\Feature\TestCase;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Nhãn ngữ cảnh `[CLI][tên job / lệnh]` của slow log, nối dây thật: bật provider rồi bắn đúng
 * các event mà queue worker và artisan bắn, thay vì gọi thẳng method của SqlLogger.
 *
 * Gốc (gth, 20/09/2026): 611 dòng query chậm chỉ ghi `[CLI]`, không biết job nào — có câu
 * 53 giây — vì tên job chỉ được truyền lúc flush() (tổng kết cuối job), còn query chậm được
 * log NGAY khi chạy.
 */
class SlowLogContextTest extends TestCase
{
    private object $sink;

    protected function setUp(): void
    {
        parent::setUp();

        config(['log-viewer-ext.slow_log.enabled' => true, 'log-viewer-ext.slow_log.environments' => ['testing']]);

        $this->sink = new class
        {
            /** @var list<string> */
            public array $lines = [];

            public function __call(string $method, array $args): void
            {
                $this->lines[] = (string) $args[0];
            }
        };
        Log::shouldReceive('channel')->andReturn($this->sink);

        // Query >= 1ms là "chậm"; 3 lần lặp là "N+1" — để test ngắn gọn.
        $this->app->instance(SqlLogger::class, new SqlLogger([
            'enabled' => true,
            'time_to_log_cli' => 1,
            'duplicate_to_log' => 3,
            'total_to_log' => 0,
            'total_ms_to_log' => 0,
            'trace' => false,
        ]));

        (new SlowLogServiceProvider($this->app))->boot();
    }

    private function slowQuery(): void
    {
        event(new QueryExecuted('select * from `slow_table` where `id` = ?', [1], 5.0, DB::connection()));
    }

    private function job(string $name): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn($name);
        // Listener khác của Laravel (Log Context…) cũng nghe event queue và đọc payload.
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldIgnoreMissing();

        return $job;
    }

    /** Dòng đầu (nhãn ngữ cảnh) của dòng log query chậm gần nhất. */
    private function slowQueryLabel(): string
    {
        foreach (array_reverse($this->sink->lines) as $line) {
            if (str_contains($line, 'slow_table') && ! str_contains($line, 'Nghi ngờ N+1')) {
                return (string) strtok($line, "\n");
            }
        }

        $this->fail('Không thấy log query chậm. Đã log: '.implode(' | ', $this->sink->lines));
    }

    public function test_outside_jobs_and_commands_the_label_is_plain_cli(): void
    {
        $this->slowQuery();

        $this->assertSame('[CLI]', $this->slowQueryLabel());
    }

    public function test_slow_query_inside_a_job_carries_the_job_name(): void
    {
        event(new JobProcessing('redis', $this->job('App\Jobs\Student\StudyLoggingJob')));

        $this->slowQuery();

        $this->assertSame('[CLI][App\Jobs\Student\StudyLoggingJob]', $this->slowQueryLabel());
    }

    public function test_summary_at_the_end_of_a_job_carries_the_job_name(): void
    {
        event(new JobProcessing('redis', $this->job('App\Jobs\Student\StudyLoggingJob')));
        $this->slowQuery();
        $this->slowQuery();
        $this->slowQuery();
        event(new JobProcessed('redis', $this->job('App\Jobs\Student\StudyLoggingJob')));

        $summaries = array_values(array_filter($this->sink->lines, fn (string $l) => str_contains($l, 'Nghi ngờ N+1')));

        $this->assertCount(1, $summaries);
        $this->assertStringStartsWith('[CLI][App\Jobs\Student\StudyLoggingJob]', $summaries[0]);
    }

    /** Job xong thì query chạy sau đó (giữa hai job của worker) không mang nhầm tên job cũ. */
    public function test_after_a_job_the_name_is_cleared(): void
    {
        event(new JobProcessing('redis', $this->job('App\Jobs\OldJob')));
        event(new JobProcessed('redis', $this->job('App\Jobs\OldJob')));

        $this->slowQuery();

        $this->assertSame('[CLI]', $this->slowQueryLabel());
    }

    /** Lần thử lỗi còn retry chỉ có JobExceptionOccurred (không JobProcessed / JobFailed). */
    public function test_a_failed_attempt_that_will_retry_also_clears_the_name(): void
    {
        event(new JobProcessing('redis', $this->job('App\Jobs\FlakyJob')));
        event(new JobExceptionOccurred('redis', $this->job('App\Jobs\FlakyJob'), new RuntimeException('boom')));

        $this->slowQuery();

        $this->assertSame('[CLI]', $this->slowQueryLabel());
    }

    public function test_slow_query_inside_an_artisan_command_carries_the_command_name(): void
    {
        event(new CommandStarting('insights:refresh', new ArrayInput([]), new NullOutput));

        $this->slowQuery();

        $this->assertSame('[CLI][insights:refresh]', $this->slowQueryLabel());

        event(new CommandFinished('insights:refresh', new ArrayInput([]), new NullOutput, 0));
    }

    /** Job chạy trong `queue:work`: tên job thắng tên lệnh worker, hết job thì về tên lệnh. */
    public function test_job_name_wins_over_the_worker_command_then_falls_back_to_it(): void
    {
        event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));
        event(new JobProcessing('redis', $this->job('App\Jobs\SyncVideo')));
        $this->slowQuery();
        $this->assertSame('[CLI][App\Jobs\SyncVideo]', $this->slowQueryLabel());

        event(new JobProcessed('redis', $this->job('App\Jobs\SyncVideo')));
        $this->slowQuery();
        $this->assertSame('[CLI][queue:work]', $this->slowQueryLabel());
    }
}
