<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiProblemException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitImportCallbackBody
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->is('api/v2/import-extraction-runs/*/callback')) {
            $maximum = max(1, (int) config('project_imports.callback.max_body_bytes', 1_048_576));
            $contentLength = $request->server('CONTENT_LENGTH');

            // Run before Laravel's JSON-transforming middleware, including when
            // Content-Length is absent or understates the actual body size.
            if ((is_numeric($contentLength) && (int) $contentLength > $maximum)
                || strlen($request->getContent()) > $maximum) {
                throw new ApiProblemException(
                    'The callback body exceeds the configured size limit.',
                    'callback_too_large',
                    Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                );
            }
        }

        return $next($request);
    }
}
