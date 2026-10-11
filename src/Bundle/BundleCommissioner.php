<?php

namespace LBHurtado\ElectionCeremonies\Bundle;

use LBHurtado\ElectionCeremonies\Ceremony\CeremonyProfile;
use LBHurtado\ElectionCeremonies\Contracts\CeremonyStore;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

final readonly class BundleCommissioner
{
    public function __construct(
        private ElectionBundleVerifier $bundles,
        private SiteAssignment $assignments,
        private CeremonyStore $store,
    ) {}

    /** @param array<string, string> $trustedKeys
     * @return array<string, mixed>
     */
    public function commission(
        string $runId,
        string $archivePath,
        string $assignmentDocument,
        string $assignmentSignature,
        array $trustedKeys,
        string $deviceId,
        string $runType,
        string $destination,
    ): array {
        $bundle = $this->bundles->verify($archivePath, $trustedKeys);
        $assignment = $this->assignments->verify($assignmentDocument, $assignmentSignature, $trustedKeys, $bundle, $deviceId);

        if ($runType === 'election_day' && ($bundle['coverage'] !== 'national' || $bundle['readiness'] !== 'election_day')) {
            throw new RuntimeException('Research or pilot election bundles cannot be commissioned for election day.');
        }

        $archive = new ZipArchive;

        if ($archive->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Cannot open verified election bundle.');
        }

        $profileBytes = $archive->getFromName('profiles/ceremonies.yaml');
        $temporaryProfile = tempnam(sys_get_temp_dir(), 'ceremony-profile-');
        if (! is_string($profileBytes) || $temporaryProfile === false
            || file_put_contents($temporaryProfile, $profileBytes) === false) {
            throw new RuntimeException('Cannot load verified ceremony profile.');
        }

        $activated = false;

        try {
            $profile = CeremonyProfile::fromYaml($temporaryProfile);
        } finally {
            unlink($temporaryProfile);
        }

        if ($profile->runType !== $runType) {
            throw new RuntimeException('Ceremony profile does not match the requested run type.');
        }

        if ($runType === 'research' && ($bundle['coverage'] !== 'source_snapshot'
            || $bundle['readiness'] !== 'research' || $assignment['role'] !== 'research')) {
            throw new RuntimeException('Research runs require a research source snapshot and assignment.');
        }

        if (array_diff($assignment['ceremonies'], array_keys($profile->ceremonies)) !== []) {
            throw new RuntimeException('Site assignment permits a ceremony absent from the bundle profile.');
        }

        if (is_dir($destination)) {
            throw new RuntimeException('Commissioned bundle destination already exists.');
        }

        $stage = $destination.'.stage-'.bin2hex(random_bytes(6));

        if (! mkdir($stage, 0700, true)) {
            throw new RuntimeException('Cannot create election bundle staging directory.');
        }

        try {
            foreach (array_keys($bundle['files']) as $relative) {
                $path = $stage.'/'.$relative;
                $directory = dirname($path);

                if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new RuntimeException('Cannot create election bundle entry directory.');
                }

                $source = $archive->getStream($relative);
                $target = fopen($path, 'wb');

                if ($source === false || $target === false) {
                    throw new RuntimeException("Cannot stage election bundle entry [{$relative}].");
                }

                if (stream_copy_to_stream($source, $target) !== $bundle['files'][$relative]['bytes']) {
                    throw new RuntimeException("Cannot copy election bundle entry [{$relative}].");
                }
                fclose($source);
                fclose($target);

                if (! hash_equals($bundle['files'][$relative]['sha256'], hash_file('sha256', $path))) {
                    throw new RuntimeException("Staged election bundle entry [{$relative}] changed.");
                }

                chmod($path, 0444);
            }

            file_put_contents($stage.'/manifest.json', $archive->getFromName('manifest.json'));
            file_put_contents($stage.'/manifest.sig', $archive->getFromName('manifest.sig'));
            if (! rename($stage, $destination)) {
                throw new RuntimeException('Cannot activate staged election bundle.');
            }

            $activated = true;

            $this->store->commission(
                $runId,
                $bundle['archive_sha256'],
                hash('sha256', $assignmentDocument),
                $profile->sha256,
                $assignment['ceremonies'],
            );

            return [
                'bundle_id' => $bundle['bundle_id'],
                'bundle_sha256' => $bundle['archive_sha256'],
                'assignment_sha256' => hash('sha256', $assignmentDocument),
                'profile_sha256' => $profile->sha256,
                'coverage' => $bundle['coverage'],
                'device_id' => $deviceId,
                'role' => $assignment['role'],
                'jurisdiction_id' => $assignment['jurisdiction_id'],
            ];
        } catch (Throwable $exception) {
            self::removeDirectory($activated ? $destination : $stage);

            throw $exception;
        } finally {
            $archive->close();
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
