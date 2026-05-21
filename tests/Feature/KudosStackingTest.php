<?php

namespace Tests\Feature;

use App\Enums\FamilyRole;
use App\Enums\PointTransactionType;
use App\Models\Family;
use App\Models\PointTransaction;
use App\Models\User;
use App\Notifications\KudosReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KudosStackingTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private Family $otherFamily;

    private User $alice;

    private User $bob;

    private User $carol;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->family = Family::create(['name' => 'Test Family', 'slug' => 'test-family', 'invite_code' => 'TEST01']);
        $this->otherFamily = Family::create(['name' => 'Other Family', 'slug' => 'other-family', 'invite_code' => 'OTHER1']);

        $this->alice = $this->makeUser('Alice', 'alice@test.com', $this->family);
        $this->bob = $this->makeUser('Bob', 'bob@test.com', $this->family);
        $this->carol = $this->makeUser('Carol', 'carol@test.com', $this->family);
        $this->stranger = $this->makeUser('Stranger', 'stranger@test.com', $this->otherFamily);
    }

    public function test_family_member_can_stack_onto_an_existing_kudo(): void
    {
        $original = $this->createKudos(from: $this->alice, to: $this->bob, reason: 'Made the team laugh');

        Sanctum::actingAs($this->carol);

        $response = $this->postJson("/api/v1/points/kudos/{$original->id}/stack");

        $response->assertCreated();

        $stack = PointTransaction::where('stacked_from_transaction_id', $original->id)
            ->where('awarded_by', $this->carol->id)
            ->firstOrFail();

        $this->assertEquals(PointTransactionType::Kudos, $stack->type);
        $this->assertEquals(1, $stack->points);
        $this->assertEquals($this->bob->id, $stack->user_id);
        $this->assertEquals('Made the team laugh', $stack->description);
    }

    public function test_recipient_cannot_stack_onto_kudos_they_received(): void
    {
        $original = $this->createKudos(from: $this->alice, to: $this->bob, reason: 'Made the team laugh');

        Sanctum::actingAs($this->bob);

        $this->postJson("/api/v1/points/kudos/{$original->id}/stack")
            ->assertStatus(422)
            ->assertJson(['message' => "You can't stack onto kudos given to you."]);
    }

    public function test_original_giver_cannot_stack_onto_their_own_kudo(): void
    {
        $original = $this->createKudos(from: $this->alice, to: $this->bob, reason: 'Made the team laugh');

        Sanctum::actingAs($this->alice);

        $this->postJson("/api/v1/points/kudos/{$original->id}/stack")
            ->assertStatus(422)
            ->assertJson(['message' => 'You already gave this kudo.']);
    }

    public function test_user_cannot_stack_onto_the_same_kudo_twice(): void
    {
        $original = $this->createKudos(from: $this->alice, to: $this->bob, reason: 'Made the team laugh');

        Sanctum::actingAs($this->carol);
        $this->postJson("/api/v1/points/kudos/{$original->id}/stack")->assertCreated();

        $this->postJson("/api/v1/points/kudos/{$original->id}/stack")
            ->assertStatus(422)
            ->assertJson(['message' => "You've already +1'd this kudo."]);
    }

    public function test_cannot_stack_onto_a_stack_only_the_original(): void
    {
        $original = $this->createKudos(from: $this->alice, to: $this->bob, reason: 'Made the team laugh');

        Sanctum::actingAs($this->carol);
        $this->postJson("/api/v1/points/kudos/{$original->id}/stack")->assertCreated();

        $stack = PointTransaction::where('stacked_from_transaction_id', $original->id)->firstOrFail();

        $dave = $this->makeUser('Dave', 'dave@test.com', $this->family);
        Sanctum::actingAs($dave);

        $this->postJson("/api/v1/points/kudos/{$stack->id}/stack")
            ->assertStatus(422)
            ->assertJson(['message' => 'Stack onto the original kudo, not another stack.']);
    }

    public function test_cannot_stack_onto_a_non_kudos_transaction(): void
    {
        $deduction = PointTransaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->bob->id,
            'type' => PointTransactionType::Deduction,
            'points' => -5,
            'description' => 'Lost privilege',
            'awarded_by' => $this->alice->id,
        ]);

        Sanctum::actingAs($this->carol);

        $this->postJson("/api/v1/points/kudos/{$deduction->id}/stack")
            ->assertStatus(422)
            ->assertJson(['message' => 'You can only stack onto a kudos.']);
    }

    public function test_cannot_stack_onto_kudos_from_another_family(): void
    {
        $original = $this->createKudos(from: $this->alice, to: $this->bob, reason: 'Made the team laugh');

        Sanctum::actingAs($this->stranger);

        $this->postJson("/api/v1/points/kudos/{$original->id}/stack")
            ->assertStatus(404);
    }

    public function test_feed_returns_stack_count_and_marks_already_stacked(): void
    {
        $original = $this->createKudos(from: $this->alice, to: $this->bob, reason: 'Made the team laugh');

        Sanctum::actingAs($this->carol);
        $this->postJson("/api/v1/points/kudos/{$original->id}/stack")->assertCreated();

        $response = $this->getJson('/api/v1/points/feed');

        $response->assertOk();
        $originalInFeed = collect($response->json('feed'))
            ->firstWhere('id', $original->id);

        $this->assertEquals(1, $originalInFeed['stacks_count']);
        $this->assertTrue($originalInFeed['stacked_by_me']);
    }

    public function test_db_constraint_prevents_duplicate_stack_bypassing_app_check(): void
    {
        $original = $this->createKudos(from: $this->alice, to: $this->bob, reason: 'Made the team laugh');

        // Simulate the race: insert a stack row directly, bypassing the controller check.
        PointTransaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->bob->id,
            'type' => PointTransactionType::Kudos,
            'points' => 1,
            'description' => $original->description,
            'awarded_by' => $this->carol->id,
            'stacked_from_transaction_id' => $original->id,
        ]);

        Sanctum::actingAs($this->carol);
        $this->postJson("/api/v1/points/kudos/{$original->id}/stack")
            ->assertStatus(422)
            ->assertJson(['message' => "You've already +1'd this kudo."]);
    }

    public function test_stacking_does_not_notify_the_recipient(): void
    {
        Notification::fake();
        $original = $this->createKudos(from: $this->alice, to: $this->bob, reason: 'Made the team laugh');

        Sanctum::actingAs($this->carol);
        $this->postJson("/api/v1/points/kudos/{$original->id}/stack")->assertCreated();

        Notification::assertNotSentTo($this->bob, KudosReceivedNotification::class);
    }

    public function test_original_kudo_still_notifies_the_recipient(): void
    {
        $this->bob->update([
            'notification_preferences' => [
                'email' => ['kudos_received' => true],
                'push' => [],
                'quiet_hours' => ['enabled' => false, 'start' => '22:00', 'end' => '07:00'],
                'muted' => false,
            ],
        ]);

        Notification::fake();

        Sanctum::actingAs($this->alice);
        $this->postJson('/api/v1/points/kudos', [
            'user_id' => $this->bob->id,
            'reason' => 'Made the team laugh',
        ])->assertCreated();

        Notification::assertSentTo($this->bob, KudosReceivedNotification::class);
    }

    private function makeUser(string $name, string $email, Family $family): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'family_id' => $family->id,
            'family_role' => FamilyRole::Parent,
        ]);
    }

    private function createKudos(User $from, User $to, string $reason): PointTransaction
    {
        return PointTransaction::create([
            'family_id' => $to->family_id,
            'user_id' => $to->id,
            'type' => PointTransactionType::Kudos,
            'points' => 1,
            'description' => $reason,
            'awarded_by' => $from->id,
        ]);
    }
}
