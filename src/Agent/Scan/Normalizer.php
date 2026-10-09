<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent\Scan;

use HocVT\LogViewerRemote\Support\LoggableUrl;
use HocVT\LogViewerRemote\Support\SqlFingerprint;

/**
 * Biến thông điệp / SQL / URL thành khoá gom nhóm: bỏ phần thay đổi theo từng lần (số,
 * id, literal, token) để cùng một lỗi / query / trang về chung một hàng. Đồng thời che
 * email và chuỗi trông như token — kết quả đi tới agent, không cần những thứ đó.
 */
final class Normalizer
{
    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    private const UUID = '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i';

    /** Hash / id hex (có ít nhất một chữ a–f); chuỗi toàn số dài là id số, để NUMBER lo. */
    private const HEX = '/\b(?=[0-9]*[a-f])[0-9a-f]{16,}\b/i';

    private const ALNUM_RUN = '/[A-Za-z0-9]{24,}/';

    /** Số không dính sau chữ / số khác: `24x` → `<n>x`, `v2`, `md5`, `Base64` giữ nguyên. */
    private const NUMBER = '/(?<![A-Za-z0-9_\\\\])\d+(?:[.,]\d+)*/';

    /** Route của Livewire 4 có hash (`/livewire-4b6b8091/update`): trang thật nằm ở referer. */
    private const LIVEWIRE_ROUTE = '#^/livewire(?:-[0-9a-f]+)?/update$#';

    /** @var (\Closure(string): ?string)|null */
    private readonly ?\Closure $routeOf;

    /**
     * `$routeOf` quy một URL về URI template của route (`/learn/video/{slug}/{id}`), null nếu
     * không khớp route nào. Lõi không biết Laravel nên lớp HTTP cắm router vào; không có thì
     * chỉ còn luật thay số / token và `urlGroups`. Đây là cách gộp được log CŨ (chưa có
     * `req.route`) và referer của request Livewire.
     *
     * @param  array<string, string>  $urlGroups  regex => thay thế, chạy trên path sau khi đã thay số / token
     * @param  list<string>  $pageKeys  key context dùng làm khoá trang, theo thứ tự ưu tiên
     * @param  (callable(string): ?string)|null  $routeOf
     */
    public function __construct(
        private readonly array $urlGroups = [],
        private readonly array $pageKeys = ['req.route'],
        private readonly int $maxKeyLength = 200,
        ?callable $routeOf = null,
    ) {
        $this->routeOf = $routeOf === null ? null : \Closure::fromCallable($routeOf);
    }

    public function message(Entry $entry, ?SlowLogRecord $record): string
    {
        if ($record !== null) {
            return $record->kind === 'query'
                ? 'slow query: '.$this->sql((string) $record->sql)
                : 'slow summary: '.($record->reasons === [] ? '?' : implode('+', $record->reasons));
        }

        $text = rtrim($entry->firstLine);

        // Entry một dòng mang trọn context JSON trên dòng đầu → tách chính xác. Context
        // trải nhiều dòng (exception kèm stack trace) thì dòng đầu chỉ có nửa đầu của nó:
        // cắt từ ` {"` cuối cùng (json_encode không sinh dấu cách nên đó là chỗ context bắt đầu).
        if (str_ends_with($text, '}') || str_ends_with($text, ']')) {
            $text = substr($text, 0, TrailingJson::split($text)[2]);
        } elseif (($start = strrpos($text, ' {"')) !== false) {
            $text = substr($text, 0, $start);
        }

        return $this->cut($this->maskVolatile($text));
    }

