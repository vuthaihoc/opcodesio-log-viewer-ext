<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

use HocVT\LogViewerRemote\Agent\Scan\Budget;
use HocVT\LogViewerRemote\Agent\Scan\Entry;
use HocVT\LogViewerRemote\Agent\Scan\EntryFilter;
use HocVT\LogViewerRemote\Agent\Scan\EntryReader;
use HocVT\LogViewerRemote\Agent\Scan\Normalizer;
use HocVT\LogViewerRemote\Agent\Scan\ScanFile;
use HocVT\LogViewerRemote\Agent\Scan\Scanner;
use HocVT\LogViewerRemote\Agent\Scan\Window;
use HocVT\LogViewerRemote\LogViewerRemote;
use Illuminate\Routing\Router;
use InvalidArgumentException;

/**
 * Ba việc agent làm trên host có file log: liệt kê file, gom số liệu, đọc entry. Dùng chung
 * cho endpoint HTTP và lệnh artisan chạy ngay trong process (`--host=local`).
 *
 * Tham số là chuỗi như query string (`files`, `channel`, `from`, `to`, `date`, `only`,
 * `top`, `cursor`…); lỗi ném AgentException để lớp gọi trả JSON đúng mã.
 */
final class AgentService
{
    /** Kết quả file đã đóng lâu hơn mức này mới được cache (file đang ghi thì luôn đọc lại). */
    private const CLOSED_AFTER_SECONDS = 600;

    public function __construct(private readonly Router $router) {}

