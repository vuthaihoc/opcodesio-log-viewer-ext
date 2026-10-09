<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Unit\Agent;

use HocVT\LogViewerRemote\Agent\Scan\Entry;
use HocVT\LogViewerRemote\Agent\Scan\Normalizer;
use HocVT\LogViewerRemote\Agent\Scan\SlowLogRecord;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    private function entry(string $first): Entry
    {
        return new Entry('x.log', 0, 1, '2026-10-06 07:00:00', 'production', 'ERROR', $first, $first, false);
    }

    /** @param array<string, mixed> $context */
    private function record(array $context, ?string $url = null, ?string $referer = null, string $scope = 'WEB'): SlowLogRecord
    {
        return new SlowLogRecord('summary', $scope, $url, $context['req.route'] ?? null, $referer, null, [], 1, 1.0, 1, [], null, null, null, $context);
    }

    public function test_message_merges_numbers_ids_emails_and_tokens(): void
    {
        $n = new Normalizer;

        $a = $n->message($this->entry('Nghi ngờ N+1: 1 query lặp 24x cho user 123 (a@b.co) token aB3dE5fG7hJ9kL1mN3pQ5rS7tU9 {"id":5}'), null);
        $b = $n->message($this->entry('Nghi ngờ N+1: 1 query lặp 15x cho user 9 (x.y@z.vn) token zZ9yY8xX7wW6vV5uU4tT3sS2rR1 {"id":6}'), null);

        $this->assertSame($a, $b);
        $this->assertSame('Nghi ngờ N+<n>: <n> query lặp <n>x cho user <n> (<email>) token <token>', $a);
        // Không đụng số dính sau chữ (tên lớp, version).
        $this->assertSame('Base64 v2 md5 <n>', $n->message($this->entry('Base64 v2 md5 42'), null));
        $this->assertSame('chương <n> hash <hex>', $n->message($this->entry('chương 1129548723095175169 hash 9f86d081884c7d659a2feaa0c55ad015'), null));
    }

    public function test_message_drops_the_start_of_a_multiline_context(): void
    {
        $this->assertSame(
            'There is already an active transaction',
            (new Normalizer)->message($this->entry('There is already an active transaction {"exception":"[object] (PDOException(code: 0): x at /app/C.php:1664)'), null),
        );
    }

    public function test_message_of_slow_log_entries_uses_sql_or_reasons(): void
    {
        $n = new Normalizer;
        $query = new SlowLogRecord('query', 'WEB', null, null, null, null, [], null, null, null, [], 9.0, 'crdb', "select * from t where id = 5 and name = 'x'", []);

        $this->assertSame('slow query: select * from t where id = ? and name = ?', $n->message($this->entry('[WEB][ip][url]'), $query));
    }

    public function test_sql_literals_and_lists_collapse(): void
    {
        $n = new Normalizer;

        $this->assertSame(
            'select * from "t1" where "id" in (?) and "x" = ? and "y" = ? limit ?',
            $n->sql("select *  from \"t1\"\n where \"id\" in (?, ?, ?) and \"x\" = 'it''s' and \"y\" = 3.5 limit 10"),
        );
        $this->assertSame('insert into "t" ("a", "b") values (?), ...', $n->sql('insert into "t" ("a", "b") values (?, ?), (?, ?), (?, ?)'));
    }

    public function test_url_replaces_ids_and_tokens_and_applies_groups(): void
    {
        $n = new Normalizer(['#^/khoa-hoc/[^/]+$#' => '/khoa-hoc/{slug}']);

        $this->assertSame('/video/{id}/shape-of-you', $n->url('https://site.test/video/123/shape-of-you?t=5'));
        $this->assertSame('/s/{token}', $n->url('/s/aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY'));
        $this->assertSame('/khoa-hoc/{slug}', $n->url('https://gitiho.test/khoa-hoc/excel-co-ban'));
    }

    public function test_route_resolver_wins_over_heuristics(): void
    {
        $n = new Normalizer(routeOf: fn (string $url) => str_starts_with((string) parse_url($url, PHP_URL_PATH), '/learn/video/') ? '/learn/video/{slug}/{id}' : null);

        $this->assertSame('/learn/video/{slug}/{id}', $n->url('https://site.test/learn/video/let-it-go/vjalve7bkba8v'));
        $this->assertSame('/about', $n->url('https://site.test/about'));
    }

    public function test_page_key_order_flat_then_nested_and_livewire_referer(): void
    {
        $n = new Normalizer(pageKeys: ['graphql.name', 'req.route']);

        $this->assertSame('graphql.name=GetToeicExams', $n->page($this->record(['req.route' => '/graphql', 'graphql' => ['name' => 'GetToeicExams']])));
        $this->assertSame('/video/{id}', $n->page($this->record(['req.route' => '/video/{id}'])));
        // Key phẳng có dấu chấm thắng đường đi theo cây.
        $this->assertSame('graphql.name=flat', $n->page($this->record(['graphql.name' => 'flat', 'graphql' => ['name' => 'nested']])));
        $this->assertSame(
            'livewire ← /learn/{id}',
            $n->page($this->record(['req.route' => '/livewire-4b6b8091/update'], referer: 'https://site.test/learn/42')),
        );
        $this->assertSame('/old/{id}', $n->page($this->record([], url: 'https://site.test/old/7')));
    }

    public function test_mask_secrets_keeps_ids(): void
    {
        $this->assertSame(
            'user 1129548723095175169 <email> token <token> slug let-it-go',
            Normalizer::maskSecrets('user 1129548723095175169 a.b@c.vn token aB3dE5fG7hJ9kL1mN3pQ5rS7tU9 slug let-it-go'),
        );
    }

    public function test_keys_are_cut_and_valid_utf8(): void
    {
        $key = (new Normalizer(maxKeyLength: 10))->message($this->entry("lỗi lỗi lỗi lỗi \xFF"), null);

        $this->assertTrue(mb_check_encoding($key, 'UTF-8'));
        $this->assertLessThanOrEqual(14, strlen($key));
    }
}