    public function sql(string $sql): string
    {
        $sql = SqlFingerprint::of($sql);
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/(?<![A-Za-z_"`.$])\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;
        // in (?, ?, …) / values (?, ?) → (?), rồi gộp chuỗi (?), (?), …
        $sql = preg_replace('/\(\s*\?(?:\s*,\s*(?:\?|\.\.\.))*\s*\)/', '(?)', $sql) ?? $sql;
        $sql = preg_replace('/\(\?\)(?:\s*,\s*\(\?\))+/', '(?), ...', $sql) ?? $sql;

        return $this->cut($sql);
    }

    public function url(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        if ($this->routeOf !== null && ($route = ($this->routeOf)($url)) !== null) {
            return $this->cut($route);
        }

        $segments = array_map(static fn (string $segment): string => match (true) {
            $segment === '' => '',
            ctype_digit($segment) => '{id}',
            preg_match(self::UUID, $segment) === 1 => '{uuid}',
            LoggableUrl::looksLikeToken($segment) => '{token}',
            default => $segment,
        }, explode('/', $path));

        $path = implode('/', $segments);

        foreach ($this->urlGroups as $pattern => $replacement) {
            $path = preg_replace($pattern, $replacement, $path) ?? $path;
        }

        return $this->cut($path);
    }

    /**
     * Khoá trang của một dòng slow log WEB: key context đầu tiên trong `pageKeys` có giá
     * trị (`graphql.name` cho host GraphQL — ở đó URL nào cũng là `/graphql`), rồi tới URL
     * đã chuẩn hoá. Request Livewire đổi sang trang ở referer.
     */
    public function page(SlowLogRecord $record): string
    {
        foreach ($this->pageKeys as $key) {
            $value = self::lookup($record->context, $key);

            if (! is_scalar($value) || (string) $value === '') {
                continue;
            }

            $value = (string) $value;

            if ($key === 'req.route' && preg_match(self::LIVEWIRE_ROUTE, $value) === 1 && $record->referer !== null) {
                return 'livewire ← '.$this->url($record->referer);
            }

            return $key === 'req.route' ? $value : $key.'='.$this->cut($value);
        }

        if ($record->url === null) {
            return '(không rõ)';
        }

        $path = $this->url($record->url);

        return preg_match(self::LIVEWIRE_ROUTE, $path) === 1 && $record->referer !== null
            ? 'livewire ← '.$this->url($record->referer)
            : $path;
    }

    /**
     * Chỉ che email và chuỗi trông như token, giữ nguyên số / id — dùng cho chữ của entry trả
     * thẳng cho agent (cần id để lần theo, không cần email hay token).
     */
    public static function maskSecrets(string $text): string
    {
        $text = preg_replace(self::EMAIL, '<email>', $text) ?? $text;

        return preg_replace_callback(
            self::ALNUM_RUN,
            static fn (array $m): string => LoggableUrl::looksLikeToken($m[0]) ? '<token>' : $m[0],
            $text,
        ) ?? $text;
    }

    /** Thay số, id, email, token… trong một đoạn chữ tự do. */
    public function maskVolatile(string $text): string
    {
        $text = preg_replace(self::EMAIL, '<email>', $text) ?? $text;
        $text = preg_replace(self::UUID, '<uuid>', $text) ?? $text;
        $text = preg_replace(self::HEX, '<hex>', $text) ?? $text;
        $text = preg_replace_callback(
            self::ALNUM_RUN,
            static fn (array $m): string => LoggableUrl::looksLikeToken($m[0]) ? '<token>' : $m[0],
            $text,
        ) ?? $text;
        $text = preg_replace(self::NUMBER, '<n>', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /**
     * Tra key phẳng trước (`req.url` là MỘT key có dấu chấm), rồi mới đi theo cây
     * (`graphql.name` là `graphql` → `name`).
     *
     * @param  array<string, mixed>  $context
     */
    public static function lookup(array $context, string $key): mixed
    {
        if (array_key_exists($key, $context)) {
            return $context[$key];
        }

        $value = $context;

        foreach (explode('.', $key) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return null;
            }

            $value = $value[$part];
        }

        return $value;
    }

    /** Cắt độ dài và bảo đảm UTF-8 hợp lệ (khoá đi vào JSON). */
    private function cut(string $text): string
    {
        if (strlen($text) > $this->maxKeyLength) {
            $text = substr($text, 0, $this->maxKeyLength).'…';
        }

        return mb_scrub($text, 'UTF-8');
    }
}
