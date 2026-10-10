<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\SlowLog;

use HocVT\LogViewerRemote\Support\LoggableUrl;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Nhãn `[WEB][ip][url]` và context `req.*` của một request web, dùng chung cho mọi dòng slow
 * log (query chậm, tổng kết, request chậm / response lớn) để bộ đọc của agent gom được theo
 * trang. URL và referer đã che qua LoggableUrl.
 */
final class WebContext
{
    public static function label(Request $request): string
    {
        return '[WEB]['.$request->ip().']['.LoggableUrl::fromRequest($request).']';
    }

    /**
     * `req.route` là URI template của route (`/video/{id}/{slug?}`) — gom theo trang mà không
     * phải đoán từ URL. Cùng key với middleware chia sẻ context request (nếu project có, gọi
     * `Log::shareContext(['req.url' => …])`): Laravel gộp context bằng array_merge nên hai nơi
     * cùng ghi cũng chỉ ra một key.
     *
     * @return array<string, string>
     */
    public static function context(Request $request): array
    {
        $route = $request->route();

        return array_filter([
            'req.url' => LoggableUrl::fromRequest($request),
            'req.route' => $route instanceof Route ? '/'.ltrim($route->uri(), '/') : null,
            'req.referer' => LoggableUrl::fromString($request->headers->get('referer')),
        ], static fn (?string $value): bool => $value !== null);
    }
}
