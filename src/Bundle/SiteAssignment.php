<?php

namespace LBHurtado\ElectionCeremonies\Bundle;

use RuntimeException;

final readonly class SiteAssignment
{
    public function __construct(private AuthoritySignature $signatures) {}

    /** @param array<string, mixed> $assignment
     * @return array{document: string, signature: string}
     */
    public function sign(array $assignment, string $secretKey): array
    {
        $this->assertShape($assignment);
        $document = json_encode($assignment, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'document' => $document,
            'signature' => json_encode($this->signatures->sign($document, $secretKey), JSON_THROW_ON_ERROR),
        ];
    }

    /** @param array<string, string> $trustedKeys
     * @return array<string, mixed>
     */
    public function verify(string $document, string $signature, array $trustedKeys, array $bundle, string $deviceId): array
    {
        $data = json_decode($document, true, flags: JSON_THROW_ON_ERROR);
        $signatureData = json_decode($signature, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! is_array($signatureData)) {
            throw new RuntimeException('Site assignment is malformed.');
        }

        $this->assertShape($data);
        $this->signatures->verify($document, $signatureData, $trustedKeys);

        if (($data['bundle_id'] ?? null) !== ($bundle['bundle_id'] ?? null)
            || ($data['bundle_sha256'] ?? null) !== ($bundle['archive_sha256'] ?? null)
            || ! hash_equals($deviceId, $data['device_id'])) {
            throw new RuntimeException('Site assignment does not match this bundle or device.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function assertShape(array $data): void
    {
        foreach (['bundle_id', 'bundle_sha256', 'device_id', 'role', 'jurisdiction_id'] as $key) {
            if (! is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new RuntimeException("Site assignment requires [{$key}].");
            }
        }

        if (! in_array($data['role'], ['precinct', 'municipal', 'provincial', 'national'], true)
            || ! is_array($data['permitted_sources'] ?? null)
            || ! is_array($data['ceremonies'] ?? null)) {
            throw new RuntimeException('Site assignment role or permissions are invalid.');
        }
    }
}
