<?php

use Illuminate\Container\Container;
use Illuminate\Pipeline\Pipeline;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyProfile;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyRequest;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyRunner;
use LBHurtado\ElectionCeremonies\Ceremony\EmlProjectionPipe;
use LBHurtado\ElectionCeremonies\Ceremony\FileCeremonyStore;
use LBHurtado\ElectionCeremonies\Ceremony\TruthQrProjectionPipe;
use LBHurtado\ElectionCeremonies\Contracts\ActorVerifier;
use LBHurtado\ElectionCeremonies\Contracts\EmlProjector;
use LBHurtado\TruthQr\Contracts\RecordService;
use LBHurtado\TruthQr\Core\DecodedRecord;
use LBHurtado\TruthQr\Core\RecordPayload;

test('EML evidence is bound into the record before Truth QR encoding', function (): void {
    $directory = sys_get_temp_dir().'/projection-test-'.bin2hex(random_bytes(5));
    mkdir($directory);
    $profilePath = $directory.'/profile.yaml';
    file_put_contents($profilePath, "id: pilot\nversion: 1\nstages: [counting, election_return]\nceremonies:\n  approve_return:\n    from: counting\n    to: election_return\n    pipes: [eml_export, truth_qr_encode]\n    evidence: [eml, truth_qr]\n");
    $profile = CeremonyProfile::fromYaml($profilePath);
    $store = new FileCeremonyStore($directory.'/state');
    $store->commission('run-1', 'bundle-hash', 'assignment-hash', $profile->sha256, ['approve_return']);
    $eml = new class implements EmlProjector
    {
        public function export(string $messageType, array $record): array
        {
            return ['sha256' => 'eml-hash', 'path' => 'return-510.xml', 'schema_valid' => true, 'signature_valid' => true];
        }
    };
    $qr = new class implements RecordService
    {
        public ?RecordPayload $received = null;

        public function encodeRecord(RecordPayload $payload): array
        {
            $this->received = $payload;

            return ['truth://fixture'];
        }

        public function decodeRecord(string $profileId, array $scans): DecodedRecord
        {
            throw new RuntimeException('Not used.');
        }
    };
    $actors = new class implements ActorVerifier
    {
        public function verify(CeremonyRequest $request): void {}
    };
    $runner = new CeremonyRunner($profile, $store, new Pipeline(new Container), $actors, [
        'eml_export' => new EmlProjectionPipe($eml),
        'truth_qr_encode' => new TruthQrProjectionPipe($qr),
    ]);
    $result = $runner->run(new CeremonyRequest('run-1', 'approve_return', 'officer-1', 'bundle-hash', 'assignment-hash', 'attempt-1', [
        'record' => ['election_id' => 'election-1'],
        'eml_message_type' => '510',
        'truth_qr_profile' => 'aes-election-v1',
        'truth_qr_record_type' => 'election_return',
    ]));

    expect($qr->received->fields['eml']['510']['sha256'])->toBe('eml-hash')
        ->and($result['evidence']['truth_qr']['fragments'])->toBe(['truth://fixture'])
        ->and(array_column($result['trace'], 'id'))->toBe(['eml_export', 'truth_qr_encode']);
});
