<?php

namespace App\Contracts\Imports;

use App\Services\Imports\ProcessResult;

interface ProcessRunner
{
    /**
     * @param  array<int, string>  $command
     */
    public function run(array $command, float $timeoutSeconds, int $maxStdoutBytes): ProcessResult;
}
