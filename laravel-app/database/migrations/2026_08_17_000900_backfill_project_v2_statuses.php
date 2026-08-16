<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const HISTORY_MARKER = '[migration:v2-initial-status]';

    public function up(): void
    {
        $executionStatusIds = DB::table('project_execution_statuses')
            ->whereIn('code', ['not_started', 'in_progress', 'completed'])
            ->pluck('id', 'code');
        $pendingEvaluationStatusId = DB::table('evaluation_statuses')
            ->where('code', 'pending')
            ->value('id');

        if ($executionStatusIds->count() !== 3 || $pendingEvaluationStatusId === null) {
            throw new RuntimeException('Required V2 project status codes are missing.');
        }

        DB::transaction(function () use ($executionStatusIds, $pendingEvaluationStatusId) {
            DB::table('projects')
                ->leftJoin('project_statuses', 'projects.project_status_id', '=', 'project_statuses.id')
                ->whereNull('projects.project_execution_status_id')
                ->select([
                    'projects.id',
                    'project_statuses.code as legacy_status_code',
                ])
                ->orderBy('projects.id')
                ->chunkById(500, function (Collection $projects) use ($executionStatusIds, $pendingEvaluationStatusId) {
                    $timestamp = now();
                    $projectIdsByExecutionStatus = $projects->groupBy(
                        fn (object $project) => match ($project->legacy_status_code) {
                            'in_progress' => 'in_progress',
                            'completed' => 'completed',
                            default => 'not_started',
                        }
                    );

                    foreach ($projectIdsByExecutionStatus as $code => $projectsForStatus) {
                        $projectIds = $projectsForStatus->pluck('id')->all();
                        $executionStatusId = $executionStatusIds[$code];

                        DB::table('projects')
                            ->whereIn('id', $projectIds)
                            ->whereNull('project_execution_status_id')
                            ->update([
                                'project_execution_status_id' => $executionStatusId,
                                'evaluation_status_id' => $pendingEvaluationStatusId,
                            ]);

                        DB::table('project_execution_status_histories')->insert(
                            array_map(
                                fn (int $projectId) => [
                                    'project_id' => $projectId,
                                    'from_status_id' => null,
                                    'to_status_id' => $executionStatusId,
                                    'changed_by' => null,
                                    'comment' => self::HISTORY_MARKER,
                                    'created_at' => $timestamp,
                                    'updated_at' => $timestamp,
                                ],
                                $projectIds
                            )
                        );
                    }
                }, 'projects.id', 'id');
        });
    }

    public function down(): void
    {
        $pendingEvaluationStatusId = DB::table('evaluation_statuses')
            ->where('code', 'pending')
            ->value('id');

        DB::transaction(function () use ($pendingEvaluationStatusId) {
            DB::table('project_execution_status_histories')
                ->where('comment', self::HISTORY_MARKER)
                ->select(['id', 'project_id', 'to_status_id'])
                ->orderBy('id')
                ->chunkById(500, function (Collection $histories) use ($pendingEvaluationStatusId) {
                    foreach ($histories as $history) {
                        DB::table('projects')
                            ->where('id', $history->project_id)
                            ->where('project_execution_status_id', $history->to_status_id)
                            ->update(['project_execution_status_id' => null]);

                        if ($pendingEvaluationStatusId !== null) {
                            DB::table('projects')
                                ->where('id', $history->project_id)
                                ->where('evaluation_status_id', $pendingEvaluationStatusId)
                                ->update(['evaluation_status_id' => null]);
                        }
                    }
                });

            DB::table('project_execution_status_histories')
                ->where('comment', self::HISTORY_MARKER)
                ->delete();
        });
    }
};
