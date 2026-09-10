<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

/** Original groups survive additions; dependency components constrain later proof. */
final class ArtifactProvenance
{
    /** @return array{files: array<string, list<string>>, components: list<list<string>>} */
    public static function initial(ArtifactInventory $current, string $proofInventory, int $releaseId): array
    {
        $files = [];
        foreach (PendingInventory::decode($proofInventory) as $file) {
            $inventory = ArtifactInventory::load((new PostingNzb)->render([$file]));
            $key = array_key_first($inventory->files());
            if ($key !== null && ($current->files()[$key] ?? null) === $inventory->files()[$key]) {
                $files[$key][] = 'source:'.$file->sourceId;
            }
        }
        foreach (array_keys($current->files()) as $key) {
            $files[$key] ??= ['legacy:'.$releaseId];
        }

        return ['files' => $files, 'components' => []];
    }

    /**
     * @param  array{files: array<string, list<string>>, components: list<list<string>>}  $prior
     * @return array{provenance: array{files: array<string, list<string>>, components: list<list<string>>}, invalidated: list<string>}
     */
    public static function advance(array $prior, ArtifactInventory $before, ArtifactInventory $target, string $operationId, string $kind): array
    {
        if ($kind === 'serialization') {
            return ['provenance' => $prior, 'invalidated' => []];
        }
        $batch = 'operation:'.$operationId;
        if ($kind === 'replacement') {
            return ['provenance' => ['files' => array_fill_keys(array_keys($target->files()), [$batch]), 'components' => []],
                'invalidated' => array_values(array_unique(array_merge([], ...array_values($prior['files']))))];
        }
        $delta = $target->deltaAgainst($before);
        $component = [$batch];
        foreach ($delta['changed'] as $key) {
            $component = array_merge($component, $prior['files'][$key] ?? []);
        }
        do {
            $previous = $component;
            foreach ($prior['components'] as $dependency) {
                if (array_intersect($dependency, $component) !== []) {
                    $component = array_values(array_unique(array_merge($component, $dependency)));
                }
            }
        } while ($component !== $previous);
        $files = $prior['files'];
        foreach ($delta['added'] as $key) {
            $files[$key] = [$batch];
        }
        foreach ($delta['changed'] as $key) {
            $files[$key] = array_values(array_unique([...($files[$key] ?? []), $batch]));
        }
        $components = array_values(array_filter($prior['components'], static fn (array $dependency): bool => array_intersect($dependency, $component) === []));
        $components[] = array_values(array_unique($component));

        return ['provenance' => ['files' => $files, 'components' => $components], 'invalidated' => $component];
    }
}
