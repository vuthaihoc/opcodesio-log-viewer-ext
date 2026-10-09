<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Agent;

use Carbon\CarbonImmutable;
use HocVT\LogViewerRemote\Agent\Scan\Window;
use Throwable;

/**
 * Đổi `from` / `to` của request sang cửa sổ so được với timestamp trong file log.
 *
 * Timestamp trong log không kèm múi giờ và theo `app.timezone` (ở app này là UTC), còn
 * người hỏi thường nghĩ theo giờ địa phương. Nên: có offset (`2026-10-06T07:00+07:00`) thì
 * theo offset; không có thì hiểu theo `log-viewer.timezone`; nhận cả cách viết tương đối
 * của Carbon (`-2 hours`, `yesterday`). `date=YYYY-MM-DD` = cả ngày đó theo giờ người hỏi.
 */
final class TimeWindow
{
    private function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly int $slack,
    ) {}

    public static function fromInput(?string $from, ?string $to, ?string $date = null, ?int $slack = null): self
    {
        $slack ??= AgentConfig::int('slack');

        if ($date !== null && $date !== '') {
            $day = self::parse($date, 'date');
            $from ??= $day->startOfDay()->toIso8601String();
            $to ??= $day->endOfDay()->toIso8601String();
        }

        $window = new self(
            $from === null || $from === '' ? null : self::toLog(self::parse($from, 'from')),
            $to === null || $to === '' ? null : self::toLog(self::parse($to, 'to')),
            $slack,
        );

        if ($window->from !== null && $window->to !== null && $window->from > $window->to) {
            throw new AgentException(422, 'from phải trước to.', ['from' => $window->from, 'to' => $window->to]);
        }

        return $window;
    }

    public function scanWindow(): Window
    {
        return new Window($this->from, $this->to, $this->slack);
    }

    /** Ngày (theo múi giờ log) để chọn file daily. */
    public function fromDate(): ?string
    {
        return $this->from === null ? null : substr($this->from, 0, 10);
    }

    public function toDate(): ?string
    {
        return $this->to === null ? null : substr($this->to, 0, 10);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'log_timezone' => AgentConfig::logTimezone(),
            'input_timezone' => AgentConfig::inputTimezone(),
            'slack_seconds' => $this->slack,
        ];
    }

    private static function parse(string $value, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, AgentConfig::inputTimezone());
        } catch (Throwable) {
            throw new AgentException(422, "Không hiểu `{$field}`: {$value}. Dùng ISO 8601 (2026-10-06T07:00+07:00), `Y-m-d H:i`, hoặc kiểu `-2 hours`.");
        }
    }

    private static function toLog(CarbonImmutable $time): string
    {
        return $time->setTimezone(AgentConfig::logTimezone())->format('Y-m-d H:i:s');
    }
}
