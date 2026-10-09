<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/channel-authorization-functions.php';

final class EnableChannelAuthorizationScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rgc-script-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/routes', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/routes/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir . '/routes');
        rmdir($this->dir);
    }

    public function testScriptAbortsOutsideALaravelProject(): void
    {
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/enable-channel-authorization.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(2, proc_close($proc), $stdout . $stderr);
        $this->assertStringContainsString('not an installed Laravel project', $stderr);
    }

    public function testRoutePatchesReportOnlyTheNamesOfEachChannelFile(): void
    {
        $declaration = "<?php\nRoute::group(['as' => '%s.'], function () {\n"
            . "    Route::get('/permissions', [AuthController::class, 'permissions'])->name('auth.permissions');\n"
            . "    Route::get('/user-profile', [AuthController::class, 'profile'])->name('auth.user-profile');\n"
            . "    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');\n"
            . "});\n";
        $files = [];
        foreach (['admin', 'client', 'mobile'] as $channel) {
            $files[] = $file = "{$this->dir}/routes/$channel.php";
            file_put_contents($file, sprintf($declaration, $channel));
        }
        $targets = [];
        foreach (['admin', 'client', 'mobile'] as $channel) {
            foreach (['auth.permissions', 'auth.user-profile'] as $local) {
                $targets[] = ['name' => "$channel.$local", 'global' => false];
            }
        }

        $patches = planRoutePatches($files, $targets, ['admin', 'client', 'mobile'], ['*.auth.permissions', '*.auth.user-profile']);

        foreach (['admin', 'client', 'mobile'] as $i => $channel) {
            $patch = $patches[$files[$i]];
            $this->assertSame(["$channel.auth.permissions", "$channel.auth.user-profile"], $patch['names']);
            $this->assertCount(2, $patch['diff']);
            $this->assertStringContainsString(
                "Route::group(['permission_global' => true], fn () => Route::get('/permissions', [AuthController::class, 'permissions'])->name('auth.permissions'));",
                $patch['content']
            );
            $this->assertStringContainsString("Route::post('/login', [AuthController::class, 'login'])->name('auth.login');", $patch['content']);
        }
    }

    public function testRoutePatchesUseTheChannelMentionedInTheFileAndSkipMarkedRoutes(): void
    {
        $file = "{$this->dir}/routes/backoffice.php";
        file_put_contents($file, "<?php\nRoute::group(['prefix' => 'admin', 'as' => 'admin.'], function () {\n"
            . "    Route::get('/permissions', fn () => 1)->name('auth.permissions');\n});\n");
        $targets = [['name' => 'admin.auth.permissions', 'global' => false], ['name' => 'client.auth.permissions', 'global' => false]];

        $patches = planRoutePatches([$file], $targets, ['admin', 'client'], ['*.auth.permissions']);
        $this->assertSame(['admin.auth.permissions'], $patches[$file]['names']);

        $marked = [['name' => 'admin.auth.permissions', 'global' => true]];
        $this->assertSame([], planRoutePatches([$file], $marked, ['admin'], ['*.auth.permissions']));
    }

    public function testRoutePatchesKeepEveryCandidateWhenTheFileServesSeveralChannels(): void
    {
        $file = "{$this->dir}/routes/shared.php";
        file_put_contents($file, "<?php\nRoute::get('/permissions', fn () => 1)->name('auth.permissions');\n");
        $targets = [['name' => 'admin.auth.permissions', 'global' => false], ['name' => 'client.auth.permissions', 'global' => false]];

        $patches = planRoutePatches([$file], $targets, ['admin', 'client'], ['*.auth.permissions']);
        $this->assertSame(['admin.auth.permissions', 'client.auth.permissions'], $patches[$file]['names']);
    }

    public function testMatchesAnyUsesShellStyleWildcards(): void
    {
        $this->assertTrue(matchesAny('admin.auth.permissions', ['*.auth.permissions']));
        $this->assertTrue(matchesAny('admin.auth.permissions', ['x', 'admin.*']));
        $this->assertFalse(matchesAny('admin.auth.permissions-extra', ['*.auth.permissions']));
        $this->assertFalse(matchesAny('admin.authXpermissions', ['*.auth.permissions']));
        $this->assertFalse(matchesAny('admin.auth.permissions', []));
    }

    public function testStatementStartSkipsBlanksAndComments(): void
    {
        $src = "<?php\n\$a = 1;\n// comment\n/* block */\n  Route::get('x')->name('y');";
        $pos = strpos($src, '->name');
        $this->assertSame(strpos($src, 'Route::'), statementStart($src, $pos));
        $this->assertNull(statementStart('<?php ; ', 7));
    }

    public function testConstraintAllowsMinChecksTheLowerBound(): void
    {
        foreach (['^2.7', '~2.7.1', '>=2.7', '2.7.*', '^3.0', '^2.10'] as $constraint) {
            $this->assertTrue(constraintAllowsMin($constraint), $constraint);
        }
        foreach (['^2.3.2', '2.6.*', 'dev-main'] as $constraint) {
            $this->assertFalse(constraintAllowsMin($constraint), $constraint);
        }
    }

    public function testParseGrantAcceptsPrefixesAndFiles(): void
    {
        $this->assertSame(['role' => 'Administrator', 'prefix' => 'admin.', 'file' => null], parseGrant('Administrator:admin.'));
        $this->assertSame(['role' => 'Client', 'prefix' => null, 'file' => 'perms/client.json'], parseGrant('Client:@perms/client.json'));
        $this->assertSame(['role' => 'Client', 'prefix' => null, 'file' => 'C:/x.json'], parseGrant('Client:@C:/x.json'));
        foreach (['Client', 'Client:', ':admin.', 'Client:@'] as $invalid) {
            $this->assertNull(parseGrant($invalid), $invalid);
        }
    }

    public function testProgressLinesMarkTheInterruptedStep(): void
    {
        $steps = ['composer.json', 'rutas', 'sincronización'];
        $this->assertSame(['  ✔ composer.json', '  ✔ rutas', '  ✘ sincronización'], progressLines($steps, 2, true));
        $this->assertSame(['  ✔ composer.json', '  · rutas', '  · sincronización'], progressLines($steps, 1, false));
        $this->assertSame(['  ✔ composer.json', '  ✔ rutas', '  ✔ sincronización'], progressLines($steps, 3, false));
    }
}
