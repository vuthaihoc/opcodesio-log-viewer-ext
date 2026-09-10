<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Opcodes\LogViewer\Http\Middleware\AuthorizeLogViewer;
use HocVT\LogViewerRemote\Http\RemoteDownloadController;

/**
 * Tải file log của host ở xa. Phải cùng origin với UI Log Viewer: nút Download gọi
 * XHR tới `download_url + '/request'`, mà URL gốc do host xa sinh nên khác origin.
 *
 * File này nạp từ LogViewerRemoteServiceProvider::register(), KHÔNG phải boot():
 * package log-viewer đăng ký route bắt-tất `log-viewer/{view?}` (where `.*`) trong
 * boot() nên mọi route dưới /log-viewer khai sau nó đều bị nuốt.
 *
 * Vì chạy ở register() nên KHÔNG chắc config của log-viewer đã merge (provider nào
 * register trước là tuỳ thứ tự auto-discovery). Default ở đây phải khớp default của
 * package gốc: app có publish config/log-viewer.php thì file đã nạp từ bootstrap nên
 * đọc ra giá trị thật, không publish thì rơi về đúng default này.
 */
Route::middleware(config('log-viewer.middleware', ['web', AuthorizeLogViewer::class]))
    ->prefix(config('log-viewer.route_path', 'log-viewer').'/remote/{host}/api/{type}')
    ->where(['type' => 'files|folders'])
    ->group(function (): void {
        Route::get('{identifier}/download/request', [RemoteDownloadController::class, 'request'])
            ->name('log-viewer.remote.request-download');
        Route::get('{identifier}/download', [RemoteDownloadController::class, 'download'])
            ->name('log-viewer.remote.download');
    });
