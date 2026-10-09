<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Unit\Agent;

use HocVT\LogViewerRemote\Agent\ChannelFiles;
use HocVT\LogViewerRemote\Tests\Support\LogBuilder;
use PHPUnit\Framework\TestCase;

class ChannelFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lvr-channels-'.uniqid();
        mkdir($this->dir.'/real/logs', 0777, true);
        // storage symlink sang thư mục dùng chung, như khi deploy.
        symlink($this->dir.'/real', $this->dir.'/storage');
    }

    protected function tearDown(): void
    {
        LogBuilder::removeDir($this->dir);
    }

    /** @return array<string, array<string, mixed>> */
    private function channels(): array
    {
        $logs = $this->dir.'/storage/logs';

        return [
            'stack' => ['driver' => 'stack', 'channels' => ['single', 'daily', 'loop']],
            'loop' => ['driver' => 'stack', 'channels' => ['stack']],
            'single' => ['driver' => 'single', 'path' => $logs.'/laravel.log'],
            'daily' => ['driver' => 'daily', 'path' => $logs.'/web.log'],
            'slow-log' => ['driver' => 'daily', 'path' => $logs.'/slow-log.log'],
            'ai_request' => ['driver' => 'daily', 'path' => $logs.'/ai_request.log'],
            'stream' => ['driver' => 'monolog', 'handler_with' => ['stream' => $logs.'/stream.log']],
            'stderr' => ['driver' => 'monolog', 'with' => ['stream' => 'php://stderr']],
            'slack' => ['driver' => 'slack', 'url' => 'https://hooks.slack.test'],
        ];
    }

    public function test_daily_single_monolog_and_stack_with_a_loop(): void
    {
        $files = new ChannelFiles($this->channels(), ['stack', 'slow-log', 'stream', 'stderr', 'slack', 'missing']);
        $real = $this->dir.'/real/logs';

        $this->assertSame(['channel' => 'slow-log', 'date' => '2026-10-09'], $files->match($real.'/slow-log-2026-10-09.log'));
        $this->assertSame(['channel' => 'daily', 'date' => '2026-10-08'], $files->match($real.'/web-2026-10-08.log'));
        $this->assertSame(['channel' => 'single', 'date' => null], $files->match($real.'/laravel.log'));
        $this->assertSame(['channel' => 'stream', 'date' => null], $files->match($real.'/stream.log'));
        $this->assertSame(['single', 'daily', 'slow-log', 'stream'], $files->channels());
    }

    public function test_files_of_channels_not_allowed_do_not_match(): void
    {
        $files = new ChannelFiles($this->channels(), ['slow-log']);
        $real = $this->dir.'/real/logs';

        $this->assertNull($files->match($real.'/ai_request-2026-10-09.log'));
        $this->assertNull($files->match($real.'/web-2026-10-09.log'));
        $this->assertNull($files->match($real.'/slow-log.log'));
        $this->assertNull($files->match($real.'/slow-log-2026-10-09.log.1'));
        $this->assertNull($files->match('/elsewhere/slow-log-2026-10-09.log'));
    }
}
