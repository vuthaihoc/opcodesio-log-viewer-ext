<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Unit\Agent;

use HocVT\LogViewerRemote\Agent\Scan\Budget;
use HocVT\LogViewerRemote\Agent\Scan\Entry;
use HocVT\LogViewerRemote\Agent\Scan\EntryReader;
use HocVT\LogViewerRemote\Agent\Scan\Window;
use HocVT\LogViewerRemote\Tests\Support\LogBuilder;
use PHPUnit\Framework\TestCase;

class EntryReaderTest extends TestCase
{
    protected function tearDown(): void
    {
        LogBuilder::cleanup();
    }

    /** @return list<Entry> */
    private function readAll(string $path, ?Window $window = null, ?callable $keepText = null, int $head = 65536, int $tail = 8192): array
    {
        $reader = new EntryReader($path, basename($path), $head, $tail);

        return iterator_to_array($reader->read(0, $window ?? Window::all(), Budget::unlimited(), $keepText), false);
    }

    public function test_splits_multiline_entries_by_header_and_covers_every_byte(): void
    {
        $log = (new LogBuilder)
            ->entry('2026-10-06 07:00:00', 'INFO', 'started', ['user_id' => 1])
            ->entry('2026-10-06 07:00:01', 'ERROR', 'boom', ['exception' => "[object] (Exception: boom)\n[stacktrace]\n#0 /app/x.php(1): f()\n#1 {main}"])
            ->entry('2026-10-06 07:00:02', 'WARNING', "[CLI][report] Quá nhiều query [gt30]: 40 query\n  40 query / 900ms / 2 query khác nhau\n    x39      800ms [crdb] select 1", ['slow_log' => 'summary'])
            ->raw("[2026-10-06T07:00:03.123456+07:00] local.DEBUG: iso header\r\n")
            ->raw('[2026-10-06 07:00:04] production.INFO: no trailing newline');
        $path = $log->write();

        $entries = $this->readAll($path);

        $this->assertSame(['INFO', 'ERROR', 'WARNING', 'DEBUG', 'INFO'], array_map(fn (Entry $e) => $e->level, $entries));
        $this->assertSame('2026-10-06 07:00:03', $entries[3]->datetime);
        $this->assertSame('local', $entries[3]->env);
        $this->assertSame('iso header', $entries[3]->firstLine);
        $this->assertSame('no trailing newline', $entries[4]->firstLine);

        // Offset + độ dài nối liền nhau và phủ hết file.
        $position = 0;
        foreach ($entries as $entry) {
            $this->assertSame($position, $entry->offset);
            $position += $entry->length;
        }
        $this->assertSame(strlen($log->content()), $position);

        // Context trải nhiều dòng: dòng đầu mang nửa đầu của nó.
        $this->assertStringStartsWith('boom {"exception":', $entries[1]->firstLine);
        $this->assertStringContainsString('#1 {main}', $entries[1]->text);
        $this->assertStringStartsWith('[CLI][report]', $entries[2]->firstLine);
        $this->assertCount(3, $entries[2]->lines());
    }

    public function test_bracket_lines_inside_an_entry_are_not_headers(): void
    {
        $path = (new LogBuilder)
            ->entry('2026-10-06 07:00:00', 'ERROR', "dump\n[2026-10-06 07:00:00] not.a.header without level\n[stacktrace]")
            ->write();

        $this->assertCount(1, $this->readAll($path));
    }

    public function test_long_entry_keeps_head_and_tail_so_context_survives(): void
    {
        $trace = implode("\n", array_map(fn (int $i) => "#{$i} /app/vendor/some/file.php({$i}): call()", range(1, 3000)));
        $path = (new LogBuilder)
            ->entry('2026-10-06 07:00:00', 'ALERT', "[CLI][job]\n12ms [crdb] select 1\n".$trace, ['slow_log' => 'query', 'ms' => 12.5])
            ->write();

        [$entry] = $this->readAll($path, head: 4096, tail: 1024);

        $this->assertTrue($entry->truncated);
        $this->assertLessThan(6000, strlen($entry->text));
        $this->assertStringContainsString('12ms [crdb] select 1', $entry->text);
        $this->assertSame(['slow_log' => 'query', 'ms' => 12.5], $entry->context());
    }

