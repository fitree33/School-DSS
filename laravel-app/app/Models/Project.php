<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    public const BUDGET_SOURCES = [
        'เงินอุดหนุนรายหัว',
        'เงินกิจกรรมพัฒนาผู้เรียน',
        'เงินรายได้สถานศึกษา',
        'งบประมาณ สพฐ.',
        'เงินบริจาค/ผ้าป่าการศึกษา',
        'อื่น ๆ',
    ];

    protected $fillable = [
        'name',
        'project_code',
        'objective',
        'description',
        'rationale',
        'target_group',
        'strategy',
        'key_points',
        'ai_summary',
        'ai_summarized_at',
        'budget',
        'budget_source',
        'responsible_person',
        'monitor_person',
        'evaluation_method',
        'evaluation_tools',
        'actual_spent',
        'start_date',
        'end_date',
        'submitted_at',
        'screened_at',
        'approved_at',
        'completed_at',
        'user_id',
        'department_id',
        'project_category_id',
        'academic_year_id',
        'fiscal_year_id',
        'school_plan_id',
        'project_status_id',
        'project_execution_status_id',
        'evaluation_status_id',
    ];

    protected $casts = [
        'ai_summarized_at' => 'datetime',
        'budget' => 'decimal:2',
        'actual_spent' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'submitted_at' => 'datetime',
        'screened_at' => 'datetime',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function schoolPlan()
    {
        return $this->belongsTo(SchoolPlan::class);
    }

    public function status()
    {
        return $this->belongsTo(ProjectStatus::class, 'project_status_id');
    }

    public function executionStatus()
    {
        return $this->belongsTo(ProjectExecutionStatus::class, 'project_execution_status_id');
    }

    public function evaluationStatus()
    {
        return $this->belongsTo(EvaluationStatus::class);
    }

    public function category()
    {
        return $this->belongsTo(ProjectCategory::class, 'project_category_id');
    }

    public function documents()
    {
        return $this->hasMany(ProjectDocument::class)->latest();
    }

    public function accessEntries()
    {
        return $this->hasMany(ProjectAccess::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(ProjectStatusHistory::class)->latest();
    }

    public function executionStatusHistory()
    {
        return $this->hasMany(ProjectExecutionStatusHistory::class)->latest();
    }

    public function evaluations()
    {
        return $this->hasMany(ProjectEvaluation::class);
    }

    public function dssResults()
    {
        return $this->hasMany(DssResult::class);
    }

    public function latestDssResult()
    {
        return $this->hasOne(DssResult::class)->latestOfMany('generated_at');
    }

    public function kpis()
    {
        return $this->hasMany(ProjectKpi::class)->orderBy('id');
    }

    public function completionReports()
    {
        return $this->hasMany(ProjectCompletionReport::class)->latest('reported_at');
    }

    public function latestCompletionReport()
    {
        return $this->hasOne(ProjectCompletionReport::class)->latestOfMany('reported_at');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasPermission('projects.view_all')) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user) {
            $visible->where('user_id', $user->id)
                ->orWhereHas('accessEntries', function (Builder $access) use ($user) {
                    $access->where('user_id', $user->id)
                        ->where('can_view', true)
                        ->where(function (Builder $expiry) {
                            $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                });

            if ($user->department_id && $user->hasPermission('projects.view_department')) {
                $visible->orWhere('department_id', $user->department_id);
            }
        });
    }

    public function hasAccess(User $user, string $ability): bool
    {
        if (! in_array($ability, ['view', 'edit', 'delete'], true)) {
            return false;
        }

        if ($this->relationLoaded('accessEntries')) {
            return $this->accessEntries->contains(function (ProjectAccess $access) use ($user, $ability) {
                return (int) $access->user_id === (int) $user->id
                    && $access->{"can_{$ability}"}
                    && ($access->expires_at === null || $access->expires_at->isFuture());
            });
        }

        return $this->accessEntries()
            ->where('user_id', $user->id)
            ->where("can_{$ability}", true)
            ->where(function (Builder $query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }
}
