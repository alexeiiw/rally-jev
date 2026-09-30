<?php

use App\Services\JsonStore;
use Illuminate\Support\Facades\Artisan;

Artisan::command('rally:setup', function (JsonStore $store): void {
    $store->ensureInitialized();
    $this->info('JEV Rally JSON data is ready.');
})->purpose('Initialize JEV Rally local JSON catalogs without overwriting existing data');
