<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\SlowLog;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
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
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/slow-log.php', 'slow-log');

        $this->app->singleton(SqlLogger::class, fn () => new SqlLogger);
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

        // Job chạy trong worker dài hạn: reset trước, tổng kết sau.
        Event::listen(JobProcessing::class, static fn () => $logger->reset());
        Event::listen(JobProcessed::class, static fn (JobProcessed $e) => $logger->flush($e->job->resolveName()));
        Event::listen(JobFailed::class, static fn (JobFailed $e) => $logger->flush($e->job->resolveName()));

        Event::listen(CommandFinished::class, static fn (CommandFinished $e) => $logger->flush($e->command ?? 'artisan'));
    }
}
