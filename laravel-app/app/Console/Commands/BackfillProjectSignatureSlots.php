<?php

namespace App\Console\Commands;

use App\Exceptions\ApiProblemException;
use App\Models\Project;
use App\Services\Projects\ProjectSignatureSlotService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class BackfillProjectSignatureSlots extends Command
{
    protected $signature = 'projects:backfill-signature-slots
        {--apply : Insert missing blank canonical slots; otherwise only inspect}
        {--project-id=* : Restrict inspection to these positive project IDs}
        {--chunk=100 : Number of projects read per batch (1-1000)}';

    protected $description = 'Initialize missing project signature slots without assigning users (dry-run by default).';

    public function handle(ProjectSignatureSlotService $slots): int
    {
        try {
            $ids = $this->projectIds();
            $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 1000],
            ]);

            if ($chunk === false) {
                throw new RuntimeException('The --chunk option must be an integer between 1 and 1000.');
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $runId = $apply ? (string) Str::uuid() : null;
        $counts = ['would_initialize' => 0, 'initialized' => 0, 'already_complete' => 0, 'inserted_rows' => 0, 'failed' => 0];
        $seen = [];
        $this->line($apply ? 'Mode: apply' : 'Mode: dry-run (no writes)');
        $query = Project::withTrashed()->orderBy('id');

        if ($ids !== []) {
            $query->whereKey($ids);
        }

        try {
            $query->chunkById($chunk, function ($projects) use ($slots, $apply, $runId, &$counts, &$seen): void {
                foreach ($projects as $project) {
                    $id = (int) $project->getKey();
                    $seen[$id] = true;

                    try {
                        if ($apply) {
                            $inserted = $this->initializeProject($slots, $id, $runId);
                            $result = $inserted === 0 ? 'already_complete' : 'initialized';
                            $counts['inserted_rows'] += $inserted;
                        } else {
                            $missing = $slots->missingCodes($project);
                            $result = $missing === [] ? 'already_complete' : 'would_initialize';
                        }

                        $counts[$result]++;
                        $this->line("Project {$id}: {$result}");
                    } catch (Throwable $exception) {
                        $counts['failed']++;
                        // Database and exception messages can contain private data.
                        $code = $exception instanceof ApiProblemException
                            ? $exception->errorCode : 'project_signature_backfill_failed';
                        $this->error("Project {$id}: failed ({$code})");
                    }
                }
            });
        } catch (Throwable) {
            $counts['failed']++;
            $this->error('Backfill failed (project_signature_backfill_failed).');
        }

        foreach ($ids as $id) {
            if (! isset($seen[$id])) {
                $counts['failed']++;
                $this->error("Project {$id}: failed (project_not_found)");
            }
        }

        $this->line(implode(', ', array_map(
            static fn (string $key, int $value): string => "{$key}={$value}",
            array_keys($counts),
            array_values($counts),
        )));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function initializeProject(ProjectSignatureSlotService $slots, int $projectId, string $runId): int
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function () use ($slots, $projectId, $runId): int {
                    $project = Project::withTrashed()->lockForUpdate()->findOrFail($projectId);

                    return $slots->initializeInTransaction($project, null, 'backfill', $runId)->count();
                }, 1);
            } catch (QueryException $exception) {
                // The whole project transaction has rolled back. A competing
                // canonical insertion may now be inspected in a fresh attempt.
                if ($attempt === 3 || ! $this->isSlotUniqueViolation($exception)) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Project signature initialization retries exhausted.');
    }

    private function isSlotUniqueViolation(QueryException $exception): bool
    {
        if (($exception->errorInfo[0] ?? null) !== '23000') {
            return false;
        }

        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = (string) ($exception->errorInfo[2] ?? '');

        if (in_array($driverCode, [19, 2067], true)) {
            return in_array($message, [
                'UNIQUE constraint failed: project_signature_slots.project_id, project_signature_slots.slot_code',
                'UNIQUE constraint failed: project_signature_slots.project_id, project_signature_slots.slot_no',
            ], true);
        }

        // MySQL exposes the index only in the driver message. Parse its terminal
        // key field, never a substring of the duplicate value or another index.
        if ($driverCode !== 1062
            || preg_match("/\\ADuplicate entry '.*' for key '([^']+)'\\z/sD", $message, $matches) !== 1) {
            return false;
        }

        return in_array($matches[1], [
            'project_signature_slots_project_code_unique',
            'project_signature_slots_project_no_unique',
            'project_signature_slots.project_signature_slots_project_code_unique',
            'project_signature_slots.project_signature_slots_project_no_unique',
        ], true);
    }

    /** @return list<int> */
    private function projectIds(): array
    {
        $ids = [];

        foreach ($this->option('project-id') as $value) {
            if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value)
                || filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new RuntimeException('Every --project-id must be a positive integer.');
            }

            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }
}
