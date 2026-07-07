<?php

namespace Tests\Feature;

use App\Models\FormDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminFormBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config(['helpdesk.admins' => ['admin@verfrut.cl']]);

        return User::factory()->create(['email' => 'admin@verfrut.cl']);
    }

    private function validField(array $overrides = []): array
    {
        return array_merge([
            'key' => 'equipo',
            'label' => 'Equipo',
            'input' => 'text',
            'required' => true,
        ], $overrides);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['email' => 'user@verfrut.cl']);

        $this->actingAs($user)->get('/admin/formularios')->assertForbidden();
    }

    public function test_admin_sees_form_list(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/formularios')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Forms/Index'));
    }

    public function test_admin_can_create_a_definition(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/formularios', [
                'type' => 'request',
                'itil_category_id' => 2,
                'name' => 'Solicitud · Sistemas',
                'is_active' => true,
                'fields' => [$this->validField()],
            ])
            ->assertRedirect('/admin/formularios')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('form_definitions', ['type' => 'request', 'itil_category_id' => 2]);
    }

    public function test_invalid_field_key_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/formularios', [
                'type' => 'request',
                'itil_category_id' => 2,
                'fields' => [$this->validField(['key' => 'Equipo Mal'])],
            ])
            ->assertSessionHasErrors('fields.0.key');
    }

    public function test_duplicate_branch_is_rejected(): void
    {
        FormDefinition::create(['type' => 'incident', 'itil_category_id' => 1, 'fields' => []]);

        $this->actingAs($this->admin())
            ->post('/admin/formularios', [
                'type' => 'incident',
                'itil_category_id' => 1,
                'fields' => [$this->validField()],
            ])
            ->assertSessionHasErrors('itil_category_id');
    }

    public function test_admin_can_update_and_delete(): void
    {
        $admin = $this->admin();
        $def = FormDefinition::create(['type' => 'incident', 'itil_category_id' => 1, 'fields' => []]);

        $this->actingAs($admin)
            ->put("/admin/formularios/{$def->id}", [
                'type' => 'incident',
                'itil_category_id' => 1,
                'name' => 'Actualizado',
                'is_active' => true,
                'fields' => [$this->validField(['input' => 'select', 'options' => [['value' => 'a', 'label' => 'A']]])],
            ])
            ->assertRedirect('/admin/formularios');

        $this->assertDatabaseHas('form_definitions', ['id' => $def->id, 'name' => 'Actualizado']);

        $this->actingAs($admin)
            ->delete("/admin/formularios/{$def->id}")
            ->assertRedirect('/admin/formularios');

        $this->assertDatabaseMissing('form_definitions', ['id' => $def->id]);
    }
}
