<?php

declare(strict_types=1);

namespace Modules\IvrMenus\Models;

use Database\Factories\Pbx\IvrMenuOptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single digit-to-action mapping within an IVR menu.
 * When a caller presses a key, the corresponding action
 * (e.g., transfer, voicemail, playback) is executed.
 *
 * @property string $id
 * @property string $ivr_menu_id
 * @property string $digit
 * @property string $action
 * @property string|null $action_data
 * @property int $order
 * @property bool $enabled
 */
class IvrMenuOption extends Model
{
    /** @use HasFactory<IvrMenuOptionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'ivr_menu_id',
        'digit',
        'action',
        'action_data',
        'order',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'order' => 'integer',
        ];
    }

    /**
     * The IVR menu to which this option belongs.
     */
    public function ivrMenu(): BelongsTo
    {
        return $this->belongsTo(IvrMenu::class);
    }

    protected static function newFactory(): IvrMenuOptionFactory
    {
        return IvrMenuOptionFactory::new();
    }
}