    public function test_keep_text_false_reads_only_the_first_line(): void
    {
        $path = (new LogBuilder)
            ->entry('2026-10-06 07:00:00', 'ERROR', "boom\n#0 trace", ['a' => 1])
            ->write();

        [$entry] = $this->readAll($path, keepText: fn (string $first) => false);

        $this->assertFalse($entry->hasText());
        $this->assertSame('boom', $entry->text);
        $this->assertNull($entry->context());
    }

    public function test_line_longer_than_a_chunk_does_not_create_false_headers(): void
    {
        // Mảnh thứ hai của dòng 100 KB bắt đầu đúng bằng một header — không phải đầu dòng.
        $filler = str_repeat('a', 65535 - strlen('[2026-10-06 07:00:00] production.INFO: '));
        $path = (new LogBuilder)
            ->raw('[2026-10-06 07:00:00] production.INFO: '.$filler.'[2026-10-06 07:00:01] production.ERROR: fake'."\n")
            ->entry('2026-10-06 07:00:02', 'INFO', 'real')
            ->write();

        $entries = $this->readAll($path);

        $this->assertSame(['INFO', 'INFO'], array_map(fn (Entry $e) => $e->level, $entries));
    }

    public function test_budget_stops_at_an_entry_boundary_and_resumes_exactly(): void
    {
        $log = new LogBuilder;
        foreach (range(0, 199) as $i) {
            $log->entry(sprintf('2026-10-06 07:%02d:%02d', intdiv($i, 60), $i % 60), 'INFO', "entry {$i}\nsecond line {$i}");
        }
        $path = $log->write();

        $reader = new EntryReader($path, 'test.log');
        $seen = [];
        $offset = 0;
        $rounds = 0;

        do {
            $rounds++;
            foreach ($reader->read($offset, Window::all(), new Budget(maxBytes: 500)) as $entry) {
                $seen[] = $entry->firstLine;
            }
            $offset = $reader->nextOffset();
        } while ($reader->stoppedByBudget());

        $this->assertGreaterThan(10, $rounds);
        $this->assertSame(array_map(fn (int $i) => "entry {$i}", range(0, 199)), $seen);
        $this->assertSame(strlen($log->content()), $offset);
    }

    public function test_window_with_slightly_unordered_timestamps_and_seek(): void
    {
        // 6000 entry, 1 entry/giây, cứ 50 entry có một entry ghi lùi 30 giây (nhiều worker).
        $log = new LogBuilder;
        $times = [];
        $base = strtotime('2026-10-06 00:00:00 UTC');
        for ($i = 0; $i < 6000; $i++) {
            $t = $base + $i - ($i % 50 === 0 ? 30 : 0);
            $times[] = gmdate('Y-m-d H:i:s', $t);
            $log->entry(end($times), 'INFO', "n={$i}", ['pad' => str_repeat('x', 40)]);
        }
        $path = $log->write();

        $from = '2026-10-06 00:30:00';
        $to = '2026-10-06 01:00:00';
        $expected = array_keys(array_filter($times, fn (string $t) => $t >= $from && $t <= $to));

        $reader = new EntryReader($path, 'test.log');
        $window = new Window($from, $to, slack: 120);
        $start = $reader->seek((string) $window->seekTarget());

        $this->assertGreaterThan(0, $start);
        $got = [];
        foreach ($reader->read($start, $window, Budget::unlimited()) as $entry) {
            $got[] = (int) substr($entry->firstLine, 2);
        }

        $this->assertSame($expected, $got);
        $this->assertTrue($reader->stoppedPastWindow());
    }
}
