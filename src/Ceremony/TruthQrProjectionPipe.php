<?php

namespace LBHurtado\ElectionCeremonies\Ceremony;

use Closure;
use LBHurtado\ElectionCeremonies\Contracts\CeremonyPipe;
use LBHurtado\TruthQr\Contracts\RecordService;
use LBHurtado\TruthQr\Core\RecordPayload;
use RuntimeException;

final readonly class TruthQrProjectionPipe implements CeremonyPipe
{
    public function __construct(private RecordService $records) {}

    public function handle(CeremonyContext $context, Closure $next): CeremonyContext
    {
        $profileId = $context->request->input['truth_qr_profile'] ?? null;
        $recordType = $context->request->input['truth_qr_record_type'] ?? null;

        if (! is_string($profileId) || $profileId === '' || ! is_string($recordType) || $recordType === '') {
            throw new RuntimeException('Ceremony requires a Truth QR profile and record type.');
        }

        $fragments = $this->records->encodeRecord(new RecordPayload($profileId, $recordType, $context->workingRecord));

        if ($fragments === []) {
            throw new RuntimeException('Truth QR produced no record fragments.');
        }

        $context->addEvidence('truth_qr', [
            'profile_id' => $profileId,
            'record_type' => $recordType,
            'fragments' => $fragments,
            'sha256' => array_map(fn (string $fragment): string => hash('sha256', $fragment), $fragments),
        ]);

        return $next($context);
    }
}
