<?php

namespace Modules\Files\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Files\Models\File;

/** @extends Factory<File> */
class FileFactory extends Factory
{
    protected $model = File::class;

    public function definition(): array
    {
        return [
            'original_name' => fake()->word().'.pdf',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (File $file): void {
            $file->forceFill([
                'uploaded_by_user_id' => null,
                'storage_disk' => 'private',
                'storage_path' => 'testing/'.fake()->uuid().'.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => fake()->numberBetween(100, 1000000),
                'checksum_sha256' => hash('sha256', fake()->uuid()),
            ]);
        });
    }
}
