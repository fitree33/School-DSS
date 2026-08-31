<?php

namespace App\Services;

use App\Models\DssResult;
use App\Models\EvaluationCriterion;
use App\Models\Project;
use App\Models\ProjectEvaluation;
use App\Models\User;
use Illuminate\Support\Collection;

class DssEvaluationService
{
    public const CALCULATION_VERSION = 'weighted-score-v1';

    public function score(Collection $criteria, array $scores): float
    {
        $totalWeight = (float) $criteria->sum('weight');

        if ($totalWeight <= 0) {
            return 0.0;
        }

        $weightedScore = $criteria->sum(function (EvaluationCriterion $criterion) use ($scores) {
            $score = (float) ($scores[$criterion->id] ?? 0);
            $maxScore = max((float) $criterion->max_score, 0.01);

            return ($score / $maxScore) * (float) $criterion->weight;
        });

        return round(($weightedScore / $totalWeight) * 100, 2);
    }

    public function refreshProjectResult(Project $project, User $generatedBy): DssResult
    {
        $latestByEvaluator = ProjectEvaluation::query()
            ->where('project_id', $project->id)
            ->whereNull('evaluation_framework_id')
            ->whereNotNull('evaluated_at')
            ->orderByDesc('round')
            ->orderByDesc('evaluated_at')
            ->get()
            ->unique('evaluator_id');

        $totalScore = round((float) $latestByEvaluator->avg('total_score'), 2);
        [$recommendation, $explanation] = $this->recommendation($totalScore, $latestByEvaluator->count());

        return DssResult::create([
            'project_id' => $project->id,
            'calculation_version' => self::CALCULATION_VERSION,
            'total_score' => $totalScore,
            'recommendation' => $recommendation,
            'explanation' => $explanation,
            'generated_by' => $generatedBy->id,
            'generated_at' => now(),
        ]);
    }

    private function recommendation(float $score, int $evaluatorCount): array
    {
        $recommendation = match (true) {
            $score >= 80 => 'แนะนำให้ดำเนินโครงการ',
            $score >= 65 => 'ควรดำเนินโครงการ',
            $score >= 50 => 'พิจารณาแบบมีเงื่อนไข',
            default => 'ควรปรับปรุงก่อนพิจารณา',
        };

        $explanation = sprintf(
            'คะแนนรวม %.2f จาก 100 คำนวณด้วยคะแนนถ่วงน้ำหนักจากแบบประเมินล่าสุดของผู้ประเมิน %d คน ผลลัพธ์นี้เป็นข้อมูลประกอบการตัดสินใจและควรใช้ร่วมกับหลักฐานของโครงการ',
            $score,
            $evaluatorCount
        );

        return [$recommendation, $explanation];
    }
}
