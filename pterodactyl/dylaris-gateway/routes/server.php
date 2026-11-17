<?php

use Dylaris\GatewayPtero\Http\ServerGatewayController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/client/servers/{server}/extensions/dylaris-gateway with the server client stack (client throttle,
// server access); every action checks its own gateway permission (view, mode or address).
Route::get('/', [ServerGatewayController::class, 'show'])->name('show');
Route::post('/mode', [ServerGatewayController::class, 'mode'])->name('mode');
Route::post('/mode/preview', [ServerGatewayController::class, 'preview'])->name('mode.preview');
Route::post('/address', [ServerGatewayController::class, 'address'])->name('address');
Route::post('/real-ip', [ServerGatewayController::class, 'realIp'])->name('real-ip');
Route::post('/bedrock', [ServerGatewayController::class, 'bedrock'])->name('bedrock');
Route::get('/floodgate-key', [ServerGatewayController::class, 'floodgateKey'])->name('floodgate-key');
