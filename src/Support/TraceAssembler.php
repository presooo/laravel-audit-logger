<?php

namespace AuditTrail\Laravel\Support;

/**
 * Turns a flat pile of audit entries (possibly gathered from several services)
 * into a parent/child tree using parent_request_id, then flattens it into
 * printable rows.
 *
 * This is the piece that answers "what actually happened to this request?"
 * across Api Suite and every backend service it touched.
 */
class TraceAssembler
{
    protected int $maxDepth = 32;

    public function tree(array $entries): array
    {
        $entries = $this->sort($entries);

        $ids = [];
        $childrenByParent = [];

        foreach ($entries as $entry) {
            $ids[(string) ($entry['request_id'] ?? '')] = true;
        }

        foreach ($entries as $entry) {
            $parent = $entry['parent_request_id'] ?? null;

            if ($parent !== null && isset($ids[$parent])) {
                $childrenByParent[$parent][] = $entry;
            }
        }

        $roots = array_values(array_filter($entries, function (array $entry) use ($ids) {
            $parent = $entry['parent_request_id'] ?? null;

            return $parent === null || $parent === '' || ! isset($ids[$parent]);
        }));

        return array_map(fn (array $root) => $this->build($root, $childrenByParent, 0), $roots);
    }

    /**
     * Flatten the tree into ordered rows carrying their depth, ready for a
     * console table or an HTML timeline.
     */
    public function flatten(array $entries): array
    {
        $rows = [];

        foreach ($this->tree($entries) as $node) {
            $this->collect($node, 0, $rows);
        }

        return $rows;
    }

    /**
     * Aggregate figures for a whole trace: how many hops, which services were
     * involved, total wall time, how many failed.
     */
    public function summarise(array $entries): array
    {
        $services = [];
        $failures = 0;
        $slowest = null;

        foreach ($entries as $entry) {
            $service = (string) ($entry['service'] ?? 'unknown');
            $services[$service] = ($services[$service] ?? 0) + 1;

            if ((int) ($entry['status_code'] ?? 0) >= 400) {
                $failures++;
            }

            if ($slowest === null || (float) ($entry['duration_ms'] ?? 0) > (float) ($slowest['duration_ms'] ?? 0)) {
                $slowest = $entry;
            }
        }

        $sorted = $this->sort($entries);
        $first  = $sorted[0] ?? null;
        $last   = end($sorted) ?: null;

        return [
            'hops'              => count($entries),
            'services'          => $services,
            'failures'          => $failures,
            'slowest'           => $slowest,
            'started_at'        => $first['started_at'] ?? null,
            'finished_at'       => $last['finished_at'] ?? ($last['started_at'] ?? null),
            'total_duration_ms' => $first === null ? null : (float) ($first['duration_ms'] ?? 0),
        ];
    }

    protected function build(array $node, array $childrenByParent, int $depth): array
    {
        $children = [];

        if ($depth < $this->maxDepth) {
            foreach ($childrenByParent[(string) ($node['request_id'] ?? '')] ?? [] as $child) {
                $children[] = $this->build($child, $childrenByParent, $depth + 1);
            }
        }

        $node['children'] = $children;

        return $node;
    }

    protected function collect(array $node, int $depth, array &$rows): void
    {
        $children = $node['children'] ?? [];
        unset($node['children']);

        $node['depth'] = $depth;
        $rows[] = $node;

        foreach ($children as $child) {
            $this->collect($child, $depth + 1, $rows);
        }
    }

    protected function sort(array $entries): array
    {
        usort($entries, function (array $a, array $b) {
            return strcmp((string) ($a['started_at'] ?? ''), (string) ($b['started_at'] ?? ''));
        });

        return array_values($entries);
    }
}
