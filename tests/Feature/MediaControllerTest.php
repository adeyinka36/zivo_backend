<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    public function test_user_can_upload_media(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('test.txt', 100, 'text/plain');
        $tag = Tag::factory()->create(['name' => 'test-tag']);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/media', [
                'file' => $file,
                'metadata' => [
                    'description' => 'Test description',
                    'tags' => [$tag->name]
                ]
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'file_name',
                    'mime_type',
                    'url',
                    'metadata',
                    'tags' => [
                        '*' => [
                            'id',
                            'name',
                            'slug',
                            'created_at',
                            'updated_at'
                        ]
                    ],
                    'created_at',
                    'updated_at'
                ]
            ]);

        $this->assertDatabaseHas('media', [
            'name' => 'test.txt',
            'mime_type' => 'text/plain'
        ]);

        Storage::disk('s3')->assertExists('zivo_media/' . $file->hashName());
    }

    public function test_user_can_list_their_media(): void
    {
        $user = User::factory()->create();
        $tags = Tag::factory()->count(3)->create();
        
        $media = Media::factory()
            ->count(3)
            ->create(['user_id' => $user->id]);

        foreach ($media as $item) {
            $item->tags()->attach($tags->random(rand(1, 3)));
        }

        $response = $this->actingAs($user)
            ->getJson('/api/v1/media');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'file_name',
                        'mime_type',
                        'url',
                        'metadata',
                        'tags' => [
                            '*' => [
                                'id',
                                'name',
                                'slug',
                                'created_at',
                                'updated_at'
                            ]
                        ],
                        'created_at',
                        'updated_at'
                    ]
                ],
                'meta' => [
                    'current_page',
                    'from',
                    'last_page',
                    'path',
                    'per_page',
                    'to',
                    'total'
                ],
                'links' => [
                    'first',
                    'last',
                    'prev',
                    'next'
                ]
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_user_can_search_media_by_tag(): void
    {
        $user = User::factory()->create();
        $tag1 = Tag::factory()->create(['name' => 'advert']);
        $tag2 = Tag::factory()->create(['name' => 'memory']);

        $media1 = Media::factory()->create(['user_id' => $user->id]);
        $media2 = Media::factory()->create(['user_id' => $user->id]);

        $media1->tags()->attach($tag1);
        $media2->tags()->attach($tag2);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/media?search=advert');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'file_name',
                        'mime_type',
                        'url',
                        'metadata',
                        'tags' => [
                            '*' => [
                                'id',
                                'name',
                                'slug',
                                'created_at',
                                'updated_at'
                            ]
                        ],
                        'created_at',
                        'updated_at'
                    ]
                ]
            ]);

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('advert', $response->json('data.0.tags.0.name'));
    }

    public function test_user_can_view_their_media(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->create(['user_id' => $user->id]);
        $tag = Tag::factory()->create();
        $media->tags()->attach($tag);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/media/{$media->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'file_name',
                    'mime_type',
                    'url',
                    'metadata',
                    'tags' => [
                        '*' => [
                            'id',
                            'name',
                            'slug',
                            'created_at',
                            'updated_at'
                        ]
                    ],
                    'created_at',
                    'updated_at'
                ]
            ]);
    }

    public function test_user_cannot_view_other_users_media(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $media = Media::factory()->create(['user_id' => $user2->id]);

        $response = $this->actingAs($user1)
            ->getJson("/api/v1/media/{$media->id}");

        $response->assertStatus(403);
    }

    public function test_user_can_delete_media(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->create(['user_id' => $user->id]);
        $tag = Tag::factory()->create();
        $media->tags()->attach($tag);

        $response = $this->actingAs($user)
            ->deleteJson("/api/v1/media/{$media->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Media deleted successfully']);

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertDatabaseMissing('media_tag', [
            'media_id' => $media->id,
            'tag_id' => $tag->id
        ]);
    }

    public function test_user_cannot_delete_other_users_media(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $media = Media::factory()->create(['user_id' => $user2->id]);

        $response = $this->actingAs($user1)
            ->deleteJson("/api/v1/media/{$media->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }
} 