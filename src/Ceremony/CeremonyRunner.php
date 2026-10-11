<?php

namespace LBHurtado\ElectionCeremonies\Ceremony;

use Illuminate\Pipeline\Pipeline;
use LBHurtado\ElectionCeremonies\Contracts\ActorVerifier;
use LBHurtado\ElectionCeremonies\Contracts\CeremonyPipe;
use LBHurtado\ElectionCeremonies\Contracts\CeremonyStore;
use RuntimeException;

final class CeremonyRunner
{
    /**
     * @param  array<string, CeremonyPipe>  $pipes
     */
    public function __construct(
        private readonly CeremonyProfile $profile,
        private readonly CeremonyStore $store,
        private readonly Pipeline $pipeline,
        private readonly ActorVerifier $actors,
        private readonly array $pipes,
    ) {}

    /** @return array<string, mixed> */
    public function run(CeremonyRequest $request): array
    {
        return $this->store->synchronized($request->runId, function () use ($request): array {
            $existing = $this->store->completed($request->runId, $request->idempotencyKey);

            if ($existing !== null) {
                if (($existing['ceremony_id'] ?? null) !== $request->ceremonyId
                    || ($existing['bundle_hash'] ?? null) !== $request->bundleHash
                    || ($existing['assignment_hash'] ?? null) !== $request->assignmentHash
                    || ($existing['request_hash'] ?? null) !== hash('sha256', json_encode($request, JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('Ceremony idempotency key was reused for a different request.');
                }

                return $existing;
            }

            $definition = $this->profile->ceremony($request->ceremonyId);
            $state = $this->store->state($request->runId);

            if ($state === [] || ! in_array($request->ceremonyId, (array) ($state['allowed_ceremonies'] ?? []), true)) {
                throw new RuntimeException('Ceremony run is not commissioned for this action.');
            }

            $current = (string) ($state['stage'] ?? $this->profile->stages[0]);

            if ($current !== $definition['from']) {
                throw new RuntimeException("Ceremony [{$request->ceremonyId}] requires stage [{$definition['from']}], found [{$current}].");
            }

            foreach (['bundle_hash' => $request->bundleHash, 'assignment_hash' => $request->assignmentHash, 'profile_hash' => $this->profile->sha256] as $key => $value) {
                if (! is_string($state[$key] ?? null) || ! hash_equals($state[$key], $value)) {
                    throw new RuntimeException("Active ceremony {$key} differs from the commissioned value.");
                }
            }

            $this->actors->verify($request);

            $steps = [];

            foreach ($definition['pipes'] as $id) {
                $pipe = $this->pipes[$id] ?? throw new RuntimeException("Ceremony pipe [{$id}] is not registered.");
                $steps[] = function (CeremonyContext $context, $next) use ($pipe, $id): CeremonyContext {
                    try {
                        $result = $pipe->handle($context, fn (CeremonyContext $processed): CeremonyContext => $processed);
                        $context->trace($id, 'ran');
                    } catch (\Throwable $exception) {
                        $context->trace($id, 'failed');

                        throw $exception;
                    }

                    return $next($result);
                };
            }

            $context = new CeremonyContext($request, $current, $definition['to'], $this->profile->sha256);
            $result = $this->pipeline->send($context)->through($steps)->thenReturn();

            if (! $result instanceof CeremonyContext) {
                throw new RuntimeException('Ceremony pipeline returned an invalid context.');
            }

            foreach ($definition['evidence'] as $id) {
                if (! isset($result->evidence[$id])) {
                    throw new RuntimeException("Ceremony evidence [{$id}] is required.");
                }
            }

            $this->store->commit($result);

            return $this->store->completed($request->runId, $request->idempotencyKey)
                ?? throw new RuntimeException('Ceremony commit did not produce a result.');
        });
    }
}
