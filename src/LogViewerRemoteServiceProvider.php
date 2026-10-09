<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote;

use HocVT\LogViewerRemote\Console\AgentAggregateCommand;
use HocVT\LogViewerRemote\Console\AgentEntriesCommand;
use HocVT\LogViewerRemote\Console\AgentFilesCommand;
use HocVT\LogViewerRemote\Console\CheckHostsCommand;
use HocVT\LogViewerRemote\Console\GenerateSecretCommand;
use HocVT\LogViewerRemote\Http\ForwardRequestToHost;
use HocVT\LogViewerRemote\SlowLog\SlowLogServiceProvider;
use HocVT\LogViewerRemote\Support\HostCredentials;
use HocVT\LogViewerRemote\Support\MergesConfigRecursively;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\Host;
use Opcodes\LogViewer\HostCollection;
use Opcodes\LogViewer\Http\Middleware\ForwardRequestToHostMiddleware;

/**
 * Mở rộng opcodesio/log-viewer (xem README.md):
 *
 * 1. Auth: Bearer shared secret cho request server-to-server giữa các host; người
 *    dùng thật thì hỏi Gate `viewLogViewer` — project định nghĩa, mặc định chỉ mở ở local.
 * 2. Tải file log của host ở xa qua host đang xem.
 * 3. Khai hosts bằng env, mặc định api_stateful_domains theo APP_URL.
 *    Host trả ra ngoài (trang chính, /api/hosts) không mang credential — xem HostCredentials.
 * 4. Slow query log (SlowLogServiceProvider, mặc định tắt) — xem docs/slow-log.md.
 * 5. Endpoint cho agent phân tích log ngay trên host (`api/agent/*`) — xem docs/agent.md.
 */
class LogViewerRemoteServiceProvider extends ServiceProvider
{
    use MergesConfigRecursively;

    /** Key config của package — một file cho auth / host xa, agent, slow log. */
    public const CONFIG = 'log-viewer-ext';

    public function register(): void
    {
        // Bản ≤ 1.2 có hai file config (log-viewer-remote.php, slow-log.php): app còn giữ thì
        // vẫn đọc, xếp dưới file mới. Phải gộp TRƯỚC khi register SlowLogServiceProvider.
        $this->mergeConfigRecursivelyFrom(__DIR__.'/../config/log-viewer-ext.php', self::CONFIG, [
            'log-viewer-remote' => '',
            'slow-log' => 'slow_log',
        ]);

        // Đi kèm luôn để app chỉ cần một provider (kể cả app tắt auto-discovery); bật tắt bằng config.
        $this->app->register(SlowLogServiceProvider::class);

        // Vendor gắn cứng middleware trong routes của package, chỉ thay được qua
        // container (Pipeline resolve middleware bằng make()).
        $this->app->bind(ForwardRequestToHostMiddleware::class, ForwardRequestToHost::class);

        // Nạp ở register(), KHÔNG ở boot(): package đăng ký route bắt-tất
        // `log-viewer/{view?}` trong boot() nên nuốt mọi route /log-viewer khai sau.
        $this->loadRoutesFrom(__DIR__.'/../routes/routes.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/agent.php');
    }

    public function boot(): void
    {
        // `log-viewer-remote-config` / `slow-log-config` là tên tag của bản ≤ 1.2, giữ cho script cũ.
        $this->publishes([
            __DIR__.'/../config/log-viewer-ext.php' => config_path('log-viewer-ext.php'),
        ], ['log-viewer-ext-config', 'log-viewer-remote-config', 'slow-log-config']);

        // Skill cho agent AI (Claude Code): cách dùng lệnh agent + cách đọc số cho đúng.
        $this->publishes([
            __DIR__.'/../stubs/skill/SKILL.md' => base_path('.claude/skills/log-viewer-remote/SKILL.md'),
        ], 'log-viewer-remote-skill');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckHostsCommand::class,
                GenerateSecretCommand::class,
                AgentAggregateCommand::class,
                AgentEntriesCommand::class,
                AgentFilesCommand::class,
            ]);
        }

        $this->mergeHostsFromEnv();
        $this->defaultStatefulDomains();
        $this->defaultGate();
        $this->hideHostCredentials();

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
        $raw = trim((string) config('log-viewer-ext.hosts'));

        if ($raw === '') {
            return;
        }

        $hosts = config('log-viewer.hosts', []);
        $secret = (string) config('log-viewer-ext.shared_secret');

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

    /**
     * Vendor đưa nguyên Host (cả `auth`, `headers`) vào `window.LogViewer` và `/api/hosts`.
     * Bỏ đi ở đây; ai cần credential thật thì đọc qua HostCredentials.
     *
     * Vendor chỉ giữ MỘT resolver: app tự gọi `LogViewer::resolveHostsUsing()` sau đó là
     * ghi đè mất bản vá này.
     */
    private function hideHostCredentials(): void
    {
        LogViewer::resolveHostsUsing(
            static fn (HostCollection $hosts): HostCollection => $hosts->map(
                static fn (Host $host): Host => HostCredentials::strip($host)
            )
        );
    }

    private function bearerMatchesSharedSecret(Request $request): bool
    {
        $secret = (string) config('log-viewer-ext.shared_secret');
        $bearer = (string) $request->bearerToken();

        return $secret !== '' && $bearer !== '' && hash_equals($secret, $bearer);
    }
}
