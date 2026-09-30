<?php

namespace App\Services;

use App\Contracts\DecisionProvider;
use Illuminate\Support\Str;
use RuntimeException;

class RaceSimulationService
{
    private const OPTIONS = ['ATACAR', 'MANTENER', 'CONSERVAR', 'FRENAR_ANTES', 'TOMAR_INTERIOR', 'TOMAR_EXTERIOR'];
    private const TICK_SECONDS = 5;

    public function __construct(private readonly DecisionProvider $jev) {}

    public function create(array $catalog, string $driverId, string $vehicleId, string $stageId): array
    {
        $driver = collect($catalog['drivers'])->firstWhere('id', $driverId);
        $vehicle = collect($catalog['vehicles'])->firstWhere('id', $vehicleId);
        $stage = collect($catalog['stages'])->firstWhere('id', $stageId);
        if (! $driver || ! $vehicle || ! $stage) {
            throw new RuntimeException('Selecciona un piloto, vehículo y etapa válidos.');
        }

        $now = now()->toISOString();
        $seed = random_int(1, 2_000_000_000);

        return [
            'id' => (string) Str::uuid(),
            'status' => 'created',
            'stage' => $stage,
            'simulation_seed' => $seed,
            'rng_state' => $seed,
            'generator' => 'Park-Miller-48271-v1',
            'tick' => 0,
            'entries' => [['id' => (string) Str::uuid(), 'driver' => $driver, 'vehicle' => $vehicle, 'position' => 1]],
            'states' => [$this->initialState()],
            'decisions' => [],
            'events' => [],
            'result' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public function start(array $race): array
    {
        if ($race['status'] !== 'created') {
            return $race;
        }
        $race['status'] = 'running';
        $race['states'][0]['current_action'] = 'MANTENER';
        $race['states'][0]['needs_decision'] = true;
        $race['states'][0]['decision_reason'] = 'Salida de etapa';
        $race['started_at'] = now()->toISOString();

        return $race;
    }

    public function tick(array $race): array
    {
        if ($race['status'] !== 'running') {
            return $race;
        }

        $state = &$race['states'][0];
        $events = [];
        $race['tick']++;
        $state['tick'] = $race['tick'];
        $state['elapsed_seconds'] = round($state['elapsed_seconds'] + self::TICK_SECONDS, 2);
        $state['decision_reason'] = 'Intervalo de estrategia';
        $sector = $this->sectorAt($race['stage'], $state['distance_m']);
        $state['sector_number'] = $sector['number'];
        $state['sector'] = $sector;

        if ($state['needs_decision'] || $race['tick'] % 3 === 1) {
            $this->makeDecision($race, $sector);
            $state = &$race['states'][0];
        }
        $decisionIndex = count($race['decisions']) - 1;
        $decisionWasMade = $decisionIndex >= 0 && $race['decisions'][$decisionIndex]['tick'] === $race['tick'];

        $action = $state['current_action'];
        $weather = $race['stage']['default_weather'];
        $surfaceGrip = ['asfalto' => 1.0, 'grava' => 0.86, 'tierra' => 0.79][$sector['surface']] ?? 0.84;
        $weatherGrip = ['seco' => 1.0, 'lluvia' => 0.83, 'niebla' => 0.92][$weather] ?? 1.0;
        $tireGrip = 0.55 + ($state['tires'] / 100) * 0.45;
        $actionTarget = match ($action) {
            'ATACAR' => 168,
            'FRENAR_ANTES' => 96,
            'CONSERVAR' => 112,
            'TOMAR_INTERIOR' => 142,
            'TOMAR_EXTERIOR' => 136,
            default => 148,
        };
        $targetSpeed = $actionTarget * $surfaceGrip * $weatherGrip * $tireGrip * (0.76 + $race['entries'][0]['vehicle']['grip'] / 310);
        $acceleration = $race['entries'][0]['vehicle']['acceleration'] / 100;
        $state['speed_kmh'] = round(max(38, min(208, $state['speed_kmh'] + ($targetSpeed - $state['speed_kmh']) * (0.16 + $acceleration * 0.08))), 1);
        $distance = $state['speed_kmh'] * 1000 / 3600 * self::TICK_SECONDS;
        $oldSectorNumber = $sector['number'];
        $newDistance = min($race['stage']['distance_m'], $state['distance_m'] + $distance);
        $nextSector = $this->sectorAt($race['stage'], $newDistance);
        if ($nextSector['number'] !== $oldSectorNumber) {
            $previousDistance = $state['distance_m'];
            $state['distance_m'] = $newDistance;
            $this->makeDecision($race, $nextSector);
            $state = &$race['states'][0];
            $state['distance_m'] = $previousDistance;
            $decisionIndex = count($race['decisions']) - 1;
            $decisionWasMade = true;
            $action = $state['current_action'];
        }
        $roll = $this->randomUnit($race);
        $risk = min(0.95, ($sector['risk'] / 105) + ($action === 'ATACAR' ? 0.24 : 0) + ($weather === 'lluvia' ? 0.12 : 0) + ((100 - $state['tires']) / 300));
        $incident = $roll < $risk * 0.11;

        if ($incident) {
            $lost = round(1.2 + $this->randomUnit($race) * 3.5, 1);
            $state['elapsed_seconds'] += $lost;
            $state['damage'] = min(100, $state['damage'] + 1 + (int) ($this->randomUnit($race) * 5));
            $state['speed_kmh'] *= 0.72;
            $eventType = $this->randomUnit($race) < 0.65 ? 'derrape' : 'golpe';
            $events[] = ['type' => $eventType, 'label' => $eventType === 'derrape' ? 'Derrape' : 'Impacto leve', 'time_lost' => $lost, 'damage' => $state['damage'], 'sector' => $sector['number'], 'tick' => $race['tick'], 'created_at' => now()->toISOString()];
            $state['last_event'] = $events[array_key_last($events)];
        }

        $state['distance_m'] = $newDistance;
        $state['tires'] = max(0, round($state['tires'] - (0.018 + ($action === 'ATACAR' ? 0.025 : 0) + ($sector['surface'] === 'grava' ? 0.008 : 0)), 1));
        $state['brakes'] = max(0, round($state['brakes'] - (0.012 + (str_contains($sector['type'], 'curvas') ? 0.018 : 0)), 1));
        $state['engine'] = max(0, round($state['engine'] - (0.008 + ($action === 'ATACAR' ? 0.014 : 0)), 1));
        $state['fuel'] = max(0, round($state['fuel'] - 0.045, 1));
        $state['temperature'] = round(min(130, max(75, $state['temperature'] + ($action === 'ATACAR' ? 1.1 : -0.2))), 1);
        if ($state['temperature'] > 112 && $state['temperature'] - 1.1 <= 112) {
            $events[] = ['type' => 'overheating', 'label' => 'Temperatura elevada', 'sector' => $sector['number'], 'tick' => $race['tick'], 'created_at' => now()->toISOString()];
        }
        $state['time_display'] = $this->formatTime($state['elapsed_seconds']);
        $state['distance_km'] = round($state['distance_m'] / 1000, 2);
        $state['progress'] = round($state['distance_m'] / $race['stage']['distance_m'] * 100, 2);
        $activeSector = $sector;
        if ($nextSector['number'] !== $oldSectorNumber) {
            $state['sector_number'] = $nextSector['number'];
            $state['sector'] = $nextSector;
            $activeSector = $nextSector;
        }
        $state['next_curve_distance'] = max(0, (int) (($activeSector['length_m'] + $activeSector['start_m']) - $state['distance_m']));
        $state['needs_decision'] = $incident || $nextSector['number'] !== $oldSectorNumber;
        if ($incident) {
            $state['decision_reason'] = 'Recuperación después de incidente';
        } elseif ($nextSector['number'] !== $oldSectorNumber) {
            $state['decision_reason'] = 'Cambio de sector';
        }

        if ($state['damage'] >= 100 || $state['engine'] <= 0 || $state['fuel'] <= 0) {
            $race['status'] = 'abandoned';
            $events[] = ['type' => 'abandonment', 'label' => 'Abandono: fallo mecánico', 'sector' => $sector['number'], 'tick' => $race['tick'], 'created_at' => now()->toISOString()];
        } elseif ($state['distance_m'] >= $race['stage']['distance_m']) {
            $race['status'] = 'finished';
            $events[] = ['type' => 'finish', 'label' => 'Etapa completada', 'sector' => $sector['number'], 'tick' => $race['tick'], 'created_at' => now()->toISOString()];
        }

        foreach ($events as $event) {
            $race['events'][] = $event;
        }

        if ($decisionWasMade) {
            $race['decisions'][$decisionIndex]['result'] = [
                'time_after_tick' => $state['elapsed_seconds'],
                'incident' => $events[0]['type'] ?? null,
                'distance_after_tick_m' => round($state['distance_m'], 1),
            ];
            $race['decisions'][$decisionIndex]['state_after'] = [
                'speed_kmh' => $state['speed_kmh'],
                'damage' => $state['damage'],
                'tires' => $state['tires'],
                'brakes' => $state['brakes'],
                'engine' => $state['engine'],
                'distance_m' => round($state['distance_m'], 1),
            ];
            $state['current_decision'] = $race['decisions'][$decisionIndex];
        }

        if (in_array($race['status'], ['finished', 'abandoned'], true)) {
            $race['result'] = $this->result($race, $race['status']);
        }

        return $race;
    }

    private function makeDecision(array &$race, array $sector): void
    {
        $state = &$race['states'][0];
        $before = ['speed_kmh' => $state['speed_kmh'], 'damage' => $state['damage'], 'tires' => $state['tires'], 'brakes' => $state['brakes'], 'engine' => $state['engine'], 'distance_m' => $state['distance_m'], 'sector_number' => $sector['number'], 'weather' => $race['stage']['default_weather']];
        $context = ['state' => $state, 'sector' => $sector, 'weather' => $race['stage']['default_weather'], 'driver' => $race['entries'][0]['driver'], 'vehicle' => $race['entries'][0]['vehicle'], 'distance_to_finish' => $race['stage']['distance_m'] - $state['distance_m']];
        try {
            $response = $this->jev->decide($context, self::OPTIONS);
            $action = $response['selected_action'] ?? 'MANTENER';
            if (! in_array($action, self::OPTIONS, true)) {
                throw new RuntimeException('Mock Jev returned an invalid action.');
            }
            $fallback = false;
            $error = null;
        } catch (\Throwable $exception) {
            $action = 'MANTENER';
            $response = ['provider' => 'fallback', 'selected_action' => $action, 'probabilities' => null, 'raw_response' => null];
            $fallback = true;
            $error = $exception->getMessage();
        }

        $decision = ['id' => (string) Str::uuid(), 'tick' => $race['tick'], 'sector' => $sector['number'], 'simulated_at' => $state['elapsed_seconds'], 'state_before' => $before, 'options' => self::OPTIONS, 'provider' => $response['provider'], 'jev_response' => $response['raw_response'], 'selected_action' => $action, 'probabilities' => $response['probabilities'] ?? null, 'fallback_used' => $fallback, 'error' => $error, 'result' => null, 'created_at' => now()->toISOString()];
        $race['decisions'][] = $decision;
        $state['current_action'] = $action;
        $state['needs_decision'] = false;
        $state['decision_reason'] = 'Estrategia de sector';
        $state['current_decision'] = $decision;
    }

    private function sectorAt(array $stage, float $distance): array
    {
        foreach ($stage['sectors'] as $sector) {
            if ($distance < $sector['start_m'] + $sector['length_m']) {
                return $sector;
            }
        }

        $sectors = $stage['sectors'];

        return end($sectors);
    }

    private function initialState(): array
    {
        return ['speed_kmh' => 0, 'distance_m' => 0, 'distance_km' => 0, 'progress' => 0, 'elapsed_seconds' => 0, 'time_display' => '00:00.00', 'damage' => 0, 'tires' => 100, 'engine' => 100, 'brakes' => 100, 'fuel' => 100, 'temperature' => 82, 'sector_number' => 1, 'current_action' => 'MANTENER', 'current_decision' => null, 'needs_decision' => true, 'decision_reason' => 'Salida de etapa', 'next_curve_distance' => 0, 'last_event' => null, 'tick' => 0];
    }

    private function result(array $race, string $status): array
    {
        $state = $race['states'][0];
        $actions = array_count_values(array_column($race['decisions'], 'selected_action'));

        return ['status' => $status, 'elapsed_seconds' => $state['elapsed_seconds'], 'time_display' => $state['time_display'], 'position' => 1, 'damage' => $state['damage'], 'decision_count' => count($race['decisions']), 'attacks' => $actions['ATACAR'] ?? 0, 'incidents' => count(array_filter($race['events'], fn ($event) => in_array($event['type'], ['derrape', 'golpe'], true)))];
    }

    private function formatTime(float $seconds): string
    {
        $minutes = (int) floor($seconds / 60);
        $wholeSeconds = (int) floor($seconds) % 60;
        $centiseconds = (int) round(($seconds - floor($seconds)) * 100);
        if ($centiseconds >= 100) {
            $centiseconds = 0;
            $wholeSeconds++;
            if ($wholeSeconds >= 60) {
                $wholeSeconds = 0;
                $minutes++;
            }
        }

        return sprintf('%02d:%02d.%02d', $minutes, $wholeSeconds, $centiseconds);
    }

    private function randomUnit(array &$race): float
    {
        $state = (int) $race['rng_state'];
        $state = ($state * 48271) % 2147483647;
        $race['rng_state'] = $state;

        return $state / 2147483647;
    }
}
