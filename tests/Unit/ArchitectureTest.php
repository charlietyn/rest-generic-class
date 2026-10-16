<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The CRUD core must not know the route permissions nor any permission backend: it talks to
 * Core\Contracts\Authorization and Laravel's Gate only. Comments do not count (tokens are scanned).
 */
final class ArchitectureTest extends TestCase
{
    /** Route permissions shipped in this repository until they move to their own package. */
    private const PERMISSION_AREA = [
        'Support/Permissions/',
        'Support/Authorization/',
        'Resolvers/',
        'Middleware/SpatieAuthorize.php',
        'Models/SpatiePermission.php',
        'Models/SpatieRole.php',
        'Traits/HasPermissionsService.php',
        'Traits/HasPermissionsController.php',
        'Traits/HasReadableUserPermissions.php',
        'Traits/HasReadableRolePermissions.php',
        'Traits/NormalizesSpatieNames.php',
        'Controllers/PermissionsController.php',
        // Wiring: binds the route permissions' AuthorizationContext while they live here.
        'Providers/',
    ];

    private const FORBIDDEN = [
        'Ronu\\RestGenericClass\\Core\\Support\\Permissions\\',
        'Ronu\\RestGenericClass\\Core\\Support\\Authorization\\',
        'Ronu\\RestGenericClass\\Core\\Resolvers\\',
        'Ronu\\RestGenericClass\\Core\\Middleware\\SpatieAuthorize',
        'Ronu\\RestGenericClass\\Core\\Models\\SpatiePermission',
        'Ronu\\RestGenericClass\\Core\\Models\\SpatieRole',
        'Ronu\\RestGenericClass\\Core\\Traits\\HasPermissions',
        'Ronu\\RestGenericClass\\Core\\Traits\\HasReadable',
        'Ronu\\RestGenericClass\\Core\\Traits\\NormalizesSpatieNames',
        'Ronu\\RestGenericClass\\Core\\Controllers\\PermissionsController',
        'Spatie\\',
    ];

    public function testTheCoreDoesNotReferenceThePermissionArea(): void
    {
        $violations = [];
        foreach ($this->coreFiles() as $relative => $path) {
            foreach ($this->references($path) as [$line, $name]) {
                foreach (self::FORBIDDEN as $forbidden) {
                    if (str_starts_with(ltrim($name, '\\'), $forbidden)) {
                        $violations[] = "{$relative}:{$line} {$name}";
                    }
                }
            }
        }
        $this->assertSame([], $violations, "The core references the permission area:\n" . implode("\n", $violations));
    }

    public function testTheScannerSeesUseStatementsAndQualifiedNames(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'arch');
        file_put_contents($file, "<?php\nuse Spatie\\Permission\\Guard;\n// Spatie\\Permission\\Ignored\n\$x = \\Spatie\\Permission\\PermissionRegistrar::class;\n\$y = 'Spatie\\\\Permission\\\\Models\\\\Role';\n");
        try {
            $this->assertSame(
                ['Spatie\\Permission\\Guard', '\\Spatie\\Permission\\PermissionRegistrar', 'Spatie\\Permission\\Models\\Role'],
                array_column($this->references($file), 1)
            );
        } finally {
            unlink($file);
        }
    }

    /** @return array<string, string> relative path => absolute path */
    private function coreFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Core/';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
            foreach (self::PERMISSION_AREA as $excluded) {
                if (str_starts_with($relative, $excluded)) {
                    continue 2;
                }
            }
            $files[$relative] = $file->getPathname();
        }
        $this->assertNotEmpty($files);
        return $files;
    }

    /** @return list<array{int, string}> [line, qualified name] in code and string literals, not comments */
    private function references(string $path): array
    {
        $names = [];
        foreach (\PhpToken::tokenize(file_get_contents($path)) as $token) {
            if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $names[] = [$token->line, $token->text];
            } elseif ($token->is(T_CONSTANT_ENCAPSED_STRING) && str_contains($token->text, '\\')) {
                $names[] = [$token->line, stripcslashes(trim($token->text, '\'"'))];
            }
        }
        return $names;
    }
}
