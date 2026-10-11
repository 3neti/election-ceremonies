<?php

namespace LBHurtado\ElectionCeremonies\Contracts;

interface EmlProjector
{
    /** @param array<string, mixed> $record
     * @return array{sha256: string, path: string, schema_valid: bool, signature_valid: bool}
     */
    public function export(string $messageType, array $record): array;
}
