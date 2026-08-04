<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

trait InteractsWithPersistentNotifications
{
    protected function notifySuccess(
        string $message,
        string $title = 'Tindakan berhasil',
        array $details = [],
        array $suggestions = [],
    ): void {
        $this->setLegacyNotificationState($message, '');
        $this->dispatchPersistentNotification('success', $title, $message, $details, $suggestions);
    }

    protected function notifyInfo(
        string $message,
        string $title = 'Informasi',
        array $details = [],
        array $suggestions = [],
    ): void {
        $this->setLegacyNotificationState($message, '');
        $this->dispatchPersistentNotification('info', $title, $message, $details, $suggestions);
    }

    protected function notifyWarning(
        string $message,
        string $title = 'Perlu perhatian',
        array $details = [],
        array $suggestions = [],
        ?string $focusField = null,
    ): void {
        $this->setLegacyNotificationState($message, '');
        $this->dispatchPersistentNotification('warning', $title, $message, $details, $suggestions, null, $focusField);
    }

    protected function notifyError(
        string $message,
        string $title = 'Tindakan gagal',
        array $details = [],
        array $suggestions = [],
        ?string $reference = null,
        ?string $focusField = null,
    ): void {
        $this->setLegacyNotificationState('', $message);
        $this->dispatchPersistentNotification('error', $title, $message, $details, $suggestions, $reference, $focusField);
    }

    protected function notifyValidationException(
        ValidationException $exception,
        string $title = 'Data belum dapat disimpan',
        array $suggestions = ['Periksa bidang yang ditandai, perbaiki nilainya, lalu simpan kembali.'],
        ?string $focusField = null,
        string $fallback = 'Data tidak valid.',
    ): void {
        $messages = collect($exception->errors())->flatten()->filter()->unique()->values()->all();
        $message = $messages[0] ?? $fallback;

        $this->notifyError($message, $title, $messages, $suggestions, null, $focusField);
    }

    protected function notifyTechnicalFailure(
        Throwable $exception,
        string $message,
        string $title = 'Gangguan teknis',
        array $details = [],
        array $suggestions = [
            'Muat ulang halaman dan ulangi tindakan sekali lagi.',
            'Jika tetap gagal, salin detail ini dan cari kode referensinya pada storage/logs/laravel.log.',
        ],
    ): string {
        $reference = 'ERR-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(6));
        Log::error("{$title} [{$reference}]", [
            'component' => static::class,
            'user_id' => auth()->id(),
            'exception' => $exception,
        ]);
        $this->notifyError($message, $title, $details, $suggestions, $reference);

        return $reference;
    }

    private function dispatchPersistentNotification(
        string $type,
        string $title,
        string $message,
        array $details = [],
        array $suggestions = [],
        ?string $reference = null,
        ?string $focusField = null,
    ): void {
        $this->dispatch('app-notification', notification: [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'details' => array_values(array_filter(array_map('strval', $details))),
            'suggestions' => array_values(array_filter(array_map('strval', $suggestions))),
            'reference' => $reference,
            'focus_field' => $focusField,
            'created_at' => now()->format('H:i:s'),
        ]);
    }

    private function setLegacyNotificationState(string $notice, string $error): void
    {
        if (property_exists($this, 'notice')) {
            $this->notice = $notice;
        }
        if (property_exists($this, 'errorMessage')) {
            $this->errorMessage = $error;
        }
    }
}
