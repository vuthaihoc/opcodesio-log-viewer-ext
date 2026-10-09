<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\SlowLog;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;

/**
 * Vá tên nguồn query của Debugbar cho Livewire.
 *
 * Debugbar (QueryCollector::parseTrace) thấy frame nằm trong storage_path() thì lấy tên file
 * làm hash rồi tra findViewFromHash(), vốn chỉ so với hash xxh128 32 ký tự của Blade. File cache
 * của Livewire lại đặt tên bằng hash md5 8 ký tự, không bao giờ khớp, nên Debugbar in hash thô.
 *
 * Thay vì thay cả QueryCollector (phải chép lại ~130 dòng __invoke của DatabaseCollectorProvider),
 * ở đây chỉ nghe QueryExecuted SAU Debugbar rồi sửa lại frame vừa được ghi. Bề mặt phụ thuộc gói
 * gọn ở property `queries` và các frame có `name`/`file`.
 *
 * Chỉ chạy ở local, khi Debugbar bật. Tắt bằng `log-viewer-ext.slow_log.debugbar_livewire` (độc lập
 * với `slow_log.enabled`: đây là công cụ dev, không phải phần ghi log).
 */
class DebugbarLivewireSource
{
    private CompiledViewResolver $resolver;

    /** Đánh dấu query đã xử lý để không quét lại toàn bộ mỗi lần. */
    private int $handled = 0;

    public function __construct(?CompiledViewResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new CompiledViewResolver;
    }

    public static function register(): void
    {
        // Debugbar gắn listener QueryExecuted trong boot() của ServiceProvider nó. Đăng ký ở
        // booted() thì chắc chắn đứng SAU listener đó, bất kể thứ tự nạp provider giữa các package.
        app()->booted(static function (): void {
            if (app()->bound('debugbar')) {
                Event::listen(QueryExecuted::class, (new self)->patch(...));
            }
        });
    }

    public function patch(): void
    {
        try {
            $debugbar = app('debugbar');

            if (! $debugbar->isEnabled() || ! $debugbar->hasCollector('queries')) {
                return;
            }

            $collector = $debugbar->getCollector('queries');

            $prop = new \ReflectionProperty($collector, 'queries');
            $prop->setAccessible(true);
            /** @var array<int, array<string, mixed>> $queries */
            $queries = $prop->getValue($collector);

            $total = count($queries);

            // Debugbar::reset() có thể xoá sạch danh sách giữa chừng.
            if ($total < $this->handled) {
                $this->handled = 0;
            }

            if ($total === $this->handled) {
                return;
            }

            for ($i = $this->handled; $i < $total; $i++) {
                foreach ($queries[$i]['source'] ?? [] as $frame) {
                    if (! is_object($frame) || empty($frame->file)) {
                        continue;
                    }

                    if (! $this->resolver->isCompiled((string) $frame->file)) {
                        continue;
                    }

                    $source = $this->resolver->resolve((string) $frame->file);

                    if ($source === $frame->file) {
                        continue;
                    }

                    // Frame là object nên sửa tại chỗ là collector thấy ngay,
                    // không cần ghi ngược mảng $queries.
                    $frame->file = $source;
                    $frame->name = $this->resolver->relative($source);
                    $frame->namespace = 'view';
                }
            }

            $this->handled = $total;
        } catch (\Throwable) {
            // Công cụ dev: hỏng thì im lặng, không được ảnh hưởng request.
        }
    }
}
