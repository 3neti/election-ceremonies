<?php

namespace LBHurtado\ElectionCeremonies\Bundle;

use RuntimeException;
use ZipArchive;

final readonly class ElectionBundleBuilder
{
    public function __construct(
        private RegistryDigest $digest,
        private AuthoritySignature $signatures,
    ) {}

    /**
     * @param  array<string, string>  $files  ZIP relative path to source path
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function build(string $sqlitePath, array $files, array $metadata, string $secretKey, string $destination): array
    {
        if (! in_array($metadata['coverage'] ?? null, ['pilot', 'national'], true)
            || ! is_string($metadata['election_id'] ?? null) || $metadata['election_id'] === ''
            || ! is_string($metadata['bundle_id'] ?? null) || $metadata['bundle_id'] === '') {
            throw new RuntimeException('Election bundle metadata is incomplete.');
        }

        if (isset($files['election.sqlite'])) {
            throw new RuntimeException('Election registry entry is reserved.');
        }

        $files = ['election.sqlite' => $sqlitePath, ...$files];
        ksort($files);
        $entries = [];

        foreach ($files as $relative => $source) {
            if (! self::safePath($relative) || in_array($relative, ['manifest.json', 'manifest.sig'], true)
                || ! is_file($source)) {
                throw new RuntimeException("Invalid election bundle entry [{$relative}].");
            }

            $entries[$relative] = ['bytes' => filesize($source), 'sha256' => hash_file('sha256', $source)];
        }

        foreach (['profiles/ceremonies.yaml', 'profiles/truth-qr.yaml'] as $required) {
            if (! isset($entries[$required])) {
                throw new RuntimeException("Election bundle requires [{$required}].");
            }
        }

        $manifest = [
            'schema_version' => 'election-bundle-1',
            'bundle_id' => $metadata['bundle_id'],
            'election_id' => $metadata['election_id'],
            'coverage' => $metadata['coverage'],
            'registry_schema' => (string) ($metadata['registry_schema'] ?? 'election-registry-1'),
            'logical_registry_sha256' => $this->digest->hash($sqlitePath),
            'files' => $entries,
        ];
        $manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signatureBytes = json_encode($this->signatures->sign($manifestBytes, $secretKey), JSON_THROW_ON_ERROR);
        $archive = new ZipArchive;

        if ($archive->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create election bundle archive.');
        }

        try {
            foreach ($files as $relative => $source) {
                if (! $archive->addFile($source, $relative)) {
                    throw new RuntimeException("Cannot add election bundle entry [{$relative}].");
                }
            }

            $archive->addFromString('manifest.json', $manifestBytes);
            $archive->addFromString('manifest.sig', $signatureBytes);
        } finally {
            $archive->close();
        }

        return $manifest;
    }

    public static function safePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '\\')
            && ! str_contains($path, "\0") && ! in_array('.', explode('/', $path), true)
            && ! in_array('..', explode('/', $path), true) && ! str_ends_with($path, '/');
    }
}
