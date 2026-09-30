<?php

use App\Http\Controllers\RaceController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'rally');

Route::get('/api/catalog', [RaceController::class, 'catalog']);
Route::get('/api/races', [RaceController::class, 'history']);
Route::post('/api/races', [RaceController::class, 'create']);
Route::get('/api/races/{race}', [RaceController::class, 'show']);
Route::get('/api/races/{race}/telemetry', [RaceController::class, 'telemetry']);
Route::get('/api/races/{race}/decisions', [RaceController::class, 'decisions']);
Route::post('/api/races/{race}/start', [RaceController::class, 'start']);
Route::post('/api/races/{race}/tick', [RaceController::class, 'tick']);
Route::post('/api/races/{race}/pause', [RaceController::class, 'pause']);
Route::post('/api/races/{race}/resume', [RaceController::class, 'resume']);
Route::post('/api/races/{race}/restart', [RaceController::class, 'restart']);
