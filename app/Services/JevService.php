<?php

namespace App\Services;

use App\Contracts\DecisionProvider;
use RuntimeException;

/**
 * Placeholder for a future official Jev integration.
 * The project is waiting for access and current official documentation; no endpoint,
 * authentication scheme, or wire format is assumed until those are available.
 */
class JevService implements DecisionProvider
{
    public function decide(array $context, array $options): array
    {
        throw new RuntimeException('The official Jev API integration has not been configured or verified.');
    }
}
