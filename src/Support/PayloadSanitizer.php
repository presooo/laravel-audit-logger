<?php

namespace AuditTrail\Laravel\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use SplFileInfo;
use Throwable;

/**
 * Strips secrets out of headers, query strings and payloads before anything is
 * written to disk, a database or a bucket.
 *
 * Pattern rules:
 *   "password"          matches a key called password at ANY depth
 *   "card.number"       matches only that exact path
 *   "items.*.token"     matches token inside any element of items
 *   "x-*-secret"        wildcards work on bare keys too
 */
class PayloadSanitizer
{
    protected array $headerPatterns;

    protected array $bodyPatterns;

    protected array $queryPatterns;

    protected array $hashPatterns;

    protected string $mask;

    protected string $algo;

    protected string $salt;

    protected int $maxDepth;

    protected int $maxStringLength;

    protected int $maxBytes;


    public function __construct(array $config = [], int $maxBytes = 65536)
    {
        $this->headerPatterns = $this->normalisePatterns($config['headers'] ?? []);
        $this->bodyPatterns   = $this->normalisePatterns($config['body'] ?? []);
        $this->queryPatterns  = $this->normalisePatterns($config['query'] ?? []);
        $this->hashPatterns   = $this->normalisePatterns($config['hash'] ?? []);

        $this->mask            = (string) ($config['mask'] ?? '[REDACTED]');
        $this->algo            = (string) ($config['hash_algo'] ?? 'sha256');
        $this->salt            = (string) ($config['hash_salt'] ?? '');
        $this->maxDepth        = (int) ($config['max_depth'] ?? 12);
        $this->maxStringLength = (int) ($config['max_string_length'] ?? 8192);
        $this->maxBytes        = $maxBytes > 0 ? $maxBytes : 65536;
    }


    public function headers(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            $key = strtolower((string) $name);

            if (is_array($value)) {
                $value = count($value) === 1 ? reset($value) : $value;
            }

            if ($this->matches($key, $key, $this->headerPatterns)) {
                $result[$key] = $this->mask;

                continue;
            }

            if ($this->matches($key, $key, $this->hashPatterns)) {
                $result[$key] = $this->hash($value);

                continue;
            }

            $result[$key] = is_array($value)
                ? array_map(fn ($item) => $this->scalar($item), $value)
                : $this->scalar($value);
        }

        ksort($result);

        return $result;
    }


    public function query(array $query): array
    {
        return $this->walk($query, $this->queryPatterns);
    }

    /**
     * Sanitize a request or response payload. Accepts arrays, JSON strings or
     * raw strings and always returns an array (or null for no payload).
     */
    public function body(mixed $body): ?array
    {
        if ($body === null || $body === '' || $body === []) {
            return null;
        }

        $body = $this->coerceToArray($body);

        return $this->enforceSizeLimit($this->walk($body, $this->bodyPatterns));
    }


    protected function coerceToArray(mixed $body): array
    {
        if ($body instanceof Arrayable) {
            $body = $body->toArray();
        }

        if (is_string($body)) {
            $decoded = json_decode($body, true);

            return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
                ? $decoded
                : ['_raw' => $this->scalar($body)];
        }

        if (is_object($body)) {
            $body = get_object_vars($body);
        }

        if (! is_array($body)) {
            return ['_raw' => $this->scalar($body)];
        }

        return $body;
    }


    protected function walk(array $data, array $patterns, string $prefix = '', int $depth = 0): array
    {
        if ($depth > $this->maxDepth) {
            return ['_truncated' => 'max depth reached'];
        }

        $result = [];

        foreach ($data as $key => $value) {
            $stringKey = (string) $key;
            $path = $prefix === '' ? $stringKey : $prefix.'.'.$stringKey;

            if ($this->matches($path, $stringKey, $patterns)) {
                $result[$key] = $this->mask;

                continue;
            }

            if ($this->matches($path, $stringKey, $this->hashPatterns)) {
                $result[$key] = $this->hash($value);

                continue;
            }

            $result[$key] = $this->value($value, $path, $patterns, $depth);
        }

        return $result;
    }


    protected function value(mixed $value, string $path, array $patterns, int $depth): mixed
    {
        if ($value instanceof UploadedFile || $value instanceof SplFileInfo) {
            return $this->describeFile($value);
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif (is_object($value)) {
            $value = method_exists($value, 'toArray') ? $value->toArray() : get_object_vars($value);
        }

        if (is_array($value)) {
            return $this->walk($value, $patterns, $path, $depth + 1);
        }

        return $this->scalar($value);
    }


    protected function describeFile(UploadedFile|SplFileInfo $file): array
    {
        try {
            return [
                '_file' => $file instanceof UploadedFile
                    ? $file->getClientOriginalName()
                    : $file->getFilename(),
                '_size' => $file->getSize(),
                '_mime' => $file instanceof UploadedFile ? $file->getClientMimeType() : null,
            ];
        } catch (Throwable) {
            return ['_file' => 'unreadable'];
        }
    }

    protected function scalar(mixed $value): mixed
    {
        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8')) {
                return '[BINARY '.strlen($value).' bytes]';
            }

            if (strlen($value) > $this->maxStringLength) {
                return substr($value, 0, $this->maxStringLength).'...[truncated]';
            }

            return $value;
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $value;
        }

        return '['.get_debug_type($value).']';
    }

    protected function hash(mixed $value): string
    {
        if (! is_scalar($value)) {
            return $this->mask;
        }

        return 'sha256:'.substr(hash($this->algo, $this->salt.(string) $value), 0, 32);
    }


    protected function matches(string $path, string $key, array $patterns): bool
    {
        if ($patterns === []) {
            return false;
        }

        $path = strtolower($path);
        $key = strtolower($key);

        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '.')) {
                if (Str::is($pattern, $path)) {
                    return true;
                }

                continue;
            }

            if ($pattern === $key || Str::is($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A single oversized payload should not be allowed to bloat the store. Keep
     * a readable preview and record what the full size was.
     */
    protected function enforceSizeLimit(array $payload): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded === false) {
            return ['_error' => 'payload could not be encoded'];
        }

        if (strlen($encoded) <= $this->maxBytes) {
            return $payload;
        }

        return [
            '_truncated' => true,
            '_original_bytes' => strlen($encoded),
            '_preview' => substr($encoded, 0, $this->maxBytes),
        ];
    }

    protected function normalisePatterns(array $patterns): array
    {
        return array_values(array_filter(array_map(
            fn ($pattern) => is_string($pattern) ? strtolower(trim($pattern)) : null,
            $patterns
        )));
    }
}
