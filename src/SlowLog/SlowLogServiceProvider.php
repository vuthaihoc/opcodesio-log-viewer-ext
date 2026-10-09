<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\SlowLog;

use HocVT\LogViewerRemote\Support\MergesNestedConfig;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Log query chậm + cảnh báo context có quá nhiều query (N+1). Xem docs/slow-log.md.
 *
 * LogViewerRemoteServiceProvider tự register provider này, app không cần khai thêm.
 */
class SlowLogServiceProvider extends ServiceProvider
{
    use MergesNestedConfig;

    public function register(): void
    {
        $this->mergeNestedConfigFrom(__DIR__.'/../../config/slow-log.php', 'slow-log', ['trace', 'dedicated']);

        $this->app->singleton(SqlLogger::class, fn () => new SqlLogger);

        $this->defineDedicatedChannel();
    }

    /**
     * Khai channel riêng (`slow-log.dedicated`) để app chỉ cần `SLOW_LOG_CHANNEL=slow-log`.
     * App đã tự khai channel cùng tên thì giữ nguyên của app.
     */
    private function defineDedicatedChannel(): void
    {
        $dedicated = (array) config('slow-log.dedicated', []);
        $name = (string) ($dedicated['name'] ?? 'slow-log');

        if ($name === '' || config('logging.channels.'.$name) !== null) {
            return;
        }

        config(['logging.channels.'.$name => array_filter([
            'driver' => 'daily',
            'path' => $dedicated['path'] ?? storage_path('logs/'.$name.'.log'),
            'level' => 'debug',
            'days' => (int) ($dedicated['days'] ?? 14),
            'permission' => $dedicated['permission'] ?? null,
        ], static fn (mixed $value): bool => $value !== null)]);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/slow-log.php' => config_path('slow-log.php'),
        ], 'slow-log-config');

        // Vá tên nguồn query của Debugbar cho Livewire — chỉ local, độc lập với slow-log.enabled.
        if (config('slow-log.debugbar_livewire', true) && $this->app->environment('local')) {
            DebugbarLivewireSource::register();
        }

        if (! config('slow-log.enabled')) {
            return;
        }

        if (! $this->app->environment((array) config('slow-log.environments', []))) {
            return;
        }

        $this->setupListener();
    }

    private function setupListener(): void
    {
        $logger = $this->app->make(SqlLogger::class);

        if (! $logger->isEnabled()) {
            return;
        }

        Event::listen(QueryExecuted::class, $logger->record(...));

        // Mỗi context kết thúc phải flush, nếu không số liệu cộng dồn và rò bộ nhớ
        // trong các process chạy dài (queue:work, schedule:run).
        Event::listen(RequestHandled::class, static fn () => $logger->flush());

        // Job chạy trong worker dài hạn: reset trước, tổng kết sau. Tên job / command gắn
        // từ lúc bắt đầu để dòng query chậm (ghi ngay giữa chừng) cũng mang tên.
        // Lần thử lỗi còn retry chỉ có JobExceptionOccurred; leaveJob() gọi lặp vẫn vô hại.
        Event::listen(JobProcessing::class, static function (JobProcessing $e) use ($logger): void {
            $logger->reset();
            $logger->enterJob($e->job->resolveName());
        });

        $finishJob = static function (JobProcessed|JobFailed|JobExceptionOccurred $e) use ($logger): void {
            $logger->flush($e->job->resolveName());
            $logger->leaveJob();
        };
        Event::listen(JobProcessed::class, $finishJob);
        Event::listen(JobFailed::class, $finishJob);
        Event::listen(JobExceptionOccurred::class, $finishJob);

        Event::listen(CommandStarting::class, static fn (CommandStarting $e) => $logger->enterCommand($e->command ?? 'artisan'));
        Event::listen(CommandFinished::class, static function (CommandFinished $e) use ($logger): void {
            $logger->flush($e->command ?? 'artisan');
            $logger->leaveCommand();
        });
    }
}
