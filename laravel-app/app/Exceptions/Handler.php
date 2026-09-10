<?php

namespace App\Exceptions;

use App\Http\Responses\ApiErrorResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (ApiProblemException $exception, Request $request) {
            if (! $request->is('api/v2/*')) {
                return null;
            }

            return ApiErrorResponse::make(
                $exception->getMessage(),
                $exception->errorCode,
                $exception->status,
                $exception->errors,
                $exception->headers,
            );
        });

        $this->renderable(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/v2/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $code = match ($status) {
                Response::HTTP_FORBIDDEN => 'forbidden',
                Response::HTTP_NOT_FOUND => 'not_found',
                Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
                Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
                419 => 'csrf_token_mismatch',
                default => 'http_error',
            };

            $message = match ($status) {
                Response::HTTP_FORBIDDEN => 'You are not allowed to perform this action.',
                Response::HTTP_NOT_FOUND => 'The requested resource was not found.',
                Response::HTTP_METHOD_NOT_ALLOWED => 'The requested method is not allowed.',
                Response::HTTP_TOO_MANY_REQUESTS => 'Too many requests.',
                419 => 'The CSRF token is invalid or has expired.',
                default => Response::$statusTexts[$status] ?? 'Request failed.',
            };

            return ApiErrorResponse::make(
                $message,
                $code,
                $status,
                headers: $exception->getHeaders(),
            );
        });

        $this->renderable(function (Throwable $exception, Request $request) {
            if (! $request->is('api/v2/*')
                || $exception instanceof AuthenticationException
                || $exception instanceof ApiProblemException
                || $exception instanceof ValidationException
                || $exception instanceof HttpExceptionInterface) {
                return null;
            }

            return ApiErrorResponse::make(
                'An unexpected error occurred.',
                'server_error',
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        });
    }

    protected function shouldReturnJson($request, Throwable $e)
    {
        return $request->is('api/v2/*') || parent::shouldReturnJson($request, $e);
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->is('api/v2/*')) {
            return ApiErrorResponse::make(
                'Authentication is required.',
                'unauthenticated',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return parent::unauthenticated($request, $exception);
    }

    protected function invalidJson($request, ValidationException $exception)
    {
        if ($request->is('api/v2/*')) {
            return ApiErrorResponse::make(
                $exception->getMessage(),
                'validation_failed',
                $exception->status,
                $exception->errors(),
            );
        }

        return parent::invalidJson($request, $exception);
    }
}
