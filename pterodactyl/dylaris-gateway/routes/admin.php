<?php

use Dylaris\GatewayPtero\Http\NodeGatewayController;
use Dylaris\GatewayPtero\TestConnectionController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/admin/extensions/dylaris-gateway with the admin API stack (root admins, admin throttle).
Route::post('/test-connection', TestConnectionController::class)->name('test-connection');

Route::get('/nodes/{node:id}', [NodeGatewayController::class, 'show'])->name('nodes.show');
Route::post('/nodes/{node:id}/connect', [NodeGatewayController::class, 'connect'])->name('nodes.connect');
Route::put('/nodes/{node:id}/modes', [NodeGatewayController::class, 'saveModes'])->name('nodes.modes');
Route::put('/nodes/{node:id}/domains', [NodeGatewayController::class, 'saveDomains'])->name('nodes.domains');
Route::post('/nodes/{node:id}/disconnect', [NodeGatewayController::class, 'disconnect'])->name('nodes.disconnect');
