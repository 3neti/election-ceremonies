<?php

namespace LBHurtado\ElectionCeremonies\Ceremony;

use Closure;
use LBHurtado\ElectionCeremonies\Contracts\CeremonyPipe;
use LBHurtado\ElectionCeremonies\Contracts\EmlProjector;
use RuntimeException;

final readonly class EmlProjectionPipe implements CeremonyPipe
{
    public function __construct(private EmlProjector $eml) {}

    public function handle(CeremonyContext $context, Closure $next): CeremonyContext
    {
        $messageType = $context->request->input['eml_message_type'] ?? null;

        if (! is_string($messageType) || ! in_array($messageType, ['110', '230', '410', '480', '510', '520', '530'], true)) {
            throw new RuntimeException('Ceremony requires a supported EML message type.');
        }

        $artifact = $this->eml->export($messageType, $context->workingRecord);

        if (! $artifact['schema_valid'] || ! $artifact['signature_valid']
            || $artifact['sha256'] === '' || $artifact['path'] === '') {
            throw new RuntimeException("EML {$messageType} projection did not pass verification.");
        }

        $context->workingRecord['eml'][$messageType] = $artifact;
        $context->addEvidence('eml', ['message_type' => $messageType, ...$artifact]);

        return $next($context);
    }
}
