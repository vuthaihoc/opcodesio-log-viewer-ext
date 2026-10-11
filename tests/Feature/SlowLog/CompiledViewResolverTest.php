<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Feature\SlowLog;

use HocVT\LogViewerRemote\SlowLog\CompiledViewResolver;
use HocVT\LogViewerRemote\Tests\Feature\TestCase;
use Illuminate\Support\Facades\File;
use Livewire\Compiler\CacheManager;

/**
 * Đường dẫn cache Livewire tính bằng chính CacheManager của Livewire: nâng Livewire mà
 * công thức hash đổi thì test này đỏ trước khi trace trên production hiện hash thô.
 */
class CompiledViewResolverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/slow-log-resolver-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/compiled');
        File::ensureDirectoryExists($this->dir.'/components/⚡panel');
        File::put($this->dir.'/components/⚡demo.blade.php', '<div></div>');
        File::put($this->dir.'/components/⚡panel/panel.php', '<?php');

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

    public function test_blade_compiled_file_resolves_to_source_via_path_marker(): void
    {
        $compiled = $this->bladeCompiled('/srv/app/resources/views/home.blade.php');

        $this->assertSame('/srv/app/resources/views/home.blade.php', (new CompiledViewResolver)->resolve($compiled));
    }

    public function test_blade_flag_off_keeps_compiled_path(): void
    {
        $compiled = $this->bladeCompiled('/srv/app/resources/views/home.blade.php');

        $this->assertSame($compiled, (new CompiledViewResolver(blade: false))->resolve($compiled));
    }

    public function test_livewire_sfc_and_mfc_cache_resolve_to_component(): void
    {
        $cache = $this->livewireCache();
        $resolver = new CompiledViewResolver;

        $sfc = $this->dir.'/components/⚡demo.blade.php';
        $this->assertSame($sfc, $resolver->resolve($cache->getClassPath($sfc)));
        $this->assertSame($sfc, $resolver->resolve($cache->getViewPath($sfc)));

        $mfc = $this->dir.'/components/⚡panel';
        $this->assertSame($mfc, $resolver->resolve($cache->getClassPath($mfc)));
    }

    public function test_livewire_flag_off_keeps_cache_path(): void
    {
        $classPath = $this->livewireCache()->getClassPath($this->dir.'/components/⚡demo.blade.php');

        $this->assertSame($classPath, (new CompiledViewResolver(livewire: false))->resolve($classPath));
    }

    public function test_files_outside_compiled_dir_are_untouched(): void
    {
        $this->assertSame(app_path('Models/User.php'), (new CompiledViewResolver)->resolve(app_path('Models/User.php')));
    }

    private function bladeCompiled(string $source): string
    {
        $compiled = $this->dir.'/compiled/'.md5($source).'.php';
        File::put($compiled, "<div><?php echo 1; ?></div>\n<?php /**PATH {$source} ENDPATH**/ ?>\n");

        return $compiled;
    }

    /** Cùng thư mục cache LivewireServiceProvider dựng cho `livewire.compiler`. */
    private function livewireCache(): CacheManager
    {
        return new CacheManager(rtrim((string) config('view.compiled'), '/\\').'/livewire');
    }
}
