<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

require_once __DIR__ . '/ContractModels.php';

/**
 * A fresh in-memory database and container per test, restored afterwards (the AggregationTest
 * pattern), seeded with:
 *
 *   users  1 Root (father null) ── 2 Staff ──┬── 3 Vet
 *                                             └── 4 Tech
 *          5 Other (father null)
 *   devices 1 (user 3, apns-…), 2 (user 4, fcm-…)
 */
trait BootsContractDatabase
{
    private Capsule $db;
    private Container $previousContainer;
    private mixed $previousResolver;
    private mixed $previousDispatcher;
    private mixed $previousFacade;

    protected function bootContractDatabase(array $filtering = []): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousFacade = Facade::getFacadeApplication();

        $container = new Container();
        $container->instance('config', new ConfigRepository([
            'rest-generic-class' => [
                'cache' => ['enabled' => false],
                'filtering' => array_merge([
                    'max_depth' => 5,
                    'max_conditions' => 100,
                    'strict_relations' => true,
                ], $filtering),
            ],
        ]));
        // RestController logs a failure before rethrowing it.
        $container->instance('log', new class {
            public function channel(...$args): static { return $this; }
            public function __call(string $method, array $args): void {}
        });
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $this->db = new Capsule($container);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db->setEventDispatcher(new Dispatcher($container));
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $container->instance('db', $this->db->getDatabaseManager());

        $schema = $this->db->getConnection()->getSchemaBuilder();
        $schema->create('contract_users', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('father_id')->nullable();
            $t->string('name');
            $t->string('password')->nullable();
            $t->boolean('active')->default(false);
            $t->timestamps();
        });
        $schema->create('contract_devices', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->string('device_token');
            $t->string('platform');
        });

        $this->db->getConnection()->table('contract_users')->insert([
            ['id' => 1, 'father_id' => null, 'name' => 'Root', 'password' => '$2y$12$root', 'active' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'father_id' => 1, 'name' => 'Staff', 'password' => '$2y$12$staff', 'active' => 1, 'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
            ['id' => 3, 'father_id' => 2, 'name' => 'Vet', 'password' => '$2y$12$vet', 'active' => 1, 'created_at' => '2026-01-03 00:00:00', 'updated_at' => '2026-01-03 00:00:00'],
            ['id' => 4, 'father_id' => 2, 'name' => 'Tech', 'password' => '$2y$12$tech', 'active' => 0, 'created_at' => '2026-01-04 00:00:00', 'updated_at' => '2026-01-04 00:00:00'],
            ['id' => 5, 'father_id' => null, 'name' => 'Other', 'password' => '$2y$12$other', 'active' => 0, 'created_at' => '2026-01-05 00:00:00', 'updated_at' => '2026-01-05 00:00:00'],
        ]);
        $this->db->getConnection()->table('contract_devices')->insert([
            ['id' => 1, 'user_id' => 3, 'device_token' => 'apns-vet', 'platform' => 'ios'],
            ['id' => 2, 'user_id' => 4, 'device_token' => 'fcm-tech', 'platform' => 'android'],
        ]);
    }

    protected function restoreContractDatabase(): void
    {
        $this->db->getConnection()->disconnect();
        if ($this->previousResolver) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
        if ($this->previousDispatcher) { Model::setEventDispatcher($this->previousDispatcher); }
        else { Model::unsetEventDispatcher(); }
        Container::setInstance($this->previousContainer);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
    }

    /** @return int[] */
    private function ids(mixed $result): array
    {
        $rows = is_array($result) && array_key_exists('data', $result) ? $result['data'] : $result;
        return array_map(static fn ($row) => (int) (is_array($row) ? $row['id'] : $row->id), (array) $rows);
    }
}
