<?php

namespace AuditTrail\Laravel\Contracts;

interface SearchableDriver
{
    public function findByCorrelationId(string $correlationId): array;

    public function findByRequestId(string $requestId): ?array;
}
