<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote;

use Illuminate\Http\Request;

/**
 * Điểm cắm để app tự quyết ai được xem Log Viewer.
 *
 * Mặc định package hỏi Gate `viewLogViewer`. Nhưng có app không dùng Gate được:
 * ví dụ app cài spatie/laravel-permission (đăng ký một Gate::before nhận
 * Illuminate\Contracts\Auth\Access\Authorizable) trong khi model User của app lại
 * không implement contract đó — mọi lần gọi Gate với user đã đăng nhập sẽ ném
 * TypeError trước cả khi closure của Gate chạy. App kiểu đó gọi authorizeUsing()
 * để bỏ qua tầng Gate, vẫn giữ nguyên đường shared secret giữa các host.
 *
 * Đặt trong AppServiceProvider::boot():
 *
 *     LogViewerRemote::authorizeUsing(
 *         fn (Request $request): bool => $request->user()?->isAdmin() ?? false
 *     );
 */
class LogViewerRemote
{
    /** @var (callable(Request): bool)|null */
    private static $authorizer = null;

    /**
     * @param  callable(Request): bool  $callback
     */
    public static function authorizeUsing(callable $callback): void
    {
        self::$authorizer = $callback;
    }

    /**
     * @return (callable(Request): bool)|null
     */
    public static function authorizer(): ?callable
    {
        return self::$authorizer;
    }

    /**
     * Chủ yếu để test dọn state giữa các case.
     */
    public static function forgetAuthorizer(): void
    {
        self::$authorizer = null;
    }
}
