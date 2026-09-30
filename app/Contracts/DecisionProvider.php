<?php

namespace App\Contracts;

interface DecisionProvider
{
    public function decide(array $context, array $options): array;
}
