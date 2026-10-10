<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class DesignSystemLintTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir().'/nntmux-design-lint-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem;
        foreach (['scripts', 'resources/views', 'resources/js', 'resources/css', 'resources/forum/blade-tailwind/views'] as $directory) {
            $filesystem->mkdir($this->fixtureRoot.'/'.$directory);
        }
        $filesystem->copy(dirname(__DIR__, 2).'/scripts/check-design-system.sh', $this->fixtureRoot.'/scripts/check-design-system.sh');
        file_put_contents($this->fixtureRoot.'/resources/css/app.css', '');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->remove($this->fixtureRoot);
        parent::tearDown();
    }

    #[DataProvider('violations')]
    public function test_rejects_public_design_regressions(string $path, string $source, string $diagnostic): void
    {
        $process = $this->lint($path, $source);
        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString($diagnostic, $process->getErrorOutput());
        $this->assertStringContainsString($path, $process->getErrorOutput());
    }

    /** @return array<string, array{string, string, string}> */
    public static function violations(): array
    {
        return [
            'white surface' => ['resources/views/browse/index.blade.php', '<div class="bg-white"></div>', 'hardcoded surface'],
            'gray dark hover' => ['resources/views/browse/index.blade.php', '<div class="dark:hover:bg-gray-800/50"></div>', 'hardcoded surface'],
            'live forum surface' => ['resources/forum/blade-tailwind/views/category/show.blade.php', '<div class="bg-white dark:bg-gray-800"></div>', 'hardcoded surface'],
            'indigo accent' => ['resources/views/browse/index.blade.php', '<a class="text-indigo-600">Link</a>', 'indigo-*/purple-*'],
            'purple component accent' => ['resources/views/components/example.blade.php', '<a class="hover:bg-purple-600">Link</a>', 'indigo-*/purple-*'],
            'FA4 clock' => ['resources/views/browse/index.blade.php', '<i class="fas fa-clock-o"></i>', 'FA4'],
            'FA4 external link' => ['resources/views/browse/index.blade.php', '<i class="fas fa-external-link"></i>', 'FA4'],
            'JS active palette' => ['resources/js/alpine/components/example.js', "button.classList.add('bg-blue-600');", 'JavaScript blue-*'],
            'colour scheme attribute' => ['resources/views/layouts/main.blade.php', '<html data-color-scheme="{{ $scheme }}">', 'colour scheme reference'],
            'colour scheme store' => ['resources/js/alpine/stores/theme.js', "this.\$store.theme.setScheme('violet');", 'colour scheme reference'],
            'arrow reorder label' => ['resources/views/home/shelves-dialog.blade.php', '<button aria-label="Move Movies up"><i class="fas fa-arrow-up"></i></button>', 'arrow-button reorder'],
            'arrow reorder title' => ['resources/views/home/shelves-dialog.blade.php', '<button title="Move {{ $shelf->name }} down">Down</button>', 'arrow-button reorder'],
            'arrow reorder bound label' => ['resources/views/home/shelves-dialog.blade.php', '<button :aria-label="\'Move \' + shelf.name + \' up\'">Up</button>', 'arrow-button reorder'],
            'arrow reorder in admin' => ['resources/views/admin/content/index.blade.php', '<button aria-label="Move up">Up</button>', 'arrow-button reorder'],
            'arrow reorder in forum' => ['resources/forum/blade-tailwind/views/category/show.blade.php', '<button title="Move down">Down</button>', 'arrow-button reorder'],
            'arrow reorder JS label' => ['resources/js/alpine/components/example.js', "button.setAttribute('aria-label', `Move \${name} down`);", 'arrow-button reorder'],
            'arrow icon button in reorder list' => ['resources/views/home/shelves-dialog.blade.php', "<ul data-reorder>\n<li>Movies\n<button type=\"button\">\n<i class=\"fas fa-chevron-up\"></i>\n</button></li></ul>", 'arrow-button reorder'],
            'arrow icon button in sortable list' => ['resources/views/home/shelves-dialog.blade.php', '<ul data-sortable><li><button><i class="fa-solid fa-arrow-down"></i></button></li></ul>', 'arrow-button reorder'],
            'arrow reorder label, any case' => ['resources/views/home/shelves-dialog.blade.php', '<button aria-label="Move Up">Up</button>', 'arrow-button reorder'],
            'arrow reorder translated label' => ['resources/views/home/shelves-dialog.blade.php', '<button title="{{ __(\'Move down\') }}">Down</button>', 'arrow-button reorder'],
            'arrow reorder JS property' => ['resources/js/alpine/components/example.js', "button.ariaLabel = 'Move ' + name + ' up';", 'arrow-button reorder'],
            'arrow icon button beside a shelf grip' => ['resources/views/home/shelves-dialog.blade.php', '<ul data-drag-zone><li><button data-grip></button><button @click="up({{ $shelf->id }})"><i class="fas fa-arrow-up"></i><span class="sr-only">Earlier</span></button></li></ul>', 'arrow-button reorder'],
            'arrow icon button beside a drag handle' => ['resources/views/admin/content/index.blade.php', '<tr><td><button data-drag-handle draggable="true"></button><button :disabled="index > 0"><i class="fas fa-caret-down"></i></button></td></tr>', 'arrow-button reorder'],
            'arrow icon move button' => ['resources/js/alpine/components/example.js', 'row.innerHTML = `<button data-shelf-mv="up"><i class="fas fa-arrow-up"></i></button>`;', 'arrow-button reorder'],
        ];
    }

    #[DataProvider('allowedSources')]
    public function test_preserves_component_and_documented_scope_boundaries(string $path, string $source): void
    {
        $process = $this->lint($path, $source);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /** @return array<string, array{string, string}> */
    public static function allowedSources(): array
    {
        return [
            'semantic public surface' => ['resources/views/browse/index.blade.php', '<div class="surface-panel-alt text-primary-600 dark:text-primary-400"></div>'],
            'component surface' => ['resources/views/components/example.blade.php', '<div class="bg-white dark:bg-gray-800"></div>'],
            'forum component surface' => ['resources/forum/blade-tailwind/views/components/input.blade.php', '<input class="bg-white dark:bg-gray-800">'],
            'admin view' => ['resources/views/admin/example.blade.php', '<div class="bg-white text-purple-600"><i class="fa fa-clock-o"></i></div>'],
            'admin component' => ['resources/views/components/admin/example.blade.php', '<span class="text-purple-600">Count</span>'],
            'admin JS' => ['resources/js/alpine/components/admin-example.js', "button.classList.add('bg-blue-600');"],
            'email' => ['resources/views/emails/example.blade.php', '<div style="color:blue" class="bg-white text-indigo-600"></div>'],
            'forum category color' => ['resources/forum/blade-tailwind/views/category/show.blade.php', '<div style="background: {{ $category->color }}"></div>'],
            'modern FA suffix' => ['resources/views/browse/index.blade.php', '<i class="fas fa-external-link-alt"></i>'],
            'JS primary state' => ['resources/js/alpine/components/example.js', "button.classList.add('bg-primary-600');"],
            'stat arrow beside a number' => ['resources/views/admin/dashboard.blade.php', '<span class="text-sm text-green-600 dark:text-green-400"><i class="fas fa-arrow-up"></i> <span data-stat="releases-today">12</span> today</span>'],
            'stat arrow in a file with a reorder list' => ['resources/views/admin/dashboard.blade.php', '<ul data-reorder><li>Movies</li></ul><span><i class="fas fa-arrow-up"></i> <span data-stat="releases-today">12</span> today</span>'],
            'two labels on one line' => ['resources/js/alpine/components/example.js', "const item = { title: 'Move it', hint: 'scroll up' };"],
            'drag grip' => ['resources/views/admin/content/index.blade.php', '<ul data-reorder><li><button draggable="true" aria-label="Drag to reorder shelves"><i class="fas fa-grip-vertical"></i></button></li></ul>'],
            'offsite link icon in reorder list' => ['resources/views/home/shelves-dialog.blade.php', '<ul data-reorder><li><button><i class="fas fa-arrow-up-right-from-square"></i></button></li></ul>'],
            'collapse chevron outside a reorder list' => ['resources/views/browse/index.blade.php', '<button aria-label="Collapse filters"><i class="fas fa-chevron-up"></i></button>'],
            'move label that is not a direction' => ['resources/views/browse/index.blade.php', '<button aria-label="Move to another list">Move</button>'],
        ];
    }

    private function lint(string $path, string $source): Process
    {
        (new Filesystem)->mkdir(dirname($this->fixtureRoot.'/'.$path));
        file_put_contents($this->fixtureRoot.'/'.$path, $source);
        $process = new Process(['bash', 'scripts/check-design-system.sh'], $this->fixtureRoot);
        $process->run();

        return $process;
    }
}
