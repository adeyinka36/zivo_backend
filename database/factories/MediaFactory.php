<?php

namespace Database\Factories;

use App\Models\Creator;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * Sample video URLs for seeding (reliable public sample videos)
     */
    protected array $sampleVideos = [
        'https://storage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4',
        'https://storage.googleapis.com/gtv-videos-bucket/sample/ElephantsDream.mp4',
        'https://storage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',
        'https://storage.googleapis.com/gtv-videos-bucket/sample/ForBiggerEscapes.mp4',
        'https://storage.googleapis.com/gtv-videos-bucket/sample/ForBiggerFun.mp4',
        'https://storage.googleapis.com/gtv-videos-bucket/sample/ForBiggerJoyrides.mp4',
        'https://storage.googleapis.com/gtv-videos-bucket/sample/ForBiggerMeltdowns.mp4',
        'https://storage.googleapis.com/gtv-videos-bucket/sample/Sintel.mp4',
        'https://storage.googleapis.com/gtv-videos-bucket/sample/SubaruOutbackOnStreetAndDirt.mp4',
        'https://storage.googleapis.com/gtv-videos-bucket/sample/TearsOfSteel.mp4'
    ];

    /**
     * Sample image URLs for seeding
     */
    protected array $sampleImages = [
        'https://picsum.photos/800/600',
        'https://picsum.photos/1024/768',
        'https://picsum.photos/1920/1080',
        'https://picsum.photos/640/480',
        'https://picsum.photos/1280/720'
    ];

    public function definition(): array
    {
        $mediaType = $this->faker->randomElement(['video', 'image']);

        if ($mediaType === 'video') {
            $url = $this->faker->randomElement($this->sampleVideos);
            $mimeType = 'video/mp4';
            $size = $this->faker->numberBetween(1000000, 10000000);
        } else {
            $url = $this->faker->randomElement($this->sampleImages);
            $mimeType = 'image/jpeg';
            $size = $this->faker->numberBetween(100000, 5000000);
        }

        $fileName = basename(parse_url($url, PHP_URL_PATH) ?: $url);

        return [
            'creator_id' => Creator::factory(),
            'name' => $fileName,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'size' => $size,
            'path' => $url,
            'disk' => 'url',
            'description' => $this->faker->sentence(),
            'payment_status' => 'paid',
            'active' => true,
        ];
    }

    /**
     * Media in pending/processing state.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'path' => 'pending',
            'disk' => 'pending',
            'payment_status' => 'pending',
            'active' => false,
        ]);
    }
}
