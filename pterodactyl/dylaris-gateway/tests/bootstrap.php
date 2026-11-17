<?php

// Pterodactyl's own bootstrap migrates and seeds a fresh database. The extension is then installed and enabled the
// way an admin does it, so every test application loads it through the panel's real extension loader.
$loader = require __DIR__ . '/../../../vendor/autoload.php';
// Outside Dylaris\GatewayPtero\: the panel refuses an extension whose namespace overlaps one it already autoloads.
$loader->addPsr4('Dylaris\\GatewayPteroTests\\', __DIR__ . '/');

require __DIR__ . '/../../../bootstrap/tests.php';

/** @var \Illuminate\Contracts\Console\Kernel $kernel set by bootstrap/tests.php */
if ($kernel->call('p:extension:install', ['path' => base_path('extensions/dylaris-gateway'), '--enable' => true]) !== 0) {
    fwrite(STDERR, "Installing the extension failed:\n" . $kernel->output());
    exit(1);
}
