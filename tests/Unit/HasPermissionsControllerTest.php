<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Ronu\RestGenericClass\Core\Traits\HasPermissionsController;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelAuthorizationTestCase;

/** HasPermissionsService's constructor calls parent::__construct(), as real controllers have a parent. */
abstract class PermissionsControllerBase
{
    public function __construct()
    {
    }
}

final class HasPermissionsControllerTest extends ChannelAuthorizationTestCase
{
    public function testModulesListsModuleNamesPlusTheGeneralModuleWithoutTheService(): void
    {
        $this->assertSame(['ModSecurity', 'ModSales', '--site--'], $this->controller()->modules(Request::create('/admin/modules')));
    }

    public function testEnabledFilterAcceptsBooleanStrings(): void
    {
        $controller = $this->controller();
        foreach (['true', '1', 'TRUE', 'on'] as $value) {
            $this->assertSame(['ModSecurity', '--site--'], $controller->modules($this->request(['enabled' => $value])), $value);
        }
        foreach (['false', '0', 'off'] as $value) {
            $this->assertSame(['ModSales', '--site--'], $controller->modules($this->request(['enabled' => $value])), $value);
        }
    }

    public function testMissingEmptyOrUnrecognizedEnabledReturnsAllModules(): void
    {
        $controller = $this->controller();
        foreach ([[], ['enabled' => ''], ['enabled' => 'maybe'], ['enabled' => ['x']]] as $query) {
            $this->assertSame(['ModSecurity', 'ModSales', '--site--'], $controller->modules($this->request($query)));
        }
    }

    public function testUriFormatReturnsTheRouteSegment(): void
    {
        $controller = $this->controller();
        $this->assertSame(['mod_security', 'mod_sales', '--site--'], $controller->modules($this->request(['format' => 'uri'])));
        $this->assertSame(['mod_security', '--site--'], $controller->modules($this->request(['format' => 'uri', 'enabled' => 'true'])));
        $this->assertSame(['ModSecurity', 'ModSales', '--site--'], $controller->modules($this->request(['format' => 'name'])));
    }

    public function testWithoutNwidartOnlyTheGeneralModuleIsReturned(): void
    {
        // nwidart/laravel-modules is not a dev dependency, so the real lookup runs without it.
        $controller = new class extends PermissionsControllerBase {
            use HasPermissionsController;
        };
        $this->assertSame([], $controller::getModules());
        $this->assertSame(['--site--'], $controller->modules($this->request(['enabled' => 'true'])));
    }

    private function request(array $query): Request
    {
        return Request::create('/admin/modules', 'GET', $query);
    }

    private function controller(): object
    {
        return new class extends PermissionsControllerBase {
            use HasPermissionsController;

            protected static function moduleCollection(): Collection
            {
                $module = static fn (string $name, bool $enabled) => new class($name, $enabled) {
                    public function __construct(private string $name, private bool $enabled) {}
                    public function getName(): string { return $this->name; }
                    public function isEnabled(): bool { return $this->enabled; }
                };
                return new Collection([$module('ModSecurity', true), $module('ModSales', false)]);
            }
        };
    }
}
