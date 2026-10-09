<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Unit\Agent;

use HocVT\LogViewerRemote\Agent\Scan\Tally;
use PHPUnit\Framework\TestCase;

class TallyTest extends TestCase
{
    public function test_a_hot_key_that_appears_late_survives_pruning(): void
    {
        $tally = new Tally(['n'], cap: 10);

        foreach (range(1, 100) as $i) {
            $tally->add("cold-{$i}", sum: ['n' => 1]);
        }
        foreach (range(1, 50) as $_) {
            $tally->add('hot', sum: ['n' => 1]);
        }

        $top = $tally->top(1);
        $this->assertSame('hot', $top[0]['key']);
        $this->assertSame(50, $top[0]['n']);
        $this->assertGreaterThan(0, $tally->pruned());
        $this->assertLessThanOrEqual(20, $tally->count());
    }

    public function test_sum_max_once_set_and_spread(): void
    {
        $tally = new Tally(['waste']);

        $tally->add('q', sum: ['waste' => 3], max: ['max' => 4], once: ['first' => 'a'], set: ['last' => 'a'], spread: ['pages' => ['/a', 3]]);
        $tally->add('q', sum: ['waste' => 5], max: ['max' => 2], once: ['first' => 'b'], set: ['last' => 'b'], spread: ['pages' => ['/b', 5]]);

        $this->assertSame([['key' => 'q', 'first' => 'a', 'waste' => 8, 'max' => 4, 'last' => 'b', 'pages' => ['/b' => 5, '/a' => 3]]], $tally->top(5));
    }

    public function test_spread_folds_small_values_into_an_other_bucket(): void
    {
        $tally = new Tally(['n']);

        foreach (range(1, 30) as $i) {
            $tally->add('q', sum: ['n' => 1], spread: ['pages' => ["/p{$i}", $i]]);
        }

        $pages = $tally->top(1)[0]['pages'];
        $this->assertCount(Tally::SPREAD_CAP + 1, $pages);
        $this->assertSame(30, $pages['/p30']);
        $this->assertSame(array_sum(range(1, 30)), array_sum($pages));
    }
}
