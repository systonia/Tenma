<?php

// Front controller as in any Waypoint app. Under tenma it runs once and then serves all
// requests from the same process, in fiber mode with the app as the handler; under php -S or FPM
// it runs once per request as usual.

require __DIR__ . '/vendor/autoload.php';

use App\HomeController;
use App\WaypointController;
use Waypoint\Waypoint;

$app = Waypoint::create();
$app->attach([HomeController::class, WaypointController::class]);

Tenma::serve(8080, $app);
echo "Waypoint on tenma: http://localhost:8080\n";
