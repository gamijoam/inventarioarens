<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixSpinoffParentCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reassigns_spinoff_to_new_parent_group(): void
    {
        $groupA = Tenant::create(['name' => 'A', 'slug' => 'a', 'is_group' => true]);
        $groupB = Tenant::create(['name' => 'B', 'slug' => 'b', 'is_group' => true]);
        $spinoff = Tenant::create([
            'name' => 'S',
            'slug' => 's',
            'is_group' => false,
            'parent_id' => $groupA->id,
        ]);

        $this->artisan('tenancy:fix-spinoff-parent', [
            'spinoff' => 's',
            'new_parent' => 'b',
            '--yes' => true,
        ])->assertSuccessful();

        $this->assertSame($groupB->id, $spinoff->fresh()->parent_id);
    }

    public function test_rejects_when_spinoff_input_is_actually_a_group(): void
    {
        Tenant::create(['name' => 'A', 'slug' => 'a', 'is_group' => true]);
        Tenant::create(['name' => 'B', 'slug' => 'b', 'is_group' => true]);

        $this->artisan('tenancy:fix-spinoff-parent', [
            'spinoff' => 'a',
            'new_parent' => 'b',
            '--yes' => true,
        ])->assertFailed();
    }

    public function test_rejects_when_new_parent_is_not_a_group(): void
    {
        $group = Tenant::create(['name' => 'A', 'slug' => 'a', 'is_group' => true]);
        Tenant::create([
            'name' => 'S',
            'slug' => 's',
            'is_group' => false,
            'parent_id' => $group->id,
        ]);
        Tenant::create([
            'name' => 'X',
            'slug' => 'x',
            'is_group' => false,
            'parent_id' => $group->id,
        ]);

        $this->artisan('tenancy:fix-spinoff-parent', [
            'spinoff' => 's',
            'new_parent' => 'x',
            '--yes' => true,
        ])->assertFailed();
    }

    public function test_no_op_when_already_assigned_to_new_parent(): void
    {
        $group = Tenant::create(['name' => 'A', 'slug' => 'a', 'is_group' => true]);
        $spinoff = Tenant::create([
            'name' => 'S',
            'slug' => 's',
            'is_group' => false,
            'parent_id' => $group->id,
        ]);

        $this->artisan('tenancy:fix-spinoff-parent', [
            'spinoff' => 's',
            'new_parent' => 'a',
            '--yes' => true,
        ])->assertSuccessful();

        $this->assertSame($group->id, $spinoff->fresh()->parent_id);
    }

    public function test_rejects_when_inputs_do_not_exist(): void
    {
        $this->artisan('tenancy:fix-spinoff-parent', [
            'spinoff' => 'no-existe',
            'new_parent' => 'tampoco',
            '--yes' => true,
        ])->assertFailed();
    }

    public function test_accepts_ids_as_arguments(): void
    {
        $a = Tenant::create(['name' => 'A', 'slug' => 'a', 'is_group' => true]);
        $b = Tenant::create(['name' => 'B', 'slug' => 'b', 'is_group' => true]);
        $spinoff = Tenant::create([
            'name' => 'S', 'slug' => 's', 'is_group' => false, 'parent_id' => $a->id,
        ]);

        $this->artisan('tenancy:fix-spinoff-parent', [
            'spinoff' => (string) $spinoff->id,
            'new_parent' => (string) $b->id,
            '--yes' => true,
        ])->assertSuccessful();

        $this->assertSame($b->id, $spinoff->fresh()->parent_id);
    }
}