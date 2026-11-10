<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class MetadataMappingMissingException extends RuntimeException
{
    public function __construct(public readonly string $mappingType, public readonly string $sourceValue)
    {
        parent::__construct("No active {$mappingType} mapping for '{$sourceValue}'.");
    }
}
