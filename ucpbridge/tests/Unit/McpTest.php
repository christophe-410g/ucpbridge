<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

final class McpTest extends TestCase
{
    private $calls = [];

    private function mcp($reply = null): UcpBridgeMcp
    {
        $this->calls = [];
        $self = $this;

        return new UcpBridgeMcp(function ($method, $path, $body, $idem, $agent) use ($self, $reply) {
            $self->calls[] = [$method, $path, $body, $idem, $agent];

            return $reply ?: [200, ['ucp' => ['version' => '2026-04-08', 'status' => 'success'], 'id' => 'x']];
        }, ['name' => 'ucpbridge', 'version' => '0.1.0']);
    }

    private function rpc($method, $params = null, $id = 1): string
    {
        $r = ['jsonrpc' => '2.0', 'method' => $method];
        if (null !== $id) {
            $r['id'] = $id;
        }
        if (null !== $params) {
            $r['params'] = $params;
        }

        return json_encode($r);
    }

    private function call($name, array $args): string
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => $args]);
    }

    private function meta($idem = null): array
    {
        $m = ['ucp-agent' => ['profile' => 'https://agent.test/.well-known/ucp']];
        if ($idem) {
            $m['idempotency-key'] = $idem;
        }

        return $m;
    }

    public function testInitialize(): void
    {
        $r = $this->mcp()->handle($this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]));
        $this->assertSame(200, $r['status']);
        $this->assertSame('2.0', $r['body']['jsonrpc']);
        $this->assertSame(1, $r['body']['id']);
        $this->assertSame('2025-06-18', $r['body']['result']['protocolVersion']);
        $this->assertArrayHasKey('tools', $r['body']['result']['capabilities']);
        $this->assertSame('ucpbridge', $r['body']['result']['serverInfo']['name']);
    }

    public function testVersionInconnueNegociee(): void
    {
        $r = $this->mcp()->handle($this->rpc('initialize', ['protocolVersion' => '1999-01-01']));
        $this->assertSame(UcpBridgeMcp::PROTOCOL_VERSIONS[0], $r['body']['result']['protocolVersion']);
    }

    public function testNotificationSansReponse(): void
    {
        $r = $this->mcp()->handle($this->rpc('notifications/initialized', null, null));
        $this->assertSame(202, $r['status']);
        $this->assertNull($r['body']);
    }

    public function testPing(): void
    {
        $this->assertEquals(new stdClass(), $this->mcp()->handle($this->rpc('ping'))['body']['result']);
    }

    public function testErreursJsonRpc(): void
    {
        $m = $this->mcp();
        $this->assertSame(-32700, $m->handle('{pas du json')['body']['error']['code']);
        $this->assertSame(-32600, $m->handle('{"jsonrpc":"1.0","id":1,"method":"ping"}')['body']['error']['code']);
        $this->assertSame(-32600, $m->handle('[{"jsonrpc":"2.0","id":1,"method":"ping"}]')['body']['error']['code']);
        $this->assertSame(-32601, $m->handle($this->rpc('resources/list'))['body']['error']['code']);
        $this->assertSame(-32602, $m->handle($this->call('inconnu', ['meta' => $this->meta()]))['body']['error']['code']);
    }

    public function testListeDesOutils(): void
    {
        $tools = $this->mcp()->handle($this->rpc('tools/list'))['body']['result']['tools'];
        $names = array_column($tools, 'name');
        foreach (['search_catalog', 'get_product', 'create_cart', 'get_cart', 'update_cart', 'cancel_cart', 'create_checkout',
            'get_checkout', 'update_checkout', 'complete_checkout', 'cancel_checkout', 'get_order'] as $n) {
            $this->assertContains($n, $names);
        }
        foreach ($tools as $t) {
            $this->assertSame('object', $t['inputSchema']['type']);
            $this->assertContains('meta', $t['inputSchema']['required']);
            $this->assertNotEmpty($t['description']);
        }
    }

    public function testMetaUcpAgentObligatoire(): void
    {
        $r = $this->mcp()->handle($this->call('search_catalog', ['catalog' => ['query' => 'x']]));
        $this->assertSame(-32602, $r['body']['error']['code']);
        $this->assertSame([], $this->calls);
    }

    public function testIdempotencyKeyObligatoirePourFinaliser(): void
    {
        $r = $this->mcp()->handle($this->call('complete_checkout', ['meta' => $this->meta(), 'id' => 'chk_1', 'checkout' => []]));
        $this->assertSame(-32602, $r['body']['error']['code']);
        $this->assertSame([], $this->calls);
    }

    public function testCorrespondanceVersLaLogiqueRest(): void
    {
        $cases = [
            ['search_catalog', ['catalog' => ['query' => 'colibri']], ['POST', 'catalog/search', ['query' => 'colibri']]],
            ['get_product', ['catalog' => ['id' => 'demo_1']], ['POST', 'catalog/product', ['id' => 'demo_1']]],
            ['create_cart', ['cart' => ['line_items' => []]], ['POST', 'carts', ['line_items' => []]]],
            ['get_cart', ['id' => 'cart_1'], ['GET', 'carts/cart_1', []]],
            ['update_cart', ['id' => 'cart_1', 'cart' => ['line_items' => [1]]], ['PUT', 'carts/cart_1', ['line_items' => [1]]]],
            ['create_checkout', ['checkout' => ['line_items' => [2]]], ['POST', 'checkout-sessions', ['line_items' => [2]]]],
            ['get_checkout', ['id' => 'chk_1'], ['GET', 'checkout-sessions/chk_1', []]],
            ['update_checkout', ['id' => 'chk_1', 'checkout' => ['buyer' => []]], ['PUT', 'checkout-sessions/chk_1', ['buyer' => []]]],
            ['get_order', ['id' => '12'], ['GET', 'orders/12', []]],
        ];
        foreach ($cases as list($tool, $args, $expected)) {
            $m = $this->mcp();
            $m->handle($this->call($tool, ['meta' => $this->meta('k-' . $tool)] + $args));
            $this->assertSame($expected, array_slice($this->calls[0], 0, 3), $tool);
            $this->assertSame('profile="https://agent.test/.well-known/ucp"', $this->calls[0][4]);
        }
    }

    public function testFinalisationTransmetLaCle(): void
    {
        $m = $this->mcp();
        $m->handle($this->call('complete_checkout', ['meta' => $this->meta('cle-1'), 'id' => 'chk_1', 'checkout' => ['payment' => ['x' => 1]]]));
        $this->assertSame(['POST', 'checkout-sessions/chk_1/complete', ['payment' => ['x' => 1]], 'cle-1'], array_slice($this->calls[0], 0, 4));
        $m->handle($this->call('cancel_checkout', ['meta' => $this->meta('cle-2'), 'id' => 'chk_1']));
        $this->assertSame(['POST', 'checkout-sessions/chk_1/cancel', [], 'cle-2'], array_slice($this->calls[1], 0, 4));
    }

    public function testIdentifiantNonEncodableRefuse(): void
    {
        $r = $this->mcp()->handle($this->call('get_cart', ['meta' => $this->meta(), 'id' => '../orders/1']));
        $this->assertSame(-32602, $r['body']['error']['code']);
    }

    public function testSortieDoubleStructuredContentEtTexte(): void
    {
        $doc = ['ucp' => ['version' => '2026-04-08', 'status' => 'success'], 'id' => 'cart_9', 'continue_url' => 'http://b/x'];
        $r = $this->mcp([201, $doc])->handle($this->call('create_cart', ['meta' => $this->meta('k'), 'cart' => []]));
        $res = $r['body']['result'];
        $this->assertSame($doc, $res['structuredContent']);
        $this->assertSame('text', $res['content'][0]['type']);
        $this->assertSame($doc, json_decode($res['content'][0]['text'], true));
        $this->assertFalse($res['isError']);
    }

    public function testResultatMetierEnErreurResteUnResultat(): void
    {
        $doc = ['ucp' => ['version' => '2026-04-08', 'status' => 'error'], 'messages' => [['type' => 'error', 'code' => 'variant_required']]];
        $r = $this->mcp([200, $doc])->handle($this->call('create_cart', ['meta' => $this->meta('k'), 'cart' => []]));
        $this->assertSame($doc, $r['body']['result']['structuredContent']);
        $this->assertTrue($r['body']['result']['isError']); // isError aligné sur ucp.status
    }

    public function testIdempotencyKeyObligatoirePourToutOutilQuiModifie(): void
    {
        foreach (['create_cart' => ['cart' => []], 'update_cart' => ['id' => 'cart_1', 'cart' => []], 'cancel_cart' => ['id' => 'cart_1'],
            'create_checkout' => ['checkout' => []], 'update_checkout' => ['id' => 'chk_1', 'checkout' => []]] as $tool => $args) {
            $r = $this->mcp()->handle($this->call($tool, ['meta' => $this->meta()] + $args));
            $this->assertSame(-32602, $r['body']['error']['code'], $tool);
        }
        $this->assertSame([], $this->calls);
        $this->mcp()->handle($this->call('get_cart', ['meta' => $this->meta(), 'id' => 'cart_1']));
        $this->assertCount(1, $this->calls); // lecture : pas de clé exigée
    }

    public function testConflitDIdempotenceEnErreurProtocole(): void
    {
        $r = $this->mcp([409, ['code' => 'idempotency_conflict', 'content' => 'x']])->handle(
            $this->call('create_cart', ['meta' => $this->meta('k'), 'cart' => []]));
        $this->assertSame(409, $r['status']);
        $this->assertSame(-32000, $r['body']['error']['code']);
        $this->assertSame('idempotency_conflict', $r['body']['error']['data']['code']);
    }

    public function testErreurInterne(): void
    {
        $r = $this->mcp([500, ['code' => 'internal_error', 'content' => 'x']])->handle(
            $this->call('get_order', ['meta' => $this->meta(), 'id' => '1']));
        $this->assertSame(-32603, $r['body']['error']['code']);
    }
}
