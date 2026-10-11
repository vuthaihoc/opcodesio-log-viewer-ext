<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\LogViewer;

use HocVT\LogViewerRemote\LogViewerRemoteServiceProvider;
use HocVT\LogViewerRemote\Tests\Feature\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Nút Download của Log Viewer gọi `axios.get(download_url + '/request')`. Với host ở
 * xa, download_url do chính host đó sinh nên là URL khác origin → CORS chặn. Các test
 * dưới khoá lại đường đi đã sửa: URL được viết lại về origin hiện tại, và bước xin
 * link ký chạy server-to-server bằng shared secret.
 */
class RemoteDownloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['log-viewer.hosts.fake' => [
            'name' => 'Fake',
            'host' => 'https://logs.example.com/log-viewer',
            'auth' => ['token' => 'shared-secret'],
        ]]);
    }

    /**
     * `*` phải có: middleware TrustProxies của monicahq tự gọi ra cloudflare.com lấy
     * dải IP, không fake thì test đi mạng thật và lọt vào các assertion của Http.
     */
    private function fakeRemote(array $response): void
    {
        Http::fake([
            'logs.example.com/*' => Http::response($response),
            '*' => Http::response(''),
        ]);
    }

    private function remoteRequests(): array
    {
        return Http::recorded(
            fn (Request $request) => str_contains($request->url(), 'logs.example.com')
        )->map(fn (array $pair): Request => $pair[0])->values()->all();
    }

    public function test_remote_download_url_is_rewritten_to_current_origin(): void
    {
        $this->fakeRemote([
            [
                'identifier' => 'abc123',
                'name' => 'laravel.log',
                'download_url' => 'https://logs.example.com/log-viewer/api/files/abc123/download',
                'can_download' => true,
            ],
        ]);

        $this->actingAs($this->admin())
            ->getJson($this->lv('/api/files?host=fake'))
            ->assertOk()
            ->assertJsonPath('0.download_url', url($this->lv('/remote/fake/api/files/abc123/download')));
    }

    /**
     * Bước `/request` phải trả URL local: trình duyệt không có session trên host xa
     * nên mở thẳng link đã ký của host đó sẽ ăn 403 từ AuthorizeLogViewer.
     */
    public function test_request_download_returns_a_local_url(): void
    {
        $this->fakeRemote([]);

        $this->actingAs($this->admin())
            ->getJson($this->lv('/remote/fake/api/files/abc123/download/request'))
            ->assertOk()
            ->assertJsonPath('url', url($this->lv('/remote/fake/api/files/abc123/download')));

        $this->assertSame([], $this->remoteRequests());
    }

    public function test_download_streams_remote_file_through_current_host(): void
    {
        Http::fake([
            'logs.example.com/log-viewer/api/files/abc123/download/request' => Http::response([
                'url' => 'https://logs.example.com/log-viewer/api/files/abc123/download?signature=deadbeef',
            ]),
            'logs.example.com/log-viewer/api/files/abc123/download*' => Http::response('nội dung log', 200, [
                'Content-Type' => 'text/plain',
                'Content-Disposition' => 'attachment; filename=laravel.log',
            ]),
            '*' => Http::response(''),
        ]);

        $response = $this->actingAs($this->admin())
            ->get($this->lv('/remote/fake/api/files/abc123/download'));

        $response->assertOk();
        $this->assertSame('nội dung log', $response->streamedContent());
        $this->assertStringContainsString('laravel.log', $response->headers->get('Content-Disposition') ?? '');

        $sent = $this->remoteRequests();

        $this->assertCount(2, $sent);
        $this->assertSame(
            'https://logs.example.com/log-viewer/api/files/abc123/download/request',
            $sent[0]->url()
        );
        $this->assertSame(
            'https://logs.example.com/log-viewer/api/files/abc123/download?signature=deadbeef',
            $sent[1]->url()
        );

        foreach ($sent as $request) {
            $this->assertSame('Bearer shared-secret', $request->header('Authorization')[0] ?? null);
            // Host ở xa phải ký URL bằng domain của chính nó, nên không được forward host hiện tại.
            $this->assertSame([], $request->header('X-Forwarded-Host'));
        }
    }

    public function test_guest_cannot_request_remote_download(): void
    {
        $this->fakeRemote([]);

        $this->getJson($this->lv('/remote/fake/api/files/abc123/download/request'))->assertForbidden();

        $this->assertSame([], $this->remoteRequests());
    }

    public function test_unknown_host_is_not_proxied(): void
    {
        $this->fakeRemote([]);

        $this->actingAs($this->admin())
            ->getJson($this->lv('/remote/nope/api/files/abc123/download/request'))
            ->assertNotFound();

        $this->assertSame([], $this->remoteRequests());
    }

    /**
     * LOG_VIEWER_HOSTS điền host chưa có vào log-viewer.hosts, không đè host khai tay.
     */
    public function test_hosts_from_env_are_merged_without_overriding_explicit_config(): void
    {
        config([
            'log-viewer-ext.shared_secret' => 'shared-secret',
            'log-viewer-ext.hosts' => 'fake=https://ignored.example.com/log-viewer, extra=https://extra.example.com/log-viewer/',
        ]);

        // Chạy lại boot() của package với config mới.
        $this->app->register(LogViewerRemoteServiceProvider::class, force: true);

        $hosts = config('log-viewer.hosts');

        $this->assertSame('https://logs.example.com/log-viewer', $hosts['fake']['host']);
        $this->assertSame([
            'name' => 'Extra',
            'host' => 'https://extra.example.com/log-viewer',
            'auth' => ['token' => 'shared-secret'],
        ], $hosts['extra']);
    }

    /**
     * Khai thiếu đường dẫn Log Viewer là lỗi dễ mắc nhất và chỉ lộ ra thành 404 lúc
     * chọn host, vì middleware forward nối thẳng `/api/files` vào sau URL.
     */
    public function test_host_url_without_a_path_gets_the_log_viewer_route_path_appended(): void
    {
        config(['log-viewer-ext.hosts' => 'bare=http://site.example.com/, full=http://site.example.com/custom-logs']);

        $this->app->register(LogViewerRemoteServiceProvider::class, force: true);

        $hosts = config('log-viewer.hosts');

        $this->assertSame('http://site.example.com/log-viewer', $hosts['bare']['host']);
        $this->assertSame('http://site.example.com/custom-logs', $hosts['full']['host']);
    }
}
