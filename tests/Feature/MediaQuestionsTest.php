<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaQuestionsTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_user_can_upload_media_with_questions(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('test.mp4', 1000, 'video/mp4');

        $questions = [
            [
                'question' => 'What is the main topic?',
                'answer' => 'A',
                'option_a' => 'Correct answer',
                'option_b' => 'Wrong answer 1',
                'option_c' => 'Wrong answer 2',
                'option_d' => 'Wrong answer 3'
            ],
            [
                'question' => 'What is the secondary topic?',
                'answer' => 'B',
                'option_a' => 'Wrong answer 1',
                'option_b' => 'Correct answer',
                'option_c' => 'Wrong answer 2',
                'option_d' => 'Wrong answer 3'
            ]
        ];

        $response = $this->actingAs($user)
            ->postJson('/api/v1/media', [
                'file' => $file,
                'description' => 'Test video description',
                'questions' => $questions
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'file_name',
                    'mime_type',
                    'media_type',
                    'url',
                    'description',
                    'tags',
                    'created_at',
                    'updated_at'
                ]
            ]);

        $media = Media::first();
        $this->assertCount(2, $media->questions);
        
        $this->assertDatabaseHas('questions', [
            'media_id' => $media->id,
            'question' => 'What is the main topic?',
            'answer' => 'A',
            'option_a' => 'Correct answer'
        ]);
    }

    public function test_user_can_view_media_with_questions(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->create(['user_id' => $user->id]);
        
        Question::factory()->count(3)->create([
            'media_id' => $media->id
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/media/{$media->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'file_name',
                    'mime_type',
                    'media_type',
                    'url',
                    'description',
                    'tags',
                    'created_at',
                    'updated_at'
                ]
            ]);

        $this->assertCount(3, $media->questions);
    }

    public function test_questions_are_deleted_when_media_is_deleted(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->create(['user_id' => $user->id]);
        
        Question::factory()->count(3)->create([
            'media_id' => $media->id
        ]);

        $this->assertCount(3, $media->questions);

        $response = $this->actingAs($user)
            ->deleteJson("/api/v1/media/{$media->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Media deleted successfully']);

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertDatabaseMissing('questions', ['media_id' => $media->id]);
    }

    public function test_questions_are_soft_deleted(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->create(['user_id' => $user->id]);
        
        $question = Question::factory()->create([
            'media_id' => $media->id
        ]);

        $question->delete();

        $this->assertSoftDeleted('questions', [
            'id' => $question->id,
            'media_id' => $media->id
        ]);
    }
} 