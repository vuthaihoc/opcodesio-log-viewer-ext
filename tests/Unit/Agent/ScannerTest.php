<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Unit\Agent;

use HocVT\LogViewerRemote\Agent\Scan\Budget;
use HocVT\LogViewerRemote\Agent\Scan\Normalizer;
use HocVT\LogViewerRemote\Agent\Scan\ScanFile;
use HocVT\LogViewerRemote\Agent\Scan\Scanner;
use HocVT\LogViewerRemote\Agent\Scan\Window;
use HocVT\LogViewerRemote\Tests\Support\LogBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ScannerTest extends TestCase
{
    protected function tearDown(): void
    {
        LogBuilder::cleanup();
    }

    /** Hai ngày log có đủ loại entry: lỗi, slow log WEB / CLI cả định dạng mới lẫn cũ. */
    private function files(): array
    {
        $files = [];

        foreach (['2026-10-05', '2026-10-06'] as $day) {
            $log = new LogBuilder;

            for ($i = 0; $i < 300; $i++) {
                $time = sprintf('%s %02d:%02d:%02d', $day, intdiv($i, 60) % 24, $i % 60, $i % 60);
                $page = ['/video/{id}', '/learn/{slug}', '/graphql'][$i % 3];
                $repeat = 2 + $i % 7;

                $log->entry($time, 'ERROR', "Call to undefined method on line {$i}", ['exception' => "[object] (Error: x)\n[stacktrace]\n#0 {main}"]);
                $log->entry($time, 'WARNING', "[WEB][1.2.3.4][https://site.test/p/{$i}] Quá nhiều query [gt30]: 40 query + Nghi ngờ N+1: 1 query lặp {$repeat}x\n"
                    ."  40 query / 900ms / 2 query khác nhau\n"
                    .sprintf("  %5s %7sms [crdb] %s\n", 'x'.$repeat, 10 * $repeat, 'select * from "words" where "id" = ?')
                    .sprintf('  %5s %7sms [crdb] %s', '', 5, 'select * from "users" where "id" = ? limit 1'),
                    ['slow_log' => 'summary', 'reasons' => ['total', 'duplicate'], 'total' => 40, 'total_ms' => 900.0, 'req.route' => $page, 'graphql' => ['name' => "Op{$i}"]]);
                $log->entry($time, 'ALERT', "[CLI][job:{$i}]\n".(300 + $i)."ms [crdb] select * from \"jobs\" where \"id\" = {$i}\n  app/Jobs/X.php:10",
                    ['slow_log' => 'query', 'ms' => 300.0 + $i, 'connection' => 'crdb', 'cli.context' => 'job:'.($i % 4)]);
            }

            // Định dạng cũ, không có context đánh dấu.
            $log->entry("{$day} 23:59:59", 'WARNING', "[CLI][queue:work] Quá nhiều query [gt100]: 1155 query + Nghi ngờ N+1: 1 query lặp 1155x\n  1155 query / 40910ms / 1 query khác nhau\n  x1155 40910ms [crdb] select * from \"jobs\" limit 1", ['total' => 1155, 'total_ms' => 40910.0]);

            $path = $log->write("laravel-{$day}.log");
            $files[] = ScanFile::at($path, "laravel-{$day}.log");
        }

        return $files;
    }

    private function scanner(): Scanner
    {
        return Scanner::make(new Normalizer(pageKeys: ['req.route']), cap: 50);
    }

    public function test_chunked_scan_with_cursor_equals_one_pass(): void
    {
        $files = $this->files();
        $window = new Window('2026-10-05 01:00:00', '2026-10-06 03:00:00');

        $once = $this->scanner()->run($files, $window, Budget::unlimited());
        $this->assertTrue($once->complete);

        $cursor = null;
        $rounds = 0;

        do {
            $rounds++;
            // Mỗi request dựng Scanner mới; cursor đi qua cache (serialize) như ở HTTP.
            $result = $this->scanner()->run($files, $window, new Budget(maxBytes: 20000), $cursor);
            $cursor = $result->cursor === null ? null : unserialize(serialize($result->cursor));
        } while (! $result->complete);

        $this->assertGreaterThan(5, $rounds);
        $this->assertSame($once->results(1000), $result->results(1000));
        $this->assertSame($once->entries, $result->entries);
        $this->assertSame(100.0, $result->percent);
    }

    public function test_numbers_add_up_across_files_and_formats(): void
    {
        $results = $this->scanner()->run($this->files(), Window::all(), Budget::unlimited())->results(10);

        $this->assertSame(1802, $results['levels']['entries']);
        $this->assertSame(['WARNING' => 602, 'ERROR' => 600, 'ALERT' => 600], $results['levels']['levels']);

        // Mỗi trang 200 lượt tổng kết × 40 query.
        $pages = array_column($results['pages']['rows'], null, 'key');
        $this->assertSame(200, $pages['/video/{id}']['summaries']);
        $this->assertSame(8000, $pages['/video/{id}']['queries']);

        // Σ(xN − 1): mỗi ngày i = 0..299 có repeat = 2 + i % 7; cộng log cũ 1154/ngày.
        $waste = array_column($results['sql_waste']['rows'], null, 'key');
        $expected = 2 * array_sum(array_map(fn (int $i) => 1 + $i % 7, range(0, 299)));
        $this->assertSame($expected, $waste['select * from "words" where "id" = ?']['waste']);
        $this->assertSame(2 * 1154, $waste['select * from "jobs" limit ?']['waste']);

        $commands = array_column($results['commands']['rows'], null, 'key');
        $this->assertSame(2, $commands['queue:work']['runs']);
        $this->assertSame(150, $commands['job:0']['slow_queries']);

        $this->assertSame(600, $results['slow_queries']['rows'][0]['n']);
        $this->assertSame('select * from "jobs" where "id" = ?', $results['slow_queries']['rows'][0]['key']);
    }

    public function test_only_selected_aggregators_and_unknown_names(): void
    {
        $result = Scanner::make(new Normalizer, ['levels'])->run($this->files(), Window::all(), Budget::unlimited());

        $this->assertSame(['levels'], array_keys($result->results(5)));

        $this->expectException(InvalidArgumentException::class);
        Scanner::make(new Normalizer, ['levels', 'nope']);
    }
}
