<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\SlowLog;

use Illuminate\Support\Str;

/**
 * Quy đường dẫn file đã biên dịch về file nguồn. Hai tầng bật tắt riêng
 * (config `slow-log.trace.blade` / `slow-log.trace.livewire`):
 *
 * - Blade: `storage/framework/views/<xxh128>.php` → đọc marker `PATH` Blade ghi ở cuối file.
 * - Livewire 4: hai tầng biên dịch nên stack trace không chỉ thẳng vào file gốc:
 *
 *   ⚡component.blade.php
 *     ├─ storage/framework/views/livewire/classes/<hash8>.php      (phần PHP, nơi mount() chạy)
 *     └─ storage/framework/views/livewire/views/<hash8>.blade.php  (phần template)
 *          └─ storage/framework/views/<xxh128>.php                 (Blade compile tiếp)
 *
 *   <hash8> = substr(md5(đường dẫn tương đối so với base_path), 0, 8) — với SFC là đường dẫn
 *   FILE, với MFC là đường dẫn THƯ MỤC ⚡tên-component (Livewire\Compiler\CacheManager).
 *
 * App không cài Livewire thì `livewire.component_locations` rỗng, tầng Livewire tự thành no-op.
 */
class CompiledViewResolver
{
    /** @var array<string, string>|null hash8 -> đường dẫn nguồn */
    private ?array $livewireMap = null;

    private string $compiledPath;

    private string $livewirePath;

    public function __construct(
        private readonly bool $blade = true,
        private readonly bool $livewire = true,
    ) {
        $this->compiledPath = rtrim((string) config('view.compiled', storage_path('framework/views')), '/\\');
        $this->livewirePath = $this->compiledPath.DIRECTORY_SEPARATOR.'livewire';
    }

    /**
     * Trả về đường dẫn nguồn, hoặc chính $file nếu không quy được.
     */
    public function resolve(string $file): string
    {
        // Blade compiled -> đọc marker PATH ở cuối file.
        if ($this->blade && str_starts_with($file, $this->compiledPath) && ! str_starts_with($file, $this->livewirePath)) {
            $file = $this->readPathMarker($file) ?? $file;
        }

        // File cache của Livewire (class hoặc view trung gian) -> tra ngược ra ⚡component.
        if ($this->livewire && str_starts_with($file, $this->livewirePath)) {
            $file = $this->resolveLivewireSource($file) ?? $file;
        }

        return $file;
    }

    public function isCompiled(string $file): bool
    {
        return str_starts_with($file, $this->compiledPath);
    }

    public function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }

    /**
     * Blade ghi `/**PATH <đường dẫn> ENDPATH*` + `/` ở cuối file compiled.
     */
    private function readPathMarker(string $file): ?string
    {
        if (! is_readable($file)) {
            return null;
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        fseek($handle, -512, SEEK_END);
        $tail = (string) fread($handle, 512);
        fclose($handle);

        return preg_match('/\*\*PATH (.+?) ENDPATH\*\*/', $tail, $m) === 1 ? $m[1] : null;
    }

    private function resolveLivewireSource(string $file): ?string
    {
        return $this->map()[Str::before(basename($file), '.')] ?? null;
    }

    /**
     * Dựng lazy, một lần mỗi process.
     *
     * @return array<string, string>
     */
    private function map(): array
    {
        if ($this->livewireMap !== null) {
            return $this->livewireMap;
        }

        $this->livewireMap = [];

        foreach ((array) config('livewire.component_locations', []) as $location) {
            if (! is_string($location) || ! is_dir($location)) {
                continue;
            }

            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($location, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($items as $item) {
                /** @var \SplFileInfo $item */
                $name = $item->getFilename();

                // SFC: file ⚡*.blade.php — MFC: thư mục ⚡tên-component.
                $isSfc = $item->isFile() && str_ends_with($name, '.blade.php');
                $isMfc = $item->isDir() && str_starts_with($name, '⚡');

                if (! $isSfc && ! $isMfc) {
                    continue;
                }

                $path = $item->getPathname();
                $this->livewireMap[substr(md5(Str::after($path, base_path())), 0, 8)] = $path;
            }
        }

        return $this->livewireMap;
    }
}
