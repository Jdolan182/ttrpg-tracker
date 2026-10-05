<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Creature;
use App\Models\Encounter;
use App\Models\User;
use App\Support\Backup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private function combatant(?Creature $creature, string $name, array $overrides = []): array
    {
        return [
            'id' => strtolower($name), 'creatureId' => $creature?->id, 'name' => $name, 'side' => 'enemy', 'initiative' => 10,
            'hp' => 7, 'maxHp' => 7, 'ac' => 15, 'conditions' => [], 'used' => [], ...$overrides,
        ];
    }

    /** A DM with a homebrew creature, an SRD goblin, and an encounter using both. */
    private function dmWithEncounter(): array
    {
        $user = User::factory()->create();
        $ogre = Creature::factory()->for($user)->create(['name' => 'Bog Ogre']);
        $goblin = Creature::factory()->srd()->create(['name' => 'Goblin']);
        $encounter = Encounter::factory()->for($user)->create([
            'name' => 'Swamp Ambush',
            'round' => 2,
            'combatants' => [$this->combatant($ogre, 'Bog Ogre'), $this->combatant($goblin, 'Goblin', ['conditions' => ['Prone']]), $this->combatant(null, 'Aria', ['side' => 'player'])],
            'log' => [['id' => 'l1', 'at' => now()->toIso8601String(), 'round' => 1, 'type' => 'combat_started']],
        ]);

        return [$user, $ogre, $goblin, $encounter];
    }

    private function upload(User $user, array|string $backup)
    {
        $content = is_string($backup) ? $backup : json_encode($backup);

        return $this->actingAs($user)->from('/encounters')->post('/backup', ['file' => UploadedFile::fake()->createWithContent('backup.json', $content)]);
    }

    public function test_exporting_everything()
    {
        [$user, $ogre] = $this->dmWithEncounter();
        Creature::factory()->create(['name' => 'Not Mine']);

        $response = $this->actingAs($user)->get('/backup')->assertOk();

        $this->assertStringContainsString('attachment; filename="ttrpg-tracker-backup-', $response->headers->get('Content-Disposition'));
        $backup = $response->json();
        $this->assertSame(Backup::FORMAT, $backup['format']);
        $this->assertSame(Backup::VERSION, $backup['version']);

        $creatures = collect($backup['creatures'])->keyBy('name');
        $this->assertEqualsCanonicalizing(['Bog Ogre', 'Goblin'], $creatures->keys()->all());
        // Your own in full; SRD by name only. No database ids.
        $this->assertSame('homebrew', $creatures['Bog Ogre']['source']);
        $this->assertSame($ogre->hp, $creatures['Bog Ogre']['hp']);
        $this->assertArrayNotHasKey('id', $creatures['Bog Ogre']);
        $this->assertSame(['ref', 'source', 'name'], array_keys($creatures['Goblin']));

        $encounter = $backup['encounters'][0];
        $this->assertSame(['Swamp Ambush', 2], [$encounter['name'], $encounter['round']]);
        $this->assertSame(
            [$creatures['Bog Ogre']['ref'], $creatures['Goblin']['ref'], null],
            array_column($encounter['combatants'], 'creature'),
        );
        $this->assertArrayNotHasKey('creatureId', $encounter['combatants'][0]);
        $this->assertCount(1, $encounter['log']);
    }

    public function test_exporting_one_encounter_only_takes_what_it_uses()
    {
        [$user, , , $encounter] = $this->dmWithEncounter();
        Creature::factory()->for($user)->create(['name' => 'Unused']);
        Encounter::factory()->for($user)->create(['name' => 'Other']);

        $backup = $this->actingAs($user)->get("/encounters/{$encounter->id}/export")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="ttrpg-tracker-swamp-ambush-'.now()->format('Y-m-d').'.json"')
            ->json();

        $this->assertEqualsCanonicalizing(['Bog Ogre', 'Goblin'], array_column($backup['creatures'], 'name'));
        $this->assertSame(['Swamp Ambush'], array_column($backup['encounters'], 'name'));
    }

    public function test_exports_are_private()
    {
        [, , , $encounter] = $this->dmWithEncounter();

        $this->get('/backup')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get("/encounters/{$encounter->id}/export")->assertForbidden();
    }

    public function test_a_backup_can_be_imported_into_another_account()
    {
        [$dm] = $this->dmWithEncounter();
        $backup = $this->actingAs($dm)->get('/backup')->json();
        $friend = User::factory()->create();

        $this->upload($friend, $backup)
            ->assertRedirect('/encounters')
            ->assertSessionHas('status', 'Imported 1 creature and 1 encounter.');

        $ogre = $friend->creatures()->sole();
        $this->assertSame('Bog Ogre', $ogre->name);
        $encounter = $friend->encounters()->sole();
        $this->assertSame(['Swamp Ambush', 2, null], [$encounter->name, $encounter->round, $encounter->campaign_id]);
        // Combatants point at the friend's new copy and at the SRD goblin.
        $this->assertSame(
            [$ogre->id, Creature::whereNull('user_id')->where('name', 'Goblin')->value('id'), null],
            array_column($encounter->combatants, 'creatureId'),
        );
        $this->assertSame(['Prone'], $encounter->combatants[1]['conditions']);
        $this->assertSame('combat_started', $encounter->log[0]['type']);
    }

    public function test_importing_again_reuses_identical_creatures()
    {
        [$user] = $this->dmWithEncounter();
        $backup = $this->actingAs($user)->get('/backup')->json();

        $this->upload($user, $backup)->assertSessionHas('status', 'Imported 1 encounter. 1 creature was already in your compendium, so it was reused.');

        $this->assertSame(1, $user->creatures()->count());
        $this->assertSame(2, $user->encounters()->count());
        $ogreId = $user->creatures()->value('id');
        foreach ($user->encounters as $encounter) {
            $this->assertSame($ogreId, $encounter->combatants[0]['creatureId']);
        }
    }

    public function test_an_edited_creature_comes_back_as_a_new_one()
    {
        [$user, $ogre] = $this->dmWithEncounter();
        $backup = $this->actingAs($user)->get('/backup')->json();
        $ogre->update(['hp' => 99]);

        $this->upload($user, $backup)->assertSessionHasNoErrors();

        $this->assertSame(2, $user->creatures()->where('name', 'Bog Ogre')->count());
    }

    public function test_creatures_missing_from_this_app_become_quick_added()
    {
        [$user] = $this->dmWithEncounter();
        $backup = $this->actingAs($user)->get('/backup')->json();
        Creature::whereNull('user_id')->where('name', 'Goblin')->delete();

        $this->upload($user, $backup)->assertSessionHas('status', fn (string $status) => str_contains($status, "1 combatant's creature couldn't be found"));

        $imported = $user->encounters()->latest('id')->first();
        $this->assertNull($imported->combatants[1]['creatureId']);
        $this->assertSame('Goblin', $imported->combatants[1]['name']);
    }

    public function test_files_that_arent_backups_are_refused()
    {
        $user = User::factory()->create();

        $this->upload($user, 'not json at all')->assertSessionHasErrors(['backup' => "That file isn't a backup: it couldn't be read."]);
        $this->upload($user, ['format' => 'something-else', 'version' => 1, 'creatures' => [], 'encounters' => []])
            ->assertSessionHasErrors(['backup' => "This isn't a backup from this app."]);
        $this->upload($user, ['format' => Backup::FORMAT, 'version' => Backup::VERSION + 1, 'creatures' => [], 'encounters' => []])
            ->assertSessionHasErrors(['backup' => 'This backup is from a newer version of the app.']);

        $this->actingAs($user)->post('/backup', ['file' => UploadedFile::fake()->create('notes.txt', 1)])->assertSessionHasErrors('file');
    }

    public function test_nothing_is_imported_when_part_of_the_backup_is_invalid()
    {
        [$user] = $this->dmWithEncounter();
        $backup = $this->actingAs($user)->get('/backup')->json();
        $friend = User::factory()->create();

        // A valid creature, but a broken encounter after it: the creature isn't kept either.
        $backup['encounters'][0]['activeIndex'] = 50;
        $this->upload($friend, $backup)->assertSessionHasErrors(['backup' => 'Encounter "Swamp Ambush" in this backup isn\'t valid: The active turn must be one of the combatants.']);
        $this->assertSame(0, $friend->creatures()->count());
        $this->assertSame(0, $friend->encounters()->count());

        $backup['encounters'][0]['activeIndex'] = 0;
        $backup['creatures'][0]['hp'] = 0;
        $this->upload($friend, $backup)->assertSessionHasErrors('backup');
        $this->assertSame(0, $friend->creatures()->count());
    }

    public function test_imports_respect_the_limits()
    {
        config()->set('plans.plans.free.limits.encounters', 1);
        [$user] = $this->dmWithEncounter();
        $backup = $this->actingAs($user)->get('/backup')->json();

        $this->upload($user, $backup)->assertSessionHasErrors([
            'limit' => "There's only room for 0 more saved encounters, but this backup has 1 new one. Delete one to make room.",
        ]);
        $this->assertSame(1, $user->encounters()->count());
    }

    public function test_guests_cant_import()
    {
        $this->post('/backup', ['file' => UploadedFile::fake()->createWithContent('backup.json', '{}')])->assertRedirect('/login');
    }

    public function test_the_encounters_list()
    {
        [$user, , , $encounter] = $this->dmWithEncounter();
        $campaign = Campaign::factory()->for($user, 'owner')->create(['name' => 'Crimson Keep']);
        $encounter->update(['campaign_id' => $campaign->id]);
        Encounter::factory()->create(['name' => 'Someone else\'s']);

        $this->actingAs($user)->get('/encounters')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Encounters/List')
                ->has('encounters', 1)
                ->where('encounters.0.name', 'Swamp Ambush')
                ->where('encounters.0.campaign', ['id' => $campaign->id, 'name' => 'Crimson Keep'])
                ->where('encounters.0.round', 2)
                ->where('encounters.0.combatants', [
                    ['name' => 'Bog Ogre', 'side' => 'enemy'],
                    ['name' => 'Goblin', 'side' => 'enemy'],
                    ['name' => 'Aria', 'side' => 'player'],
                ])
            );

        $this->get('/encounters')->assertOk();
        auth()->logout();
        $this->get('/encounters')->assertRedirect('/login');
    }

    public function test_deleting_from_the_list_stays_on_the_list()
    {
        [$user, , , $encounter] = $this->dmWithEncounter();

        $this->actingAs($user)->from('/encounters')->delete("/encounters/{$encounter->id}")->assertRedirect('/encounters');
        $this->assertNull($encounter->fresh());
    }
}
