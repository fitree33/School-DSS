<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiProblemException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyImportCallbackSignature
{
    public const HEADER_TIMESTAMP = 'X-Import-Timestamp';

    public const HEADER_EVENT_ID = 'X-Import-Event-Id';

    public const HEADER_SIGNATURE = 'X-Import-Signature';

    public const ATTRIBUTE_TIMESTAMP = 'import_callback.timestamp';

    public const ATTRIBUTE_EVENT_ID = 'import_callback.event_id';

    public const ATTRIBUTE_BODY_DIGEST = 'import_callback.body_digest';

    public const ATTRIBUTE_SECRET_SLOT = 'import_callback.secret_slot';

    public function handle(Request $request, Closure $next): Response
    {
        $rawBody = $request->getContent();

        $timestampHeader = $request->header(self::HEADER_TIMESTAMP);
        $eventId = $request->header(self::HEADER_EVENT_ID);
        $signature = $request->header(self::HEADER_SIGNATURE);

        if (! is_string($timestampHeader)
            || preg_match('/^[0-9]{1,12}$/D', $timestampHeader) !== 1
            || ! is_string($eventId)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $eventId) !== 1
            || ! is_string($signature)) {
            $this->rejectInvalidSignature();
        }

        $timestamp = (int) $timestampHeader;
        $maximumClockSkew = max(0, (int) config('project_imports.callback.max_clock_skew_seconds', 300));

        if (abs(now()->timestamp - $timestamp) > $maximumClockSkew) {
            throw new ApiProblemException(
                'The callback timestamp is outside the accepted time window.',
                'expired_callback',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $normalizedSignature = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;

        if (preg_match('/^[a-fA-F0-9]{64}$/D', $normalizedSignature) !== 1) {
            $this->rejectInvalidSignature();
        }

        $bodyDigest = hash('sha256', $rawBody);
        $runPublicId = $request->route('run');

        if (! is_string($runPublicId)
            || preg_match('/^[0-9a-fA-F-]{36}$/D', $runPublicId) !== 1) {
            $this->rejectInvalidSignature();
        }

        $message = self::canonicalMessage(
            $timestampHeader,
            $eventId,
            $runPublicId,
            $bodyDigest,
        );
        $matchedSlot = null;

        foreach ($this->configuredSecrets() as $slot => $secret) {
            $expected = hash_hmac('sha256', $message, $secret);

            if (hash_equals($expected, strtolower($normalizedSignature))) {
                $matchedSlot = $slot;
                break;
            }
        }

        if ($matchedSlot === null) {
            $this->rejectInvalidSignature();
        }

        if (! $request->isJson()) {
            throw new ApiProblemException(
                'Callbacks require an application/json body.',
                'invalid_callback_content_type',
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        $request->attributes->set(self::ATTRIBUTE_TIMESTAMP, $timestamp);
        $request->attributes->set(self::ATTRIBUTE_EVENT_ID, $eventId);
        $request->attributes->set(self::ATTRIBUTE_BODY_DIGEST, $bodyDigest);
        $request->attributes->set(self::ATTRIBUTE_SECRET_SLOT, $matchedSlot);

        return $next($request);
    }

    public static function canonicalMessage(
        string $timestamp,
        string $eventId,
        string $runPublicId,
        string $bodyDigest,
    ): string {
        return implode("\n", [$timestamp, $eventId, strtolower($runPublicId), $bodyDigest]);
    }

    /**
     * @return array<string, string>
     */
    private function configuredSecrets(): array
    {
        $secrets = [];

        foreach (['current' => 'current_secret', 'previous' => 'previous_secret'] as $slot => $key) {
            $secret = config("project_imports.callback.{$key}");

            if (is_string($secret) && $secret !== '') {
                $secrets[$slot] = $secret;
            }
        }

        if ($secrets === []) {
            throw new ApiProblemException(
                'Import callback verification is not configured.',
                'invalid_callback_signature',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return $secrets;
    }

    /**
     * @return never
     */
    private function rejectInvalidSignature(): void
    {
        throw new ApiProblemException(
            'The callback signature is invalid.',
            'invalid_callback_signature',
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
