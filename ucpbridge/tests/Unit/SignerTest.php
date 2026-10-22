<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    const S = 'secret-de-test';

    public function testLienValide(): void
    {
        $q = UcpBridgeSigner::signedParams(self::S, 'handoff', ['cart' => 42], 3600, 1000);
        $this->assertSame(['cart' => 42, 'exp' => 4600], array_diff_key($q, ['sig' => 1]));
        $this->assertSame(64, strlen($q['sig']));
        $this->assertTrue(UcpBridgeSigner::verify(self::S, 'handoff', $q, 2000));
    }

    public function testLienFalsifie(): void
    {
        $q = UcpBridgeSigner::signedParams(self::S, 'handoff', ['cart' => 42], 3600, 1000);
        $q['cart'] = 43;
        $this->assertFalse(UcpBridgeSigner::verify(self::S, 'handoff', $q, 2000));
    }

    public function testLienExpire(): void
    {
        $q = UcpBridgeSigner::signedParams(self::S, 'handoff', ['cart' => 42], 3600, 1000);
        $this->assertFalse(UcpBridgeSigner::verify(self::S, 'handoff', $q, 4601));
    }

    public function testExpirationProlongeeRefusee(): void
    {
        $q = UcpBridgeSigner::signedParams(self::S, 'handoff', ['cart' => 42], 3600, 1000);
        $q['exp'] = 99999;
        $this->assertFalse(UcpBridgeSigner::verify(self::S, 'handoff', $q, 2000));
    }

    public function testMauvaisSecretEtSecretVide(): void
    {
        $q = UcpBridgeSigner::signedParams(self::S, 'handoff', ['cart' => 42], 3600, 1000);
        $this->assertFalse(UcpBridgeSigner::verify('autre', 'handoff', $q, 2000));
        $vide = UcpBridgeSigner::signedParams('', 'handoff', ['cart' => 42], 3600, 1000);
        $this->assertFalse(UcpBridgeSigner::verify('', 'handoff', $vide, 2000));
    }

    public function testUsagesNonInterchangeables(): void
    {
        $q = UcpBridgeSigner::signedParams(self::S, 'handoff', ['order' => 5], 3600, 1000);
        $this->assertFalse(UcpBridgeSigner::verify(self::S, 'withdraw', $q, 2000));
    }

    public function testSignatureAbsente(): void
    {
        $this->assertFalse(UcpBridgeSigner::verify(self::S, 'handoff', ['cart' => 1, 'exp' => 5000], 2000));
        $this->assertFalse(UcpBridgeSigner::verify(self::S, 'handoff', ['cart' => 1, 'sig' => 'x'], 2000));
    }
}
