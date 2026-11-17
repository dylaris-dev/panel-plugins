<?php

use Dylaris\GatewayPtero\Http\DomainListController;
use Dylaris\GatewayPtero\Http\NodeGatewayController;
use Dylaris\GatewayPtero\TestConnectionController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/admin/extensions/dylaris-gateway with the admin API stack (root admins, admin throttle).
Route::post('/test-connection', TestConnectionController::class)->name('test-connection');

Route::get('/nodes/{node:id}', [NodeGatewayController::class, 'show'])->name('nodes.show');
Route::post('/nodes/{node:id}/connect', [NodeGatewayController::class, 'connect'])->name('nodes.connect');
Route::put('/nodes/{node:id}/modes', [NodeGatewayController::class, 'saveModes'])->name('nodes.modes');
Route::post('/nodes/{node:id}/disconnect', [NodeGatewayController::class, 'disconnect'])->name('nodes.disconnect');

// The panel-wide domain list.
Route::get('/domains', [DomainListController::class, 'show'])->name('domains.show');
Route::post('/domains/refresh', [DomainListController::class, 'refresh'])->name('domains.refresh');
Route::post('/domains', [DomainListController::class, 'add'])->name('domains.add');
Route::put('/domains/{domain}/enabled', [DomainListController::class, 'enable'])->name('domains.enable');
Route::post('/domains/{domain}/verify', [DomainListController::class, 'verify'])->name('domains.verify');
Route::delete('/domains/{domain}', [DomainListController::class, 'remove'])->name('domains.remove');
