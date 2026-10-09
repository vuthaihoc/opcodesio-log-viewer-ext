<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\SlowLog;

use HocVT\LogViewerRemote\Support\LoggableUrl;
use HocVT\LogViewerRemote\Support\SqlFingerprint;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;

/**
 * Gom số liệu query cho từng "context" (1 HTTP request / 1 job / 1 command),
 * log query chậm ngay lập tức và log tổng kết khi context kết thúc.
 *
 * Bắt buộc phải gọi flush() ở cuối mỗi context, nếu không số liệu sẽ cộng dồn
 * và tích luỹ bộ nhớ trong các process chạy dài (queue:work, schedule:run).
 *
 * Config: `slow-log` (config/slow-log.php của package) — xem docs/slow-log.md.
 *
 * Định dạng dòng log là hợp đồng với bộ đọc của agent (HocVT\LogViewerRemote\Agent):
 * context mang `slow_log` = `query` | `summary` cùng các trường máy đọc, phần chữ chỉ để
 * người đọc. Đổi định dạng thì đổi cả parser và test khớp format ở app.
 */
class SqlLogger
{
    /** Binding dài hơn mức này (payload, nội dung, token OAuth/FCM) bị thay bằng độ dài. */
    protected const MAX_BINDING_LENGTH = 64;

    /** Bảng mà giá trị nào cũng nhạy cảm: payload job, session, cache, token — không bao giờ ghi giá trị. */
    protected const SENSITIVE_TABLES_PATTERN = '/\b(?:from|into|update|join)\s+[`"]?(?:jobs|failed_jobs|job_batches|sessions|cache|cache_locks|personal_access_tokens|password_reset_tokens|password_resets)\b/i';

    /** @var array<string, array{sql: string, count: int, ms: float, connection: string}> */
    protected array $groups = [];

    protected int $total = 0;

    protected float $totalMs = 0;

    /** Số nhóm bị bỏ qua do vượt max_groups. */
    protected int $droppedGroups = 0;

    protected bool $enabled;

    protected bool $console;

    protected int $maxTime;

    protected ?string $channel;

    protected string $level;

    protected int $totalToLog;

    protected int $totalMsToLog;

    protected int $duplicateToLog;

    protected bool $rawBindings;

    protected int $maxSqlLength;

    protected int $maxGroups;

    protected int $topQueries;

    protected bool $trace;

    protected int $traceFrames;

    /** @var list<string> Gói vendor (`vendor/name`) bỏ khỏi stack trace. */
    protected array $skipVendors;

    protected CompiledViewResolver $resolver;

    /** Job / command đang chạy: vào nhãn `[CLI][…]` và context `cli.context`. Job ưu tiên hơn command. */
    protected ?string $job = null;

    protected ?string $command = null;

