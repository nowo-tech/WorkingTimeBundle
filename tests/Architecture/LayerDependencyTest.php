<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function in_array;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function str_contains;
use function str_ends_with;
use function str_replace;

/**
 * Layer rules of contract section 4 (REQ-ARCH-001), checked on the source text:
 *
 * - `Holiday` imports nothing from `Register` or `Leave` (the holiday component is usable on its own, contract section 1);
 * - `Register` imports nothing from `Leave` (leave hooks into the register through a port);
 * - `Domain` imports no Symfony, Doctrine or HTTP class.
 */
final class LayerDependencyTest extends TestCase
{
    private const ROOT_NAMESPACE = 'Nowo\\WorkingTimeBundle\\';

    public function testTheHolidayComponentImportsNothingFromRegisterOrLeave(): void
    {
        self::assertSame([], $this->violations('Holiday', ['Register', 'Leave']));
    }

    public function testTheRegisterImportsNothingFromLeave(): void
    {
        self::assertSame([], $this->violations('Register', ['Leave']));
    }

    public function testTheDomainImportsNoSymfonyDoctrineOrHttpClass(): void
    {
        $violations = [];
        foreach ($this->sourceFiles() as $path => $source) {
            if (str_contains($path, '/Domain/') && preg_match('/^\s*use\s+(Symfony|Doctrine|Psr\\\\Http)\\\\/m', $source) === 1) {
                $violations[] = $path;
            }
        }

        self::assertSame([], $violations);
    }

    public function testTheDetectorFlagsAnImportOfAForbiddenComponent(): void
    {
        $source = "<?php\nnamespace Nowo\\WorkingTimeBundle\\Domain\\Holiday;\n\nuse Nowo\\WorkingTimeBundle\\Domain\\Register\\Workday;\nuse Nowo\\WorkingTimeBundle\\Application\\Leave\\LeaveRequestService;\n";

        self::assertSame(['Register', 'Leave'], $this->importedComponents($source, ['Register', 'Leave']));
        self::assertSame([], $this->importedComponents("<?php\nuse Nowo\\WorkingTimeBundle\\Domain\\Holiday\\Day;\n", ['Register', 'Leave']));
    }

    public function testTheSourceTreeIsScanned(): void
    {
        self::assertNotSame([], $this->sourceFiles(), 'src/ must contain PHP files');
    }

    /**
     * @param list<string> $forbidden component names that must not be imported
     *
     * @return list<string> "path: component" per violation
     */
    private function violations(string $component, array $forbidden): array
    {
        $violations = [];
        foreach ($this->sourceFiles() as $path => $source) {
            if (!$this->belongsTo($path, $source, $component)) {
                continue;
            }
            foreach ($this->importedComponents($source, $forbidden) as $imported) {
                $violations[] = $path . ': ' . $imported;
            }
        }

        return $violations;
    }

    private function belongsTo(string $path, string $source, string $component): bool
    {
        return str_contains($path, '/' . $component . '/') || preg_match('/^namespace\s+[\w\\\\]*\\\\' . $component . '\b/m', $source) === 1;
    }

    /**
     * @param list<string> $components
     *
     * @return list<string> the forbidden components the source imports
     */
    private function importedComponents(string $source, array $components): array
    {
        $found = [];
        preg_match_all('/^\s*use\s+(?:function\s+|const\s+)?' . preg_quote(self::ROOT_NAMESPACE, '/') . '([\w\\\\]+)/m', $source, $matches);
        foreach ($matches[1] as $imported) {
            foreach ($components as $component) {
                if (preg_match('/(^|\\\\)' . $component . '(\\\\|$)/', $imported) === 1 && !in_array($component, $found, true)) {
                    $found[] = $component;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, string> path relative to src/ => source text
     */
    private function sourceFiles(): array
    {
        $root  = dirname(__DIR__, 2) . '/src';
        $files = [];
        $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, '.php')) {
                $files[str_replace($root, '', $path)] = (string) file_get_contents($path);
            }
        }

        return $files;
    }
}
