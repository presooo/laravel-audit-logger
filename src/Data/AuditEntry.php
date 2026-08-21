<?php

namespace AuditTrail\Laravel\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * An immutable, transport agnostic representation of one audited request.
 *
 * Every driver receives this object, so adding a field here makes it available
 * to the database, file and S3 drivers at once.
 *
 */
final class AuditEntry implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $correlationId,
        public readonly ?string $parentRequestId,
        public readonly string $service,
        public readonly ?string $environment,
        public readonly string $direction,
        public readonly string $method,
        public readonly string $url,
        public readonly string $path,
        public readonly ?string $route = null,
        public readonly ?int $statusCode = null,
        public readonly ?float $durationMs = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $userId = null,
        public readonly ?string $userType = null,
        public readonly array $requestHeaders = [],
        public readonly ?array $requestBody = null,
        public readonly array $query = [],
        public readonly array $responseHeaders = [],
        public readonly ?array $responseBody = null,
        public readonly ?string $exceptionClass = null,
        public readonly ?string $exceptionMessage = null,
        public readonly array $tags = [],
        public readonly ?string $startedAt = null,
        public readonly ?string $finishedAt = null,
        public readonly ?int $memoryPeakKb = null,
    ) {}


    public function toArray(): array
    {
        return [
            'request_id'        => $this->requestId,
            'correlation_id'    => $this->correlationId,
            'parent_request_id' => $this->parentRequestId,
            'service'           => $this->service,
            'environment'       => $this->environment,
            'direction'         => $this->direction,
            'method'            => $this->method,
            'url'               => $this->url,
            'path'              => $this->path,
            'route'             => $this->route,
            'status_code'       => $this->statusCode,
            'duration_ms'       => $this->durationMs,
            'ip'                => $this->ip,
            'user_agent'        => $this->userAgent,
            'user_id'           => $this->userId,
            'user_type'         => $this->userType,
            'request_headers'   => $this->requestHeaders,
            'request_body'      => $this->requestBody,
            'query'             => $this->query,
            'response_headers'  => $this->responseHeaders,
            'response_body'     => $this->responseBody,
            'exception_class'   => $this->exceptionClass,
            'exception_message' => $this->exceptionMessage,
            'tags'              => $this->tags,
            'started_at'        => $this->startedAt,
            'finished_at'       => $this->finishedAt,
            'memory_peak_kb'    => $this->memoryPeakKb,
        ];
    }

    /**
     * Rebuild an entry from its array form. Used when the entry travels through
     * a queue, where we serialise plain arrays rather than objects.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            requestId: (string) ($data['request_id'] ?? ''),
            correlationId: (string) ($data['correlation_id'] ?? ''),
            parentRequestId: $data['parent_request_id'] ?? null,
            service: (string) ($data['service'] ?? 'unknown-service'),
            environment: $data['environment'] ?? null,
            direction: (string) ($data['direction'] ?? Direction::INBOUND),
            method: (string) ($data['method'] ?? 'GET'),
            url: (string) ($data['url'] ?? ''),
            path: (string) ($data['path'] ?? ''),
            route: $data['route'] ?? null,
            statusCode: isset($data['status_code']) ? (int) $data['status_code'] : null,
            durationMs: isset($data['duration_ms']) ? (float) $data['duration_ms'] : null,
            ip: $data['ip'] ?? null,
            userAgent: $data['user_agent'] ?? null,
            userId: isset($data['user_id']) ? (string) $data['user_id'] : null,
            userType: $data['user_type'] ?? null,
            requestHeaders: (array) ($data['request_headers'] ?? []),
            requestBody: isset($data['request_body']) ? (array) $data['request_body'] : null,
            query: (array) ($data['query'] ?? []),
            responseHeaders: (array) ($data['response_headers'] ?? []),
            responseBody: isset($data['response_body']) ? (array) $data['response_body'] : null,
            exceptionClass: $data['exception_class'] ?? null,
            exceptionMessage: $data['exception_message'] ?? null,
            tags: (array) ($data['tags'] ?? []),
            startedAt: $data['started_at'] ?? null,
            finishedAt: $data['finished_at'] ?? null,
            memoryPeakKb: isset($data['memory_peak_kb']) ? (int) $data['memory_peak_kb'] : null,
        );
    }

    public function with(array $attributes): self
    {
        return self::fromArray(array_merge($this->toArray(), $attributes));
    }

    public function isInbound(): bool
    {
        return $this->direction === Direction::INBOUND;
    }

    public function failed(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 400;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $flags = 0): string
    {
        $encoded = json_encode(
            $this->toArray(),
            $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $encoded === false ? '{}' : $encoded;
    }
}
