<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationScore;
use App\Models\Project;
use App\Models\ProjectEvaluation;
use App\Services\DssEvaluationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectEvaluationController extends Controller
{
    public function edit(Request $request, Project $project)
    {
        $this->authorize('evaluate', $project);

        $criteria = EvaluationCriterion::query()
            ->whereNull('evaluation_framework_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $round = max(1, $request->integer('round', 1));
        $evaluation = ProjectEvaluation::query()
            ->with('scores')
            ->where('project_id', $project->id)
            ->where('evaluator_id', $request->user()->id)
            ->whereNull('evaluation_framework_id')
            ->where('round', $round)
            ->first();

        $project->load(['status', 'academicYear', 'department', 'latestDssResult']);

        return view('projects.evaluate', compact('project', 'criteria', 'evaluation', 'round'));
    }

    public function store(Request $request, Project $project, DssEvaluationService $service)
    {
        $this->authorize('evaluate', $project);

        $criteria = EvaluationCriterion::query()
            ->whereNull('evaluation_framework_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($criteria->isEmpty()) {
            throw ValidationException::withMessages([
                'scores' => 'ยังไม่มีเกณฑ์ประเมินที่เปิดใช้งาน',
            ]);
        }

        $rules = [
            'comment' => ['nullable', 'string', 'max:3000'],
            'scores' => ['required', 'array'],
        ];

        foreach ($criteria as $criterion) {
            $rules["scores.{$criterion->id}"] = [
                'required',
                'numeric',
                'min:0',
                'max:'.(float) $criterion->max_score,
            ];
        }

        $validated = $request->validate($rules);
        $totalScore = $service->score($criteria, $validated['scores']);

        [$evaluation, $result] = DB::transaction(function () use ($validated, $criteria, $project, $request, $service, $totalScore) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $this->authorize('evaluate', $project);
            $round = (int) ProjectEvaluation::query()
                ->where('project_id', $project->id)
                ->where('evaluator_id', $request->user()->id)
                ->max('round') + 1;
            $evaluation = ProjectEvaluation::create([
                'project_id' => $project->id,
                'evaluator_id' => $request->user()->id,
                'evaluation_framework_id' => null,
                'round' => max(1, $round),
                'total_score' => $totalScore,
                'comment' => $validated['comment'] ?? null,
                'evaluated_at' => now(),
            ]);

            foreach ($criteria as $criterion) {
                EvaluationScore::create([
                    'evaluation_id' => $evaluation->id,
                    'criteria_id' => $criterion->id,
                    'score' => $validated['scores'][$criterion->id],
                ]);
            }

            $result = $service->refreshProjectResult($project, $request->user());

            AuditLog::record('project.evaluated', $evaluation, [], [
                'project_id' => $project->id,
                'round' => $evaluation->round,
                'total_score' => $evaluation->total_score,
                'dss_result_id' => $result->id,
            ]);

            return [$evaluation, $result];
        });

        return redirect()
            ->route('projects.show', $project)
            ->with('success', "บันทึกการประเมินแล้ว คะแนน DSS {$result->total_score} จาก 100");
    }
}
