<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\SlowLog;

use HocVT\LogViewerRemote\SlowLog\CompiledViewResolver;
use HocVT\LogViewerRemote\SlowLog\DebugbarLivewireSource;
use HocVT\LogViewerRemote\Tests\Feature\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Livewire\Compiler\CacheManager;

/**
 * Debugbar không nằm trong require-dev: collector giả giữ đúng bề mặt mà bản vá dựa vào —
 * property `queries` không public, mỗi query có `source` là danh sách frame object `file`/`name`.
 */
class DebugbarLivewireSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/slow-log-debugbar-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/compiled');
        File::ensureDirectoryExists($this->dir.'/components');
        File::put($this->dir.'/components/⚡demo.blade.php', '<div></div>');

        config([
            'view.compiled' => $this->dir.'/compiled',
            'livewire.component_locations' => [$this->dir.'/components'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_livewire_cache_frame_is_renamed_to_component_and_other_frames_are_untouched(): void
    {
        $sfc = $this->dir.'/components/⚡demo.blade.php';
        $livewire = $this->frame((new CacheManager($this->dir.'/compiled/livewire'))->getClassPath($sfc));
        $model = $this->frame('/srv/app/app/Models/User.php');
        $this->fakeDebugbar([['source' => [$livewire, $model]]]);

        (new DebugbarLivewireSource)->patch();

        $this->assertSame($sfc, $livewire->file);
        $this->assertSame((new CompiledViewResolver)->relative($sfc), $livewire->name);
        $this->assertSame('view', $livewire->namespace);
        $this->assertSame('/srv/app/app/Models/User.php', $model->file);
    }

    public function test_register_patches_after_each_query_once_debugbar_is_bound(): void
    {
        $sfc = $this->dir.'/components/⚡demo.blade.php';
        $frame = $this->frame((new CacheManager($this->dir.'/compiled/livewire'))->getClassPath($sfc));
        $this->fakeDebugbar([['source' => [$frame]]]);

        DebugbarLivewireSource::register();
        Event::dispatch(QueryExecuted::class);

        $this->assertSame($sfc, $frame->file);
    }

    private function frame(string $file): object
    {
        return (object) ['file' => $file, 'name' => basename($file), 'namespace' => null];
    }

    /** @param  list<array<string, mixed>>  $queries */
    private function fakeDebugbar(array $queries): void
    {
        $collector = new class($queries)
        {
            /** Debugbar khai `protected $queries`: bản vá phải đọc được qua reflection. */
            public function __construct(protected array $queries) {}
        };

        $this->app->instance('debugbar', new class($collector)
        {
            public function __construct(private object $collector) {}

            public function isEnabled(): bool
            {
                return true;
            }

            public function hasCollector(string $name): bool
            {
                return $name === 'queries';
            }

            public function getCollector(string $name): object
            {
                return $this->collector;
            }
        });
    }
}
