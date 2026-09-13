<?php

declare(strict_types=1);

namespace Modules\Extensions\Livewire;

use App\Support\BaseEditComponent;
use Illuminate\Validation\ValidationException;
use Modules\Extensions\Models\Extension;
use Modules\Extensions\Services\ExtensionServiceInterface;

/**
 * Livewire component for creating a numeric range of extensions.
 */
class ExtensionsBulkCreate extends BaseEditComponent
{
    public string $startExtension = '';

    public string $endExtension = '';

    public int $increment = 1;

    public string $displayNameTemplate = 'Extension {number}';

    public bool $voicemailEnabled = false;

    public bool $copyNumberAlias = false;

    public string $description = '';

    private const MAX_BATCH_SIZE = 1000;

    private ExtensionServiceInterface $extensionService;

    /**
     * Boot the component with the extension service.
     */
    public function boot(ExtensionServiceInterface $extensionService): void
    {
        $this->extensionService = $extensionService;
    }

    /**
     * Mount the bulk creation form.
     */
    public function mount(): void
    {
        $this->loadTenants();
    }

    /**
     * Show a small preview of the extension numbers that will be created.
     *
     * @return array<int, string>
     */
    public function getPreviewNumbersProperty(): array
    {
        if (! ctype_digit($this->startExtension) || ! ctype_digit($this->endExtension) || $this->increment < 1) {
            return [];
        }

        return array_slice($this->extensionNumbers(), 0, 10);
    }

    /**
     * Create the requested extension range.
     *
     * @throws ValidationException
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $numbers = $this->extensionNumbers();
        $this->validateBatchSize($numbers);
        $this->validateNoConflicts($numbers);

        $this->extensionService->createRange(
            [
                'tenant_id' => $this->tenantId,
                'number_alias' => $this->copyNumberAlias ? $this->startExtension : null,
                'display_name' => $this->nullableString($this->displayNameTemplate),
                'voicemail_enabled' => $this->voicemailEnabled,
                'description' => $this->nullableString($this->description),
                'enabled' => $this->enabled,
            ],
            (int) $this->startExtension,
            (int) $this->endExtension,
            $this->increment,
            $this->displayNameTemplate,
        );

        $this->redirect(route('panel.extensions.index'));
    }

    /**
     * Validation rules for the extension range form.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'tenantId' => ['required', 'exists:tenants,id'],
            'startExtension' => ['required', 'integer', 'min:1', 'max:999999999'],
            'endExtension' => ['required', 'integer', 'gte:startExtension', 'max:999999999'],
            'increment' => ['required', 'integer', 'min:1', 'max:1000'],
            'displayNameTemplate' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Build the numeric range requested by the form.
     *
     * @return array<int, string>
     */
    private function extensionNumbers(): array
    {
        $numbers = [];

        for ($number = (int) $this->startExtension; $number <= (int) $this->endExtension; $number += $this->increment) {
            $numbers[] = (string) $number;
        }

        return $numbers;
    }

    /**
     * Prevent accidentally creating an unreasonably large batch.
     *
     * @param  array<int, string>  $numbers
     *
     * @throws ValidationException
     */
    private function validateBatchSize(array $numbers): void
    {
        if (count($numbers) > self::MAX_BATCH_SIZE) {
            throw ValidationException::withMessages([
                'endExtension' => ['Create up to '.self::MAX_BATCH_SIZE.' extensions at a time.'],
            ]);
        }
    }

    /**
     * Ensure none of the requested numbers already exist in the selected tenant.
     *
     * @param  array<int, string>  $numbers
     *
     * @throws ValidationException
     */
    private function validateNoConflicts(array $numbers): void
    {
        $conflicts = Extension::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenantId)
            ->whereIn('extension_number', $numbers)
            ->orderBy('extension_number')
            ->pluck('extension_number')
            ->all();

        if ($conflicts !== []) {
            throw ValidationException::withMessages([
                'startExtension' => ['These extension numbers already exist: '.implode(', ', $conflicts).'.'],
            ]);
        }
    }

    /**
     * Convert blank form strings to null for optional database fields.
     */
    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
