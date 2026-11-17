<?php

namespace Tests\Feature;

use App\Models\Creator;
use App\Models\Media;
use App\Models\User;
use Tests\TestCase;

class CreatorAccountDeletionTest extends TestCase
{
    public function test_creator_can_delete_account_and_media(): void
    {
        $creator = Creator::factory()->create();
        $media = Media::factory()->create([
            'creator_id' => $creator->id,
        ]);
        $token = $creator->createToken('creator-auth-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/creators');

        $response->assertOk()
            ->assertJson([
                'message' => 'Account deleted successfully',
            ]);

        $this->assertDatabaseMissing('creators', [
            'id' => $creator->id,
        ]);
        $this->assertDatabaseMissing('media', [
            'id' => $media->id,
        ]);
    }

    public function test_user_cannot_delete_a_creator_account(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/creators');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_delete_a_creator_account(): void
    {
        $this->deleteJson('/api/v1/creators')->assertStatus(401);
    }
}
