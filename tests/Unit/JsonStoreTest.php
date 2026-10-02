<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use Tests\TestCase;
use App\Services\JsonStore;

class JsonStoreTest extends TestCase
{
    private string $dataPath;
    private JsonStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataPath = sys_get_temp_dir().'/rally-json-'.bin2hex(random_bytes(8));
        $this->removeDataDirectory();
        $this->store = JsonStore::forTesting($this->dataPath);
    }

    protected function tearDown(): void
    {
        $this->removeDataDirectory();
        parent::tearDown();
    }

    public function test_catalog_setup_is_idempotent_and_does_not_overwrite_existing_races(): void
    {
        $store = $this->store;
        $store->ensureInitialized();
        $race = [
            'id' => (string) Str::uuid(),
            'status' => 'created',
            'stage' => ['name' => 'Test Stage'],
            'entries' => [['driver' => ['name' => 'TEST'], 'vehicle' => ['name' => 'TEST CAR'], 'position' => 1]],
            'states' => [['elapsed_seconds' => 0, 'damage' => 0]],
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];
        $store->createRace($race);
        $store->ensureInitialized();

        self::assertSame($race['id'], $store->history()[0]['id']);
        self::assertSame($race['id'], $store->race($race['id'])['id']);
        self::assertSame('Sierra de la Mina', $store->catalog()['stages'][0]['name']);
    }

    public function test_setup_adds_new_driver_and_vehicle_profiles_without_replacing_custom_catalog_entries(): void
    {
        $this->store->ensureInitialized();
        $catalogPath = $this->dataPath.'/catalog/drivers.json';
        $custom = ['id' => 'custom-driver', 'name' => 'MI PILOTO', 'age_group' => 'Custom', 'experience' => 50, 'reaction' => 50, 'aggressiveness' => 50, 'conservation' => 50, 'risk_tolerance' => 50];
        file_put_contents($catalogPath, json_encode([$custom], JSON_THROW_ON_ERROR));

        $this->store->ensureInitialized();
        $catalog = $this->store->catalog();

        self::assertSame('MI PILOTO', $catalog['drivers'][0]['name']);
        self::assertCount(4, $catalog['drivers']);
        self::assertCount(3, $catalog['vehicles']);
        self::assertCount(1, $catalog['stages']);
    }

    public function test_events_are_appended_as_individual_json_lines_and_can_be_read(): void
    {
        $this->store->ensureInitialized();
        $event = ['type' => 'derrape', 'sector' => 4, 'label' => 'Derrape'];
        $raceId = (string) Str::uuid();
        $this->store->appendEvents($raceId, [$event]);
        $this->store->appendEvents($raceId, [['type' => 'finish', 'sector' => 12]]);

        self::assertSame([[$event['type'] ?? 'derrape', $event['label'] ?? 'Derrape'], ['finish', null]], array_map(fn (array $stored) => [$stored['type'], $stored['label'] ?? null], $this->store->events($raceId)));
    }

    public function test_create_update_and_read_race_do_not_deadlock_on_json_locks(): void
    {
        $store = $this->store;
        $race = [
            'id' => (string) Str::uuid(),
            'status' => 'created',
            'stage' => ['id' => 'test-stage', 'name' => 'Test Stage'],
            'entries' => [
                ['id' => 'entry-1', 'driver' => ['name' => 'PILOTO A'], 'vehicle' => ['name' => 'AUTO A'], 'position' => 1],
                ['id' => 'entry-2', 'driver' => ['name' => 'PILOTO B'], 'vehicle' => ['name' => 'AUTO B'], 'position' => 2],
            ],
            'states' => [
                ['status' => 'created', 'elapsed_seconds' => 0, 'damage' => 0, 'time_display' => '00:00.00'],
                ['status' => 'created', 'elapsed_seconds' => 0, 'damage' => 0, 'time_display' => '00:00.00'],
            ],
            'result' => null,
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        $store->createRace($race);
        $updated = $store->updateRace($race['id'], function (array $data): array {
            $data['status'] = 'running';

            return $data;
        });
        $store->appendEvents($race['id'], [['type' => 'finish', 'label' => 'Etapa completada']]);

        self::assertSame('running', $updated['status']);
        self::assertSame('running', $store->race($race['id'])['status']);
        self::assertCount(1, $store->events($race['id']));
        self::assertSame($race['id'], $store->history()[0]['id']);
    }

    private function removeDataDirectory(): void
    {
        if (! is_dir($this->dataPath)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dataPath);
    }
}
