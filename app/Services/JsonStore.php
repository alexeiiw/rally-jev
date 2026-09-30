<?php

namespace App\Services;

use RuntimeException;

class JsonStore
{
    private string $root;
    private bool $initializeOnLock = true;

    public function __construct()
    {
        $this->root = storage_path('app/data');
    }

    public static function forTesting(string $path): self
    {
        $store = new self();
        $store->root = $path;
        $store->initializeOnLock = false;

        return $store;
    }

    public function ensureInitialized(): void
    {
        foreach (['catalog', 'races', 'locks'] as $directory) {
            $path = $this->root.'/'.$directory;
            if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
                throw new RuntimeException("Could not create data directory: {$path}");
            }
        }

        $this->withIndexLock(function (): void {
            $this->withLock('setup', function (): void {
                foreach ($this->initialCatalogs() as $name => $defaults) {
                    $path = $this->root.'/catalog/'.$name.'.json';
                    if (! file_exists($path)) {
                        $this->writeAtomically($path, $defaults);
                        continue;
                    }

                    $existing = $this->read($path);
                    $existingIds = array_column($existing, 'id');
                    $missing = array_values(array_filter($defaults, fn (array $item) => ! in_array($item['id'], $existingIds, true)));
                    if ($missing !== []) {
                        $this->writeAtomically($path, [...$existing, ...$missing]);
                    }
                }
                $index = $this->root.'/races/index.json';
                if (! file_exists($index)) {
                    $this->writeAtomically($index, []);
                }
            });
        });
    }

    public function catalog(): array
    {
        $this->ensureInitialized();

        return $this->withLock('setup', fn (): array => [
            'drivers' => $this->read($this->root.'/catalog/drivers.json'),
            'vehicles' => $this->read($this->root.'/catalog/vehicles.json'),
            'stages' => $this->read($this->root.'/catalog/stages.json'),
        ]);
    }

    public function createRace(array $race): array
    {
        $this->ensureInitialized();
        $indexPath = $this->root.'/races/index.json';
        $this->withRaceAndIndexLocks($race['id'], function () use ($indexPath, $race): void {
            $index = $this->read($indexPath);
            foreach ($index as $existing) {
                if ($existing['id'] === $race['id']) {
                    throw new RuntimeException('Race id already exists.');
                }
            }
            array_unshift($index, $this->summary($race));
            $this->writeRace($race);
            $this->writeAtomically($indexPath, $index);
        });

        return $race;
    }

    public function race(string $id): ?array
    {
        if (! $this->validRaceId($id)) {
            return null;
        }

        return $this->withRaceAndIndexLocks($id, function () use ($id): ?array {
            $path = $this->racePath($id);

            return is_file($path) ? $this->read($path) : null;
        });
    }

    public function updateRace(string $id, callable $callback): ?array
    {
        if (! $this->validRaceId($id)) {
            return null;
        }
        return $this->withRaceAndIndexLocks($id, function () use ($id, $callback): ?array {
            $path = $this->racePath($id);
            if (! is_file($path)) return null;
            $race = $this->read($path);
            $updated = $callback($race);
            if ($updated === null) return null;

            $updated['updated_at'] = now()->toISOString();
            $indexPath = $this->root.'/races/index.json';
            $index = $this->read($indexPath);
            foreach ($index as $i => $item) {
                if ($item['id'] === $id) {
                    $index[$i] = $this->summary($updated);
                    break;
                }
            }
            $this->writeAtomically($indexPath, $index);
            $this->writeRace($updated);

            return $updated;
        });
    }

    public function history(): array
    {
        $this->ensureInitialized();

        return $this->withIndexLock(fn () => $this->read($this->root.'/races/index.json'));
    }

    public function appendEvents(string $raceId, array $events): void
    {
        if ($events === []) {
            return;
        }
        if (! $this->validRaceId($raceId)) {
            throw new RuntimeException('Invalid race identifier.');
        }

        $this->withRaceAndIndexLocks($raceId, function () use ($raceId, $events): void {
            $path = $this->eventPath($raceId);
            $handle = fopen($path, 'ab');
            if ($handle === false) {
                throw new RuntimeException('Could not open race event log.');
            }
            if (! flock($handle, LOCK_EX)) {
                fclose($handle);
                throw new RuntimeException('Could not lock race event log.');
            }
            try {
                foreach ($events as $event) {
                    fwrite($handle, json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
                }
                fflush($handle);
                flock($handle, LOCK_UN);
            } finally {
                fclose($handle);
            }
        });
    }

    public function clearEvents(string $raceId): void
    {
        if (! $this->validRaceId($raceId)) {
            throw new RuntimeException('Invalid race identifier.');
        }
        $this->withRaceAndIndexLocks($raceId, function () use ($raceId): void {
            $path = $this->eventPath($raceId);
            $handle = fopen($path, 'c+');
            if ($handle === false) {
                throw new RuntimeException('Could not reset race event log.');
            }
            try {
                if (! flock($handle, LOCK_EX)) {
                    throw new RuntimeException('Could not lock race event log.');
                }
                ftruncate($handle, 0);
                fflush($handle);
                flock($handle, LOCK_UN);
            } finally {
                fclose($handle);
            }
        });
    }

    public function events(string $raceId): array
    {
        if (! $this->validRaceId($raceId)) {
            return [];
        }
        return $this->withRaceAndIndexLocks($raceId, function () use ($raceId): array {
            $path = $this->eventPath($raceId);
            if (! is_file($path)) {
                return [];
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                throw new RuntimeException('Could not read race event log.');
            }

            return array_map(fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
        });
    }

    private function summary(array $race): array
    {
        $classification = $race['result']['classification'] ?? [];
        $leaderIndex = array_search(1, array_column($race['entries'], 'position'), true);
        if ($leaderIndex === false) $leaderIndex = 0;
        $entry = $race['entries'][$leaderIndex] ?? [];
        $state = $race['states'][$leaderIndex] ?? [];
        if ($classification === []) {
            foreach ($race['entries'] ?? [] as $entryIndex => $raceEntry) {
                $raceState = $race['states'][$entryIndex] ?? [];
                $classification[] = ['entry_id' => $raceEntry['id'], 'driver_name' => $raceEntry['driver']['name'], 'vehicle_name' => $raceEntry['vehicle']['name'], 'status' => $raceState['status'] ?? 'running', 'position' => $raceEntry['position'] ?? $entryIndex + 1, 'elapsed_seconds' => $raceState['elapsed_seconds'] ?? 0, 'time_display' => $raceState['time_display'] ?? '00:00.00', 'damage' => $raceState['damage'] ?? 0, 'decision_count' => 0, 'attacks' => 0, 'incidents' => 0];
            }
        }
        $winnerName = null;
        foreach ($classification as $resultEntry) {
            if (($resultEntry['entry_id'] ?? null) === ($race['result']['winner_entry_id'] ?? null)) {
                $winnerName = $resultEntry['driver_name'];
                break;
            }
        }

        return [
            'id' => $race['id'],
            'status' => $race['status'],
            'stage_name' => $race['stage']['name'] ?? 'Etapa',
            'driver_name' => $entry['driver']['name'] ?? 'Piloto',
            'vehicle_name' => $entry['vehicle']['name'] ?? 'Vehículo',
            'elapsed_seconds' => count($race['states'] ?? []) > 1 ? max(array_column($race['states'], 'elapsed_seconds')) : ($state['elapsed_seconds'] ?? 0),
            'position' => $entry['position'] ?? 1,
            'damage' => $state['damage'] ?? 0,
            'participant_count' => count($race['entries'] ?? []),
            'winner_name' => $winnerName,
            'created_at' => $race['created_at'],
            'updated_at' => $race['updated_at'],
        ];
    }

    private function writeRace(array $race): void
    {
        $id = $race['id'] ?? '';
        if (! $this->validRaceId($id)) {
            throw new RuntimeException('Invalid race identifier.');
        }
        $this->writeAtomically($this->racePath($id), $race);
    }

    private function racePath(string $id): string
    {
        return $this->root.'/races/'.$id.'.json';
    }

    private function eventPath(string $id): string
    {
        return $this->root.'/races/'.$id.'.events.jsonl';
    }

    private function validRaceId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9-]{36}$/i', $id);
    }

    private function read(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Could not read JSON file: {$path}");
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    private function writeAtomically(string $path, array $data): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(5)).'.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json."\n", LOCK_EX) === false || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Could not persist JSON file: {$path}");
        }
    }

    private function withLock(string $key, callable $callback): mixed
    {
        if ($this->initializeOnLock && $key !== 'setup') {
            $this->ensureInitialized();
        }
        $directory = $this->root.'/locks';
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create JSON lock directory.');
        }
        $path = $directory.'/'.preg_replace('/[^a-zA-Z0-9-]/', '', $key).'.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Could not open storage lock for {$key}.");
        }
        if (! flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException("Could not acquire storage lock for {$key}.");
        }

        try {
            return $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function withIndexLock(callable $callback): mixed
    {
        $directory = $this->root.'/locks';
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create JSON lock directory.');
        }
        $handle = fopen($directory.'/index.lock', 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('Could not acquire history index lock.');
        }
        try {
            return $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function withRaceAndIndexLocks(string $raceId, callable $callback): mixed
    {
        return $this->withIndexLock(fn () => $this->withLock($raceId, $callback));
    }

    private function initialCatalogs(): array
    {
        $sectors = [
            ['type' => 'recta', 'length_m' => 1450, 'surface' => 'grava', 'difficulty' => 48, 'curves' => 1, 'critical_curve' => 'derecha rápida', 'slope' => 'subida', 'risk' => 28, 'visibility' => 'buena'],
            ['type' => 'curvas rápidas', 'length_m' => 1520, 'surface' => 'grava', 'difficulty' => 55, 'curves' => 5, 'critical_curve' => 'izquierda rápida', 'slope' => 'plano', 'risk' => 42, 'visibility' => 'buena'],
            ['type' => 'bajada', 'length_m' => 1430, 'surface' => 'tierra', 'difficulty' => 62, 'curves' => 4, 'critical_curve' => 'derecha cerrada', 'slope' => 'bajada', 'risk' => 55, 'visibility' => 'media'],
            ['type' => 'subida', 'length_m' => 1510, 'surface' => 'grava', 'difficulty' => 51, 'curves' => 3, 'critical_curve' => 'izquierda media', 'slope' => 'subida', 'risk' => 39, 'visibility' => 'media'],
            ['type' => 'curvas cerradas', 'length_m' => 1470, 'surface' => 'tierra', 'difficulty' => 75, 'curves' => 6, 'critical_curve' => 'horquilla derecha', 'slope' => 'plano', 'risk' => 68, 'visibility' => 'media'],
            ['type' => 'recta', 'length_m' => 1560, 'surface' => 'grava', 'difficulty' => 42, 'curves' => 2, 'critical_curve' => 'izquierda rápida', 'slope' => 'bajada', 'risk' => 30, 'visibility' => 'buena'],
            ['type' => 'subida', 'length_m' => 1500, 'surface' => 'grava', 'difficulty' => 58, 'curves' => 4, 'critical_curve' => 'derecha media', 'slope' => 'subida', 'risk' => 46, 'visibility' => 'media'],
            ['type' => 'curvas rápidas', 'length_m' => 1490, 'surface' => 'tierra', 'difficulty' => 65, 'curves' => 5, 'critical_curve' => 'izquierda cerrada', 'slope' => 'bajada', 'risk' => 60, 'visibility' => 'media'],
            ['type' => 'recta', 'length_m' => 1600, 'surface' => 'grava', 'difficulty' => 45, 'curves' => 1, 'critical_curve' => 'derecha rápida', 'slope' => 'plano', 'risk' => 31, 'visibility' => 'buena'],
            ['type' => 'curvas cerradas', 'length_m' => 1480, 'surface' => 'tierra', 'difficulty' => 72, 'curves' => 6, 'critical_curve' => 'horquilla izquierda', 'slope' => 'subida', 'risk' => 66, 'visibility' => 'media'],
            ['type' => 'bajada', 'length_m' => 1650, 'surface' => 'grava', 'difficulty' => 57, 'curves' => 3, 'critical_curve' => 'derecha media', 'slope' => 'bajada', 'risk' => 45, 'visibility' => 'media'],
            ['type' => 'sprint final', 'length_m' => 1640, 'surface' => 'grava', 'difficulty' => 50, 'curves' => 3, 'critical_curve' => 'izquierda rápida', 'slope' => 'plano', 'risk' => 38, 'visibility' => 'buena'],
        ];

        $distance = 0;
        foreach ($sectors as $index => &$sector) {
            $sector = array_merge(['id' => 'sector-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 'number' => $index + 1, 'name' => 'Sector '.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 'start_m' => $distance], $sector);
            $distance += $sector['length_m'];
        }
        unset($sector);

        return [
            'drivers' => [
                ['id' => 'jev-joven', 'name' => 'JEV JOVEN', 'age_group' => 'Joven', 'experience' => 48, 'reaction' => 88, 'aggressiveness' => 78, 'conservation' => 38, 'risk_tolerance' => 76],
                ['id' => 'jev-intermedio', 'name' => 'JEV INTERMEDIO', 'age_group' => 'Intermedio', 'experience' => 76, 'reaction' => 78, 'aggressiveness' => 62, 'conservation' => 58, 'risk_tolerance' => 57],
                ['id' => 'jev-mayor', 'name' => 'JEV MAYOR', 'age_group' => 'Mayor', 'experience' => 91, 'reaction' => 66, 'aggressiveness' => 43, 'conservation' => 82, 'risk_tolerance' => 39],
            ],
            'vehicles' => [
                ['id' => 'rally-x1-bueno', 'name' => 'RALLY-X1 PRO · BUENO', 'class' => 'Bueno', 'power' => 350, 'weight_kg' => 1220, 'acceleration' => 88, 'braking' => 84, 'grip' => 86, 'durability' => 82],
                ['id' => 'rally-x1-medio', 'name' => 'RALLY-X1 · MEDIO', 'class' => 'Medio', 'power' => 300, 'weight_kg' => 1290, 'acceleration' => 70, 'braking' => 68, 'grip' => 69, 'durability' => 72],
                ['id' => 'rally-x1-basico', 'name' => 'RALLY-X1 CLUB · BÁSICO', 'class' => 'Básico', 'power' => 250, 'weight_kg' => 1370, 'acceleration' => 55, 'braking' => 53, 'grip' => 52, 'durability' => 60],
            ],
            'stages' => [['id' => 'sierra-de-la-mina', 'name' => 'Sierra de la Mina', 'distance_m' => $distance, 'distance_km' => round($distance / 1000, 1), 'difficulty' => 'media-alta', 'default_weather' => 'lluvia', 'sectors' => $sectors]],
        ];
    }
}
