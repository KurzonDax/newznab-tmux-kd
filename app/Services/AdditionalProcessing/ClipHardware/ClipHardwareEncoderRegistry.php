<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\ClipHardware;

final class ClipHardwareEncoderRegistry
{
    /**
     * @var array<string, ClipHardwareEncoder>
     */
    private array $encoders = [];

    /**
     * @param  iterable<ClipHardwareEncoder>|null  $encoders
     */
    public function __construct(?iterable $encoders = null)
    {
        $encoders ??= [
            new VaapiClipHardwareEncoder,
            new QsvClipHardwareEncoder,
        ];

        foreach ($encoders as $encoder) {
            $this->encoders[$encoder->id()] = $encoder;
        }
    }

    public function resolve(string $id): ?ClipHardwareEncoder
    {
        return $this->encoders[strtolower(trim($id))] ?? null;
    }
}
