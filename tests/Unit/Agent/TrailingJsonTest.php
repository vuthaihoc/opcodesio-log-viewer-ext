<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Unit\Agent;

use HocVT\LogViewerRemote\Agent\Scan\TrailingJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrailingJsonTest extends TestCase
{
    public function test_nested_context_is_not_mistaken_for_its_inner_object(): void
    {
        // strrpos('{"') của bản thử rơi vào `{"name":…}` bên trong.
        [$context, $extra, $start] = TrailingJson::split('msg {"total":3,"graphql":{"operation":"query","name":"GetToeic"}} ');

        $this->assertSame(['total' => 3, 'graphql' => ['operation' => 'query', 'name' => 'GetToeic']], $context);
        $this->assertNull($extra);
        $this->assertSame(4, $start);
    }

    public function test_real_newlines_inside_json_strings_are_restored(): void
    {
        // Laravel bật allowInlineLineBreaks: `\n` trong JSON được ghi thành xuống dòng thật.
        $text = "boom {\"exception\":\"[object] (Exception: boom at /app/x.php:1)\n[stacktrace]\n#0 {main}\"} ";

        $this->assertSame("[object] (Exception: boom at /app/x.php:1)\n[stacktrace]\n#0 {main}", TrailingJson::split($text)[0]['exception']);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<mixed>}>
     */
    public static function tricky_strings(): iterable
    {
        yield 'ngoặc trong chuỗi' => ['m {"sql":"select \'{\' from t where a = \'}\'"}', ['sql' => "select '{' from t where a = '}'"]];
        yield 'nháy đã thoát' => ['m {"q":"say \"hi\""}', ['q' => 'say "hi"']];
        yield 'chuỗi kết thúc bằng backslash' => ['m {"path":"C:\\\\"}', ['path' => 'C:\\']];
        yield 'mảng lồng' => ['m {"ids":[1,[2,3]],"x":{}}', ['ids' => [1, [2, 3]], 'x' => []]];
    }

    /** @param array<mixed> $expected */
    #[DataProvider('tricky_strings')]
    public function test_strings_containing_json_syntax(string $text, array $expected): void
    {
        $this->assertSame($expected, TrailingJson::split($text)[0]);
    }

    public function test_context_and_extra(): void
    {
        $this->assertSame([['a' => 1], ['request_id' => 'r1'], 2], TrailingJson::split('m {"a":1} {"request_id":"r1"}'));
    }

    public function test_extra_only_when_context_is_empty(): void
    {
        // ignoreEmptyContextAndExtra thay `%context%` rỗng bằng chuỗi rỗng → hai dấu cách.
        $this->assertSame([null, ['request_id' => 'r1'], 1], TrailingJson::split('m  {"request_id":"r1"}'));
    }

    public function test_text_without_json(): void
    {
        $this->assertSame([null, null, 13], TrailingJson::split('[CLI][tinker]  '));
        $this->assertSame([null, null, 9], TrailingJson::split('not json}'));
    }

    public function test_big_ids_stay_exact(): void
    {
        // id CockroachDB vượt 2^53 vẫn nằm trong int64; số vượt int64 về chuỗi chứ không thành float.
        $this->assertSame(1129548723095175169, TrailingJson::split('m {"id":1129548723095175169}')[0]['id']);
        $this->assertSame('99999999999999999999', TrailingJson::split('m {"id":99999999999999999999}')[0]['id']);
    }
}
