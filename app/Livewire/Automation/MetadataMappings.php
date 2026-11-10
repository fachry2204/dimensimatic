<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use App\Models\MetadataMapping;
use App\Services\Automation\OperatorAudit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

final class MetadataMappings extends Component
{
    public string $mappingType = 'genre';

    public string $sourceValue = '';

    public string $targetValue = '';

    public function save(): void
    {
        Gate::authorize('mappings.manage');
        $data = $this->validate([
            'mappingType' => ['required', Rule::in(['genre', 'subgenre', 'language', 'role', 'country', 'release_type'])],
            'sourceValue' => ['required', 'string', 'max:255'],
            'targetValue' => ['required', 'string', 'max:255'],
        ]);
        MetadataMapping::updateOrCreate(
            ['mapping_type' => $data['mappingType'], 'source_value' => trim($data['sourceValue'])],
            ['target_value' => trim($data['targetValue']), 'is_active' => true, 'created_by' => auth()->id()],
        );
        app(OperatorAudit::class)->record('metadata_mapping_saved', 'Operator saved metadata mapping.', context: ['mapping_type' => $data['mappingType'], 'source_value' => trim($data['sourceValue'])]);
        $this->reset('sourceValue', 'targetValue');
    }

    public function toggle(int $id): void
    {
        Gate::authorize('mappings.manage');
        $mapping = MetadataMapping::findOrFail($id);
        $mapping->update(['is_active' => ! $mapping->is_active]);
        app(OperatorAudit::class)->record('metadata_mapping_toggled', 'Operator changed metadata mapping status.', context: ['mapping_id' => $mapping->id, 'is_active' => $mapping->is_active]);
    }

    public function render()
    {
        return view('livewire.automation.metadata-mappings', ['mappings' => MetadataMapping::query()->orderBy('mapping_type')->orderBy('source_value')->get()]);
    }
}
