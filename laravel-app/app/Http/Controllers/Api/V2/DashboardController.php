<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\DashboardRequest;
use App\Http\Resources\Api\V2\DashboardResource;
use App\Services\Budgets\BudgetMetricsService;

class DashboardController extends Controller
{
    public function __invoke(
        DashboardRequest $request,
        BudgetMetricsService $metrics,
    ): DashboardResource {
        $filters = $request->validated();

        return new DashboardResource(
            $metrics->dashboard(
                $request->user(),
                isset($filters['fiscal_year_id']) ? (int) $filters['fiscal_year_id'] : null,
            )
        );
    }
}
