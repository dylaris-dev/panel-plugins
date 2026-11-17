<?php

// The panel does not load plugins under test, so map this plugin's namespaces by hand, as PluginService would.
$loader = require __DIR__ . '/../../../vendor/autoload.php';
$loader->addPsr4('Dylaris\\GatewayPelican\\', __DIR__ . '/../src/');
$loader->addPsr4('Dylaris\\GatewayPelican\\Tests\\', __DIR__ . '/');
