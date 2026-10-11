<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\LogViewer;

use HocVT\LogViewerRemote\LogViewerRemoteServiceProvider;
use HocVT\LogViewerRemote\Tests\Feature\TestCase;

/**
 * Một file config `log-viewer-ext` cho cả package, gộp đệ quy với file của app; hai file
 * của bản ≤ 1.2 (log-viewer-remote.php, slow-log.php) vẫn được đọc nhưng xếp dưới file mới.
 */
class ConfigMergeTest extends TestCase
{
    /** Chạy lại register() của package như lúc boot, với config "của app" đặt sẵn. */
    private function bootWithAppConfig(array $config): void
    {
        config($config);

        $this->app->register(LogViewerRemoteServiceProvider::class, force: true);
    }

    public function test_app_only_writes_the_keys_it_changes_even_in_nested_groups(): void
    {
        $this->bootWithAppConfig([
            'log-viewer-ext' => [
                'agent' => ['page_key' => ['graphql.name']],
                'slow_log' => ['dedicated' => ['permission' => 0640], 'trace' => ['blade' => false]],
            ],
        ]);

        $this->assertSame(['graphql.name'], config('log-viewer-ext.agent.page_key'));
        $this->assertSame(20.0, config('log-viewer-ext.agent.max_seconds'));
        $this->assertSame(0640, config('log-viewer-ext.slow_log.dedicated.permission'));
        $this->assertSame('slow-log', config('log-viewer-ext.slow_log.dedicated.name'));
        $this->assertFalse(config('log-viewer-ext.slow_log.trace.blade'));
        // Key app không khai lấy mặc định của package, ngay trong nhóm app có ghi đè.
        $this->assertTrue(config('log-viewer-ext.slow_log.trace.enabled'));
        $this->assertFalse(config('log-viewer-ext.slow_log.trace.livewire'));
        $this->assertSame(15, config('log-viewer-ext.timeout.request'));
    }

    public function test_lists_are_replaced_not_merged_by_position(): void
    {
        $this->bootWithAppConfig(['log-viewer-ext' => ['slow_log' => ['trace' => ['skip_vendors' => ['acme/noise']]]]]);

        $this->assertSame(['acme/noise'], config('log-viewer-ext.slow_log.trace.skip_vendors'));
    }

    public function test_legacy_config_files_still_work_but_the_new_file_wins(): void
    {
        $this->bootWithAppConfig([
            'log-viewer-ext' => ['agent' => ['page_key' => ['new']]],
            'log-viewer-remote' => [
                'timeout' => ['download' => 999],
                'agent' => ['page_key' => ['legacy'], 'slots' => 7],
            ],
            'slow-log' => ['enabled' => true, 'trace' => ['blade' => false]],
        ]);

        $this->assertSame(999, config('log-viewer-ext.timeout.download'));
        $this->assertSame(15, config('log-viewer-ext.timeout.request'));
        $this->assertSame(['new'], config('log-viewer-ext.agent.page_key'));
        $this->assertSame(7, config('log-viewer-ext.agent.slots'));
        $this->assertTrue(config('log-viewer-ext.slow_log.enabled'));
        $this->assertFalse(config('log-viewer-ext.slow_log.trace.blade'));
        $this->assertTrue(config('log-viewer-ext.slow_log.trace.enabled'));
    }

    public function test_check_command_warns_about_legacy_files(): void
    {
        config(['slow-log' => ['enabled' => true], 'log-viewer.hosts' => []]);

        $this->artisan('log-viewer-remote:check')
            ->expectsOutputToContain('config/slow-log.php')
            ->assertExitCode(2);
    }
}
