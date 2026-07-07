<?php

namespace Tests\Feature;

use App\Models\FormDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/inicio')->assertRedirect('/');
    }

    public function test_login_page_renders_for_guests(): void
    {
        $this->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Login'));
    }

    public function test_authenticated_user_sees_dashboard_in_demo_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/inicio')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('glpiConfigured', false)
                ->where('tickets', [])
            );
    }

    public function test_user_can_open_new_ticket_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/tickets/nuevo')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Create')
                ->has('types', 2)
                ->has('categories')
            );
    }

    public function test_form_schema_returns_seeded_branch(): void
    {
        $user = User::factory()->create();
        $this->seedSupportBranch();

        $this->actingAs($user)
            ->getJson('/tickets/form-schema?type=incident&itil_category_id=1')
            ->assertOk()
            ->assertJson(['configured' => true])
            ->assertJsonPath('fields.0.key', 'equipo');
    }

    public function test_form_schema_reports_unconfigured_branch(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/tickets/form-schema?type=request&itil_category_id=99')
            ->assertOk()
            ->assertJson(['configured' => false, 'fields' => []]);
    }

    public function test_ticket_validation_rejects_short_subject(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/tickets/nuevo')
            ->post('/tickets', ['type' => 'incident', 'itil_category_id' => 1, 'subject' => 'x'])
            ->assertRedirect('/tickets/nuevo')
            ->assertSessionHasErrors('subject');
    }

    public function test_ticket_requires_visible_conditional_field(): void
    {
        $user = User::factory()->create();
        $this->seedSupportBranch();

        // "equipo=impresora" hace visible y requerido "modelo_impresora".
        $this->actingAs($user)
            ->from('/tickets/nuevo')
            ->post('/tickets', [
                'type' => 'incident',
                'itil_category_id' => 1,
                'subject' => 'Impresora no imprime',
                'answers' => ['equipo' => 'impresora', 'detalle' => 'No responde'],
            ])
            ->assertRedirect('/tickets/nuevo')
            ->assertSessionHasErrors('answers.modelo_impresora');
    }

    public function test_ticket_store_fails_gracefully_when_glpi_not_configured(): void
    {
        $user = User::factory()->create();
        $this->seedSupportBranch();

        $this->actingAs($user)
            ->from('/tickets/nuevo')
            ->post('/tickets', [
                'type' => 'incident',
                'itil_category_id' => 1,
                'subject' => 'No puedo acceder al correo',
                'answers' => ['equipo' => 'correo', 'detalle' => 'Outlook no abre desde hoy.'],
            ])
            ->assertRedirect('/tickets/nuevo')
            ->assertSessionHas('error');
    }

    private function seedSupportBranch(): void
    {
        FormDefinition::create([
            'type' => 'incident',
            'itil_category_id' => 1,
            'name' => 'Incidente · Soporte',
            'is_active' => true,
            'fields' => [
                ['key' => 'equipo', 'label' => 'Equipo', 'input' => 'select', 'required' => true,
                    'options' => [['value' => 'impresora', 'label' => 'Impresora'], ['value' => 'correo', 'label' => 'Correo']]],
                ['key' => 'modelo_impresora', 'label' => 'Modelo', 'input' => 'text', 'required' => true,
                    'showIf' => ['field' => 'equipo', 'equals' => 'impresora']],
                ['key' => 'detalle', 'label' => 'Detalle', 'input' => 'textarea', 'required' => true],
            ],
        ]);
    }
}
