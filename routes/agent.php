<?php

declare(strict_types=1);

use HocVT\LogViewerRemote\Http\AgentController;
use HocVT\LogViewerRemote\Http\Middleware\AuthorizeAgent;
use Illuminate\Support\Facades\Route;

/**
 * Endpoint cho agent / script phân tích log ngay trên host này — xem docs/agent.md.
 *
 * Nạp ở register() như routes.php (route bắt-tất của vendor đăng ký trong boot() sẽ nuốt
 * route khai sau nó). Không gắn `web` / `api_middleware`: không session, không cookie, chỉ
 * Bearer qua AuthorizeAgent. Không forward qua `?host=`: lệnh agent gọi thẳng từng host.
 */
Route::prefix(config('log-viewer.route_path', 'log-viewer').'/api/agent')
    ->domain(config('log-viewer.route_domain'))
    ->middleware(AuthorizeAgent::class)
    ->group(function (): void {
        Route::get('ping', [AgentController::class, 'ping'])->name('log-viewer.agent.ping');
        Route::get('files', [AgentController::class, 'files'])->name('log-viewer.agent.files');
        Route::get('aggregate', [AgentController::class, 'aggregate'])->name('log-viewer.agent.aggregate');
        Route::get('entries', [AgentController::class, 'entries'])->name('log-viewer.agent.entries');
    });
