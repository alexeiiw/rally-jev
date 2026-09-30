<?php

namespace App\Http\Controllers;

use App\Services\JsonStore;
use App\Services\RaceSimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class RaceController
{
    public function __construct(private readonly JsonStore $store, private readonly RaceSimulationService $simulation) {}

    public function catalog(): JsonResponse
    {
        return response()->json($this->store->catalog());
    }

    public function history(): JsonResponse
    {
        return response()->json(['races' => $this->store->history()]);
    }

    public function create(Request $request): JsonResponse
    {
        $values = $request->validate([
            'stage_id' => ['required', 'string'],
            'participants' => ['required', 'array', 'size:2'],
            'participants.*.driver_id' => ['required', 'string', 'distinct'],
            'participants.*.vehicle_id' => ['required', 'string'],
        ]);

        try {
            $race = $this->simulation->create($this->store->catalog(), $values['participants'], $values['stage_id']);
        } catch (Throwable $exception) {
            Log::error('Unable to create JEV Rally race.', ['error' => $exception->getMessage()]);
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        try {
            $this->store->createRace($race);
        } catch (Throwable $exception) {
            Log::error('Unable to persist JEV Rally race.', ['error' => $exception->getMessage()]);
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['race' => $race], 201);
    }

    public function show(string $race): JsonResponse
    {
        $data = $this->store->updateRace($race, fn (array $data) => $data);
        abort_if($data === null, 404, 'Carrera no encontrada.');
        $data['event_log'] = $data['events'];

        return response()->json(['race' => $data]);
    }

    public function start(string $race): JsonResponse
    {
        $events = [];
        $newDecisions = [];
        $updated = $this->store->updateRace($race, function (array $data) use (&$events, &$newDecisions): array {
            $shouldAdvance = $data['status'] === 'created';
            $data = $this->simulation->start($data);
            if ($shouldAdvance) {
                $data = $this->simulation->tick($data);
                $events = $this->simulation->newEvents($data);
                $newDecisions = $this->simulation->newDecisions($data);
            }

            return $data;
        });
        abort_if($updated === null, 404, 'Carrera no encontrada.');
        $this->store->appendEvents($race, $events);
        foreach ($newDecisions as $decision) {
            if ($decision['fallback_used'] ?? false) {
                Log::warning('JEV Rally used decision fallback.', ['race_id' => $race, 'entry_id' => $decision['entry_id'] ?? null, 'error' => $decision['error'] ?? null]);
            }
        }

        return response()->json(['race' => $updated]);
    }

    public function tick(string $race): JsonResponse
    {
        $events = [];
        $newDecisions = [];
        try {
            $updated = $this->store->updateRace($race, function (array $data) use (&$events, &$newDecisions): array {
                $data = $this->simulation->tick($data);
                $events = $this->simulation->newEvents($data);
                $newDecisions = $this->simulation->newDecisions($data);
                return $data;
            });
        } catch (Throwable $exception) {
            Log::error('JEV Rally tick failed.', ['race_id' => $race, 'error' => $exception->getMessage()]);
            return response()->json(['message' => 'No se pudo guardar el tick; el estado previo de la carrera se conserva.'], 500);
        }

        abort_if($updated === null, 404, 'Carrera no encontrada.');
        try {
            $this->store->appendEvents($race, $events);
        } catch (Throwable $exception) {
            Log::error('JEV Rally event log append failed.', ['race_id' => $race, 'error' => $exception->getMessage()]);
        }
        foreach ($newDecisions as $decision) {
            if ($decision['fallback_used'] ?? false) {
                Log::warning('JEV Rally used decision fallback.', ['race_id' => $race, 'entry_id' => $decision['entry_id'] ?? null, 'error' => $decision['error'] ?? null]);
            }
        }
        foreach ($events as $event) {
            if ($event['type'] === 'finish') {
                Log::info('JEV Rally competitor finished the stage.', ['race_id' => $race, 'entry_id' => $event['entry_id'] ?? null, 'position' => $event['position'] ?? null]);
            }
        }

        return response()->json(['race' => $updated]);
    }

    public function telemetry(string $race): JsonResponse
    {
        $data = $this->store->updateRace($race, fn (array $data) => $data);
        abort_if($data === null, 404, 'Carrera no encontrada.');

        return response()->json(['states' => $data['states'], 'entries' => $data['entries'], 'events' => $data['events'], 'decisions' => $data['decisions'], 'result' => $data['result']]);
    }

    public function decisions(string $race): JsonResponse
    {
        $data = $this->store->updateRace($race, fn (array $data) => $data);
        abort_if($data === null, 404, 'Carrera no encontrada.');

        return response()->json(['decisions' => $data['decisions']]);
    }

    public function pause(string $race): JsonResponse
    {
        $updated = $this->store->updateRace($race, fn (array $data) => $this->simulation->pause($data));
        abort_if($updated === null, 404, 'Carrera no encontrada.');

        return response()->json(['race' => $updated]);
    }

    public function resume(string $race): JsonResponse
    {
        $updated = $this->store->updateRace($race, fn (array $data) => $this->simulation->resume($data));
        abort_if($updated === null, 404, 'Carrera no encontrada.');

        return response()->json(['race' => $updated]);
    }

    public function restart(string $race): JsonResponse
    {
        $updated = $this->store->updateRace($race, function (array $data): array {
            if (! in_array($data['status'], ['finished', 'paused'], true)) {
                abort(409, 'Pausa la carrera antes de reiniciarla.');
            }
            $catalog = $this->store->catalog();
            $fresh = $this->simulation->restart($catalog, $data);
            $fresh['id'] = $data['id'];
            $fresh['created_at'] = $data['created_at'];

            return $fresh;
        });

        abort_if($updated === null, 404, 'Carrera no encontrada.');

        try {
            $this->store->clearEvents($race);
        } catch (Throwable $exception) {
            Log::error('Unable to reset race event log.', ['race_id' => $race, 'error' => $exception->getMessage()]);
        }

        return response()->json(['race' => $updated]);
    }

}
