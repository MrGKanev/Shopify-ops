<?php

namespace Tests\Feature;

use App\Application\Orders\SaveOrderNote;
use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class OrderNoteControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_an_operator_can_save_a_note(): void
    {
        $this->post(route('orders.note.update'), ['order_id' => '123', 'order_number' => '1001', 'note' => 'x'])->assertRedirect(route('login'));

        [$viewer] = $this->userWithStore(false);
        $this->actingAs($viewer)->post(route('orders.note.update'), ['order_id' => '123', 'order_number' => '1001', 'note' => 'x'])->assertForbidden();
    }

    public function test_saves_the_note_and_flashes_a_status_message(): void
    {
        [$operator] = $this->userWithStore(true);
        $saveOrderNote = Mockery::mock(SaveOrderNote::class);
        $saveOrderNote->shouldReceive('handle')->once()->with(Mockery::type(Store::class), '123', 'Fragile')->andReturnNull();
        $this->app->instance(SaveOrderNote::class, $saveOrderNote);

        $this->actingAs($operator)->post(route('orders.note.update'), ['order_id' => '123', 'order_number' => '#1001', 'note' => 'Fragile'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Note saved for order #1001.');
    }

    public function test_redirects_with_an_error_when_shopify_rejects_the_update(): void
    {
        [$operator] = $this->userWithStore(true);
        $saveOrderNote = Mockery::mock(SaveOrderNote::class);
        $saveOrderNote->shouldReceive('handle')->once()->andThrow(new ShopifyGraphqlException([], 'Shopify orderUpdate error: Note is too long.'));
        $this->app->instance(SaveOrderNote::class, $saveOrderNote);

        $this->actingAs($operator)->post(route('orders.note.update'), ['order_id' => '123', 'order_number' => '1001', 'note' => 'x'])
            ->assertRedirect()
            ->assertSessionHasErrors('note');
    }

    public function test_allows_an_empty_note_to_clear_it(): void
    {
        [$operator] = $this->userWithStore(true);
        $saveOrderNote = Mockery::mock(SaveOrderNote::class);
        $saveOrderNote->shouldReceive('handle')->once()->with(Mockery::type(Store::class), '123', '')->andReturnNull();
        $this->app->instance(SaveOrderNote::class, $saveOrderNote);

        $this->actingAs($operator)->post(route('orders.note.update'), ['order_id' => '123', 'order_number' => '1001', 'note' => ''])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
    }

    public function test_order_id_must_be_numeric(): void
    {
        [$operator] = $this->userWithStore(true);

        $this->actingAs($operator)->post(route('orders.note.update'), ['order_id' => 'gid://shopify/Order/1', 'order_number' => '1001', 'note' => 'x'])
            ->assertSessionHasErrors('order_id');
    }

    /** @return array{User, Store} */
    private function userWithStore(bool $operator): array
    {
        $user = $operator ? User::factory()->operator()->create() : User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
