<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory as ValidatorFactory;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Helpers\HelpersValidations;
use Ronu\RestGenericClass\Core\Validation\UpdateArrayUniqueValidator;

final class UpdateArrayUniqueValidatorTest extends TestCase
{
    private static ?Capsule $capsule = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$capsule === null) {
            $capsule = new Capsule(new Container());
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            $capsule->setEventDispatcher(new Dispatcher(new Container()));
            $capsule->bootEloquent();
            $capsule->getConnection()->getSchemaBuilder()->create('users', function ($table) {
                $table->increments('id');
                $table->string('email');
            });

            self::$capsule = $capsule;
        }

        $container = Container::getInstance();
        $container->instance('db', self::$capsule->getDatabaseManager());
        $container->instance('validator', $this->makeValidatorFactory($container));
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('db');
        Facade::clearResolvedInstance('validator');

        $this->connection()->table('users')->delete();
        $this->connection()->table('users')->insert([
            ['id' => 1, 'email' => 'taken@example.com'],
            ['id' => 2, 'email' => 'current@example.com'],
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('db');
        Facade::clearResolvedInstance('validator');

        parent::tearDown();
    }

    public function testParseAttributeKeepsLegacyTableIndexAndColumnShape(): void
    {
        $this->assertSame([
            'table' => 'tenant.users',
            'index' => '3',
            'column' => 'email',
        ], UpdateArrayUniqueValidator::parseAttribute('users.3.email', 'tenant'));

        $this->assertSame([
            'table' => 'users',
            'index' => 0,
            'column' => 0,
        ], UpdateArrayUniqueValidator::parseAttribute('users'));
    }

    public function testValidateReturnsUniqueMessageWhenAnotherRowOwnsValue(): void
    {
        $request = (object) ['users' => [['id' => 2]]];

        $this->assertSame(
            'The email has already been taken.',
            UpdateArrayUniqueValidator::validate('users.0.email', 'taken@example.com', $request, 'id')
        );
    }

    public function testValidateIgnoresCurrentItemAndSkipsMissingIds(): void
    {
        $this->assertNull(UpdateArrayUniqueValidator::validate(
            'users.0.email',
            'current@example.com',
            (object) ['users' => [['id' => 2]]],
            'id'
        ));

        $this->assertNull(UpdateArrayUniqueValidator::validate(
            'users.0.email',
            'taken@example.com',
            (object) ['users' => [[]]],
            'id'
        ));
    }

    public function testLegacyHelperDelegatesAndCallsFailOnlyOnConflict(): void
    {
        $failedMessages = [];
        $request = (object) ['users' => [['id' => 2]]];

        HelpersValidations::validateUniqueValueInUpdateArray(
            'users.0.email',
            'taken@example.com',
            function (string $message) use (&$failedMessages): void {
                $failedMessages[] = $message;
            },
            $request,
            'id'
        );

        HelpersValidations::validateUniqueValueInUpdateArray(
            'users.0.email',
            'current@example.com',
            function (string $message) use (&$failedMessages): void {
                $failedMessages[] = $message;
            },
            $request,
            'id'
        );

        $this->assertSame(['The email has already been taken.'], $failedMessages);
    }

    private function makeValidatorFactory(Container $container): ValidatorFactory
    {
        $loader = new ArrayLoader();
        $loader->addMessages('en', 'validation', [
            'unique' => 'The :attribute has already been taken.',
        ]);

        $factory = new ValidatorFactory(new Translator($loader, 'en'), $container);
        $factory->setPresenceVerifier(
            new DatabasePresenceVerifier(self::$capsule->getDatabaseManager())
        );

        return $factory;
    }

    private function connection()
    {
        return self::$capsule->getConnection();
    }
}
