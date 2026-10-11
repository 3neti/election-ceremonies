<?php

namespace LBHurtado\ElectionCeremonies\Bundle;

use LBHurtado\ElectionCeremonies\Ceremony\CeremonyProfile;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

final readonly class ElectionBundleVerifier
{
    public function __construct(
        private RegistryDigest $digest,
        private AuthoritySignature $signatures,
    ) {}

    /** @param array<string, string> $trustedKeys
     * @return array<string, mixed>
     */
    public function verify(string $archivePath, array $trustedKeys): array
    {
        $archive = new ZipArchive;

        if ($archive->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Election bundle ZIP cannot be opened.');
        }

        try {
            $manifestBytes = $archive->getFromName('manifest.json');
            $signatureBytes = $archive->getFromName('manifest.sig');

            if (! is_string($manifestBytes) || ! is_string($signatureBytes)) {
                throw new RuntimeException('Election bundle manifest or signature is missing.');
            }

            $manifest = json_decode($manifestBytes, true, flags: JSON_THROW_ON_ERROR);
            $signature = json_decode($signatureBytes, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($manifest) || ! is_array($signature)
                || ($manifest['schema_version'] ?? null) !== 'election-bundle-1'
                || ! in_array($manifest['coverage'] ?? null, ['source_snapshot', 'pilot', 'national'], true)
                || ($manifest['readiness'] ?? 'research') !== 'research'
                || ! is_array($manifest['files'] ?? null)) {
                throw new RuntimeException('Election bundle manifest is malformed.');
            }

            $this->signatures->verify($manifestBytes, $signature, $trustedKeys);
            $expected = [...array_keys($manifest['files']), 'manifest.json', 'manifest.sig'];
            $actual = [];
            $totalBytes = 0;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);
                $name = $stat['name'] ?? '';

                if (! is_string($name) || ! ElectionBundleBuilder::safePath($name) || in_array($name, $actual, true)) {
                    throw new RuntimeException('Election bundle contains an unsafe or duplicate path.');
                }

                $archive->getExternalAttributesIndex($index, $system, $attributes);

                if ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Election bundle contains a symbolic link.');
                }

                $totalBytes += (int) ($stat['size'] ?? 0);

                if ($totalBytes > 1_000_000_000 || ! in_array($name, $expected, true)) {
                    throw new RuntimeException('Election bundle contains unexpected or excessive content.');
                }

                $actual[] = $name;
            }

            sort($actual);
            sort($expected);

            if ($actual !== $expected) {
                throw new RuntimeException('Election bundle file inventory does not match its manifest.');
            }

            $sqlitePath = tempnam(sys_get_temp_dir(), 'election-registry-');

            if ($sqlitePath === false) {
                throw new RuntimeException('Cannot allocate registry verification file.');
            }

            try {
                foreach ($manifest['files'] as $name => $entry) {
                    if (! is_array($entry) || ! is_int($entry['bytes'] ?? null)
                        || ! is_string($entry['sha256'] ?? null)) {
                        throw new RuntimeException("Election bundle entry [{$name}] is malformed.");
                    }

                    $stream = $archive->getStream($name);

                    if ($stream === false) {
                        throw new RuntimeException("Cannot read election bundle entry [{$name}].");
                    }

                    $hash = hash_init('sha256');
                    $bytes = 0;
                    $target = $name === 'election.sqlite' ? fopen($sqlitePath, 'wb') : null;

                    while (! feof($stream)) {
                        $chunk = fread($stream, 65536);

                        if ($chunk === false) {
                            throw new RuntimeException("Cannot stream election bundle entry [{$name}].");
                        }

                        $bytes += strlen($chunk);
                        hash_update($hash, $chunk);

                        if ($target !== null) {
                            fwrite($target, $chunk);
                        }
                    }

                    fclose($stream);

                    if ($target !== null) {
                        fclose($target);
                    }

                    if ($bytes !== $entry['bytes'] || ! hash_equals($entry['sha256'], hash_final($hash))) {
                        throw new RuntimeException("Election bundle entry [{$name}] failed its digest check.");
                    }
                }

                if (! isset($manifest['files']['election.sqlite'], $manifest['files']['profiles/ceremonies.yaml'], $manifest['files']['profiles/truth-qr.yaml'])
                    || ! hash_equals((string) ($manifest['logical_registry_sha256'] ?? ''), $this->digest->hash($sqlitePath))) {
                    throw new RuntimeException('Election bundle registry digest is invalid.');
                }

                if (($manifest['coverage'] ?? null) === 'source_snapshot') {
                    $report = json_decode((string) $archive->getFromName('reports/source-snapshot.json'), true);

                    if (! is_array($report) || ($report['valid'] ?? null) !== true
                        || ($report['schema_version'] ?? null) !== 'national-source-snapshot-report-1'
                        || ($report['source_catalog_sha256'] ?? null) !== ($manifest['source_catalog_sha256'] ?? null)
                        || ($report['pop_sha256'] ?? null) !== ($manifest['pop_sha256'] ?? null)) {
                        throw new RuntimeException('Source snapshot validation report is invalid.');
                    }
                }

                if (($manifest['coverage'] ?? null) === 'national') {
                    throw new RuntimeException('National coverage verification requires a reconciliation report.');
                }

                $ceremonyProfile = $archive->getFromName('profiles/ceremonies.yaml');
                $truthProfile = Yaml::parse((string) $archive->getFromName('profiles/truth-qr.yaml'));

                if (! is_string($ceremonyProfile) || ! is_array($truthProfile)
                    || ! is_string($truthProfile['id'] ?? null) || ! is_array($truthProfile['records'] ?? null)) {
                    throw new RuntimeException('Election bundle profiles are malformed.');
                }

                $profilePath = tempnam(sys_get_temp_dir(), 'ceremony-profile-');
                file_put_contents($profilePath, $ceremonyProfile);

                try {
                    CeremonyProfile::fromYaml($profilePath);
                } finally {
                    unlink($profilePath);
                }

                return [...$manifest, 'readiness' => $manifest['readiness'] ?? 'research', 'archive_sha256' => hash_file('sha256', $archivePath)];
            } finally {
                unlink($sqlitePath);
            }
        } finally {
            $archive->close();
        }
    }
}
