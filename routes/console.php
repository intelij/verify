<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('verification:scan-stale')->everyFiveMinutes();
Schedule::command('verification:prune')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
