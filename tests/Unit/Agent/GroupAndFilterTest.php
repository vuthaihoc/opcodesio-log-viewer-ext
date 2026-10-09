<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Unit\Agent;

use HocVT\LogViewerRemote\Agent\Scan\Aggregators\Grouped;
use HocVT\LogViewerRemote\Agent\Scan\Budget;
use HocVT\LogViewerRemote\Agent\Scan\Entry;
use HocVT\LogViewerRemote\Agent\Scan\EntryFilter;
use HocVT\LogViewerRemote\Agent\Scan\Normalizer;
use HocVT\LogViewerRemote\Agent\Scan\ScanFile;
use HocVT\LogViewerRemote\Agent\Scan\Scanner;
use HocVT\LogViewerRemote\Agent\Scan\Window;
use HocVT\LogViewerRemote\Tests\Support\LogBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Lọc entry + gom theo regex do agent gửi. */
class GroupAndFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        LogBuilder::cleanup();
    }

    private function entry(string $level, string $first, ?string $text = null): Entry
    {
        return new Entry('x.log', 0, 1, '2026-10-05 01:00:00', 'production', $level, $first, $text ?? $first, false);
    }

    public function test_filter_by_level_contains_and_regex_on_first_line_or_text(): void
    {
        $entry = $this->entry('ERROR', 'Job failed', "Job failed\n#0 App\\Jobs\\SyncVideo->handle()");

        $this->assertTrue((new EntryFilter(levels: ['error']))->matches($entry));
        $this->assertFalse((new EntryFilter(levels: ['warning']))->matches($entry));
        $this->assertTrue((new EntryFilter(contains: 'JOB FAILED'))->matches($entry));
        $this->assertFalse((new EntryFilter(regex: 'SyncVideo'))->matches($entry));
        $this->assertTrue((new EntryFilter(regex: 'SyncVideo', wholeText: true))->matches($entry));
        $this->assertTrue((new EntryFilter(regex: 'a~b|failed'))->matches($entry), 'dấu ~ trong regex không phá dấu phân cách');

        $this->assertFalse((new EntryFilter(regex: 'x'))->needsText());
        $this->assertTrue((new EntryFilter(regex: 'x', wholeText: true))->needsText());
        $this->assertTrue((new EntryFilter)->isEmpty());

        $this->expectException(InvalidArgumentException::class);
        new EntryFilter(regex: '(unclosed');
    }

    public function test_group_key_from_named_key_or_unnamed_groups_and_sum(): void
    {
        $named = new Grouped('Job (?<key>\S+) failed after (?<sum>\d+)ms');
        $named->consume($this->entry('ERROR', 'Job Sync failed after 120ms'), null);
        $named->consume($this->entry('ERROR', 'Job Sync failed after 30ms'), null);
        $named->consume($this->entry('WARNING', 'Job Mail failed after 5ms'), null);
        $named->consume($this->entry('ERROR', 'unrelated'), null);

        $rows = array_column($named->result(10)['rows'], null, 'key');
        $this->assertSame(2, $rows['Sync']['n']);
        $this->assertSame(150.0, $rows['Sync']['sum']);
        $this->assertSame(120.0, $rows['Sync']['max']);
        $this->assertSame(['WARNING' => 1], $rows['Mail']['levels']);

        // Nhóm không tên nối bằng ` | `; nhóm có tên (sum) không lọt vào khoá.
        $unnamed = new Grouped('(GET|POST) (/\S+) (?<sum>\d+)ms');
        $unnamed->consume($this->entry('INFO', 'POST /api/x 12ms'), null);
        $this->assertSame('POST | /api/x', $unnamed->result(1)['rows'][0]['key']);

        // Không nhóm nào: cả đoạn khớp; email bị che.
        $whole = new Grouped('user \S+@\S+');
        $whole->consume($this->entry('INFO', 'login user a@b.co ok'), null);
        $this->assertSame('user <email>', $whole->result(1)['rows'][0]['key']);
    }

    public function test_scanner_filters_before_every_table_and_chunks_equal_one_pass(): void
    {
        $log = new LogBuilder;
        for ($i = 0; $i < 400; $i++) {
            $log->entry(sprintf('2026-10-05 %02d:%02d:00', intdiv($i, 60), $i % 60), $i % 3 === 0 ? 'ERROR' : 'INFO',
                $i % 3 === 0 ? 'Job '.(['Sync', 'Mail'][$i % 2]).' failed after '.$i."ms\n#0 stack" : "ok {$i}");
        }
        $file = ScanFile::at($log->write());

        $scan = fn (?array $cursor, Budget $budget) => Scanner::make(
            new Normalizer, ['levels', 'group'],
            filter: new EntryFilter(levels: ['error']),
            group: 'Job (?<key>\w+) failed after (?<sum>\d+)ms',
        )->run([$file], Window::all(), $budget, $cursor);

        $once = $scan(null, Budget::unlimited());
        $this->assertSame(134, $once->entries);
        $this->assertSame(400, $once->scannedEntries);
        $this->assertSame(['ERROR' => 134], $once->results(5)['levels']['levels']);
        $this->assertSame('all', $once->results(5)['group']['applies_to']);

        $cursor = null;
        do {
            $result = $scan($cursor, new Budget(maxBytes: 3000));
            $cursor = $result->cursor === null ? null : unserialize(serialize($result->cursor));
        } while (! $result->complete);

        $this->assertSame($once->results(50), $result->results(50));
    }

    public function test_only_group_without_a_regex_is_rejected(): void
    {
        $this->expectExceptionMessage('only=group cần tham số group');

        Scanner::make(new Normalizer, ['group']);
    }
}
