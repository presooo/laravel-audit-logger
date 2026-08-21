<?php

namespace AuditTrail\Laravel\Http;

use AuditTrail\Laravel\AuditManager;
use AuditTrail\Laravel\Context\CorrelationContext;
use AuditTrail\Laravel\Data\AuditEntry;
use AuditTrail\Laravel\Data\Direction;
use AuditTrail\Laravel\Support\PayloadSanitizer;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Turns a Request/Response pair into a sanitised AuditEntry, and decides
 * whether that pair is worth recording at all.
 */
class RequestRecorder
{
    public function __construct(
        protected AuditManager $manager,
        protected CorrelationContext $context,
        protected PayloadSanitizer $sanitizer,
    ) {}

    public function shouldRecord(Request $request, Response $response): bool
    {
        $path = ltrim($request->path(), '/');

        $includeOnly = (array) $this->manager->config('include_only.paths', []);

        if ($includeOnly !== [] && ! $this->matchesPath($path, $includeOnly)) {
            return false;
        }

        if ($this->matchesPath($path, (array) $this->manager->config('exclude.paths', []))) {
            return false;
        }

        $excludedMethods = array_map('strtoupper', (array) $this->manager->config('exclude.methods', []));

        if (in_array(strtoupper($request->method()), $excludedMethods, true)) {
            return false;
        }

        $excludedStatuses = array_map('intval', (array) $this->manager->config('exclude.status_codes', []));

        if (in_array($response->getStatusCode(), $excludedStatuses, true)) {
            return false;
        }

        return true;
    }

    public function build(Request $request, Response $response, float $startedAt): AuditEntry
    {
        $finishedAt = microtime(true);
        $capture = (array) $this->manager->config('capture', []);

        [$userId, $userType] = $this->resolveUser($request, (bool) ($capture['user'] ?? true));
        $exception = $this->resolveException($response);
        $route = $request->route();

        return new AuditEntry(
            requestId: $this->context->requestId(),
            correlationId: $this->context->correlationId(),
            parentRequestId: $this->context->parentRequestId(),
            service: (string) $this->manager->config('service_name', 'unknown-service'),
            environment: $this->environment(),
            direction: Direction::INBOUND,
            method: strtoupper($request->method()),
            url: $request->fullUrl(),
            path: '/'.ltrim($request->path(), '/'),
            route: $route instanceof Route ? ($route->getName() ?: $route->uri()) : null,
            statusCode: $response->getStatusCode(),
            durationMs: round(($finishedAt - $startedAt) * 1000, 2),
            ip: ($capture['ip'] ?? true) ? $request->ip() : null,
            userAgent: ($capture['user_agent'] ?? true) ? $request->userAgent() : null,
            userId: $userId,
            userType: $userType,
            requestHeaders: ($capture['request_headers'] ?? true)
                ? $this->sanitizer->headers($request->headers->all())
                : [],
            requestBody: ($capture['request_body'] ?? true)
                ? $this->sanitizer->body($this->requestPayload($request))
                : null,
            query: ($capture['query'] ?? true)
                ? $this->sanitizer->query($request->query())
                : [],
            responseHeaders: ($capture['response_headers'] ?? true)
                ? $this->sanitizer->headers($response->headers->all())
                : [],
            responseBody: ($capture['response_body'] ?? true)
                ? $this->sanitizer->body($this->responsePayload($response, $capture))
                : null,
            exceptionClass: $exception === null ? null : get_class($exception),
            exceptionMessage: $exception?->getMessage(),
            tags: $this->context->tags(),
            startedAt: $this->timestamp($startedAt),
            finishedAt: $this->timestamp($finishedAt),
            memoryPeakKb: ($capture['memory'] ?? true) ? (int) round(memory_get_peak_usage(true) / 1024) : null,
        );
    }

    /**
     * Carbon's createFromTimestamp handling of floats varies between versions,
     * so go through milliseconds explicitly. Sub-second precision matters here:
     * it is what orders the hops within a trace.
     */
    protected function timestamp(float $microtime): string
    {
        return Carbon::createFromTimestampMs((int) round($microtime * 1000))->toIso8601String();
    }

    /**
     * Pull the request payload without disturbing the application: JSON bodies
     * are decoded, form posts use the parsed bag, uploads are described rather
     * than stored, and anything else is kept as a raw string.
     */
    protected function requestPayload(Request $request): mixed
    {
        try {
            $files = $this->describeFiles($request->allFiles());

            if ($request->isJson()) {
                $decoded = $request->json()->all();

                return array_merge(is_array($decoded) ? $decoded : ['_raw' => $decoded], $files);
            }

            $parsed = $request->request->all();

            if ($parsed !== []) {
                return array_merge($parsed, $files);
            }

            if ($files !== []) {
                return $files;
            }

            $content = $request->getContent();

            return $content === '' ? null : $content;
        } catch (Throwable) {
            return ['_error' => 'request payload could not be read'];
        }
    }


    protected function responsePayload(Response $response, array $capture): mixed
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return ['_omitted' => 'streamed or file response'];
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        $allowed = (array) ($capture['response_content_types'] ?? []);

        if ($allowed !== [] && ! $this->contentTypeAllowed($contentType, $allowed)) {
            return ['_omitted' => 'content type not captured', '_content_type' => $contentType];
        }

        try {
            $content = $response->getContent();
        } catch (Throwable) {
            return ['_omitted' => 'response body could not be read'];
        }

        return $content === false || $content === '' ? null : $content;
    }


    protected function describeFiles(array $files): array
    {
        return $files;
    }


    protected function contentTypeAllowed(string $contentType, array $allowed): bool
    {
        if ($contentType === '') {
            return true;
        }

        foreach ($allowed as $type) {
            if (str_contains($contentType, strtolower((string) $type))) {
                return true;
            }
        }

        return false;
    }


    protected function resolveUser(Request $request, bool $capture): array
    {
        if (! $capture) {
            return [null, null];
        }

        try {
            $user = $request->user();

            if ($user === null) {
                return [null, null];
            }

            $identifier = method_exists($user, 'getAuthIdentifier')
                ? $user->getAuthIdentifier()
                : ($user->id ?? null);

            return [
                $identifier === null ? null : (string) $identifier,
                get_class($user),
            ];
        } catch (Throwable) {
            // No guard configured, or a guard that needs a session we do not
            // have. Never let user resolution break the audit entry.
            return [null, null];
        }
    }

    protected function resolveException(Response $response): ?Throwable
    {
        $exception = $response->exception ?? null;

        return $exception instanceof Throwable ? $exception : null;
    }

    protected function environment(): ?string
    {
        try {
            return (string) app('config')->get('app.env');
        } catch (Throwable) {
            return null;
        }
    }


    protected function matchesPath(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            $normalised = ltrim($pattern, '/');

            if (Str::is($normalised, $path) || Str::is($pattern, '/'.$path)) {
                return true;
            }
        }

        return false;
    }
}
