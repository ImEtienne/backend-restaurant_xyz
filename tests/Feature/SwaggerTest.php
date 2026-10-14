<?php

namespace Tests\Feature;

use Illuminate\Support\Js;
use Tests\TestCase;

class SwaggerTest extends TestCase
{
    public function test_displays_swagger_ui_with_the_local_specification(): void
    {
        $response = $this->get('/docs');

        $response->assertOk()
            ->assertViewIs('swagger')
            ->assertSee('swagger-ui-bundle.js', false)
            ->assertSee(Js::from('/docs/openapi.json')->toHtml(), false)
            ->assertSee('validatorUrl: null', false);
    }

    public function test_exposes_the_reservation_contract_as_openapi_json(): void
    {
        $response = $this->getJson('/docs/openapi.json');

        $response->assertOk()
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('servers.0.url', '/')
            ->assertJsonPath('paths./api/reservations.post.operationId', 'storeReservation')
            ->assertJsonPath('components.schemas.ReservationRequest.required', [
                'name',
                'email',
                'phone',
                'date',
                'time',
                'guests',
            ])
            ->assertJsonPath('components.schemas.ReservationRequest.properties.guests.maximum', 10)
            ->assertJsonPath('components.schemas.ReservationCreated.properties.status.enum', ['pending'])
            ->assertJsonStructure([
                'paths' => [
                    '/api/reservations' => [
                        'post' => ['responses' => ['201', '422', '429', '500']],
                    ],
                ],
            ]);
    }
}
