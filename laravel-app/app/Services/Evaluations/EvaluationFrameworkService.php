<?php

namespace App\Services\Evaluations;

use App\Models\AuditLog;
use App\Models\EvaluationFramework;
use App\Models\FiscalYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EvaluationFrameworkService
{
    public function create(User $actor, array $attributes): EvaluationFramework
    {
        return DB::transaction(function () use ($actor, $attributes): EvaluationFramework {
            Gate::forUser($actor)->authorize('create', EvaluationFramework::class);
            $this->ensureFiscalYearsWritable([$attributes['fiscal_year_id'] ?? null]);

            $criteria = Arr::pull($attributes, 'criteria', []);
            $attributes['code'] = $this->normalizeCode($attributes['code']);
            $attributes['version'] = trim($attributes['version']);
            $attributes['is_active'] = false;
            $this->ensureEffectivePeriod($attributes);

            $framework = EvaluationFramework::create($attributes);
            $this->createCriteria($framework, $criteria);

            AuditLog::record('evaluation_framework.created', $framework, [], [
                ...$framework->only([
                    'code',
                    'version',
                    'name',
                    'description',
                    'fiscal_year_id',
                    'effective_from',
                    'effective_to',
                    'is_active',
                ]),
                'criteria_count' => count($criteria),
            ]);

            return $this->load($framework->refresh());
        }, 3);
    }

    public function update(User $actor, EvaluationFramework $framework, array $attributes): EvaluationFramework
    {
        return DB::transaction(function () use ($actor, $framework, $attributes): EvaluationFramework {
            $framework = EvaluationFramework::query()->lockForUpdate()->findOrFail($framework->id);
            Gate::forUser($actor)->authorize('update', $framework);
            $this->ensureFiscalYearsWritable([
                $framework->fiscal_year_id,
                $attributes['fiscal_year_id'] ?? null,
            ]);

            $criteriaWasSubmitted = array_key_exists('criteria', $attributes);
            $criteria = Arr::pull($attributes, 'criteria', []);
            $isUsed = $framework->evaluations()->exists();
            $this->ensureEffectivePeriod($attributes, $framework);

            if ($isUsed) {
                $this->ensureUsedFrameworkChangesAreDisplayOnly($framework, $attributes, $criteriaWasSubmitted);
            }

            $oldValues = $framework->only(array_keys($attributes));
            $framework->update($attributes);

            if ($criteriaWasSubmitted && ! $isUsed) {
                $framework->criteria()->delete();
                $this->createCriteria($framework, $criteria);
            }

            if ($framework->is_active && $criteriaWasSubmitted) {
                $this->ensureActiveCriteriaAreValid($framework);
            }

            AuditLog::record('evaluation_framework.updated', $framework, $oldValues, [
                ...$framework->only(array_keys($attributes)),
                'criteria_replaced' => $criteriaWasSubmitted,
            ]);

            return $this->load($framework->refresh());
        }, 3);
    }

    public function createVersion(
        User $actor,
        EvaluationFramework $source,
        array $attributes,
    ): EvaluationFramework {
        return DB::transaction(function () use ($actor, $source, $attributes): EvaluationFramework {
            $source = EvaluationFramework::query()->lockForUpdate()->findOrFail($source->id);
            Gate::forUser($actor)->authorize('createVersion', $source);
            $this->ensureFiscalYearsWritable([
                $source->fiscal_year_id,
                $attributes['fiscal_year_id'] ?? null,
            ]);

            $criteria = Arr::pull($attributes, 'criteria', []);
            $this->ensureEffectivePeriod($attributes);
            $framework = EvaluationFramework::create([
                ...$attributes,
                'code' => $source->code,
                'version' => trim($attributes['version']),
                'is_active' => false,
            ]);
            $this->createCriteria($framework, $criteria);

            AuditLog::record('evaluation_framework.version_created', $framework, [], [
                'source_framework_id' => $source->id,
                'code' => $framework->code,
                'version' => $framework->version,
                'criteria_count' => count($criteria),
            ]);

            return $this->load($framework->refresh());
        }, 3);
    }

    public function activate(User $actor, EvaluationFramework $framework): EvaluationFramework
    {
        return $this->setActive($actor, $framework, true);
    }

    public function deactivate(User $actor, EvaluationFramework $framework): EvaluationFramework
    {
        return $this->setActive($actor, $framework, false);
    }

    private function setActive(
        User $actor,
        EvaluationFramework $framework,
        bool $isActive,
    ): EvaluationFramework {
        return DB::transaction(function () use ($actor, $framework, $isActive): EvaluationFramework {
            $framework = EvaluationFramework::query()->lockForUpdate()->findOrFail($framework->id);
            Gate::forUser($actor)->authorize($isActive ? 'activate' : 'deactivate', $framework);
            $this->ensureFiscalYearsWritable([$framework->fiscal_year_id]);

            if ($isActive) {
                $this->ensureActiveCriteriaAreValid($framework);
            }

            $oldValue = $framework->is_active;
            $framework->update(['is_active' => $isActive]);
            AuditLog::record(
                $isActive ? 'evaluation_framework.activated' : 'evaluation_framework.deactivated',
                $framework,
                ['is_active' => $oldValue],
                ['is_active' => $framework->is_active],
            );

            return $this->load($framework->refresh());
        }, 3);
    }

    private function ensureActiveCriteriaAreValid(EvaluationFramework $framework): void
    {
        $criteria = $framework->activeCriteria()->lockForUpdate()->get();

        if ($criteria->isEmpty()) {
            throw ValidationException::withMessages([
                'criteria' => ['An active framework must have at least one active criterion.'],
            ]);
        }

        $positiveWeights = $criteria->filter(
            fn ($criterion): bool => (float) $criterion->weight > 0,
        )->count();

        if ($positiveWeights !== 0 && $positiveWeights !== $criteria->count()) {
            throw ValidationException::withMessages([
                'criteria' => ['Weights must be defined for every active criterion or omitted for all criteria.'],
            ]);
        }
    }

    private function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * @param  array<int, int|string|null>  $fiscalYearIds
     */
    private function ensureFiscalYearsWritable(array $fiscalYearIds): void
    {
        $ids = collect($fiscalYearIds)
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $hasLockedYear = FiscalYear::query()
            ->whereKey($ids->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->contains(fn (FiscalYear $year): bool => $year->is_locked);

        if ($hasLockedYear) {
            throw ValidationException::withMessages([
                'fiscal_year_id' => ['The selected fiscal year is locked and read-only.'],
            ]);
        }
    }

    private function ensureEffectivePeriod(
        array $attributes,
        ?EvaluationFramework $framework = null,
    ): void {
        $effectiveFrom = array_key_exists('effective_from', $attributes)
            ? $attributes['effective_from']
            : $framework?->effective_from?->toDateString();
        $effectiveTo = array_key_exists('effective_to', $attributes)
            ? $attributes['effective_to']
            : $framework?->effective_to?->toDateString();

        if ($effectiveFrom !== null
            && $effectiveTo !== null
            && CarbonImmutable::parse($effectiveTo)->startOfDay()
                ->lt(CarbonImmutable::parse($effectiveFrom)->startOfDay())) {
            throw ValidationException::withMessages([
                'effective_to' => ['The effective end date must be on or after the effective start date.'],
            ]);
        }
    }

    private function createCriteria(EvaluationFramework $framework, array $criteria): void
    {
        foreach (array_values($criteria) as $index => $criterion) {
            $framework->criteria()->create([
                ...$criterion,
                'weight' => $criterion['weight'] ?? 0,
                'sort_order' => $criterion['sort_order'] ?? (($index + 1) * 10),
                'is_active' => $criterion['is_active'] ?? true,
            ]);
        }
    }

    private function ensureUsedFrameworkChangesAreDisplayOnly(
        EvaluationFramework $framework,
        array $attributes,
        bool $criteriaWasSubmitted,
    ): void {
        $semanticFields = [
            'fiscal_year_id',
            'effective_from',
            'effective_to',
        ];

        $semanticChange = collect($semanticFields)->contains(function (string $field) use ($framework, $attributes): bool {
            if (! array_key_exists($field, $attributes)) {
                return false;
            }

            $current = $framework->{$field};
            $currentValue = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;

            return (string) ($attributes[$field] ?? '') !== (string) ($currentValue ?? '');
        });

        if ($criteriaWasSubmitted || $semanticChange) {
            throw ValidationException::withMessages([
                'framework' => ['A framework that has been used can only change its display name or description. Create a new version for structural changes.'],
            ]);
        }
    }

    private function load(EvaluationFramework $framework): EvaluationFramework
    {
        return $framework->load(['fiscalYear:id,year,is_locked', 'criteria'])
            ->loadExists('evaluations');
    }
}
