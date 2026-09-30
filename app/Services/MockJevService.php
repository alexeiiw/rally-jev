<?php

namespace App\Services;

use App\Contracts\DecisionProvider;

class MockJevService implements DecisionProvider
{
    private const ACTIONS = ['ATACAR', 'MANTENER', 'CONSERVAR', 'FRENAR_ANTES', 'TOMAR_INTERIOR', 'TOMAR_EXTERIOR'];

    public function decide(array $context, array $options): array
    {
        $sector = $context['sector']['number'];
        $state = $context['state'];
        $driver = $context['driver'] ?? [];
        $vehicle = $context['vehicle'] ?? [];
        $experience = $driver['experience'] ?? 50;
        $reaction = $driver['reaction'] ?? 50;
        $risk = $context['sector']['risk'] + ($context['weather'] === 'lluvia' ? 12 : 0)
            + (100 - $state['tires']) * 0.35
            + max(0, 65 - $reaction) * 0.12
            - max(0, $experience - 50) * 0.1
            - max(0, ($vehicle['braking'] ?? 50) - 50) * 0.08
            - max(0, ($driver['risk_tolerance'] ?? 50) - 50) * 0.16;

        if ($state['tires'] < 28 || $state['brakes'] < 25 || $state['engine'] < 24) {
            $action = 'CONSERVAR';
        } elseif ($risk > 72 || (($driver['risk_tolerance'] ?? 50) < 35 && $risk > 55)) {
            $action = 'FRENAR_ANTES';
        } elseif (($driver['aggressiveness'] ?? 50) > 70 && $risk < 62 && $sector % 2 === 0) {
            $action = 'ATACAR';
        } elseif (($driver['conservation'] ?? 50) > 70 && $state['tires'] < 75) {
            $action = 'CONSERVAR';
        } else {
            $action = match ($sector % 4) {
                1 => 'MANTENER',
                2 => 'ATACAR',
                3 => 'TOMAR_INTERIOR',
                default => 'ATACAR',
            };
        }

        if (! in_array($action, $options, true)) {
            $action = 'MANTENER';
        }

        return [
            'provider' => 'mock',
            'selected_action' => $action,
            'probabilities' => null,
            'raw_response' => ['mode' => 'deterministic-demo', 'sector' => $sector, 'driver_profile' => $driver['age_group'] ?? 'Personalizado'],
        ];
    }
}
