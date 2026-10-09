<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Unit\Agent;

use HocVT\LogViewerRemote\Agent\Scan\Budget;
use HocVT\LogViewerRemote\Agent\Scan\EntryReader;
use HocVT\LogViewerRemote\Agent\Scan\SlowLogParser;
use HocVT\LogViewerRemote\Agent\Scan\SlowLogRecord;
use HocVT\LogViewerRemote\Agent\Scan\Window;
use HocVT\LogViewerRemote\Tests\Support\LogBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Định dạng mới (context máy đọc) và định dạng cũ (chỉ có chữ, kể cả `[mysql]` của các app
 * tự viết SqlLogger cùng dòng). Test khớp với SqlLogger thật nằm ở app.
 */
class SlowLogParserTest extends TestCase
{
    protected function tearDown(): void
    {
        LogBuilder::cleanup();
    }

    private function parse(LogBuilder $log): ?SlowLogRecord
    {
        $path = $log->write();
        $entries = iterator_to_array((new EntryReader($path, 'x.log'))->read(0, Window::all(), Budget::unlimited()), false);

        return (new SlowLogParser)->parse($entries[0]);
    }

    public function test_new_summary_reads_context_first(): void
    {
        $record = $this->parse((new LogBuilder)->entry(
            '2026-10-06 07:00:00',
            'WARNING',
            "[WEB][1.2.3.4][https://site.test/video/1?filter[a]=1] Quá nhiều query [gt50]: 63 query + Nghi ngờ N+1: 1 query lặp 10000x\n"
            ."  63 query / 812ms / 3 query khác nhau\n"
            ."   x10000  100000ms [crdb] select * from \"vocabularies\" where \"id\" = ?\n"
            ."          1234567ms [crdb] select 1\n"
            ."     x2         3ms [mysql] select * from `t` where `id` in (?, ...)\n"
            .'  ... còn 4 query khác',
            [
                'slow_log' => 'summary', 'reasons' => ['total', 'duplicate'], 'total' => 63, 'total_ms' => 812.5,
                'worst_duplicate' => 10000, 'req.url' => 'https://site.test/video/1?filter[a]=1', 'req.route' => '/video/{id}',
                'graphql' => ['operation' => 'query', 'name' => 'GetVideo'],
            ],
        ));

        $this->assertSame('summary', $record->kind);
        $this->assertSame('WEB', $record->scope);
        $this->assertSame(['total', 'duplicate'], $record->reasons);
        $this->assertSame(63, $record->total);
        $this->assertSame(812.5, $record->totalMs);
        $this->assertSame(10000, $record->worstDuplicate);
        $this->assertSame('/video/{id}', $record->route);
        $this->assertSame('https://site.test/video/1?filter[a]=1', $record->url);
        $this->assertSame([
            ['count' => 10000, 'ms' => 100000.0, 'connection' => 'crdb', 'sql' => 'select * from "vocabularies" where "id" = ?'],
            ['count' => 1, 'ms' => 1234567.0, 'connection' => 'crdb', 'sql' => 'select 1'],
            ['count' => 2, 'ms' => 3.0, 'connection' => 'mysql', 'sql' => 'select * from `t` where `id` in (?, ...)'],
        ], $record->groups);
    }

    public function test_new_slow_query_with_multiline_sql_and_trace(): void
    {
        $record = $this->parse((new LogBuilder)->entry(
            '2026-10-06 07:00:00',
            'ALERT',
            "[CLI][App\\Jobs\\SyncVideo]\n402ms [crdb] select *\n  from \"videos\"\n  where \"id\" = ?\n  app/Jobs/SyncVideo.php:42\n  artisan:13",
            ['slow_log' => 'query', 'ms' => 402.37, 'connection' => 'crdb', 'cli.context' => 'App\\Jobs\\SyncVideo'],
        ));

        $this->assertSame('query', $record->kind);
        $this->assertSame('CLI', $record->scope);
        $this->assertSame('App\\Jobs\\SyncVideo', $record->command);
        $this->assertSame(402.37, $record->ms);
        $this->assertSame("select *\n  from \"videos\"\n  where \"id\" = ?", $record->sql);
    }

    public function test_old_summary_without_context_markers(): void
    {
        // Định dạng cũ: `%4s%6s`, context chỉ có số liệu, URL nằm trong nhãn, dấu cách cuối dòng.
        $record = $this->parse((new LogBuilder)->entry(
            '2026-10-03 15:45:30',
            'WARNING',
            "[WEB][14.241.36.242][https://gitiho.test/khoa-hoc/abc] Quá nhiều query [gt100]: 1155 query + Tổng thời gian query 40910ms > 200ms + Nghi ngờ N+1: 1 query lặp 1155x\n"
            ."  1155 query / 40910ms / 2 query khác nhau\n"
            ."   x19    21ms [mysql] select * from `lessons` where `id` = ?\n"
            .'  x1155 40910ms [mysql] select * from `jobs` limit 1 FOR UPDATE SKIP LOCKED',
            ['total' => 1155, 'total_ms' => 40909.68, 'total_class' => 'gt100', 'unique_queries' => 2],
        ));

        $this->assertSame('summary', $record->kind);
        $this->assertSame('https://gitiho.test/khoa-hoc/abc', $record->url);
        $this->assertSame(['total', 'total_ms', 'duplicate'], $record->reasons);
        $this->assertSame(1155, $record->worstDuplicate);
        $this->assertSame('select * from `jobs` limit 1 FOR UPDATE SKIP LOCKED', $record->groups[1]['sql']);
        $this->assertSame(1155, $record->groups[1]['count']);
    }

    public function test_old_cli_alert_without_context(): void
    {
        $record = $this->parse((new LogBuilder)->entry('2026-10-03 15:45:30', 'ALERT', "[CLI]\n60076ms [crdb] select * from \"jobs\" where x = ARRAY[1,2]"));

        $this->assertSame('query', $record->kind);
        $this->assertNull($record->command);
        $this->assertSame(60076.0, $record->ms);
        $this->assertSame('select * from "jobs" where x = ARRAY[1,2]', $record->sql);
    }

    public function test_other_entries_are_not_slow_log(): void
    {
        $this->assertNull($this->parse((new LogBuilder)->entry('2026-10-03 15:45:30', 'ERROR', '[WEB] something else', ['a' => 1])));
        $this->assertNull($this->parse((new LogBuilder)->entry('2026-10-03 15:45:30', 'ERROR', 'boom', ['exception' => 'x'])));
    }
}
