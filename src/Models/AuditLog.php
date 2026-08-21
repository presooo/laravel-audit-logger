<?php

namespace AuditTrail\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Optional Eloquent model over the database driver's table. The driver itself
 * writes with the query builder; this exists purely for querying audit data
 * from your own dashboards and commands.
 *
 * @property string $correlation_id
 * @property string $request_id
 * @property string|null $parent_request_id
 * @property string $service
 * @property int|null $status_code
 * @property int|null $duration_ms
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'request_headers' => 'array',
        'request_body' => 'array',
        'query' => 'array',
        'response_headers' => 'array',
        'response_body' => 'array',
        'tags' => 'array',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'memory_peak_kb' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return $this->table ?? config('audit-logger.drivers.database.table', 'audit_logs');
    }

    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('audit-logger.drivers.database.connection');
    }

    /**
     * Every other hop in the same trace, across every service.
     */
    public function scopeForCorrelation(Builder $query, string $correlationId): Builder
    {
        return $query->where('correlation_id', $correlationId)->orderBy('started_at');
    }

    public function scopeForService(Builder $query, string $service): Builder
    {
        return $query->where('service', $service);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status_code', '>=', 400);
    }

    public function scopeSlowerThan(Builder $query, int $milliseconds): Builder
    {
        return $query->where('duration_ms', '>=', $milliseconds);
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', 'inbound');
    }

    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', 'outbound');
    }

    /**
     * Direct children of this hop, i.e. the calls it made.
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_request_id', 'request_id');
    }
}
