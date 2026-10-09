<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

/**
 * Tách context (và extra) mà LineFormatter ghi ở CUỐI entry: `message {context} {extra}`.
 *
 * Ba cái bẫy khiến `strrpos('{"')` không dùng được:
 * - Context lồng nhau (`{"graphql":{"name":…}}`): `{"` cuối cùng là object con.
 * - Laravel bật `allowInlineLineBreaks`: Monolog đổi `\n` trong JSON thành xuống dòng
 *   thật, `json_decode` báo lỗi ký tự điều khiển. JSON của Monolog không có xuống dòng
 *   ngoài chuỗi, nên đổi ngược lại là an toàn.
 * - Chuỗi trong JSON có thể chứa `{`, `}`, `"` đã thoát.
 *
 * Nên ở đây quét ngược từ cuối, cân ngoặc, bỏ qua nội dung chuỗi (dấu `"` là thật khi số
 * dấu `\` đứng trước nó là chẵn), rồi decode thử. PHP thuần.
 */
final class TrailingJson
{
    /** Không quét ngược xa hơn mức này (entry đã bị cắt còn đầu + đuôi). */
    private const MAX_SCAN = 65536;

    /**
     * Trả [context, extra, vị trí bắt đầu phần JSON ở cuối]; không có JSON thì vị trí = độ dài.
     *
     * @return array{0: array<mixed>|null, 1: array<mixed>|null, 2: int}
     */
    public static function split(string $text): array
    {
        $end = strlen(rtrim($text));
        $last = self::valueEndingAt($text, $end);

        if ($last === null) {
            return [null, null, $end];
        }

        [$start, $value] = $last;

        // `message  {extra}` — context rỗng bị ignoreEmptyContextAndExtra thay bằng chuỗi rỗng.
        if ($start >= 2 && $text[$start - 1] === ' ' && $text[$start - 2] === ' ') {
            return [null, $value, $start - 2];
        }

        if ($start >= 1 && $text[$start - 1] === ' ' && ($before = self::valueEndingAt($text, $start - 1)) !== null) {
            return [$before[1], $value, $before[0]];
        }

        return [$value, null, $start];
    }

    /**
     * Giá trị JSON (object hoặc mảng) kết thúc ngay trước vị trí $end.
     *
     * @return array{0: int, 1: array<mixed>}|null [vị trí bắt đầu, giá trị đã decode]
     */
    private static function valueEndingAt(string $text, int $end): ?array
    {
        if ($end <= 0 || ($text[$end - 1] !== '}' && $text[$end - 1] !== ']')) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $stop = max(0, $end - self::MAX_SCAN);

        for ($i = $end - 1; $i >= $stop; $i--) {
            $char = $text[$i];

            if ($char === '"') {
                if (! self::escaped($text, $i)) {
                    $inString = ! $inString;
                }

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '}' || $char === ']') {
                $depth++;
            } elseif ($char === '{' || $char === '[') {
                $depth--;

                if ($depth === 0) {
                    $decoded = self::decode(substr($text, $i, $end - $i));

                    return $decoded === null ? null : [$i, $decoded];
                }
            }
        }

        return null;
    }

    private static function escaped(string $text, int $position): bool
    {
        $backslashes = 0;

        for ($i = $position - 1; $i >= 0 && $text[$i] === '\\'; $i--) {
            $backslashes++;
        }

        return $backslashes % 2 === 1;
    }

    /** @return array<mixed>|null */
    private static function decode(string $json): ?array
    {
        $json = str_replace(["\r", "\n"], ['\r', '\n'], $json);
        $decoded = json_decode($json, true, 512, JSON_BIGINT_AS_STRING | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_array($decoded) ? $decoded : null;
    }
}
