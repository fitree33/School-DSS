<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SearchLog;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProjectSearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:1000'],
        ]);

        $keyword = trim($validated['q'] ?? '');
        $results = collect();
        $answer = null;
        $filters = [];

        if ($keyword !== '') {
            $startedAt = hrtime(true);
            [$results, $filters] = $this->search($request, $keyword);

            $answer = $results->isEmpty()
                ? 'ไม่พบโครงการที่ตรงกับคำค้นภายในขอบเขตสิทธิ์ของคุณ'
                : 'พบ '.$results->count().' โครงการที่เกี่ยวข้องและคุณมีสิทธิ์เข้าถึง';

            $searchLog = SearchLog::create([
                'user_id' => $request->user()->id,
                'keyword' => $keyword,
                'intent' => 'project_retrieval',
                'filters' => $filters,
                'ai_response' => $answer,
                'model_name' => 'secure-access-search-v1',
                'response_status' => 'completed',
                'response_time_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            ]);

            foreach ($results as $index => $result) {
                $searchLog->results()->create([
                    'project_id' => $result->id,
                    'rank' => $index + 1,
                    'relevance_score' => $result->relevance_score,
                    'reason' => $result->match_reason,
                ]);
            }
        }

        return view('projects.search', [
            'keyword' => $keyword,
            'results' => $results,
            'answer' => $answer,
            'filters' => $filters,
        ]);
    }

    private function search(Request $request, string $keyword): array
    {
        $academicYear = null;
        if (preg_match('/\b(25\d{2})\b/u', $keyword, $matches)) {
            $academicYear = (int) $matches[1];
        }

        $tokens = $this->tokens($keyword);
        $query = Project::query()
            ->visibleTo($request->user())
            ->leftJoin('academic_years', 'projects.academic_year_id', '=', 'academic_years.id')
            ->leftJoin('project_statuses', 'projects.project_status_id', '=', 'project_statuses.id')
            ->leftJoin('departments', 'projects.department_id', '=', 'departments.id')
            ->select([
                'projects.*',
                'academic_years.year as academic_year',
                'project_statuses.name as status_name',
                'departments.name as department_name',
            ]);

        if ($academicYear) {
            $query->where('academic_years.year', $academicYear);
        }

        if ($tokens->isNotEmpty()) {
            $query->where(function ($search) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';
                    $search->orWhere('projects.name', 'like', $like)
                        ->orWhere('projects.objective', 'like', $like)
                        ->orWhere('projects.description', 'like', $like)
                        ->orWhere('departments.name', 'like', $like);
                }
            });
        }

        $projects = $query->latest('projects.created_at')->limit(50)->get();

        $ranked = $projects->map(function (Project $project) use ($tokens, $academicYear) {
            $score = 0;
            $reasons = [];

            foreach ($tokens as $token) {
                if (Str::contains(Str::lower($project->name), $token)) {
                    $score += 3;
                    $reasons[] = 'ตรงกับชื่อโครงการ';
                }
                if (Str::contains(Str::lower($project->objective), $token)) {
                    $score += 2;
                    $reasons[] = 'ตรงกับวัตถุประสงค์';
                }
                if (Str::contains(Str::lower((string) $project->description), $token)) {
                    $score += 1;
                    $reasons[] = 'ตรงกับรายละเอียด';
                }
                if (Str::contains(Str::lower((string) $project->department_name), $token)) {
                    $score += 2;
                    $reasons[] = 'ตรงกับฝ่าย';
                }
            }

            if ($academicYear && (int) $project->academic_year === $academicYear) {
                $score += 5;
                $reasons[] = "ปีการศึกษา {$academicYear}";
            }

            $project->setAttribute('relevance_score', max($score, 1));
            $project->setAttribute('match_reason', collect($reasons)->unique()->implode(', ') ?: 'อยู่ในขอบเขตสิทธิ์ของคุณ');

            return $project;
        })->sortByDesc('relevance_score')->take(10)->values();

        return [$ranked, array_filter(['academic_year' => $academicYear])];
    }

    private function tokens(string $keyword): Collection
    {
        $stopWords = collect([
            'ขอ', 'ดู', 'ค้นหา', 'หา', 'แสดง', 'โครงการ', 'ของ', 'ใน', 'ที่',
            'เกี่ยวกับ', 'ปี', 'ปีการศึกษา', 'ให้', 'หน่อย', 'ทั้งหมด',
        ]);

        return collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower($keyword)))
            ->filter(fn ($token) => mb_strlen($token) >= 2 && ! $stopWords->contains($token))
            ->reject(fn ($token) => preg_match('/^25\d{2}$/', $token))
            ->unique()
            ->values();
    }
}
