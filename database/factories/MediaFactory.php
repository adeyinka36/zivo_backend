<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Media>
 */
class MediaFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Media::class;

    /**
     * Sample video URLs for seeding
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

    /**
     * Sample document URLs for seeding
     */
    protected array $sampleDocuments = [
        'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
        'https://www.africau.edu/images/default/sample.pdf',
        'https://www.learningcontainer.com/wp-content/uploads/2019/09/sample-pdf-file.pdf'
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $mediaType = $this->faker->randomElement(['video', 'image', 'document']);
        
        switch ($mediaType) {
            case 'video':
                $url = $this->faker->randomElement($this->sampleVideos);
                $mimeType = 'video/mp4';
                $size = $this->faker->numberBetween(1000000, 10000000); // 1MB to 10MB
                break;
                
            case 'image':
                $url = $this->faker->randomElement($this->sampleImages);
                $mimeType = 'image/jpeg';
                $size = $this->faker->numberBetween(100000, 5000000); // 100KB to 5MB
                break;
                
            case 'document':
                $url = $this->faker->randomElement($this->sampleDocuments);
                $mimeType = 'application/pdf';
                $size = $this->faker->numberBetween(50000, 2000000); // 50KB to 2MB
                break;
        }

        $fileName = basename($url);
        
        return [
            'user_id' => User::factory(),
            'name' => $fileName,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'size' => $size,
            'path' => $url,
            'disk' => 'url',
            'description' => $this->faker->sentence(),
            'reward' => $this->faker->numberBetween(100, 100000),
        ];
    }
} 