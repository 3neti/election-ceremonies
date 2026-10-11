<?php

use LBHurtado\ElectionCeremonies\Bundle\AuthoritySignature;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleBuilder;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleVerifier;
use LBHurtado\ElectionCeremonies\Bundle\RegistryDigest;
use LBHurtado\ElectionCeremonies\Bundle\SiteAssignment;

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
