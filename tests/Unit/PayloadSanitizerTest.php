<?php

namespace AuditTrail\Laravel\Tests\Unit;

use AuditTrail\Laravel\Support\PayloadSanitizer;
use PHPUnit\Framework\TestCase;

class PayloadSanitizerTest extends TestCase
{
    protected function sanitizer(array $overrides = [], int $maxBytes = 65536): PayloadSanitizer
    {
        return new PayloadSanitizer(array_merge([
            'mask' => '[REDACTED]',
            'headers' => ['authorization', 'cookie', 'x-api-key'],
            'body' => ['password', 'card.number', 'items.*.token', 'client_secret'],
            'query' => ['token'],
            'hash' => ['email'],
            'hash_salt' => 'salt',
            'max_string_length' => 50,
        ], $overrides), $maxBytes);
    }

    public function test_it_redacts_configured_headers_case_insensitively(): void
    {
        $result = $this->sanitizer()->headers([
            'Authorization' => ['Bearer super-secret-token'],
            'X-API-KEY' => ['abcdef'],
            'Accept' => ['application/json'],
        ]);

        $this->assertSame('[REDACTED]', $result['authorization']);
        $this->assertSame('[REDACTED]', $result['x-api-key']);
        $this->assertSame('application/json', $result['accept']);
    }

    public function test_it_redacts_a_key_at_any_depth(): void
    {
        $result = $this->sanitizer()->body([
            'password' => 'top-level',
            'user' => ['profile' => ['password' => 'deeply-nested']],
        ]);

        $this->assertSame('[REDACTED]', $result['password']);
        $this->assertSame('[REDACTED]', $result['user']['profile']['password']);
    }

    public function test_it_redacts_by_exact_path(): void
    {
        $result = $this->sanitizer()->body([
            'card' => ['number' => '4111111111111111', 'expiry' => '12/29'],
            'other' => ['number' => 'keep-me'],
        ]);

        $this->assertSame('[REDACTED]', $result['card']['number']);
        $this->assertSame('12/29', $result['card']['expiry']);
        $this->assertSame('keep-me', $result['other']['number']);
    }

    public function test_it_supports_wildcards_in_paths(): void
    {
        $result = $this->sanitizer()->body([
            'items' => [
                ['sku' => 'A1', 'token' => 'secret-a'],
                ['sku' => 'B2', 'token' => 'secret-b'],
            ],
        ]);

        $this->assertSame('[REDACTED]', $result['items'][0]['token']);
        $this->assertSame('[REDACTED]', $result['items'][1]['token']);
        $this->assertSame('A1', $result['items'][0]['sku']);
    }

    public function test_it_hashes_instead_of_masking_when_configured(): void
    {
        $result = $this->sanitizer()->body(['email' => 'someone@example.com']);

        $this->assertStringStartsWith('sha256:', $result['email']);
        $this->assertStringNotContainsString('example.com', $result['email']);

        // Same input hashes identically, so you can still group by the value.
        $again = $this->sanitizer()->body(['email' => 'someone@example.com']);
        $this->assertSame($result['email'], $again['email']);
    }

    public function test_it_decodes_json_string_bodies(): void
    {
        $result = $this->sanitizer()->body('{"sku":"ABC","password":"hunter2"}');

        $this->assertSame('ABC', $result['sku']);
        $this->assertSame('[REDACTED]', $result['password']);
    }

    public function test_it_keeps_non_json_strings_as_raw(): void
    {
        $result = $this->sanitizer()->body('plain text body');

        $this->assertSame('plain text body', $result['_raw']);
    }

    public function test_it_truncates_long_strings(): void
    {
        $result = $this->sanitizer()->body(['note' => str_repeat('a', 200)]);

        $this->assertStringEndsWith('...[truncated]', $result['note']);
        $this->assertLessThan(200, strlen($result['note']));
    }

    public function test_it_truncates_oversized_payloads(): void
    {
        $result = $this->sanitizer([], 200)->body([
            'items' => array_fill(0, 200, ['description' => 'lots of text here']),
        ]);

        $this->assertTrue($result['_truncated']);
        $this->assertGreaterThan(200, $result['_original_bytes']);
        $this->assertArrayHasKey('_preview', $result);
    }

    public function test_it_replaces_binary_strings(): void
    {
        $result = $this->sanitizer()->body(['blob' => "\xB1\x31\xFF\xFE"]);

        $this->assertStringStartsWith('[BINARY', $result['blob']);
    }

    public function test_it_guards_against_deep_nesting(): void
    {
        $payload = ['level' => 'value'];

        for ($i = 0; $i < 30; $i++) {
            $payload = ['nested' => $payload];
        }

        $result = $this->sanitizer(['max_depth' => 5])->body($payload);

        $this->assertStringContainsString('max depth reached', json_encode($result));
    }

    public function test_it_returns_null_for_empty_payloads(): void
    {
        $this->assertNull($this->sanitizer()->body(null));
        $this->assertNull($this->sanitizer()->body(''));
        $this->assertNull($this->sanitizer()->body([]));
    }

    public function test_it_redacts_query_parameters(): void
    {
        $result = $this->sanitizer()->query(['page' => 2, 'token' => 'secret']);

        $this->assertSame(2, $result['page']);
        $this->assertSame('[REDACTED]', $result['token']);
    }
}
