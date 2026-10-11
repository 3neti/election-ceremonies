<?php

use LBHurtado\ElectionCeremonies\Bundle\AuthoritySignature;
use LBHurtado\ElectionCeremonies\Bundle\BundleCommissioner;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleBuilder;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleVerifier;
use LBHurtado\ElectionCeremonies\Bundle\RegistryDigest;
use LBHurtado\ElectionCeremonies\Bundle\SiteAssignment;
use LBHurtado\ElectionCeremonies\Ceremony\FileCeremonyStore;

test('signed pilot bundle and site assignment verify offline', function (): void {
    $directory = sys_get_temp_dir().'/election-bundle-test-'.bin2hex(random_bytes(5));
    mkdir($directory);
    $databasePath = $directory.'/election.sqlite';
    $database = new PDO('sqlite:'.$databasePath);
    $database->exec('CREATE TABLE precincts (id TEXT PRIMARY KEY)');
    $database->exec("INSERT INTO precincts VALUES ('0421-A')");
    unset($database);
    mkdir($directory.'/profiles');
    file_put_contents($directory.'/profiles/ceremonies.yaml', "id: pilot\nversion: 1\nstages: [provision, certification]\nceremonies: {}\n");
    file_put_contents($directory.'/profiles/truth-qr.yaml', "id: aes-election-v1\nversion: 1\nrecords:\n  ballot: {}\n");
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    $signatures = new AuthoritySignature;
    $keys = [$signatures->keyId($public) => $public];
    $builder = new ElectionBundleBuilder(new RegistryDigest, $signatures);
    $path = $directory.'/bundle.zip';
    $builder->build($databasePath, [
        'profiles/ceremonies.yaml' => $directory.'/profiles/ceremonies.yaml',
        'profiles/truth-qr.yaml' => $directory.'/profiles/truth-qr.yaml',
    ], ['bundle_id' => 'pilot-1', 'election_id' => 'election-1', 'coverage' => 'pilot'], $secret, $path);
    $manifest = (new ElectionBundleVerifier(new RegistryDigest, $signatures))->verify($path, $keys);

    expect($manifest['bundle_id'])->toBe('pilot-1')
        ->and($manifest['readiness'])->toBe('research')
        ->and($manifest['archive_sha256'])->toBe(hash_file('sha256', $path));

    $assignment = new SiteAssignment($signatures);
    $signed = $assignment->sign([
        'bundle_id' => 'pilot-1',
        'bundle_sha256' => $manifest['archive_sha256'],
        'device_id' => 'device-1',
        'role' => 'precinct',
        'jurisdiction_id' => '0421-A',
        'permitted_sources' => [],
        'ceremonies' => ['certify'],
    ], $secret);

    expect($assignment->verify($signed['document'], $signed['signature'], $keys, $manifest, 'device-1')['role'])
        ->toBe('precinct');
    expect(fn () => $assignment->verify($signed['document'], $signed['signature'], $keys, $manifest, 'device-2'))
        ->toThrow(RuntimeException::class);
});

test('signed source snapshot remains unavailable for election day', function (): void {
    $directory = sys_get_temp_dir().'/source-snapshot-test-'.bin2hex(random_bytes(5));
    mkdir($directory);
    $databasePath = $directory.'/election.sqlite';
    $database = new PDO('sqlite:'.$databasePath);
    $database->exec('CREATE TABLE precincts (id TEXT PRIMARY KEY)');
    $database->exec("INSERT INTO precincts VALUES ('0421-A')");
    unset($database);
    file_put_contents($directory.'/ceremonies.yaml', "id: research\nversion: 1\nstages: [provision, certification]\nceremonies: {}\n");
    file_put_contents($directory.'/truth-qr.yaml', "id: aes-election-v1\nversion: 1\nrecords:\n  ballot: {}\n");
    $catalogHash = str_repeat('a', 64);
    $popHash = str_repeat('b', 64);
    file_put_contents($directory.'/report.json', json_encode(['schema_version' => 'national-source-snapshot-report-1', 'valid' => true, 'source_catalog_sha256' => $catalogHash, 'pop_sha256' => $popHash], JSON_THROW_ON_ERROR));
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    $signatures = new AuthoritySignature;
    $keys = [$signatures->keyId($public) => $public];
    $builder = new ElectionBundleBuilder(new RegistryDigest, $signatures);
    $archivePath = $directory.'/research.zip';
    $manifest = $builder->build($databasePath, [
        'profiles/ceremonies.yaml' => $directory.'/ceremonies.yaml',
        'profiles/truth-qr.yaml' => $directory.'/truth-qr.yaml',
        'reports/source-snapshot.json' => $directory.'/report.json',
    ], ['bundle_id' => 'source-1', 'election_id' => 'election-1', 'coverage' => 'source_snapshot', 'source_catalog_sha256' => $catalogHash, 'pop_sha256' => $popHash], $secret, $archivePath);
    $verifier = new ElectionBundleVerifier(new RegistryDigest, $signatures);
    $verified = $verifier->verify($archivePath, $keys);
    $assignment = new SiteAssignment($signatures);
    $signed = $assignment->sign([
        'bundle_id' => 'source-1',
        'bundle_sha256' => $verified['archive_sha256'],
        'device_id' => 'device-1',
        'role' => 'precinct',
        'jurisdiction_id' => '0421-A',
        'permitted_sources' => [],
        'ceremonies' => ['certify'],
    ], $secret);
    $commissioner = new BundleCommissioner($verifier, $assignment, new FileCeremonyStore($directory.'/states'));

    expect($manifest['coverage'])->toBe('source_snapshot')
        ->and($verified['readiness'])->toBe('research')
        ->and(fn () => $commissioner->commission('run-1', $archivePath, $signed['document'], $signed['signature'], $keys, 'device-1', 'election_day', $directory.'/active'))
        ->toThrow(RuntimeException::class);
});
