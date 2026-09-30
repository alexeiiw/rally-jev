<?php

namespace Tests\Unit;

use App\Services\MockJevService;
use App\Services\RaceSimulationService;

class RaceSimulationServiceTest extends \Tests\TestCase
{
    private function simulation(): RaceSimulationService
    {
        return new RaceSimulationService(new MockJevService());
    }

    private function catalog(): array
    {
        $sectors = [];
        $distance = 0;
        for ($i = 1; $i <= 12; $i++) {
            $sectors[] = ['id' => 'sector-'.$i, 'number' => $i, 'name' => 'Sector '.$i, 'start_m' => $distance, 'type' => $i % 4 === 0 ? 'curvas cerradas' : 'recta', 'length_m' => 1525, 'surface' => 'grava', 'difficulty' => 50, 'curves' => 2, 'critical_curve' => 'izquierda rápida', 'slope' => 'plano', 'risk' => 30, 'visibility' => 'buena'];
            $distance += 1525;
        }

        return [
            'drivers' => [['id' => 'jev-01', 'name' => 'JEV-01', 'aggressiveness' => 70, 'conservation' => 45, 'risk_tolerance' => 65]],
            'vehicles' => [['id' => 'rally-x1', 'name' => 'RALLY-X1', 'power' => 320, 'weight_kg' => 1280, 'acceleration' => 78, 'braking' => 72, 'grip' => 74, 'durability' => 80]],
            'stages' => [['id' => 'sierra-de-la-mina', 'name' => 'Sierra de la Mina', 'distance_m' => $distance, 'distance_km' => 18.3, 'difficulty' => 'media-alta', 'default_weather' => 'seco', 'sectors' => $sectors]],
        ];
    }

    public function test_decisions_and_random_consequences_replay_from_the_same_seed(): void
    {
        $simulation = $this->simulation();
        $catalog = $this->catalog();
        $first = $simulation->create($catalog, 'jev-01', 'rally-x1', 'sierra-de-la-mina');
        $second = $simulation->create($catalog, 'jev-01', 'rally-x1', 'sierra-de-la-mina');
        $second['id'] = $first['id'];
        $second['simulation_seed'] = $first['simulation_seed'] = 1357911;
        $second['rng_state'] = $first['rng_state'] = 1357911;
        $first = $simulation->start($first);
        $second = $simulation->start($second);

        for ($i = 0; $i < 8; $i++) {
            $first = $simulation->tick($first);
            $second = $simulation->tick($second);
        }

        self::assertSame($first['rng_state'], $second['rng_state']);
        self::assertSame($this->withoutRuntimeIds($first['states']), $this->withoutRuntimeIds($second['states']));
        self::assertSame($this->withoutRuntimeIds($first['decisions']), $this->withoutRuntimeIds($second['decisions']));
        self::assertSame($this->withoutRuntimeIds($first['events']), $this->withoutRuntimeIds($second['events']));
    }

    public function test_tick_records_a_decision_and_advances_official_state(): void
    {
        $race = $this->simulation()->create($this->catalog(), 'jev-01', 'rally-x1', 'sierra-de-la-mina');
        $simulation = $this->simulation();
        $race = $simulation->start($race);
        $race['states'][0]['needs_decision'] = true;
        $race = $simulation->tick($race);

        self::assertSame('running', $race['status']);
        self::assertGreaterThan(0, $race['states'][0]['distance_m']);
        self::assertSame(1, count($race['decisions']));
        self::assertContains($race['decisions'][0]['selected_action'], $race['decisions'][0]['options']);
        self::assertNotNull($race['decisions'][0]['state_after']);
        self::assertNull($race['decisions'][0]['probabilities']);
    }

    public function test_race_finishes_and_persists_a_derived_result(): void
    {
        $simulation = $this->simulation();
        $race = $simulation->create($this->catalog(), 'jev-01', 'rally-x1', 'sierra-de-la-mina');
        $race['simulation_seed'] = $race['rng_state'] = 24680;
        $race = $simulation->start($race);

        for ($i = 0; $i < 200 && $race['status'] === 'running'; $i++) {
            $race = $simulation->tick($race);
        }

        self::assertContains($race['status'], ['finished', 'abandoned']);
        self::assertNotNull($race['result']);
        self::assertSame(count($race['decisions']), $race['result']['decision_count']);
        self::assertContains($race['result']['status'], ['finished', 'abandoned']);
    }

    private function withoutRuntimeIds(array $value): array
    {
        foreach ($value as $key => $item) {
            if (in_array($key, ['id', 'created_at', 'updated_at'], true)) {
                unset($value[$key]);
            } elseif (is_array($item)) {
                $value[$key] = $this->withoutRuntimeIds($item);
            }
        }

        return $value;
    }
}
