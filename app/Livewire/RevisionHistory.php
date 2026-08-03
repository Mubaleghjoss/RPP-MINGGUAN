<?php

namespace App\Livewire;

use App\Models\RevisionBatch;
use App\Services\CurriculumRevisionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

#[Layout('layouts.app')]
#[Title('Riwayat Revisi')]
class RevisionHistory extends Component
{
    use WithPagination;

    public string $restoreReason = '';
    public string $notice = '';
    public string $errorMessage = '';

    #[Url]
    public string $domain = '';

    #[Url(as: 'baris')]
    public ?int $rowId = null;

    public function updatedDomain(): void { $this->resetPage(); }
    public function updatedRowId(): void { $this->resetPage(); }

    public function restore(int $batchId, CurriculumRevisionService $revisions): void
    {
        $this->errorMessage = '';
        try {
            $source = RevisionBatch::query()->findOrFail($batchId);
            $batch = $revisions->restoreBatch($source, $this->restoreReason, Auth::user());
            $this->notice = "Revisi dipulihkan melalui batch baru {$batch->uuid}.";
            $this->restoreReason = '';
        } catch (ValidationException $exception) {
            $this->errorMessage = collect($exception->errors())->flatten()->first();
        } catch (RuntimeException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.revision-history', [
            'batches' => RevisionBatch::query()->with(['user', 'items'])
                ->when($this->domain !== '' && $this->rowId, fn ($query) => $query->whereHas('items', fn ($item) => $item->where('revisable_type', $this->domain)->where('revisable_id', $this->rowId)))
                ->latest()->paginate(25),
        ]);
    }
}
