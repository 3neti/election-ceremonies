<?php

namespace LBHurtado\ElectionCeremonies\Contracts;

use Closure;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyContext;

interface CeremonyStore
{
    public function synchronized(string $runId, Closure $operation): mixed;

    /** @return array<string, mixed> */
    public function state(string $runId): array;

    /** @param array<int, string> $allowedCeremonies */
    public function commission(string $runId, string $bundleHash, string $assignmentHash, string $profileHash, array $allowedCeremonies): void;

    /** @return array<string, mixed>|null */
    public function completed(string $runId, string $idempotencyKey): ?array;

    public function commit(CeremonyContext $context): void;
}
