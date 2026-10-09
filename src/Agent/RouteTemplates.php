<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Throwable;

/**
 * Quy một URL đã log về URI template của route trong app (`/learn/video/{slug}/{id}`), để
 * gộp được log cũ chưa có `req.route` và referer của request Livewire.
 *
 * Match route bind tham số vào chính object Route dùng chung — vô hại ở đây (request agent
 * không chạy route nào trong số đó), nhưng là lý do LoggableUrl KHÔNG làm thế lúc ghi log.
 * Nhớ kết quả theo host + path, có trần để một file toàn URL khác nhau không phình bộ nhớ.
 */
final class RouteTemplates
{
    private const MEMO_CAP = 5000;

    /** @var array<string, string|null> */
    private array $memo = [];

    public function __construct(private readonly Router $router) {}

    public function __invoke(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $key = (is_string($host) ? $host : '').'|'.$path;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        if (count($this->memo) >= self::MEMO_CAP) {
            return null;
        }

        return $this->memo[$key] = $this->match(is_string($host) ? 'https://'.$host.$path : $path);
    }

    private function match(string $url): ?string
    {
        try {
            $route = $this->router->getRoutes()->match(Request::create($url, 'GET'));
        } catch (Throwable) {
            return null;
        }

        // Route::fallback() khớp mọi thứ — coi như không biết.
        return $route->isFallback ? null : '/'.ltrim($route->uri(), '/');
    }
}
