<?php

declare(strict_types=1);

namespace App\Enums;

enum ReleaseCheckpoint: string
{
    case Discovered = 'discovered';
    case MetadataExtracted = 'metadata_extracted';
    case AssetsDownloaded = 'assets_downloaded';
    case SoundOnDraftCreationStarted = 'soundon_draft_creation_started';
    case SoundOnDraftCreated = 'soundon_draft_created';
    case MetadataFilled = 'metadata_filled';
    case AssetsUploaded = 'assets_uploaded';
    case DraftSaved = 'draft_saved';
    case SoundfreshReviewed = 'soundfresh_reviewed';
    case Completed = 'completed';
}
