<?php

namespace LBHurtado\ElectionCeremonies\Ceremony;

use Closure;
use LBHurtado\ElectionCeremonies\Contracts\CeremonyStore;
use RuntimeException;

final readonly class FileCeremonyStore implements CeremonyStore
{
    public function __construct(private string $root) {}

    public function synchronized(string $runId, Closure $operation): mixed
    {
        $path = $this->path($runId).'.lock';
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Cannot open ceremony run lock.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock ceremony run.');
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function state(string $runId): array
    {
        $path = $this->path($runId);

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Ceremony state is malformed.');
        }

        $this->verifyJournal($decoded);

        return $decoded;
    }

    public function completed(string $runId, string $idempotencyKey): ?array
    {
        $state = $this->state($runId);
        $result = $state['completed'][$idempotencyKey] ?? null;

        return is_array($result) ? $result : null;
    }

    public function commission(string $runId, string $bundleHash, string $assignmentHash, string $profileHash, array $allowedCeremonies): void
    {
        $this->synchronized($runId, function () use ($runId, $bundleHash, $assignmentHash, $profileHash, $allowedCeremonies): void {
            $existing = $this->state($runId);
            $state = [
                'stage' => $existing['stage'] ?? null,
                'bundle_hash' => $bundleHash,
                'assignment_hash' => $assignmentHash,
                'profile_hash' => $profileHash,
                'allowed_ceremonies' => array_values($allowedCeremonies),
                'completed' => $existing['completed'] ?? [],
                'events' => $existing['events'] ?? [],
            ];

            if ($existing !== [] && (($existing['bundle_hash'] ?? null) !== $bundleHash
                || ($existing['assignment_hash'] ?? null) !== $assignmentHash
                || ($existing['profile_hash'] ?? null) !== $profileHash
                || ($existing['allowed_ceremonies'] ?? null) !== array_values($allowedCeremonies))) {
                throw new RuntimeException('Commissioned election identity cannot be replaced for this run.');
            }

            $this->write($runId, $state);
        });
    }

    public function commit(CeremonyContext $context): void
    {
        $request = $context->request;
        $state = $this->state($request->runId);
        $events = (array) ($state['events'] ?? []);
        $previousHash = $events === [] ? null : end($events)['hash'];
        $result = [
            'run_id' => $request->runId,
            'ceremony_id' => $request->ceremonyId,
            'actor_id' => $request->actorId,
            'from' => $context->from,
            'to' => $context->to,
            'bundle_hash' => $request->bundleHash,
            'assignment_hash' => $request->assignmentHash,
            'profile_hash' => $context->profileHash,
            'request_hash' => hash('sha256', json_encode($request, JSON_THROW_ON_ERROR)),
            'evidence' => $context->evidence,
            'trace' => $context->trace,
        ];
        $event = [
            'sequence' => count($events) + 1,
            'previous_hash' => $previousHash,
            'idempotency_key' => $request->idempotencyKey,
            'result_hash' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)),
        ];
        $event['hash'] = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
        $events[] = $event;
        $state = [
            'stage' => $context->to,
            'bundle_hash' => $request->bundleHash,
            'assignment_hash' => $request->assignmentHash,
            'profile_hash' => $context->profileHash,
            'allowed_ceremonies' => $state['allowed_ceremonies'],
            'completed' => [...(array) ($state['completed'] ?? []), $request->idempotencyKey => $result],
            'events' => $events,
        ];
        $this->write($request->runId, $state);
    }

    /** @param array<string, mixed> $state */
    private function write(string $runId, array $state): void
    {
        $path = $this->path($runId);
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';

        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) === false
            || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot commit ceremony state.');
        }
    }

    private function path(string $runId): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $runId)) {
            throw new RuntimeException('Ceremony run ID contains invalid characters.');
        }

        if (! is_dir($this->root) && ! mkdir($this->root, 0700, true) && ! is_dir($this->root)) {
            throw new RuntimeException('Cannot create ceremony state directory.');
        }

        return rtrim($this->root, '/').'/'.$runId.'.json';
    }

    /** @param array<string, mixed> $state */
    private function verifyJournal(array $state): void
    {
        $previousHash = null;

        foreach ((array) ($state['events'] ?? []) as $index => $event) {
            if (! is_array($event) || ($event['sequence'] ?? null) !== $index + 1
                || ($event['previous_hash'] ?? null) !== $previousHash) {
                throw new RuntimeException('Ceremony journal chain is invalid.');
            }

            $hash = $event['hash'] ?? null;
            unset($event['hash']);

            if (! is_string($hash) || ! hash_equals($hash, hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('Ceremony journal event hash is invalid.');
            }

            $result = $state['completed'][$event['idempotency_key'] ?? ''] ?? null;

            if (! is_array($result) || ! hash_equals((string) ($event['result_hash'] ?? ''), hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('Ceremony journal result hash is invalid.');
            }

            $previousHash = $hash;
        }
    }
}
