<?php

use Illuminate\Console\Scheduling\Schedule;

it('every scheduled task uses onOneServer to prevent duplication with N>=2 nodes', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $offenders = [];

    foreach ($schedule->events() as $event) {
        if (! $event->onOneServer) {
            $offenders[] = $event->command ?: $event->description ?: 'closure@'.spl_object_id($event);
        }
    }

    expect($offenders)->toBeEmpty(
        "Schedules sin ->onOneServer():\n - ".implode("\n - ", $offenders)."\n\n".
        'Con DesiredCapacity>=2 (issue #43) se ejecutan en cada nodo y duplican trabajo. '.
        'Agregar ->onOneServer(). Si la duplicacion es intencional (ej. healthcheck por nodo), '.
        'whitelistearlo explicitamente en este test.'
    );
});
