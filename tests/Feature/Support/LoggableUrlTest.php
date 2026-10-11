<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\Support;

use HocVT\LogViewerRemote\Support\LoggableUrl;
use HocVT\LogViewerRemote\Tests\Feature\TestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class LoggableUrlTest extends TestCase
{
    private const string STATS_TOKEN = 'aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5bC7d';

    public function test_masks_sensitive_route_parameter_but_keeps_ids(): void
    {
        $request = $this->routed('affiliate/s/{stats_token}', '/affiliate/s/'.self::STATS_TOKEN);
        $this->assertSame('https://vocamax.test/affiliate/s/{stats_token}', LoggableUrl::fromRequest($request));

        $request = $this->routed('video/{id}/{slug?}', '/video/123/shape-of-you');
        $this->assertSame('https://vocamax.test/video/123/shape-of-you', LoggableUrl::fromRequest($request));

        $request = $this->routed('video/{id}/{slug?}', '/video/123');
        $this->assertSame('https://vocamax.test/video/123', LoggableUrl::fromRequest($request));
    }

    public function test_masks_sensitive_query_values_and_keeps_the_rest(): void
    {
        // Link reset mật khẩu redirect sang đây (routes/localized.php, password.reset).
        $request = $this->routed('auth', '/auth?tab=reset_password_confirm&token=abc123&email=a@b.c&page=2');

        $this->assertSame(
            'https://vocamax.test/auth?tab=reset_password_confirm&token=***&email=***&page=2',
            LoggableUrl::fromRequest($request)
        );
    }

    public function test_masks_nested_query_like_baokim_callback(): void
    {
        $request = $this->routed('baokim/success', '/baokim/success?mrc_order_id=77&signature[data][token]=eyJ&signature[data][signature]=zz');

        $this->assertSame('https://vocamax.test/baokim/success?mrc_order_id=77&signature=***', LoggableUrl::fromRequest($request));
    }

    public function test_sensitive_key_matching(): void
    {
        foreach (['token', 'stats_token', 'rememberToken', 'API_KEY', 'apiKey', 'user_email', 'code', 'X-Signature', 'password_confirmation'] as $key) {
            $this->assertTrue(LoggableUrl::isSensitiveKey($key), $key);
        }

        // Chứa từ nhạy cảm như một phần của từ khác, hoặc quá chung chung để che theo đoạn.
        foreach (['id', 'page', 'tab', 'language_code', 'key', 'group', 'tokenizer_mode', 'passage'] as $key) {
            $this->assertFalse(LoggableUrl::isSensitiveKey($key), $key);
        }
    }

    public function test_referer_masks_token_looking_path_segments_and_drops_fragment(): void
    {
        $this->assertSame(
            'https://vocamax.test/affiliate/s/***?tab=1',
            LoggableUrl::fromString('https://vocamax.test/affiliate/s/'.self::STATS_TOKEN.'?tab=1#access_token=xyz')
        );

        $this->assertSame(
            'https://vocamax.test/auth?tab=reset_password_confirm&token=***&email=***',
            LoggableUrl::fromString('https://vocamax.test/auth?tab=reset_password_confirm&token=abc&email=a%40b.c')
        );

        // Slug có số và id số không bị nhầm là token.
        $this->assertSame(
            'https://vocamax.test/video/123/top-10-songs-2024-for-learning-english',
            LoggableUrl::fromString('https://vocamax.test/video/123/top-10-songs-2024-for-learning-english')
        );

        $this->assertNull(LoggableUrl::fromString(null));
        $this->assertNull(LoggableUrl::fromString(''));
    }

    public function test_request_without_route_falls_back_to_token_heuristic(): void
    {
        $request = Request::create('https://vocamax.test/password/reset/'.str_repeat('ab12', 16));

        $this->assertSame('https://vocamax.test/password/reset/***', LoggableUrl::fromRequest($request));
    }

    private function routed(string $uri, string $path, array $server = []): Request
    {
        $request = Request::create('https://vocamax.test'.$path, 'GET', server: $server);
        $route = (new Route('GET', $uri, fn () => null))->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}
