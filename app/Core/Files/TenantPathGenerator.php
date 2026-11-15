<?php

namespace App\Core\Files;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Stores each organisation's files under `org/{organisation_id}/{file uuid}/`, never by guessable numbers.
 */
final class TenantPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return "org/{$media->getAttribute('organisation_id')}/{$media->uuid}/";
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getPath($media).'conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getPath($media).'responsive/';
    }
}
