<?php

namespace App\Services;

use App\Contracts\DecisionProvider;
use RuntimeException;

/**
 * Reserved for the official Jev integration. No endpoint or wire format is assumed here.
 * Enable it only after the provider's current official documentation has been verified.
 */
class JevService implements DecisionProvider
{
    public function decide(array $context, array $options): array
    {
        throw new RuntimeException('The official Jev API integration has not been configured or verified.');
    }
}
