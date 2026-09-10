<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyImportCallbackSignature;
use App\Http\Requests\Api\V2\ImportExtractionCallbackRequest;
use App\Models\AiExtractionRun;
use App\Services\Imports\ImportExtractionCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ImportExtractionCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        string $run,
        ImportExtractionCallbackService $callbacks,
    ): JsonResponse {
        $eventId = $request->attributes->get(VerifyImportCallbackSignature::ATTRIBUTE_EVENT_ID);
        $bodyDigest = $request->attributes->get(VerifyImportCallbackSignature::ATTRIBUTE_BODY_DIGEST);

        if (! is_string($eventId) || ! is_string($bodyDigest)) {
            throw new ApiProblemException(
                'The callback request was not verified.',
                'invalid_callback_signature',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $extractionRun = AiExtractionRun::query()
            ->where('public_id', $run)
            ->firstOrFail();
        $result = $callbacks->handle(
            $extractionRun,
            fn (): array => app(ImportExtractionCallbackRequest::class)->callbackData(),
            $eventId,
            $bodyDigest,
        );

        return response()->json(
            ['data' => $result],
            $result['stale'] ? Response::HTTP_ACCEPTED : Response::HTTP_OK,
        );
    }
}
