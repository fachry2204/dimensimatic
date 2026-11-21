<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Exceptions\MetadataMappingMissingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AutomationErrorClassifier
{
    /** @return array{code:string, disposition:string} */
    public function classify(Throwable $error): array
    {
        if ($error instanceof MetadataMappingMissingException) {
            return ['code' => 'METADATA_MAPPING_MISSING', 'disposition' => 'needs_attention'];
        }
        if ($error instanceof ValidationException) {
            $fields = array_keys($error->errors());
            if (in_array('upc', $fields, true)) {
                return ['code' => 'UPC_INVALID', 'disposition' => 'needs_attention'];
            }
            if (collect($fields)->contains(fn (string $field): bool => str_ends_with($field, '.isrc'))) {
                return ['code' => 'ISRC_INVALID', 'disposition' => 'needs_attention'];
            }
            if (in_array('original_release_date', $fields, true)) {
                return ['code' => 'ORIGINAL_RELEASE_DATE_INVALID', 'disposition' => 'needs_attention'];
            }
            return ['code' => 'METADATA_MISSING', 'disposition' => 'needs_attention'];
        }
        if ($error instanceof ConnectionException) {
            return ['code' => 'NETWORK_TIMEOUT', 'disposition' => 'retry'];
        }
        $message = strtoupper($error->getMessage());
        if (str_contains($message, 'SOUNDFRESH_REVIEW_') || str_contains($message, 'SOUNDFRESH_UI_CHANGED:REVIEW_RELEASE')) {
            return ['code' => 'CMS_REVIEW_FAILED', 'disposition' => 'needs_attention'];
        }
        if (str_contains($message, 'SOUNDON_MONETIZATION_NOT_ENABLED')) {
            return ['code' => 'SOUNDON_MONETIZATION_NOT_ENABLED', 'disposition' => 'needs_attention'];
        }
        if (str_contains($message, 'SOUNDON_REQUIRED_FIELD_UNRESOLVED')) {
            return ['code' => 'SOUNDON_REQUIRED_FIELD_UNRESOLVED', 'disposition' => 'needs_attention'];
        }
        if (str_contains($message, 'SOUNDON_ARTIST_PROFILE_CREATE_NOT_SELECTED')
            || str_contains($message, 'SOUNDON_ARTIST_CREATE_SUBMIT_DISABLED')) {
            return ['code' => 'SOUNDON_ARTIST_CREATE_FAILED', 'disposition' => 'needs_attention'];
        }
        if (str_contains($message, 'SOUNDON_FORM_VALIDATION')) {
            return ['code' => 'SOUNDON_FORM_VALIDATION', 'disposition' => 'needs_attention'];
        }
        if (str_contains($message, 'SOUNDON_AUTOMATION_TIMEOUT')) {
            if (preg_match('/FOR\s+([2-9]|[1-9]\d+)\s+TRACK/', $message)) {
                return ['code' => 'SOUNDON_ALBUM_TIMEOUT', 'disposition' => 'retry'];
            }
            return ['code' => 'SOUNDON_UI_STALLED', 'disposition' => 'retry'];
        }
        if ((str_contains($message, 'LOCATOR.CLICK') || str_contains($message, 'LOCATOR.WAITFOR')) && str_contains($message, 'TIMEOUT')) {
            return ['code' => 'UI_INTERACTION_TIMEOUT', 'disposition' => 'retry'];
        }
        foreach (['CAPTCHA' => 'AUTH_CAPTCHA_REQUIRED', 'OTP' => 'AUTH_OTP_REQUIRED', 'MANUAL_AUTH' => 'AUTH_MANUAL_REQUIRED'] as $needle => $code) {
            if (str_contains($message, $needle)) {
                return ['code' => $code, 'disposition' => 'manual_auth'];
            }
        }
        foreach (['SOUNDON_RELEASE_TYPE_NOT_FOUND' => 'SOUNDON_RELEASE_TYPE_NOT_SELECTED', 'SOUNDON_RELEASE_TYPE_NOT_SELECTED' => 'SOUNDON_RELEASE_TYPE_NOT_SELECTED', 'ASSET_UPLOAD_FAILED:ALBUM_TRACK' => 'ALBUM_TRACK_UPLOAD_FAILED', 'SELECTOR_DISCOVERY_REQUIRED' => 'UI_CHANGED', 'SELECTOR_NOT_UNIQUE' => 'UI_CHANGED', 'SOUNDON_UI_CHANGED' => 'UI_CHANGED', 'SOUNDON_MONETIZATION_NOT_ENABLED' => 'UI_CHANGED', 'SOUNDON_CONTRIBUTOR_NOT_PERSISTED' => 'SOUNDON_CONTRIBUTOR_NOT_PERSISTED', 'SOUNDON_ENTITY_MATCH_REQUIRED' => 'SOUNDON_ENTITY_MATCH_REQUIRED', 'SOUNDON_FIELD_NOT_PERSISTED' => 'SOUNDON_FIELD_NOT_PERSISTED', 'SOUNDON_TIKTOK_PRE_RELEASE_NOT_SKIPPED' => 'PRE_RELEASE_INVALID', 'SOUNDON_YOUTUBE_PRE_RELEASE_NOT_DISABLED' => 'PRE_RELEASE_INVALID', 'TIKTOK_AUDIO_UPLOAD_FAILED' => 'TIKTOK_AUDIO_UPLOAD_FAILED', 'AUDIO_UPLOAD_FAILED' => 'AUDIO_UPLOAD_FAILED', 'COVER_UPLOAD_FAILED' => 'COVER_UPLOAD_FAILED', 'AUDIO_INVALID' => 'AUDIO_INVALID', 'COVER_INVALID' => 'COVER_INVALID', 'ASSET_MISSING' => 'ASSET_MISSING', 'ASSET_RENAME_FAILED' => 'ASSET_RENAME_FAILED', 'METADATA_MAPPING_MISSING' => 'METADATA_MAPPING_MISSING'] as $needle => $code) {
            if (str_contains($message, $needle)) {
                return ['code' => $code, 'disposition' => 'needs_attention'];
            }
        }
        foreach (['AUTH_SESSION_EXPIRED' => 'AUTH_SESSION_EXPIRED', 'ECONNREFUSED' => 'WORKER_UNAVAILABLE', 'CONNECTION REFUSED' => 'WORKER_UNAVAILABLE', 'CURL ERROR 6' => 'DNS_ERROR', 'CURL ERROR 7' => 'CONNECTION_FAILED', 'CURL ERROR 28' => 'NETWORK_TIMEOUT', 'CURL ERROR 56' => 'CONNECTION_INTERRUPTED', 'TIMEOUT' => 'NETWORK_TIMEOUT', 'UPLOAD_FAILED' => 'UPLOAD_FAILED', 'RATE_LIMIT' => 'RATE_LIMITED', 'HTTP 429' => 'RATE_LIMITED'] as $needle => $code) {
            if (str_contains($message, $needle)) {
                return ['code' => $code, 'disposition' => 'retry'];
            }
        }

        return ['code' => 'AUTOMATION_FAILED', 'disposition' => 'retry'];
    }
}
