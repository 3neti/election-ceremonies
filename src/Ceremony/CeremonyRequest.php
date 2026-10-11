<?php

namespace LBHurtado\ElectionCeremonies\Ceremony;

use InvalidArgumentException;

final readonly class CeremonyRequest
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public string $runId,
        public string $ceremonyId,
        public string $actorId,
        public string $bundleHash,
        public string $assignmentHash,
        public string $idempotencyKey,
        public array $input = [],
    ) {
        foreach ([$runId, $ceremonyId, $actorId, $bundleHash, $assignmentHash, $idempotencyKey] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Ceremony request identifiers are required.');
            }
        }
    }
}
