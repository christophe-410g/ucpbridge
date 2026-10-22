<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

final class PkceTest extends TestCase
{
    // Vecteur de test de la RFC 7636, annexe B
    const V = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    const C = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    public function testVecteurRfc7636(): void
    {
        $this->assertSame(self::C, UcpBridgePkce::challenge(self::V));
        $this->assertTrue(UcpBridgePkce::verify(self::V, self::C));
    }

    public function testMauvaisVerifier(): void
    {
        $this->assertFalse(UcpBridgePkce::verify(str_repeat('x', 43), self::C));
    }

    public function testFormeDuVerifier(): void
    {
        $this->assertFalse(UcpBridgePkce::isValidVerifier(str_repeat('a', 42)));
        $this->assertTrue(UcpBridgePkce::isValidVerifier(str_repeat('a', 128)));
        $this->assertFalse(UcpBridgePkce::isValidVerifier(str_repeat('a', 129)));
        $this->assertFalse(UcpBridgePkce::isValidVerifier(str_repeat('a', 42) . '+'));
        $this->assertFalse(UcpBridgePkce::verify(str_repeat('a', 42), UcpBridgePkce::challenge(str_repeat('a', 42))));
    }

    public function testFormeDuChallenge(): void
    {
        $this->assertTrue(UcpBridgePkce::isValidChallenge(self::C));
        $this->assertFalse(UcpBridgePkce::isValidChallenge(self::C . 'A'));
        $this->assertFalse(UcpBridgePkce::isValidChallenge('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-c='));
    }
}