    /**
     * `$auth`: `agent` (token chỉ đọc → giới hạn channel), `shared` (shared secret) hoặc
     * `local` (lệnh chạy ngay trên máy). Chỉ `agent` bị giới hạn channel.
     *
     * @return array<string, mixed>
     */
    public function ping(string $auth): array
    {
        $resolver = self::resolver($auth);

        return [
            'ok' => true,
            'version' => LogViewerRemote::VERSION,
            'auth' => $auth,
            'restricted' => $resolver->restricted(),
            'channels' => $resolver->channels(),
            'aggregators' => Scanner::AGGREGATORS,
            'log_timezone' => AgentConfig::logTimezone(),
            'input_timezone' => AgentConfig::inputTimezone(),
            'limits' => [
                'max_seconds' => (float) AgentConfig::get('max_seconds'),
                'slots' => AgentConfig::int('slots'),
                'entries_limit' => AgentConfig::int('entries_limit'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function files(string $auth): array
    {
        $resolver = self::resolver($auth);

        return [
            'restricted' => $resolver->restricted(),
            'channels' => $resolver->channels(),
            'log_timezone' => AgentConfig::logTimezone(),
            'files' => array_map(static fn (AgentFile $f): array => $f->toArray(), $resolver->allowed()),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function aggregate(array $params, string $auth): array
    {
        $window = TimeWindow::fromInput(self::str($params, 'from'), self::str($params, 'to'), self::str($params, 'date'));
        $files = self::resolver($auth)->select(self::str($params, 'files'), self::str($params, 'channel'), $window->fromDate(), $window->toDate());
        $only = self::list($params, 'only');
        $top = max(1, min(200, (int) (self::str($params, 'top') ?? 20)));
        $partial = filter_var($params['partial'] ?? false, FILTER_VALIDATE_BOOL);

        // Lọc + gom tự do: `match` / `contains` / `level` lọc entry trước mọi bảng; `group` thêm
        // bảng gom theo regex; `in=text` so trên cả entry thay vì dòng đầu (chậm hơn).
        $wholeText = self::str($params, 'in') === 'text';
        $group = self::str($params, 'group');
        $filter = $this->filter($params, 'match', $wholeText);
        $query = [$only, self::str($params, 'match'), self::str($params, 'contains'), self::list($params, 'level'), $wholeText, $group];

        $fingerprint = sha1((string) json_encode([array_map(static fn (AgentFile $f) => $f->name, $files), $window->toArray(), $query]));
        $cursorId = self::str($params, 'cursor');
        $state = $cursorId !== null ? CursorStore::pull($cursorId, $fingerprint) : null;

        $cacheKey = $state === null && $this->allClosed($files)
            ? 'lvr:agent:aggregate:'.sha1((string) json_encode([
                LogViewerRemote::VERSION,
                url('/'), // ui_url dựng theo host của request
                array_map(static fn (AgentFile $f) => [$f->name, $f->size, $f->mtime], $files),
                $window->toArray(), $query, $top, AgentConfig::get('page_key'), AgentConfig::get('url_groups'),
            ]))
            : null;

        if ($cacheKey !== null && is_array($cached = CursorStore::store()->get($cacheKey))) {
            return ['cached' => true] + $cached;
        }

        try {
            $scanner = Scanner::make(
                $this->normalizer(), $only, AgentConfig::int('cap'), AgentConfig::int('head_bytes'), AgentConfig::int('tail_bytes'),
                filter: $filter, group: $group, groupWholeText: $wholeText,
            );
        } catch (InvalidArgumentException $e) {
            throw new AgentException(422, $e->getMessage(), ['aggregators' => [...Scanner::AGGREGATORS, 'group']]);
        }

        $result = ScanSlots::run(fn () => $scanner->run(
            array_map(static fn (AgentFile $f) => new ScanFile($f->path, $f->name, $f->size), $files),
            $window->scanWindow(),
            new Budget((float) AgentConfig::get('max_seconds'), AgentConfig::int('max_bytes')),
            $state,
        ));

        $payload = [
            'complete' => $result->complete,
            'cursor' => $result->complete ? null : CursorStore::put((array) $result->cursor, $fingerprint),
            'window' => $window->toArray(),
            'stats' => $result->stats(),
            'results' => $result->complete || $partial ? (new UiLinks($files))->addTo($result->results($top)) : null,
        ];

        if ($cacheKey !== null && $result->complete) {
            CursorStore::store()->put($cacheKey, $payload, AgentConfig::int('cache_ttl'));
        }

        return ['cached' => false] + $payload;
    }

    /**
     * Đọc entry đầy đủ: một entry theo `at=file@offset` (lấy từ `sample` của kết quả gom),
     * hoặc lọc theo `contains` / `regex` / `level` trong cửa sổ thời gian. Chữ đã che email
     * và token, cắt ở `max_bytes`.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function entries(array $params, string $auth): array
    {
        $resolver = self::resolver($auth);
        $maxBytes = max(256, min(AgentConfig::int('entry_max_bytes'), (int) (self::str($params, 'max_bytes') ?? AgentConfig::int('entry_max_bytes'))));
        $limit = max(1, min(AgentConfig::int('entries_limit'), (int) (self::str($params, 'limit') ?? 10)));

        if (($at = self::str($params, 'at')) !== null) {
            [$file, $offset] = $this->position($at, $resolver);
            $reader = new EntryReader($file->path, $file->name, AgentConfig::int('head_bytes'), AgentConfig::int('tail_bytes'));

            foreach ($reader->read($offset, Window::all(), new Budget(5)) as $entry) {
                return ['entries' => [$this->present($entry, $maxBytes, new UiLinks([$file]))], 'next' => null, 'complete' => true];
            }

            throw new AgentException(404, "Không có entry ở {$at}.");
        }

        $window = TimeWindow::fromInput(self::str($params, 'from'), self::str($params, 'to'), self::str($params, 'date'));
        $files = $resolver->select(self::str($params, 'files'), self::str($params, 'channel'), $window->fromDate(), $window->toDate());
        $match = $this->filter($params, 'regex', wholeText: true);
        // `cursor` = `next` của lượt trước: đọc tiếp TỪ vị trí đó (tính cả entry tại đó).
        $resume = ($cursor = self::str($params, 'cursor')) !== null ? $this->position($cursor, $resolver) : null;

        $links = new UiLinks($files);

        return ScanSlots::run(function () use ($files, $window, $match, $limit, $maxBytes, $resume, $links): array {
            $scanWindow = $window->scanWindow();
            $budget = new Budget((float) AgentConfig::get('max_seconds'));
            $found = [];
            $skipping = $resume !== null;

            foreach ($files as $file) {
                if ($skipping && $file->name !== $resume[0]->name) {
                    continue;
                }

                $reader = new EntryReader($file->path, $file->name, AgentConfig::int('head_bytes'), AgentConfig::int('tail_bytes'));
                $offset = match (true) {
                    $skipping => $resume[1],
                    $scanWindow->seekTarget() !== null => $reader->seek((string) $scanWindow->seekTarget()),
                    default => 0,
                };
                $skipping = false;

                foreach ($reader->read($offset, $scanWindow, $budget) as $entry) {
                    if (! $match->matches($entry)) {
                        continue;
                    }

                    if (count($found) >= $limit) {
                        return $this->entriesPage($found, $entry->file.'@'.$entry->offset, $window);
                    }

                    $found[] = $this->present($entry, $maxBytes, $links);
                }

                if ($reader->stoppedByBudget()) {
                    return $this->entriesPage($found, $file->name.'@'.$reader->nextOffset(), $window);
                }
            }

            return $this->entriesPage($found, null, $window);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $found
     * @return array<string, mixed>
     */
    private function entriesPage(array $found, ?string $next, TimeWindow $window): array
    {
        return ['entries' => $found, 'next' => $next, 'complete' => $next === null, 'window' => $window->toArray()];
    }

    private static function resolver(string $auth): FileResolver
    {
        return FileResolver::fromConfig(restricted: $auth === 'agent');
    }

    private function normalizer(): Normalizer
    {
        return new Normalizer(
            urlGroups: (array) AgentConfig::get('url_groups'),
            pageKeys: array_values((array) AgentConfig::get('page_key')),
            routeOf: new RouteTemplates($this->router),
        );
    }

    /** @param list<AgentFile> $files */
    private function allClosed(array $files): bool
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone(AgentConfig::logTimezone())))->format('Y-m-d');

        foreach ($files as $file) {
            $closed = ($file->date !== null && $file->date < $today) || $file->mtime < time() - self::CLOSED_AFTER_SECONDS;

            if (! $closed) {
                return false;
            }
        }

        return true;
    }

    /**
     * `level`, `contains` và regex (`$regexKey`: `match` ở aggregate, `regex` ở entries).
     *
     * @param  array<string, mixed>  $params
     */
    private function filter(array $params, string $regexKey, bool $wholeText): EntryFilter
    {
        try {
            return new EntryFilter(self::str($params, 'contains'), self::str($params, $regexKey), self::list($params, 'level'), $wholeText);
        } catch (InvalidArgumentException $e) {
            throw new AgentException(422, $e->getMessage().' (PCRE, không cần dấu phân cách).');
        }
    }

    /** @return array{0: AgentFile, 1: int} */
    private function position(string $value, FileResolver $resolver): array
    {
        if (preg_match('/^(.+)@(\d+)$/', $value, $m) !== 1) {
            throw new AgentException(422, "Vị trí phải có dạng file@offset, nhận được: {$value}.");
        }

        return [$resolver->select($m[1], null)[0], (int) $m[2]];
    }

    /** @return array<string, mixed> */
    private function present(Entry $entry, int $maxBytes, UiLinks $links): array
    {
        $text = Normalizer::maskSecrets($entry->text);
        $cut = strlen($text) > $maxBytes;

        return [
            'at' => $entry->file.'@'.$entry->offset,
            'datetime' => $entry->datetime,
            'level' => $entry->level,
            'length' => $entry->length,
            'truncated' => $entry->truncated || $cut,
            'text' => mb_scrub($cut ? substr($text, 0, $maxBytes).'…' : $text, 'UTF-8'),
            'ui_url' => $links->for($entry->file.'@'.$entry->offset, $entry->datetime),
        ];
    }

    /** @param array<string, mixed> $params */
    private static function str(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<string>|null
     */
    private static function list(array $params, string $key): ?array
    {
        $value = self::str($params, $key);

        return $value === null ? null : array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
