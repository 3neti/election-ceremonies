<?php

namespace LBHurtado\ElectionCeremonies\Ceremony;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

final readonly class CeremonyProfile
{
    /**
     * @param  array<int, string>  $stages
     * @param  array<string, array{from: string, to: string, pipes: array<int, string>, evidence: array<int, string>}>  $ceremonies
     */
    public function __construct(
        public string $id,
        public int $version,
        public array $stages,
        public array $ceremonies,
        public string $sha256,
    ) {}

    public static function fromYaml(string $path): self
    {
        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new InvalidArgumentException("Cannot read ceremony profile [{$path}].");
        }

        $data = Yaml::parse($bytes);

        if (! is_array($data) || ! is_string($data['id'] ?? null)
            || ! is_int($data['version'] ?? null) || ! is_array($data['stages'] ?? null)
            || ! is_array($data['ceremonies'] ?? null)) {
            throw new InvalidArgumentException('Ceremony profile is malformed.');
        }

        $stages = $data['stages'];

        if ($stages === [] || ! array_is_list($stages) || count($stages) !== count(array_unique($stages))
            || count(array_filter($stages, fn (mixed $stage): bool => is_string($stage) && $stage !== '')) !== count($stages)) {
            throw new InvalidArgumentException('Ceremony profile stages must be unique non-empty strings.');
        }

        $ceremonies = [];

        foreach ($data['ceremonies'] as $id => $definition) {
            if (! is_string($id) || $id === '' || ! is_array($definition)
                || ! in_array($definition['from'] ?? null, $stages, true)
                || ! in_array($definition['to'] ?? null, $stages, true)
                || ! is_array($definition['pipes'] ?? null) || ! array_is_list($definition['pipes'])
                || ! is_array($definition['evidence'] ?? null) || ! array_is_list($definition['evidence'])) {
                throw new InvalidArgumentException("Ceremony definition [{$id}] is malformed.");
            }

            foreach ([...$definition['pipes'], ...$definition['evidence']] as $value) {
                if (! is_string($value) || $value === '') {
                    throw new InvalidArgumentException("Ceremony definition [{$id}] contains an invalid identifier.");
                }
            }

            $ceremonies[$id] = [
                'from' => $definition['from'],
                'to' => $definition['to'],
                'pipes' => $definition['pipes'],
                'evidence' => $definition['evidence'],
            ];
        }

        return new self($data['id'], $data['version'], $stages, $ceremonies, hash('sha256', $bytes));
    }

    /** @return array{from: string, to: string, pipes: array<int, string>, evidence: array<int, string>} */
    public function ceremony(string $id): array
    {
        return $this->ceremonies[$id] ?? throw new InvalidArgumentException("Unknown ceremony [{$id}].");
    }
}
