<?php

use Illuminate\Container\Container;
use Illuminate\Pipeline\Pipeline;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyContext;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyProfile;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyRequest;
use LBHurtado\ElectionCeremonies\Ceremony\CeremonyRunner;
use LBHurtado\ElectionCeremonies\Ceremony\FileCeremonyStore;
use LBHurtado\ElectionCeremonies\Contracts\ActorVerifier;
use LBHurtado\ElectionCeremonies\Contracts\CeremonyPipe;

test('commissioned ceremony runs registered pipes once and records evidence', function (): void {
    $directory = sys_get_temp_dir().'/ceremony-test-'.bin2hex(random_bytes(5));
    mkdir($directory);
    $profilePath = $directory.'/profile.yaml';
    file_put_contents($profilePath, "id: pilot\nversion: 1\nstages: [provision, certification]\nceremonies:\n  certify:\n    from: provision\n    to: certification\n    pipes: [make_report]\n    evidence: [report]\n");
    $profile = CeremonyProfile::fromYaml($profilePath);
    $store = new FileCeremonyStore($directory.'/state');
    $store->commission('run-1', 'bundle-hash', 'assignment-hash', $profile->sha256, ['certify']);
    $pipe = new class implements CeremonyPipe
    {
        public int $calls = 0;

        public function handle(CeremonyContext $context, Closure $next): CeremonyContext
        {
            $this->calls++;
            $context->addEvidence('report', ['sha256' => 'report-hash']);

            return $next($context);
        }
    };
    $actors = new class implements ActorVerifier
    {
        public function verify(CeremonyRequest $request): void
        {
            if ($request->actorId !== 'officer-1') {
                throw new RuntimeException('Unauthorized actor.');
            }
        }
    };
    $runner = new CeremonyRunner($profile, $store, new Pipeline(new Container), $actors, ['make_report' => $pipe]);
    $request = new CeremonyRequest('run-1', 'certify', 'officer-1', 'bundle-hash', 'assignment-hash', 'attempt-1');
    $first = $runner->run($request);

    expect($first['to'])->toBe('certification')
        ->and($first['evidence']['report']['sha256'])->toBe('report-hash')
        ->and($runner->run($request))->toBe($first)
        ->and($pipe->calls)->toBe(1)
        ->and($store->state('run-1')['stage'])->toBe('certification');

    expect(fn () => $runner->run(new CeremonyRequest('run-1', 'certify', 'officer-1', 'bundle-hash', 'assignment-hash', 'attempt-2')))
        ->toThrow(RuntimeException::class);
});

test('ceremony requires commissioning and cannot change its bundle identity', function (): void {
    $directory = sys_get_temp_dir().'/ceremony-test-'.bin2hex(random_bytes(5));
    mkdir($directory);
    $profilePath = $directory.'/profile.yaml';
    file_put_contents($profilePath, "id: pilot\nversion: 1\nstages: [provision, certification]\nceremonies:\n  certify:\n    from: provision\n    to: certification\n    pipes: []\n    evidence: []\n");
    $profile = CeremonyProfile::fromYaml($profilePath);
    $store = new FileCeremonyStore($directory.'/state');
    $actors = new class implements ActorVerifier
    {
        public function verify(CeremonyRequest $request): void {}
    };
    $runner = new CeremonyRunner($profile, $store, new Pipeline(new Container), $actors, []);
    $request = new CeremonyRequest('run-2', 'certify', 'officer-1', 'bundle-hash', 'assignment-hash', 'attempt-1');

    expect(fn () => $runner->run($request))->toThrow(RuntimeException::class);

    $store->commission('run-2', 'bundle-hash', 'assignment-hash', $profile->sha256, ['certify']);

    expect(fn () => $runner->run(new CeremonyRequest('run-2', 'certify', 'officer-1', 'other', 'assignment-hash', 'attempt-1')))
        ->toThrow(RuntimeException::class);
});
