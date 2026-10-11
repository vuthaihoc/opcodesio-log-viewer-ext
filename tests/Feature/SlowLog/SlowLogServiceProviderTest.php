<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\SlowLog;

use HocVT\LogViewerRemote\SlowLog\SlowLogServiceProvider;
use HocVT\LogViewerRemote\SlowLog\SqlLogger;
use HocVT\LogViewerRemote\Tests\Feature\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;

/**
 * Provider của package được LogViewerRemoteServiceProvider tự register; ở đây boot lại
 * với config từng ca để kiểm việc gắn listener theo `enabled` / `environments`.
 */
class SlowLogServiceProviderTest extends TestCase
{
    public function test_records_queries_when_enabled_in_current_environment(): void
    {
        $logger = $this->bootWith(['log-viewer-ext.slow_log.enabled' => true, 'log-viewer-ext.slow_log.environments' => ['testing']]);

        $this->fireQuery();

        $logger->shouldHaveReceived('record')->once();
    }

    public function test_does_not_listen_when_disabled(): void
    {
        $logger = $this->bootWith(['log-viewer-ext.slow_log.enabled' => false, 'log-viewer-ext.slow_log.environments' => ['testing']]);

        $this->fireQuery();

        $logger->shouldNotHaveReceived('record');
    }

    public function test_does_not_listen_outside_configured_environments(): void
    {
        $logger = $this->bootWith(['log-viewer-ext.slow_log.enabled' => true, 'log-viewer-ext.slow_log.environments' => ['production']]);

        $this->fireQuery();

        $logger->shouldNotHaveReceived('record');
    }

    public function test_package_defaults(): void
    {
        // Mặc định tắt (cài package không tự sinh log), ghi channel riêng slow-log, hai phần
        // Livewire tắt, luật request không có prefix nào.
        $this->assertFalse(config('log-viewer-ext.slow_log.enabled'));
        $this->assertSame('slow-log', config('log-viewer-ext.slow_log.channel'));
        $this->assertFalse(config('log-viewer-ext.slow_log.trace.livewire'));
        $this->assertFalse(config('log-viewer-ext.slow_log.debugbar_livewire'));
        $this->assertSame('', config('log-viewer-ext.slow_log.requests.time.prefixes'));
        $this->assertSame('slow-log', config('log-viewer-ext.agent.channels'));
    }

    public function test_dedicated_channel_is_defined_and_writes_its_own_daily_file(): void
    {
        $this->assertSame('slow-log', config('log-viewer-ext.slow_log.channel'));
        $this->assertSame('daily', config('logging.channels.slow-log.driver'));
        $this->assertArrayNotHasKey('permission', config('logging.channels.slow-log'));

        $dir = sys_get_temp_dir().'/slow-log-channel-'.uniqid();
        config(['logging.channels.slow-log.path' => $dir.'/slow-log.log']);
        Log::forgetChannel('slow-log');

        Log::channel('slow-log')->warning('[CLI][test] Quá nhiều query');

        $files = glob($dir.'/slow-log-*.log') ?: [];
        $this->assertCount(1, $files);
        $this->assertStringContainsString('Quá nhiều query', (string) file_get_contents($files[0]));

        File::deleteDirectory($dir);
        Log::forgetChannel('slow-log');
    }

    public function test_dedicated_channel_takes_permission_from_config(): void
    {
        config(['logging.channels.slow-log' => null, 'log-viewer-ext.slow_log.dedicated.permission' => 0666]);

        (new SlowLogServiceProvider($this->app))->register();

        $this->assertSame(0666, config('logging.channels.slow-log.permission'));
    }

    public function test_app_defined_channel_with_the_same_name_is_kept(): void
    {
        config(['logging.channels.slow-log' => ['driver' => 'single', 'path' => '/tmp/app-own.log']]);

        (new SlowLogServiceProvider($this->app))->register();

        $this->assertSame(['driver' => 'single', 'path' => '/tmp/app-own.log'], config('logging.channels.slow-log'));
    }

    private function bootWith(array $config): MockInterface
    {
        config($config);

        $logger = Mockery::spy(SqlLogger::class);
        $logger->allows('isEnabled')->andReturn(true);
        $this->app->instance(SqlLogger::class, $logger);

        (new SlowLogServiceProvider($this->app))->boot();

        return $logger;
    }

    /** Bắn event trực tiếp: không cần chạm DB test từ xa. */
    private function fireQuery(): void
    {
        event(new QueryExecuted('select 1', [], 1.0, DB::connection()));
    }
}
