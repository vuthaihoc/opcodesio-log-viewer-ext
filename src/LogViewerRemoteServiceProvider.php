<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\Http\Middleware\ForwardRequestToHostMiddleware;
use HocVT\LogViewerRemote\Console\CheckHostsCommand;
use HocVT\LogViewerRemote\Console\GenerateSecretCommand;
use HocVT\LogViewerRemote\Http\ForwardRequestToHost;

/**
 * Mở rộng opcodesio/log-viewer (xem README.md):
 *
 * 1. Auth: Bearer shared secret cho request server-to-server giữa các host; người
 *    dùng thật thì hỏi Gate `viewLogViewer` — project định nghĩa, mặc định chỉ mở ở local.
 * 2. Tải file log của host ở xa qua host đang xem.
 * 3. Khai hosts bằng env, mặc định api_stateful_domains theo APP_URL.
 */
class LogViewerRemoteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/log-viewer-remote.php', 'log-viewer-remote');

        // Vendor gắn cứng middleware trong routes của package, chỉ thay được qua
        // container (Pipeline resolve middleware bằng make()).
        $this->app->bind(ForwardRequestToHostMiddleware::class, ForwardRequestToHost::class);

        // Nạp ở register(), KHÔNG ở boot(): package đăng ký route bắt-tất
        // `log-viewer/{view?}` trong boot() nên nuốt mọi route /log-viewer khai sau.
        $this->loadRoutesFrom(__DIR__.'/../routes/routes.php');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/log-viewer-remote.php' => config_path('log-viewer-remote.php'),
        ], 'log-viewer-remote-config');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckHostsCommand::class, GenerateSecretCommand::class]);
        }

        $this->mergeHostsFromEnv();
        $this->defaultStatefulDomains();
        $this->defaultGate();

        LogViewer::auth(fn (Request $request): bool => $this->bearerMatchesSharedSecret($request)
            || $this->userCanView($request));
    }

    /**
     * Mặc định hỏi Gate `viewLogViewer`. App nào không dùng Gate được thì cắm callback
     * riêng bằng LogViewerRemote::authorizeUsing() — xem docblock của lớp đó.
     */
    private function userCanView(Request $request): bool
    {
        $authorizer = LogViewerRemote::authorizer();

        if ($authorizer !== null) {
            return (bool) $authorizer($request);
        }

        return Gate::forUser($request->user())->allows('viewLogViewer');
    }

    /**
     * LOG_VIEWER_HOSTS="web=https://a/log-viewer,m=https://b/log-viewer" → log-viewer.hosts.
     * Host đã khai tay trong config/log-viewer.php giữ nguyên.
     *
     * URL phải kèm đủ đường dẫn Log Viewer của host đó, vì middleware forward nối
     * thẳng `/api/files` vào sau nó. Chỉ URL trần (không có path) mới được bù prefix.
     */
    private function mergeHostsFromEnv(): void
    {
        $raw = trim((string) config('log-viewer-remote.hosts'));

        if ($raw === '') {
            return;
        }

        $hosts = config('log-viewer.hosts', []);
        $secret = (string) config('log-viewer-remote.shared_secret');

        foreach (explode(',', $raw) as $entry) {
            [$id, $url] = array_pad(explode('=', trim($entry), 2), 2, '');
            $id = trim($id);
            $url = rtrim(trim($url), '/');

            if ($id === '' || $url === '' || isset($hosts[$id])) {
                continue;
            }

            $hosts[$id] = [
                'name' => ucfirst($id),
                'host' => $this->normalizeHostUrl($url),
                'auth' => ['token' => $secret],
            ];
        }

        config(['log-viewer.hosts' => $hosts]);
    }

    /**
     * Chỉ bù prefix cho URL TRẦN (mới có mỗi trang chủ) — đó gần như chắc chắn là khai
     * thiếu, và chỉ lộ ra thành 404 lúc chọn host. URL đã có path thì giữ nguyên văn:
     * prefix là thứ nên đổi khác mặc định (xem README) nên host xa hoàn toàn có thể
     * dùng prefix khác host này.
     *
     * Giá trị bù là `route_path` của chính host này, tức đoán rằng cả cụm dùng chung
     * một quy ước. Nếu host xa đặt prefix khác, phải khai đủ đường dẫn trong
     * LOG_VIEWER_HOSTS.
     */
    private function normalizeHostUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path === ''
            ? $url.'/'.trim((string) config('log-viewer.route_path', 'log-viewer'), '/')
            : $url;
    }

    /**
     * EnsureFrontendRequestsAreStateful chỉ mở session cho request từ domain hợp lệ;
     * không khai thì $request->user() luôn null và UI 403 toàn bộ API.
     */
    private function defaultStatefulDomains(): void
    {
        if (config('log-viewer.api_stateful_domains') !== null) {
            return;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            config(['log-viewer.api_stateful_domains' => [$host]]);
        }
    }

    /**
     * Project định nghĩa lại `viewLogViewer` trong AppServiceProvider::boot() là ghi
     * đè được (app provider boot sau package provider).
     */
    private function defaultGate(): void
    {
        if (! Gate::has('viewLogViewer')) {
            Gate::define('viewLogViewer', fn (mixed $user = null): bool => $this->app->isLocal());
        }
    }

    private function bearerMatchesSharedSecret(Request $request): bool
    {
        $secret = (string) config('log-viewer-remote.shared_secret');
        $bearer = (string) $request->bearerToken();

        return $secret !== '' && $bearer !== '' && hash_equals($secret, $bearer);
    }
}
