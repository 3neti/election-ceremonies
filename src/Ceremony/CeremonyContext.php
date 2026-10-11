<?php

namespace LBHurtado\ElectionCeremonies\Ceremony;

final class CeremonyContext
{
    /** @var array<string, array<string, mixed>> */
    public array $evidence = [];

    /** @var array<int, array{id: string, status: string}> */
    public array $trace = [];

    /** @var array<string, mixed> */
    public array $workingRecord;

    public function __construct(
        public readonly CeremonyRequest $request,
        public readonly string $from,
        public readonly string $to,
        public readonly string $profileHash,
    ) {
        $this->workingRecord = (array) ($request->input['record'] ?? []);
    }

    /** @param array<string, mixed> $reference */
    public function addEvidence(string $id, array $reference): void
    {
        $this->evidence[$id] = $reference;
    }

    public function trace(string $id, string $status): void
    {
        $this->trace[] = ['id' => $id, 'status' => $status];
    }
}
