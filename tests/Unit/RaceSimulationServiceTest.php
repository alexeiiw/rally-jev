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
            'drivers' => [
                ['id' => 'jev-01', 'name' => 'JEV-01', 'age_group' => 'Intermedio', 'experience' => 70, 'reaction' => 72, 'aggressiveness' => 70, 'conservation' => 45, 'risk_tolerance' => 65],
                ['id' => 'jev-02', 'name' => 'JEV-02', 'age_group' => 'Mayor', 'experience' => 85, 'reaction' => 65, 'aggressiveness' => 48, 'conservation' => 75, 'risk_tolerance' => 45],
            ],
            'vehicles' => [
                ['id' => 'rally-x1', 'name' => 'RALLY-X1', 'class' => 'Bueno', 'power' => 320, 'weight_kg' => 1280, 'acceleration' => 78, 'braking' => 72, 'grip' => 74, 'durability' => 80],
                ['id' => 'rally-x2', 'name' => 'RALLY-X2', 'class' => 'Básico', 'power' => 250, 'weight_kg' => 1370, 'acceleration' => 55, 'braking' => 53, 'grip' => 52, 'durability' => 60],
            ],
            'stages' => [['id' => 'sierra-de-la-mina', 'name' => 'Sierra de la Mina', 'distance_m' => $distance, 'distance_km' => 18.3, 'difficulty' => 'media-alta', 'default_weather' => 'seco', 'sectors' => $sectors]],
        ];
    }

    public function test_decisions_and_random_consequences_replay_from_the_same_seed(): void
    {
        $simulation = $this->simulation();
        $catalog = $this->catalog();
        $selection = [['driver_id' => 'jev-01', 'vehicle_id' => 'rally-x1'], ['driver_id' => 'jev-02', 'vehicle_id' => 'rally-x2']];
        $first = $simulation->create($catalog, $selection, 'sierra-de-la-mina', 1357911);
        $second = $simulation->create($catalog, $selection, 'sierra-de-la-mina', 1357911);
        $second['id'] = $first['id'];
        $second['entries'] = $first['entries'];
        $second['simulation_seed'] = $first['simulation_seed'] = 1357911;
        $second['rng_state'] = $first['rng_state'] = 1357911;
        $first = $simulation->start($first);
        $second = $simulation->start($second);

        for ($i = 0; $i < 80; $i++) {
            $first = $simulation->tick($first);
            $second = $simulation->tick($second);
        }

        self::assertSame(array_column($first['states'], 'rng_state'), array_column($second['states'], 'rng_state'));
        self::assertSame($this->withoutRuntimeIds($first['states']), $this->withoutRuntimeIds($second['states']));
        self::assertSame($this->withoutRuntimeIds($first['decisions']), $this->withoutRuntimeIds($second['decisions']));
        self::assertSame($this->withoutRuntimeIds($first['events']), $this->withoutRuntimeIds($second['events']));
    }

    public function test_tick_records_a_decision_and_advances_official_state(): void
    {
        $race = $this->simulation()->create($this->catalog(), [['driver_id' => 'jev-01', 'vehicle_id' => 'rally-x1'], ['driver_id' => 'jev-02', 'vehicle_id' => 'rally-x2']], 'sierra-de-la-mina', 98765);
        $simulation = $this->simulation();
        $race = $simulation->start($race);
        $race['states'][0]['needs_decision'] = true;
        $race = $simulation->tick($race);

        self::assertSame('running', $race['status']);
        self::assertGreaterThan(0, $race['states'][0]['distance_m']);
        self::assertCount(2, $race['decisions']);
        self::assertCount(2, $race['states']);
        self::assertCount(2, $race['entries']);
        self::assertSame($race['tick'], $race['states'][0]['tick']);
        self::assertSame($race['tick'], $race['states'][1]['tick']);
        self::assertContains($race['decisions'][0]['selected_action'], $race['decisions'][0]['options']);
        self::assertNotNull($race['decisions'][0]['state_after']);
        self::assertNull($race['decisions'][0]['probabilities']);
        self::assertNotSame($race['states'][0]['rng_state'], $race['states'][1]['rng_state']);
        self::assertGreaterThan(0, $race['states'][1]['distance_m']);
    }

    public function test_race_finishes_and_persists_a_derived_result(): void
    {
        $simulation = $this->simulation();
        $race = $simulation->create($this->catalog(), [['driver_id' => 'jev-01', 'vehicle_id' => 'rally-x1'], ['driver_id' => 'jev-02', 'vehicle_id' => 'rally-x2']], 'sierra-de-la-mina', 24680);
        $race['simulation_seed'] = $race['rng_state'] = 24680;
        $race = $simulation->start($race);

        for ($i = 0; $i < 250 && $race['status'] === 'running'; $i++) {
            $race = $simulation->tick($race);
        }

        self::assertSame('finished', $race['status']);
        self::assertNotNull($race['result']);
        self::assertCount(2, $race['result']['classification']);
        self::assertNotNull($race['result']['winner_entry_id']);
        self::assertCount(2, array_unique(array_column($race['result']['classification'], 'position')));
    }

    public function test_two_entry_race_rejects_the_same_driver_twice(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->simulation()->create($this->catalog(), [['driver_id' => 'jev-01', 'vehicle_id' => 'rally-x1'], ['driver_id' => 'jev-01', 'vehicle_id' => 'rally-x2']], 'sierra-de-la-mina');
    }

    public function test_vehicle_quality_changes_speed_for_the_same_pilot_and_stage(): void
    {
        $catalog = $this->catalog();
        $simulation = $this->simulation();
        $race = $simulation->create($catalog, [['driver_id' => 'jev-01', 'vehicle_id' => 'rally-x1'], ['driver_id' => 'jev-02', 'vehicle_id' => 'rally-x2']], 'sierra-de-la-mina', 112233);
        $race['states'][0]['status'] = $race['states'][1]['status'] = 'running';
        $race['states'][0]['current_action'] = $race['states'][1]['current_action'] = 'MANTENER';
        $race['states'][0]['needs_decision'] = $race['states'][1]['needs_decision'] = false;
        $race['tick'] = 3;

        $race = $simulation->tick($race);

        self::assertGreaterThan($race['states'][1]['speed_kmh'], $race['states'][0]['speed_kmh']);
    }

    public function test_finished_entry_stops_advancing_while_opponent_continues(): void
    {
        $simulation = $this->simulation();
        $race = $simulation->create($this->catalog(), [['driver_id' => 'jev-01', 'vehicle_id' => 'rally-x1'], ['driver_id' => 'jev-02', 'vehicle_id' => 'rally-x2']], 'sierra-de-la-mina', 998877);
        $race = $simulation->start($race);
        $race['states'][0]['distance_m'] = $race['stage']['distance_m'] - 120;
        $race['states'][0]['elapsed_seconds'] = 1000;
        $race['states'][0]['speed_kmh'] = 200;
        $race['tick'] = 2;

        $race = $simulation->tick($race);
        self::assertSame('finished', $race['states'][0]['status']);
        $finishedDistance = $race['states'][0]['distance_m'];
        $finishedTime = $race['states'][0]['elapsed_seconds'];
        $opponentDistance = $race['states'][1]['distance_m'];

        $race = $simulation->tick($race);
        self::assertSame($finishedDistance, $race['states'][0]['distance_m']);
        self::assertSame($finishedTime, $race['states'][0]['elapsed_seconds']);
        self::assertGreaterThan($opponentDistance, $race['states'][1]['distance_m']);
        self::assertSame('running', $race['status']);
    }

    public function test_simultaneous_finishers_are_ordered_by_crossing_time(): void
    {
        $simulation = $this->simulation();
        $race = $simulation->create($this->catalog(), [['driver_id' => 'jev-01', 'vehicle_id' => 'rally-x1'], ['driver_id' => 'jev-02', 'vehicle_id' => 'rally-x2']], 'sierra-de-la-mina', 13579);
        $race = $simulation->start($race);
        foreach ($race['states'] as $index => $state) {
            $race['states'][$index]['distance_m'] = $race['stage']['distance_m'] - (80 + ($index * 50));
            $race['states'][$index]['elapsed_seconds'] = 600;
            $race['states'][$index]['speed_kmh'] = 200;
            $race['states'][$index]['needs_decision'] = false;
        }
        $race['tick'] = 2;

        $race = $simulation->tick($race);

        self::assertSame('finished', $race['status']);
        self::assertSame('finished', $race['states'][0]['status']);
        self::assertSame('finished', $race['states'][1]['status']);
        self::assertLessThan($race['states'][1]['finish_time'], $race['states'][0]['finish_time']);
        self::assertSame($race['entries'][0]['id'], $race['result']['winner_entry_id']);
    }

    public function test_double_abandonment_classifies_by_distance_without_declaring_winner(): void
    {
        $simulation = $this->simulation();
        $race = $simulation->create($this->catalog(), [['driver_id' => 'jev-01', 'vehicle_id' => 'rally-x1'], ['driver_id' => 'jev-02', 'vehicle_id' => 'rally-x2']], 'sierra-de-la-mina', 778899);
        $race = $simulation->start($race);
        $race['states'][0]['distance_m'] = 2500;
        $race['states'][1]['distance_m'] = 4000;
        $race['states'][0]['engine'] = $race['states'][1]['engine'] = 0;
        $race['states'][0]['needs_decision'] = $race['states'][1]['needs_decision'] = false;
        $race['tick'] = 2;
        $race = $simulation->tick($race);

        self::assertSame('finished', $race['status']);
        self::assertNull($race['result']['winner_entry_id']);
        self::assertSame('abandoned', $race['result']['classification'][0]['status']);
        self::assertSame('abandoned', $race['result']['classification'][1]['status']);
        self::assertGreaterThan($race['result']['classification'][1]['distance_m'], $race['result']['classification'][0]['distance_m']);
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
