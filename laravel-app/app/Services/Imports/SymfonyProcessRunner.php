<?php

namespace App\Services\Imports;

use App\Contracts\Imports\ProcessRunner;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

final class SymfonyProcessRunner implements ProcessRunner
{
    private const MAX_STDERR_BYTES = 16_384;

    public function run(array $command, float $timeoutSeconds, int $maxStdoutBytes): ProcessResult
    {
        if ($command === [] || $timeoutSeconds <= 0 || $maxStdoutBytes < 1) {
            throw new InvalidArgumentException('A command, positive timeout, and positive output limit are required.');
        }

        $stdout = '';
        $stderr = '';
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);

        try {
            $exitCode = $process->run(function (string $type, string $chunk) use (
                &$stdout,
                &$stderr,
                $maxStdoutBytes,
                $process,
            ): void {
                if ($type === Process::OUT) {
                    $process->clearOutput();

                    if (strlen($stdout) + strlen($chunk) > $maxStdoutBytes) {
                        throw new ProcessOutputLimitExceeded($maxStdoutBytes);
                    }

                    $stdout .= $chunk;

                    return;
                }

                $process->clearErrorOutput();
                $remaining = self::MAX_STDERR_BYTES - strlen($stderr);

                if ($remaining > 0) {
                    $stderr .= substr($chunk, 0, $remaining);
                }
            });
        } catch (Throwable $exception) {
            if ($process->isRunning()) {
                $process->stop(0.1);
            }

            throw $exception;
        }

        return new ProcessResult($exitCode, $stdout, $stderr);
    }
}
