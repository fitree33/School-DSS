<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

require_once dirname(__DIR__).'/phase6a/bootstrap.php';

/** Reuse the opt-in, exact connection, actual server and disposable datadir guards. */
function phaseSixBOneBootstrap(string $runtime): Application
{
    return phaseSixBootstrap($runtime);
}
