<?php

namespace App;

use Waypoint\Attributes\{Length, NotBlank};
use Waypoint\FromArray;

class WaypointDTO
{
    use FromArray;

    #[NotBlank]
    #[Length(max: 80)]
    public string $name = '';

    public float $lat = 0.0;

    public float $lon = 0.0;
}
