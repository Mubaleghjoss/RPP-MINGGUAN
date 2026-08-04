<?php

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithPersistentNotifications;
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
use Throwable;

#[Layout('layouts.app')]
#[Title('Riwayat Revisi')]
class RevisionHistory extends Component
{
    use InteractsWithPersistentNotifications;
    use WithPagination;

    public string $restoreReason = '';

    public string $notice = '';

    public string $errorMessage = '';

    #[Url]
    public string $domain = '';

    #[Url(as: 'baris')]
    public ?int $rowId = null;

    public function updatedDomain(): void
    {
        $this->resetPage();
    }

    public function updatedRowId(): void
    {
        $this->resetPage();
    }

    public function restore(int $batchId, CurriculumRevisionService $revisions): void
    {
        $this->errorMessage = '';
        try {
            $source = RevisionBatch::query()->findOrFail($batchId);
            $batch = $revisions->restoreBatch($source, $this->restoreReason, Auth::user());
            $this->notifySuccess("Revisi dipulihkan melalui batch baru {$batch->uuid}.", 'Pemulihan revisi berhasil');
            $this->restoreReason = '';
        } catch (ValidationException $exception) {
            $this->notifyValidationException($exception, 'Revisi belum dapat dipulihkan', ['Isi alasan pemulihan dan pastikan data belum berubah pada revisi lain.'], 'restoreReason');
        } catch (RuntimeException $exception) {
            $this->notifyError($exception->getMessage(), 'Revisi belum dapat dipulihkan', [$exception->getMessage()], ['Muat ulang riwayat untuk mengambil versi terbaru lalu coba kembali.']);
        } catch (Throwable $exception) {
            $this->notifyTechnicalFailure($exception, 'Revisi gagal dipulihkan. Tidak ada perubahan yang diterapkan.', 'Pemulihan revisi mengalami gangguan');
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
