<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\LogViewer;

use HocVT\LogViewerRemote\Tests\Feature\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Vendor đưa nguyên Host (kể cả `auth` = shared secret) vào `window.LogViewer` và
 * `/api/hosts`. Package bỏ credential khỏi Host trả ra ngoài, tự gắn lại khi gọi sang host xa.
 */
class HostSecretTest extends TestCase
{
    private const SECRET = 'very-secret-token-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config(['log-viewer.hosts.fake' => [
            'name' => 'Fake',
            'host' => 'https://logs.example.com/log-viewer',
            'headers' => ['X-Host-Key' => 'header-secret'],
            'auth' => ['token' => self::SECRET],
        ]]);
    }

    /** `*` phải có: TrustProxies của monicahq tự gọi ra cloudflare.com. */
    private function fakeRemote(mixed $body = [], int $status = 200, array $headers = []): void
    {
        Http::fake([
            'logs.example.com/*' => Http::response($body, $status, $headers),
            '*' => Http::response(''),
        ]);
    }

    /** @return list<Request> */
    private function remoteRequests(): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'logs.example.com'))
            ->map(fn (array $pair): Request => $pair[0])->values()->all();
    }

    public function test_hosts_api_and_index_page_do_not_expose_credentials(): void
    {
        $admin = $this->admin();

        $hosts = $this->actingAs($admin)->getJson($this->lv('/api/hosts'))->assertOk();
        $hosts->assertDontSee(self::SECRET)->assertDontSee('header-secret');

        $fake = collect($hosts->json('data'))->firstWhere('identifier', 'fake');
        $this->assertSame([], $fake['auth']);
        $this->assertSame([], $fake['headers']);
        $this->assertTrue($fake['is_remote']);

        $this->actingAs($admin)->get($this->lv(''))->assertOk()
            ->assertDontSee(self::SECRET)->assertDontSee('header-secret');
    }

    public function test_forwarding_still_sends_credentials_and_forwarded_headers(): void
    {
        $this->fakeRemote([]);

        $this->actingAs($this->admin())->getJson($this->lv('/api/files?host=fake&direction=desc'))->assertOk();

        [$sent] = $this->remoteRequests();

        $this->assertSame('https://logs.example.com/log-viewer/api/files?direction=desc', $sent->url());
        $this->assertSame('Bearer '.self::SECRET, $sent->header('Authorization')[0] ?? null);
        $this->assertSame('header-secret', $sent->header('X-Host-Key')[0] ?? null);
        $this->assertNotSame([], $sent->header('X-Forwarded-Host'));
    }

    public function test_forwarding_passes_retry_after_through(): void
    {
        $this->fakeRemote(['message' => 'busy'], 429, ['Retry-After' => '7']);

        $this->actingAs($this->admin())->getJson($this->lv('/api/files?host=fake'))
            ->assertStatus(429)
            ->assertHeader('Retry-After', '7');
    }

    public function test_local_host_is_not_forwarded(): void
    {
        $this->fakeRemote([]);

        $this->actingAs($this->admin())->getJson($this->lv('/api/files?host=local'))->assertOk();

        $this->assertSame([], $this->remoteRequests());
    }

    public function test_check_command_still_authenticates(): void
    {
        $this->fakeRemote([]);

        $this->artisan('log-viewer-remote:check', ['host' => 'fake'])->assertSuccessful();

        [$sent] = $this->remoteRequests();
        $this->assertSame('Bearer '.self::SECRET, $sent->header('Authorization')[0] ?? null);
    }
}
