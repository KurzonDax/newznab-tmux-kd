<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * Where a settings page sits in the release pipeline.
 *
 * The hub draws the same four-stage strip on every page and highlights the stage that page
 * belongs to, so an admin can see what a setting affects without reading the help text first.
 */
enum PipelineStage: string
{
    case Ingest = 'ingest';

    case FormReleases = 'form-releases';

    case Enrich = 'enrich';

    case Publish = 'publish';

    public function label(): string
    {
        return match ($this) {
            self::Ingest => 'Ingest',
            self::FormReleases => 'Form releases',
            self::Enrich => 'Enrich',
            self::Publish => 'Publish',
        };
    }

    /**
     * The strip, in pipeline order.
     *
     * @return list<self>
     */
    public static function strip(): array
    {
        return [self::Ingest, self::FormReleases, self::Enrich, self::Publish];
    }
}
