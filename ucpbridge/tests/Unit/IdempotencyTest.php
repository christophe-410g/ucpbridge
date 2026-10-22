<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

final class IdempotencyTest extends TestCase
{
    public function testEmpreinteIndependanteDeLOrdreDesCles(): void
    {
        $a = UcpBridgeIdempotency::fingerprint('post', 'carts', ['b' => 1, 'a' => ['y' => 2, 'x' => 1]]);
        $b = UcpBridgeIdempotency::fingerprint('POST', 'carts', ['a' => ['x' => 1, 'y' => 2], 'b' => 1]);
        $this->assertSame($a, $b);
    }

    public function testEmpreinteSensibleAuCorpsEtAuChemin(): void
    {
        $a = UcpBridgeIdempotency::fingerprint('POST', 'carts', ['line_items' => [1, 2]]);
        $this->assertNotSame($a, UcpBridgeIdempotency::fingerprint('POST', 'carts', ['line_items' => [2, 1]]));
        $this->assertNotSame($a, UcpBridgeIdempotency::fingerprint('POST', 'checkout-sessions', ['line_items' => [1, 2]]));
    }

    public function testCleCloisonneeParAppelant(): void
    {
        $this->assertNotSame(UcpBridgeIdempotency::scopedKey('platform', 'k1'), UcpBridgeIdempotency::scopedKey('user:a:2', 'k1'));
        $this->assertSame(64, strlen(UcpBridgeIdempotency::scopedKey('platform', str_repeat('x', 255))));
    }

    public function testDecisionNouvelleCle(): void
    {
        $this->assertNull(UcpBridgeIdempotency::decide(null, 'h'));
    }

    public function testDecisionConflit(): void
    {
        $d = UcpBridgeIdempotency::decide(['request_hash' => 'autre', 'http_code' => 200, 'response' => '{}'], 'h');
        $this->assertSame(409, $d[0]);
        $this->assertSame('idempotency_conflict', $d[1]['code']);
    }

    public function testDecisionEnCours(): void
    {
        $d = UcpBridgeIdempotency::decide(['request_hash' => 'h', 'http_code' => 0, 'response' => null], 'h');
        $this->assertSame([409, 'idempotency_in_progress'], [$d[0], $d[1]['code']]);
    }

    public function testDecisionRejeu(): void
    {
        $d = UcpBridgeIdempotency::decide(['request_hash' => 'h', 'http_code' => 201, 'response' => '{"id":"cart_1"}'], 'h');
        $this->assertSame([201, ['id' => 'cart_1'], true], $d);
    }
}
