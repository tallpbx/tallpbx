<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Backups\Models\Backup;

/**
 * Factory for creating Backup model instances in tests.
 *
 * @extends Factory<Backup>
 */
class BackupFactory extends Factory
{
    /**
     * The model class this factory creates.
     *
     * @var class-string<Backup>
     */
    protected $model = Backup::class;

    /**
     * Default attribute values for a Backup.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true).' Backup',
            'scope' => ['database', 'app_files'],
            'retention_count' => 7,
            'compression' => true,
            'destination_disk' => 'local',
            'schedule_cron' => null,
            'status' => 'pending',
            'notify_on_success' => true,
            'notify_on_failure' => true,
        ];
    }
}
