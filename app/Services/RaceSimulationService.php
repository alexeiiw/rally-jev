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

    public function create(array $catalog, array $selections, string $stageId, ?int $seed = null): array
    {
        $stage = collect($catalog['stages'])->firstWhere('id', $stageId);
        if (! $stage || count($selections) !== 2) {
            throw new RuntimeException('Selecciona una etapa y exactamente dos participantes.');
        }

        $entries = [];
        foreach ($selections as $index => $selection) {
            $driver = collect($catalog['drivers'])->firstWhere('id', $selection['driver_id'] ?? null);
            $vehicle = collect($catalog['vehicles'])->firstWhere('id', $selection['vehicle_id'] ?? null);
            if (! $driver || ! $vehicle) {
                throw new RuntimeException('Selecciona pilotos y vehículos válidos para ambos participantes.');
            }
            if (in_array($driver['id'], array_column(array_column($entries, 'driver'), 'id'), true)) {
                throw new RuntimeException('Cada participante debe usar un piloto distinto.');
            }
            $entries[] = ['id' => (string) Str::uuid(), 'number' => $index + 1, 'driver' => $driver, 'vehicle' => $vehicle, 'position' => $index + 1];
        }

        $now = now()->toISOString();
        $seed ??= random_int(1, 2_000_000_000);
        $states = [];
        foreach ($entries as $index => $_entry) {
            $states[] = $this->initialState((($seed + (($index + 1) * 104729)) % 2147483646) + 1);
        }

        return [
            'id' => (string) Str::uuid(),
            'status' => 'created',
            'stage' => $stage,
            'simulation_seed' => $seed,
            'rng_state' => $seed,
            'generator' => 'Park-Miller-48271-v1',
            'legacy_entries' => array_map(fn (array $entry) => ['id' => $entry['id'], 'driver' => $entry['driver'], 'vehicle' => $entry['vehicle']], $entries),
            'tick' => 0,
            'entries' => $entries,
            'states' => $states,
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
        foreach ($race['states'] as &$state) {
            $state['status'] = 'running';
            $state['current_action'] = 'MANTENER';
            $state['needs_decision'] = true;
            $state['decision_reason'] = 'Salida de etapa';
        }
        unset($state);
        $race['started_at'] = now()->toISOString();

        return $race;
    }

    public function pause(array $race): array
    {
        if ($race['status'] === 'running') {
            $race['status'] = 'paused';
        }

        return $race;
    }

    public function resume(array $race): array
    {
        if ($race['status'] === 'paused') {
            $race['status'] = 'running';
        }

        return $race;
    }

    public function tick(array $race): array
    {
        if (! in_array($race['status'], ['running', 'created'], true)) {
            return $race;
        }
        if ($race['status'] === 'created') {
            $race = $this->start($race);
        }

        $race['tick']++;
        $previousEventIds = array_column($race['events'], 'id');
        $decisionsBefore = array_column($race['decisions'], 'id');
        foreach (array_keys($race['states']) as $index) {
            if (in_array($race['states'][$index]['status'], ['finished', 'abandoned'], true)) {
                continue;
            }
            $this->tickEntry($race, $index);
        }
        $decisionsById = [];
        foreach ($race['decisions'] as $decision) {
            $decisionsById[$decision['id']] = $decision;
        }
        $race['decisions'] = array_values($decisionsById);

        $this->updatePositions($race);
        foreach ($race['events'] as &$event) {
            if (($event['tick'] ?? null) !== $race['tick'] || ! in_array($event['type'] ?? null, ['finish', 'abandonment'], true)) {
                continue;
            }
            $entryIndex = array_search($event['entry_id'] ?? null, array_column($race['entries'], 'id'), true);
            if ($entryIndex !== false) {
                $event['position'] = $race['entries'][$entryIndex]['position'];
                $event['label'] = str_replace([' · Posición 1', ' · Posición 2'], '', $event['label']).' · Posición '.$event['position'];
            }
        }
        unset($event);
        $active = array_filter($race['states'], fn (array $state) => $state['status'] === 'running');
        if ($active === []) {
            $race['status'] = 'finished';
            $race['result'] = $this->classifyRace($race);
        }
        $race['new_events'] = array_values(array_filter($race['events'], fn (array $event) => ! in_array($event['id'], $previousEventIds, true)));
        $race['new_decisions'] = array_values(array_filter($race['decisions'], fn (array $decision) => ! in_array($decision['id'], $decisionsBefore, true)));
        return $race;
    }

    public function newEvents(array &$race): array
    {
        $events = $race['new_events'] ?? [];
        unset($race['new_events']);
        return $events;
    }

    public function newDecisions(array &$race): array
    {
        $decisions = $race['new_decisions'] ?? [];
        unset($race['new_decisions']);

        return $decisions;
    }

    private function tickEntry(array &$race, int $entryIndex): void
    {
        $state = &$race['states'][$entryIndex];
        $state['tick'] = $race['tick'];
        $entry = $race['entries'][$entryIndex];
        $localRace = $race;
        $localRace['states'] = [$state];
        $localRace['entries'] = [$entry];
        $localRace['entries'][0]['position'] = $entry['position'];
        $localRace['decisions'] = array_values(array_filter($race['decisions'], fn (array $decision) => $decision['entry_id'] === $entry['id']));
        $localRace['events'] = array_values(array_filter($race['events'], fn (array $event) => ($event['entry_id'] ?? null) === $entry['id']));
        $events = [];
        $elapsedBeforeTick = $state['elapsed_seconds'];
        $state['elapsed_seconds'] = round($state['elapsed_seconds'] + self::TICK_SECONDS, 2);
        $state['time_lost_this_tick'] = 0;
        $state['decision_reason'] = 'Intervalo de estrategia';
        $sector = $this->sectorAt($race['stage'], $state['distance_m']);
        $sectorChanged = $state['sector_number'] !== $sector['number'];
        $state['needs_decision'] = $state['needs_decision'] || $sectorChanged;
        $state['sector_number'] = $sector['number'];
        $state['sector'] = $sector;

        if ($state['needs_decision'] || $race['tick'] % 3 === 1) {
            $this->makeDecision($localRace, $sector, 0);
            $state = &$localRace['states'][0];
        }
        $decisionIndex = count($localRace['decisions']) - 1;
        $decisionWasMade = $decisionIndex >= 0 && $localRace['decisions'][$decisionIndex]['tick'] === $race['tick'];
        $decisionId = $decisionWasMade ? $localRace['decisions'][$decisionIndex]['id'] : null;

        $action = $state['current_action'];
        $weather = $race['stage']['default_weather'];
        $surfaceGrip = ['asfalto' => 1.0, 'grava' => 0.86, 'tierra' => 0.79][$sector['surface']] ?? 0.84;
        $weatherGrip = ['seco' => 1.0, 'lluvia' => 0.83, 'niebla' => 0.92][$weather] ?? 1.0;
        $weatherRisk = $weather === 'lluvia' ? 0.12 : 0;
        $tireGrip = 0.55 + ($state['tires'] / 100) * 0.45;
        $vehicle = $entry['vehicle'];
        $driver = $entry['driver'];
        $actionTarget = match ($action) {
            'ATACAR' => 168,
            'FRENAR_ANTES' => 96,
            'CONSERVAR' => 112,
            'TOMAR_INTERIOR' => 142,
            'TOMAR_EXTERIOR' => 136,
            default => 148,
        };
        $experienceBonus = 0.96 + (($driver['experience'] ?? 50) / 1250);
        $powerFactor = 0.74 + ($vehicle['power'] / 1000) * 0.75;
        $weightFactor = max(0.9, 1.13 - ($vehicle['weight_kg'] / 10000));
        $targetSpeed = $actionTarget * $surfaceGrip * $weatherGrip * $tireGrip * (0.64 + $vehicle['grip'] / 265) * $powerFactor * $weightFactor * $experienceBonus;
        $acceleration = $vehicle['acceleration'] / 100;
        $state['speed_kmh'] = round(max(35, min(208, $state['speed_kmh'] + ($targetSpeed - $state['speed_kmh']) * (0.13 + $acceleration * 0.11))), 1);
        $distanceBeforeTick = $state['distance_m'];
        $distance = $state['speed_kmh'] * 1000 / 3600 * self::TICK_SECONDS;
        $oldSectorNumber = $sector['number'];
        $newDistance = min($race['stage']['distance_m'], $state['distance_m'] + $distance);
        $nextSector = $this->sectorAt($race['stage'], $newDistance);
        $crossedSector = $nextSector['number'] !== $oldSectorNumber;
        if ($crossedSector) {
            $state['sector_number'] = $nextSector['number'];
            $state['sector'] = $nextSector;
            $state['needs_decision'] = true;
            $state['decision_reason'] = 'Cambio de sector';
        }
        $activeSector = $nextSector;

        $roll = $this->randomUnit($state);
        $risk = min(0.95, ($sector['risk'] / 105) + ($action === 'ATACAR' ? 0.24 : 0) + $weatherRisk + ((100 - $state['tires']) / 300) - (($driver['experience'] ?? 50) / 1500) - (($vehicle['braking'] ?? 50) / 2500));
        $incident = $roll < $risk * 0.11;
        if ($incident) {
            $lost = round(1.2 + $this->randomUnit($state) * 3.5, 1);
            $state['elapsed_seconds'] += $lost;
            $state['time_lost_this_tick'] = $lost;
            $state['damage'] = min(100, $state['damage'] + 1 + (int) ($this->randomUnit($state) * max(1, (101 - $vehicle['durability']) / 16)));
            $state['speed_kmh'] *= 0.72;
            $eventType = $this->randomUnit($state) < 0.65 ? 'derrape' : 'golpe';
            $events[] = ['type' => $eventType, 'label' => $eventType === 'derrape' ? 'Derrape' : 'Impacto leve', 'time_lost' => $lost, 'damage' => $state['damage'], 'sector' => $sector['number'], 'tick' => $race['tick'], 'entry_id' => $entry['id'], 'driver_name' => $driver['name'], 'created_at' => now()->toISOString()];
            $state['last_event'] = $events[array_key_last($events)];
        }

        $state['distance_m'] = $newDistance;
        $state['tires'] = max(0, round($state['tires'] - (0.018 + ($action === 'ATACAR' ? 0.025 : 0) + ($sector['surface'] === 'grava' ? 0.008 : 0)) * (1.15 - $vehicle['durability'] / 500), 1));
        $state['brakes'] = max(0, round($state['brakes'] - (0.012 + (str_contains($sector['type'], 'curvas') ? 0.018 : 0)) * (1.1 - $vehicle['durability'] / 1000), 1));
        $state['engine'] = max(0, round($state['engine'] - (0.008 + ($action === 'ATACAR' ? 0.014 : 0)) * (1.15 - $vehicle['durability'] / 500), 1));
        $state['fuel'] = max(0, round($state['fuel'] - (0.045 + ($vehicle['power'] / 10000)), 1));
        $state['temperature'] = round(min(130, max(75, $state['temperature'] + ($action === 'ATACAR' ? 1.1 : -0.2))), 1);
        if ($state['temperature'] > 112 && $state['temperature'] - 1.1 <= 112) {
            $events[] = ['type' => 'overheating', 'label' => 'Temperatura elevada', 'sector' => $sector['number'], 'tick' => $race['tick'], 'entry_id' => $entry['id'], 'driver_name' => $driver['name'], 'created_at' => now()->toISOString()];
        }
        $state['distance_km'] = round($state['distance_m'] / 1000, 2);
        $state['progress'] = round($state['distance_m'] / $race['stage']['distance_m'] * 100, 2);
        $state['next_curve_distance'] = max(0, (int) (($activeSector['length_m'] + $activeSector['start_m']) - $state['distance_m']));
        $state['needs_decision'] = $incident;
        if ($incident) {
            $state['decision_reason'] = 'Recuperación después de incidente';
        } elseif ($nextSector['number'] !== $oldSectorNumber) {
            $state['decision_reason'] = 'Cambio de sector';
        }

        if ($state['damage'] >= 100 || $state['engine'] <= 0 || $state['fuel'] <= 0) {
            $state['status'] = 'abandoned';
            $events[] = ['type' => 'abandonment', 'label' => 'Abandono: fallo mecánico', 'sector' => $sector['number'], 'tick' => $race['tick'], 'entry_id' => $entry['id'], 'driver_name' => $driver['name'], 'created_at' => now()->toISOString()];
        } elseif ($state['distance_m'] >= $race['stage']['distance_m']) {
            $state['status'] = 'finished';
            $events[] = ['type' => 'finish', 'label' => 'Etapa completada', 'sector' => $sector['number'], 'tick' => $race['tick'], 'entry_id' => $entry['id'], 'driver_name' => $driver['name'], 'created_at' => now()->toISOString()];
            $state['finish_time'] = $this->finishTimeForTick($race, $state, $distanceBeforeTick, $distance);
        }
        $officialTime = $state['finish_time'] ?? $state['elapsed_seconds'];
        $state['time_display'] = $this->formatTime($officialTime);
        $state['sector_time_display'] = $state['time_display'];
        $state['sector_time_delta'] = round($state['elapsed_seconds'] - $elapsedBeforeTick, 2);
        if ($state['status'] === 'finished' || $state['status'] === 'abandoned') {
            foreach ($events as &$event) {
                if ($event['type'] === 'finish' || $event['type'] === 'abandonment') {
                    $event['position'] = $entry['position'];
                    $event['label'] .= ' · Posición '.$entry['position'];
                }
            }
            unset($event);
        }

        if ($decisionId !== null) {
            $incidentEventType = null;
            foreach ($events as $event) {
                if (in_array($event['type'], ['derrape', 'golpe'], true)) {
                    $incidentEventType = $event['type'];
                    break;
                }
            }
            foreach ($localRace['decisions'] as &$decision) {
                if ($decision['id'] === $decisionId) {
                    $decision['result'] = ['time_after_tick' => $state['elapsed_seconds'], 'incident' => $incidentEventType, 'distance_after_tick_m' => round($state['distance_m'], 1)];
                    $decision['state_after'] = ['speed_kmh' => $state['speed_kmh'], 'damage' => $state['damage'], 'tires' => $state['tires'], 'brakes' => $state['brakes'], 'engine' => $state['engine'], 'distance_m' => round($state['distance_m'], 1)];
                    $state['current_decision'] = $decision;
                    break;
                }
            }
            unset($decision);
        }

        $race['states'][$entryIndex] = $state;
        $allDecisions = array_merge(
            array_values(array_filter($race['decisions'], fn (array $decision) => $decision['entry_id'] !== $entry['id'])),
            $localRace['decisions'],
        );
        $decisionMap = [];
        foreach ($allDecisions as $decision) {
            $decisionMap[$decision['id']] = $decision;
        }
        $race['decisions'] = array_values($decisionMap);
        usort($race['decisions'], fn (array $a, array $b) => [$a['tick'], $a['entry_id']] <=> [$b['tick'], $b['entry_id']]);
        foreach ($events as $event) {
            $event['id'] = (string) Str::uuid();
            $race['events'] = array_values(array_filter($race['events'], fn (array $existing) => ($existing['id'] ?? null) !== $event['id']));
            $race['events'][] = $event;
        }
    }

    private function makeDecision(array &$race, array $sector, int $entryIndex): void
    {
        $state = &$race['states'][$entryIndex];
        $entry = $race['entries'][$entryIndex];
        $before = ['speed_kmh' => $state['speed_kmh'], 'damage' => $state['damage'], 'tires' => $state['tires'], 'brakes' => $state['brakes'], 'engine' => $state['engine'], 'distance_m' => $state['distance_m'], 'sector_number' => $sector['number'], 'weather' => $race['stage']['default_weather']];
        $context = ['state' => $state, 'sector' => $sector, 'weather' => $race['stage']['default_weather'], 'driver' => $entry['driver'], 'vehicle' => $entry['vehicle'], 'distance_to_finish' => $race['stage']['distance_m'] - $state['distance_m'], 'position' => $entry['position']];
        try {
            $response = $this->jev->decide($context, self::OPTIONS);
            $action = $response['selected_action'] ?? 'MANTENER';
            if (! in_array($action, self::OPTIONS, true)) {
                throw new RuntimeException('Decision provider returned an invalid action.');
            }
            $fallback = false;
            $error = null;
        } catch (\Throwable $exception) {
            $action = 'MANTENER';
            $response = ['provider' => 'fallback', 'selected_action' => $action, 'probabilities' => null, 'raw_response' => null];
            $fallback = true;
            $error = $exception->getMessage();
        }

        $decision = ['id' => (string) Str::uuid(), 'entry_id' => $entry['id'], 'driver_name' => $entry['driver']['name'], 'tick' => $race['tick'], 'sector' => $sector['number'], 'simulated_at' => $state['elapsed_seconds'], 'state_before' => $before, 'options' => self::OPTIONS, 'provider' => $response['provider'], 'jev_response' => $response['raw_response'], 'selected_action' => $action, 'probabilities' => $response['probabilities'] ?? null, 'fallback_used' => $fallback, 'error' => $error, 'result' => null, 'created_at' => now()->toISOString()];
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

    public function restart(array $catalog, array $race): array
    {
        $participants = $race['entries'] ?? $race['legacy_entries'] ?? [];
        if (count($participants) === 1) {
            $legacyEntry = $participants[0];
            $legacyDriverId = $legacyEntry['driver']['id'];
            $legacyVehicleId = $legacyEntry['vehicle']['id'];
            $drivers = collect($catalog['drivers']);
            $vehicles = collect($catalog['vehicles']);
            $rivalDriver = $drivers->first(fn (array $driver) => $driver['id'] !== $legacyDriverId);
            $rivalVehicle = $vehicles->first(fn (array $vehicle) => $vehicle['id'] !== $legacyVehicleId) ?? $vehicles->first();
            if ($rivalDriver && $rivalVehicle) {
                $participants[] = ['driver' => $rivalDriver, 'vehicle' => $rivalVehicle];
            }
        }
        if (count($participants) === 1) {
            $catalog['drivers'] = [...$catalog['drivers'], ['id' => 'legacy-rival', 'name' => 'RIVAL DE PRUEBA', 'age_group' => 'Intermedio', 'experience' => 65, 'reaction' => 72, 'aggressiveness' => 58, 'conservation' => 55, 'risk_tolerance' => 55]];
            $catalog['vehicles'] = [...$catalog['vehicles'], ['id' => 'legacy-rival-car', 'name' => 'AUTO RIVAL', 'class' => 'Medio', 'power' => 280, 'weight_kg' => 1300, 'acceleration' => 65, 'braking' => 65, 'grip' => 65, 'durability' => 70]];
            $participants[] = ['driver' => $catalog['drivers'][array_key_last($catalog['drivers'])], 'vehicle' => $catalog['vehicles'][array_key_last($catalog['vehicles'])]];
        }
        $selections = array_map(fn (array $entry) => ['driver_id' => $entry['driver']['id'], 'vehicle_id' => $entry['vehicle']['id']], $participants);
        try {
            $fresh = $this->create($catalog, $selections, $race['stage']['id']);
            $fresh['id'] = $race['id'];
            $fresh['created_at'] = $race['created_at'];

            return $fresh;
        } catch (RuntimeException) {
            $drivers = collect($catalog['drivers'])->keyBy('id');
            $vehicles = collect($catalog['vehicles'])->keyBy('id');
            $selections = array_map(function (array $entry) use ($drivers, $vehicles): array {
                $driverId = $drivers->has($entry['driver']['id']) ? $entry['driver']['id'] : $drivers->keys()->first();
                $vehicleId = $vehicles->has($entry['vehicle']['id']) ? $entry['vehicle']['id'] : $vehicles->keys()->first();

                return ['driver_id' => $driverId, 'vehicle_id' => $vehicleId];
            }, $participants);
            if (count($selections) === 2 && $selections[0]['driver_id'] === $selections[1]['driver_id']) {
                $selections[1]['driver_id'] = collect($catalog['drivers'])->first(fn (array $driver) => $driver['id'] !== $selections[0]['driver_id'])['id'];
            }

            $fresh = $this->create($catalog, $selections, $race['stage']['id']);
            $fresh['id'] = $race['id'];
            $fresh['created_at'] = $race['created_at'];

            return $fresh;
        }
    }

    private function initialState(int $rngState): array
    {
        return ['status' => 'created', 'rng_state' => $rngState, 'speed_kmh' => 0, 'distance_m' => 0, 'distance_km' => 0, 'progress' => 0, 'elapsed_seconds' => 0, 'time_display' => '00:00.00', 'damage' => 0, 'tires' => 100, 'engine' => 100, 'brakes' => 100, 'fuel' => 100, 'temperature' => 82, 'sector_number' => 1, 'sector' => null, 'current_action' => 'MANTENER', 'current_decision' => null, 'needs_decision' => true, 'decision_reason' => 'Salida de etapa', 'next_curve_distance' => 0, 'last_event' => null, 'tick' => 0];
    }

    private function updatePositions(array &$race): void
    {
        $order = array_keys($race['entries']);
        usort($order, function (int $a, int $b) use (&$race): int {
            $aStatus = $race['states'][$a]['status'];
            $bStatus = $race['states'][$b]['status'];
            $aFinished = $aStatus === 'finished';
            $bFinished = $bStatus === 'finished';
            $aAbandoned = $aStatus === 'abandoned';
            $bAbandoned = $bStatus === 'abandoned';
            if ($aFinished !== $bFinished) return $aFinished ? -1 : 1;
            if ($aAbandoned !== $bAbandoned) return $aAbandoned ? 1 : -1;
            if ($aFinished && $bFinished) return ($race['states'][$a]['finish_time'] ?? $race['states'][$a]['elapsed_seconds']) <=> ($race['states'][$b]['finish_time'] ?? $race['states'][$b]['elapsed_seconds']);
            if ($aAbandoned && $bAbandoned) return $race['states'][$b]['distance_m'] <=> $race['states'][$a]['distance_m'];
            if ($aAbandoned) return 1;
            if ($bAbandoned) return -1;
            return $race['states'][$b]['distance_m'] <=> $race['states'][$a]['distance_m'];
        });

        foreach ($order as $position => $entryIndex) {
            $race['entries'][$entryIndex]['position'] = $position + 1;
            $race['states'][$entryIndex]['position'] = $position + 1;
        }
    }

    public function classifyRace(array $race): array
    {
        $classification = [];
        foreach ($race['entries'] as $index => $entry) {
            $state = $race['states'][$index];
            $distance = $state['distance_m'];
            $actionCounts = array_count_values(array_column(array_filter($race['decisions'], fn (array $decision) => $decision['entry_id'] === $entry['id']), 'selected_action'));
            $incidents = count(array_filter($race['events'], fn (array $event) => ($event['entry_id'] ?? null) === $entry['id'] && in_array($event['type'], ['derrape', 'golpe'], true)));
            $classification[] = ['entry_id' => $entry['id'], 'driver_name' => $entry['driver']['name'], 'vehicle_name' => $entry['vehicle']['name'], 'status' => $state['status'], 'position' => $entry['position'], 'elapsed_seconds' => $state['elapsed_seconds'], 'finish_time' => $state['finish_time'] ?? null, 'time_display' => $state['time_display'], 'distance_m' => $distance, 'damage' => $state['damage'], 'decision_count' => array_sum($actionCounts), 'attacks' => $actionCounts['ATACAR'] ?? 0, 'incidents' => $incidents];
        }

        usort($classification, fn (array $a, array $b) => $a['position'] <=> $b['position']);
        $finished = array_values(array_filter($classification, fn (array $entry) => $entry['status'] === 'finished'));
        usort($finished, fn (array $a, array $b) => ($a['finish_time'] ?? $a['elapsed_seconds']) <=> ($b['finish_time'] ?? $b['elapsed_seconds']));
        $winner = $finished[0]['entry_id'] ?? null;

        return ['classification' => $classification, 'winner_entry_id' => $winner];
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

    private function finishTimeForTick(array $race, array $state, float $distanceBeforeTick, float $tickDistance): float
    {
        $lostInTick = max(0, $state['time_lost_this_tick'] ?? 0);
        $previousTime = $state['elapsed_seconds'] - self::TICK_SECONDS - $lostInTick;
        $fraction = $tickDistance > 0 ? max(0, min(1, ($race['stage']['distance_m'] - $distanceBeforeTick) / $tickDistance)) : 1;

        return round($previousTime + (self::TICK_SECONDS * $fraction) + $lostInTick, 2);
    }

    private function randomUnit(array &$entryState): float
    {
        $state = ((int) $entryState['rng_state'] * 48271) % 2147483647;
        $entryState['rng_state'] = $state;

        return $state / 2147483647;
    }
}
