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

    public function test_events_are_appended_as_individual_json_lines_and_can_be_read(): void
    {
        $this->store->ensureInitialized();
        $event = ['type' => 'derrape', 'sector' => 4, 'label' => 'Derrape'];
        $raceId = (string) Str::uuid();
        $this->store->appendEvents($raceId, [$event]);
        $this->store->appendEvents($raceId, [['type' => 'finish', 'sector' => 12]]);

        self::assertSame([$event, ['type' => 'finish', 'sector' => 12]], $this->store->events($raceId));
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
