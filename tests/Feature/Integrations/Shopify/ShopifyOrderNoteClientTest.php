<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\BuildsShopifyAdminClient;
use Tests\TestCase;

class ShopifyOrderNoteClientTest extends TestCase
{
    use BuildsShopifyAdminClient;

    public function test_update_order_note_sends_the_order_gid_and_note(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['orderUpdate' => ['order' => ['id' => 'gid://shopify/Order/1', 'note' => 'Fragile'], 'userErrors' => []]]])]);

        $this->client()->updateOrderNote($this->store(), '1', 'Fragile');

        Http::assertSent(fn (Request $request): bool => $request['variables'] === ['id' => 'gid://shopify/Order/1', 'note' => 'Fragile']);
    }

    public function test_update_order_note_throws_on_a_shopify_user_error(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['orderUpdate' => ['order' => null, 'userErrors' => [['field' => ['note'], 'message' => 'Note is too long.']]]]])]);

        $this->expectException(ShopifyGraphqlException::class);
        $this->expectExceptionMessage('Shopify orderUpdate error: Note is too long.');

        $this->client()->updateOrderNote($this->store(), '1', str_repeat('x', 10000));
    }

    public function test_update_order_note_rejects_an_invalid_order_id_before_requesting(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidArgumentException::class);

        $this->client()->updateOrderNote($this->store(), '123 OR id:*', 'note');
    }

    public function test_update_order_note_accepts_an_empty_string_to_clear_the_note(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://acme.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['orderUpdate' => ['order' => ['id' => 'gid://shopify/Order/1', 'note' => ''], 'userErrors' => []]]])]);

        $this->client()->updateOrderNote($this->store(), '1', '');

        Http::assertSent(fn (Request $request): bool => $request['variables']['note'] === '');
    }
}
