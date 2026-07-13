<?php

namespace Tests\Feature;

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
            );
    }

    public function test_categories_endpoint_returns_nested_tree_filtered_by_incident(): void
    {
        $user = User::factory()->create();

        $areas = $this->actingAs($user)
            ->getJson('/tickets/categorias?type=incident')
            ->assertOk()
            ->json('areas');

        $allNames = $this->collectNames($areas);
        $leafNames = $this->collectLeafNames($areas);

        // Hojas de distinta profundidad conviven:
        $this->assertContains('Equipos Computacionales', $leafNames); // nivel 3
        $this->assertContains('Error', $leafNames);                   // nivel 4 (Sistemas > Frusys)
        // El sistema intermedio es navegación, NO una hoja seleccionable:
        $this->assertNotContains('Frusys', $leafNames);
        $this->assertContains('Frusys', $allNames);
        // El nivel Incidente/Solicitud nunca aparece:
        $this->assertNotContains('Incidente', $allNames);
        $this->assertNotContains('Solicitud', $allNames);
        // Otra rama filtrada:
        $this->assertNotContains('Desbloqueo Web', $allNames);
    }

    public function test_categories_endpoint_filters_by_request(): void
    {
        $user = User::factory()->create();

        $areas = $this->actingAs($user)
            ->getJson('/tickets/categorias?type=request')
            ->assertOk()
            ->json('areas');

        $leafNames = $this->collectLeafNames($areas);

        $this->assertContains('Desbloqueo Web', $leafNames);   // nivel 3
        $this->assertContains('Desarrollo', $leafNames);       // nivel 4 (Sistemas)
        $this->assertNotContains('Equipos Computacionales', $leafNames);
    }

    public function test_leaves_carry_a_glpi_category_id(): void
    {
        $user = User::factory()->create();

        $areas = $this->actingAs($user)
            ->getJson('/tickets/categorias?type=incident')
            ->assertOk()
            ->json('areas');

        foreach ($this->collectLeaves($areas) as $leaf) {
            $this->assertIsInt($leaf['id'], "La hoja {$leaf['name']} debe traer id de GLPI.");
        }
    }

    public function test_ticket_detail_is_not_found_in_demo_or_when_not_owned(): void
    {
        $user = User::factory()->create();

        // Sin GLPI configurado (demo) o ticket ajeno => 404, nunca expone datos.
        $this->actingAs($user)->get('/tickets/123')->assertNotFound();
    }

    /** @return array<int, string> */
    private function collectNames(array $nodes): array
    {
        $names = [];
        foreach ($nodes as $n) {
            $names[] = $n['name'];
            $names = array_merge($names, $this->collectNames($n['children']));
        }

        return $names;
    }

    /** @return array<int, string> */
    private function collectLeafNames(array $nodes): array
    {
        return array_map(fn ($l) => $l['name'], $this->collectLeaves($nodes));
    }

    /** @return array<int, array{id:?int, name:string, children:array}> */
    private function collectLeaves(array $nodes): array
    {
        $leaves = [];
        foreach ($nodes as $n) {
            if (empty($n['children'])) {
                $leaves[] = $n;
            } else {
                $leaves = array_merge($leaves, $this->collectLeaves($n['children']));
            }
        }

        return $leaves;
    }

    public function test_ticket_validation_rejects_short_subject(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/tickets/nuevo')
            ->post('/tickets', [
                'type' => 'incident',
                'itil_category_id' => 21,
                'subject' => 'x',
                'description' => 'Descripción suficientemente larga.',
            ])
            ->assertRedirect('/tickets/nuevo')
            ->assertSessionHasErrors('subject');
    }

    public function test_ticket_validation_requires_description(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/tickets/nuevo')
            ->post('/tickets', [
                'type' => 'incident',
                'itil_category_id' => 21,
                'subject' => 'Impresora no imprime',
            ])
            ->assertRedirect('/tickets/nuevo')
            ->assertSessionHasErrors('description');
    }

    public function test_ticket_store_fails_gracefully_when_glpi_not_configured(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/tickets/nuevo')
            ->post('/tickets', [
                'type' => 'incident',
                'itil_category_id' => 21,
                'subject' => 'No puedo acceder al correo',
                'description' => 'Outlook no abre desde esta mañana.',
            ])
            ->assertRedirect('/tickets/nuevo')
            ->assertSessionHas('error');
    }
}