    public function __construct(array $config = [])
    {
        $config += (array) config('slow-log', []);

        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->console = app()->runningInConsole();

        // null / rỗng = kênh mặc định của app.
        $channel = (string) ($config['channel'] ?? '');
        $this->channel = $channel !== '' ? $channel : null;
        $this->level = (string) ($config['level'] ?? 'alert');
        $this->maxTime = (int) ($this->console
            ? ($config['time_to_log_cli'] ?? 10000)
            : ($config['time_to_log'] ?? 500));

        $this->totalToLog = (int) ($config['total_to_log'] ?? 0);
        $this->totalMsToLog = (int) ($config['total_ms_to_log'] ?? 0);
        $this->duplicateToLog = (int) ($config['duplicate_to_log'] ?? 0);

        // Chặn cứng ngoài local: lỡ bật SLOW_LOG_RAW_BINDINGS ở production cũng không có tác dụng.
        $this->rawBindings = (bool) ($config['raw_bindings'] ?? false) && app()->environment('local');
        $this->maxSqlLength = (int) ($config['max_sql_length'] ?? 2000);
        $this->maxGroups = (int) ($config['max_groups'] ?? 200);
        $this->topQueries = (int) ($config['top_queries'] ?? 15);

        // `trace` nhận cả dạng tắt-bật nhanh (bool) lẫn nhóm đầy đủ trong config.
        $trace = is_array($config['trace'] ?? null) ? $config['trace'] : ['enabled' => $config['trace'] ?? true];

        $this->trace = (bool) ($trace['enabled'] ?? true);
        $this->traceFrames = max(1, (int) ($trace['max_frames'] ?? 8));
        $this->skipVendors = array_values((array) ($trace['skip_vendors'] ?? ['laravel/framework', 'livewire/livewire', 'barryvdh/laravel-debugbar']));

        $this->resolver = new CompiledViewResolver(
            blade: (bool) ($trace['blade'] ?? true),
            livewire: (bool) ($trace['livewire'] ?? true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enterCommand(string $name): void
    {
        $this->command = $name;
    }

    public function leaveCommand(): void
    {
        $this->command = null;
    }

    public function enterJob(string $name): void
    {
        $this->job = $name;
    }

    public function leaveJob(): void
    {
        $this->job = null;
    }

    public function currentContext(): ?string
    {
        return $this->job ?? $this->command;
    }

    /**
     * Ghi nhận 1 query. Query chậm được log ngay, phần còn lại chỉ gom nhóm.
     */
    public function record(QueryExecuted $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->total++;
        $this->totalMs += (float) $event->time;

        $connection = $event->connectionName;
        $key = $connection.'|'.$this->fingerprint($event->sql);

        if (isset($this->groups[$key])) {
            $this->groups[$key]['count']++;
            $this->groups[$key]['ms'] += (float) $event->time;
        } elseif (count($this->groups) < $this->maxGroups) {
            $this->groups[$key] = [
                'sql' => $this->fingerprint($event->sql),
                'count' => 1,
                'ms' => (float) $event->time,
                'connection' => $connection,
                // Giữ 1 mẫu có giá trị thật để copy chạy lại; chỉ tính 1 lần cho mỗi nhóm.
                'sample' => $this->rawSqlOf($event),
            ];
        } else {
            $this->droppedGroups++;
        }

        if ($this->maxTime > 0 && $event->time >= $this->maxTime) {
            $this->logSlowQuery($event);
        }
    }

    /**
     * Log tổng kết context rồi reset. Luôn reset kể cả khi không log.
     */
    public function flush(?string $context = null): void
    {
        if (! $this->enabled || $this->total === 0) {
            $this->reset();

            return;
        }

        $context ??= $this->currentContext();

        try {
            $reasons = $this->reasons();

            if ($reasons !== []) {
                Log::channel($this->channel)->warning(
                    $this->contextLabel($context).' '.implode(' + ', $reasons)."\n".$this->summary(),
                    [
                        'slow_log' => 'summary',
                        'reasons' => array_keys($reasons),
                        'total' => $this->total,
                        'total_ms' => round($this->totalMs, 2),
                        'total_class' => $this->totalClass(),
                        'unique_queries' => count($this->groups),
                        'worst_duplicate' => $this->maxDuplicate(),
                    ] + $this->scopeContext($context)
                );
            }
        } catch (\Throwable $ex) {
            Log::error('SqlLogger flush error: '.$ex->getMessage());
        } finally {
            $this->reset();
        }
    }

    public function reset(): void
    {
        $this->groups = [];
        $this->total = 0;
        $this->totalMs = 0;
        $this->droppedGroups = 0;
    }

    /**
     * Mã (vào context `reasons`, cho máy đọc) => câu (vào dòng log, cho người đọc).
     *
     * @return array<'total'|'total_ms'|'duplicate', string>
     */
    protected function reasons(): array
    {
        $reasons = [];

        if ($this->totalToLog > 0 && $this->total > $this->totalToLog) {
            $reasons['total'] = "Quá nhiều query [{$this->totalClass()}]: {$this->total} query";
        }

        if ($this->totalMsToLog > 0 && $this->totalMs > $this->totalMsToLog) {
            $reasons['total_ms'] = 'Tổng thời gian query '.round($this->totalMs).'ms > '.$this->totalMsToLog.'ms';
        }

        if ($this->duplicateToLog > 0 && ($worst = $this->maxDuplicate()) >= $this->duplicateToLog) {
            $reasons['duplicate'] = "Nghi ngờ N+1: 1 query lặp {$worst}x";
        }

        return $reasons;
    }

    /** Số lần lặp của query lặp nhiều nhất trong context. */
    protected function maxDuplicate(): int
    {
        $max = 0;

        foreach ($this->groups as $group) {
            $max = max($max, $group['count']);
        }

        return $max;
    }

    protected function summary(): string
    {
        $groups = $this->groups;

        uasort($groups, fn (array $a, array $b) => [$b['count'], $b['ms']] <=> [$a['count'], $a['ms']]);

        $lines = [
            sprintf(
                '  %d query / %sms / %d query khác nhau',
                $this->total,
                round($this->totalMs),
                count($this->groups)
            ),
        ];

        foreach (array_slice($groups, 0, $this->topQueries) as $group) {
            // Luôn có một dấu cách giữa `xN` và số ms, kể cả khi số dài hơn cột:
            // định dạng cũ `%4s%6s` ra `x10000100000ms` khi lặp 10000 lần, tổng 100000ms.
            $lines[] = sprintf(
                '  %5s %7sms [%s] %s',
                $group['count'] > 1 ? 'x'.$group['count'] : '',
                round($group['ms']),
                $group['connection'],
                $this->truncate($group['sql'])
            );

            if (($group['sample'] ?? null) !== null) {
                $lines[] = '         -> '.$this->truncate($group['sample']);
            }
        }

        if (count($groups) > $this->topQueries) {
            $lines[] = '  ... còn '.(count($groups) - $this->topQueries).' query khác';
        }

        if ($this->droppedGroups > 0) {
            $lines[] = '  ... '.$this->droppedGroups.' query bị bỏ qua (vượt max_groups='.$this->maxGroups.')';
        }

        return implode("\n", $lines);
    }

    protected function logSlowQuery(QueryExecuted $event): void
    {
        $context = $this->currentContext();

        try {
            Log::channel($this->channel)->{$this->level}(
                $this->contextLabel($context)."\n"
                .round((float) $event->time).'ms ['.$event->connectionName.'] '
                .$this->truncate($this->sqlOf($event))
                .$this->findSource(),
                [
                    'slow_log' => 'query',
                    'ms' => round((float) $event->time, 2),
                    'connection' => $event->connectionName,
                ] + $this->scopeContext($context)
            );
        } catch (\Throwable $ex) {
            Log::error('SqlLogger error: '.$ex->getMessage());
        }
    }

    protected function totalClass(): string
    {
        return match (true) {
            $this->total <= 30 => 'normal',
            $this->total <= 50 => 'gt30',
            $this->total <= 100 => 'gt50',
            default => 'gt100',
        };
    }

    /**
     * Đọc ngữ cảnh tại thời điểm gọi — không cache, để dùng được với queue worker
     * xử lý nhiều job và các runtime giữ process (Octane).
     */
    protected function contextLabel(?string $context = null): string
    {
        if ($context !== null) {
            return '[CLI]['.$context.']';
        }

        if ($this->console) {
            return '[CLI]';
        }

        $request = request();

        return '[WEB]['.$request->ip().']['.LoggableUrl::fromRequest($request).']';
    }

    /**
     * `cli.context` khi đang trong job / command, cộng context request khi chạy web.
     *
     * @return array<string, string>
     */
    protected function scopeContext(?string $context): array
    {
        return ($context !== null ? ['cli.context' => $context] : []) + $this->requestContext();
    }

    /**
     * URL, route và referer để lọc log theo trang; CLI/job không có. URL và referer đã che
     * qua LoggableUrl; `req.route` là URI template của route (`/video/{id}/{slug?}`), gom
     * theo trang mà không phải đoán từ URL.
     * Dùng cùng key với middleware chia sẻ context request (nếu project có, gọi
     * `Log::shareContext(['req.url' => …])`) — Laravel gộp context bằng array_merge nên
     * hai nơi cùng ghi cũng chỉ ra một key.
     *
     * @return array<string, string>
     */
    protected function requestContext(): array
    {
        if ($this->console) {
            return [];
        }

        $request = request();

        $route = $request->route();

        return array_filter([
            'req.url' => LoggableUrl::fromRequest($request),
            'req.route' => $route instanceof Route ? '/'.ltrim($route->uri(), '/') : null,
            'req.referer' => LoggableUrl::fromString($request->headers->get('referer')),
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * SQL dùng để log. Mặc định giữ placeholder `?` để không rò rỉ dữ liệu người dùng
     * vào file log.
     */
    protected function sqlOf(QueryExecuted $event): string
    {
        return $this->rawSqlOf($event) ?? $event->sql;
    }

    /**
     * SQL có giá trị thật để copy chạy lại, hoặc null khi không được phép ghi giá trị.
     * Không che theo tên cột: binding đi theo vị trí, muốn biết `?` thứ mấy là cột
     * `password` phải parse SQL — vỡ với upsert/subquery/raw. Thay vào đó bỏ hẳn các bảng
     * chỉ chứa dữ liệu nhạy cảm, và che binding theo hình dạng giá trị ở redact().
     */
    protected function rawSqlOf(QueryExecuted $event): ?string
    {
        if (! $this->rawBindings || preg_match(self::SENSITIVE_TABLES_PATTERN, $event->sql) === 1) {
            return null;
        }

        try {
            $bindings = array_map($this->redact(...), $event->connection->prepareBindings($event->bindings));

            return $event->connection->query()->getGrammar()->substituteBindingsIntoRawSql($event->sql, $bindings);
        } catch (\Throwable) {
            // escape() ném lỗi với kiểu lạ (mảng, enum...) — listener không được làm hỏng query.
            return null;
        }
    }

    protected function redact(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match (true) {
            // Grammar::escape() ném RuntimeException với null byte / UTF-8 hỏng.
            str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8') => '[binary '.strlen($value).' bytes]',
            preg_match('/^\$(2[abxy]|argon2id?)\$/', $value) === 1 => '[hash]',
            mb_strlen($value) > self::MAX_BINDING_LENGTH => '['.mb_strlen($value).' chars]',
            LoggableUrl::looksLikeToken($value) => '[token '.strlen($value).' chars]',
            default => $value,
        };
    }

    /**
     * Chuẩn hoá SQL để gom nhóm: gộp danh sách placeholder dài và bỏ khoảng trắng thừa.
     */
    protected function fingerprint(string $sql): string
    {
        return SqlFingerprint::of($sql);
    }

    protected function truncate(string $sql): string
    {
        if ($this->maxSqlLength <= 0 || mb_strlen($sql) <= $this->maxSqlLength) {
            return $sql;
        }

        return mb_substr($sql, 0, $this->maxSqlLength)
            .'... [cắt bớt '.(mb_strlen($sql) - $this->maxSqlLength).' ký tự]';
    }

    protected function findSource(): string
    {
        if (! $this->trace) {
            return '';
        }

        $stack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60);
        $frames = [];

        foreach ($stack as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || $this->isNoise($file)) {
                continue;
            }

            $frames[] = $this->resolver->relative($this->resolver->resolve($file)).':'.($frame['line'] ?? '?');

            if (count($frames) >= $this->traceFrames) {
                break;
            }
        }

        return $frames === [] ? '' : "\n  ".implode("\n  ", $frames);
    }

    protected function isNoise(string $file): bool
    {
        // Chính bản thân logger và listener của nó.
        if (str_starts_with($file, __DIR__.DIRECTORY_SEPARATOR)) {
            return true;
        }

        foreach ($this->skipVendors as $package) {
            if (str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $package).DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
