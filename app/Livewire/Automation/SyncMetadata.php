<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use App\Models\MetadataFieldSync;
use App\Models\MetadataMapping;
use App\Services\Automation\OperatorAudit;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class SyncMetadata extends Component
{
    public function toggle(int $id): void
    {
        Gate::authorize('mappings.manage');
        $sync = MetadataFieldSync::findOrFail($id);
        $sync->update(['is_active' => ! $sync->is_active]);
        app(OperatorAudit::class)->record('metadata_field_sync_toggled', 'Operator changed metadata field synchronization.', context: ['sync_id' => $sync->id, 'is_active' => $sync->is_active]);
    }

    public function enableAll(): void
    {
        Gate::authorize('mappings.manage');
        MetadataFieldSync::query()->update(['is_active' => true, 'updated_at' => now()]);
        app(OperatorAudit::class)->record('metadata_field_sync_all_enabled', 'Operator enabled all metadata field synchronization.');
    }

    public function render()
    {
        $syncs = MetadataFieldSync::orderBy('scope')->orderBy('sort_order')->get();
        return view('livewire.automation.sync-metadata', [
            'syncs' => $syncs,
            'activeCount' => $syncs->where('is_active', true)->count(),
            'valueMappings' => MetadataMapping::query()
                ->whereIn('mapping_type', ['genre', 'subgenre', 'language'])
                ->where('is_active', true)
                ->latest('updated_at')
                ->limit(100)
                ->get(),
        ]);
    }
}
