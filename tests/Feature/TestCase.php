<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature;

use HocVT\LogViewerRemote\LogViewerRemote;
use HocVT\LogViewerRemote\LogViewerRemoteServiceProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Opcodes\LogViewer\LogViewerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Test cần Laravel (HTTP, lệnh, logger, config) chạy trên orchestra/testbench, không cần app
 * nào: file log nằm ở thư mục tạm, host xa là Http::fake, người xem là user không lưu DB.
 */
abstract class TestCase extends Orchestra
{
    /** @var list<string> deprecation phát ra từ src/ của package trong test đang chạy */
    private array $packageDeprecations = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Như app dùng package: test không được gọi mạng thật.
        Http::preventStrayRequests();

        $this->recordPackageDeprecations();
    }

    /**
     * Laravel đưa deprecation vào kênh log `deprecations` (mặc định `null`), nên PHPUnit không
     * thấy và test vẫn xanh dù code gọi hàm đã bị deprecate (từng gặp: `setAccessible()` trên
     * PHP 8.5). Ở đây ghi lại deprecation có file nằm trong src/, rồi chuyển tiếp cho handler
     * cũ: hành vi không đổi, kể cả với code tự `catch (\Throwable)`.
     */
    private function recordPackageDeprecations(): void
    {
        $src = (string) realpath(__DIR__.'/../../src');
        $previous = null;
        $previous = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previous, $src): mixed {
            if (in_array($level, [E_DEPRECATED, E_USER_DEPRECATED], true) && str_starts_with($file, $src)) {
                $this->packageDeprecations[] = "{$message} ({$file}:{$line})";
            }

            // Trả nguyên giá trị của handler cũ: chỉ `false` mới để PHP in lỗi ra tiếp.
            return is_callable($previous) ? $previous($level, $message, $file, $line) : false;
        });
    }

    protected function assertPostConditions(): void
    {
        $this->assertSame([], $this->packageDeprecations, 'Code của package gọi API đã bị deprecate trên PHP '.PHP_VERSION.'.');

        parent::assertPostConditions();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        LogViewerRemote::forgetAuthorizer();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [LogViewerServiceProvider::class, LogViewerRemoteServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Route của Log Viewer đi qua nhóm `web` (cookie mã hoá) nên cần key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    /**
     * Đường dẫn Log Viewer theo config, không hardcode: đổi `log-viewer.route_path` (nên đổi,
     * xem README) là cả bộ test vẫn chạy.
     */
    protected function lv(string $path = ''): string
    {
        return '/'.trim((string) config('log-viewer.route_path'), '/').$path;
    }

    /**
     * Người được xem Log Viewer: Gate `viewLogViewer` chỉ cho email này — đúng vai của Gate
     * mà app định nghĩa (package mặc định chỉ mở ở local, môi trường test là `testing`).
     */
    protected function admin(): Authenticatable
    {
        Gate::define('viewLogViewer', static fn (?Authenticatable $user = null): bool => $user?->getAttribute('email') === 'admin@example.test');

        return (new User)->forceFill(['id' => 1, 'email' => 'admin@example.test']);
    }
}
