<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/UcpBridgeApiKeys.php';

/** Une clé API par plateforme (issue #5). */
final class ApiKeysTest extends TestCase
{
    const A = 'ucpk_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    const B = 'ucpk_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private function keys(): array
    {
        return ['default' => ['label' => 'Défaut', 'key' => self::A], 'google' => ['label' => 'Google', 'key' => self::B]];
    }

    public function testChaqueCleDesigneSaPlateforme(): void
    {
        $this->assertSame('default', UcpBridgeApiKeys::match($this->keys(), self::A));
        $this->assertSame('google', UcpBridgeApiKeys::match($this->keys(), self::B));
    }

    public function testCleInconnueOuVideRefusee(): void
    {
        $this->assertNull(UcpBridgeApiKeys::match($this->keys(), 'ucpk_' . str_repeat('c', 48)));
        $this->assertNull(UcpBridgeApiKeys::match($this->keys(), ''));
        $this->assertNull(UcpBridgeApiKeys::match(['vide' => ['label' => 'x', 'key' => '']], ''));
        $this->assertNull(UcpBridgeApiKeys::match([], self::A));
        // Jeton client OAuth : jamais confondu avec une clé de plateforme.
        $this->assertNull(UcpBridgeApiKeys::match($this->keys(), 'ucpat_' . str_repeat('a', 64)));
    }

    public function testIdentitesDistinctesParPlateforme(): void
    {
        // Plateforme par défaut : identité historique « platform » (sessions créées avant la migration conservées).
        $this->assertSame('platform', UcpBridgeApiKeys::callerId('default'));
        $this->assertSame('platform:google', UcpBridgeApiKeys::callerId('google'));
        $this->assertNotSame(UcpBridgeApiKeys::callerId('google'), UcpBridgeApiKeys::callerId('openai'));
    }

    public function testIdentifiantsValides(): void
    {
        foreach (['google', 'openai-2', 'agent_test', 'a'] as $id) {
            $this->assertTrue(UcpBridgeApiKeys::isValidId($id), $id);
        }
        foreach (['', 'Google', 'a b', 'a:b', '-x', str_repeat('a', 33), "x\n"] as $id) {
            $this->assertFalse(UcpBridgeApiKeys::isValidId($id), $id);
        }
    }

    public function testMigrationDeLaCleUnique(): void
    {
        $m = UcpBridgeApiKeys::migrate([], self::A, '2026-10-22 10:00:00');
        $this->assertSame(['default'], array_keys($m));
        $this->assertSame(self::A, $m['default']['key']);
        $this->assertSame('default', UcpBridgeApiKeys::match($m, self::A));
        // Déjà migrée, ou plateforme par défaut existante : rien ne change.
        $this->assertSame($this->keys(), UcpBridgeApiKeys::migrate($this->keys(), self::A, 'x'));
        $this->assertSame($this->keys(), UcpBridgeApiKeys::migrate($this->keys(), 'ucpk_autre', 'x'));
        $this->assertSame([], UcpBridgeApiKeys::migrate([], '', 'x'));
    }

    public function testClesGenereesUniques(): void
    {
        $k = UcpBridgeApiKeys::generate();
        $this->assertMatchesRegularExpression('/^ucpk_[0-9a-f]{48}$/', $k);
        $this->assertNotSame($k, UcpBridgeApiKeys::generate());
    }

    public function testInstallationNeuveUneSeulePlateforme(): void
    {
        $k = UcpBridgeApiKeys::initial('2026-10-22 10:00:00');
        $this->assertSame(['default'], array_keys($k));
        $this->assertMatchesRegularExpression('/^ucpk_[0-9a-f]{48}$/', $k['default']['key']);
        $this->assertSame('default', UcpBridgeApiKeys::match($k, $k['default']['key']));
        $this->assertSame('{}', UcpBridgeApiKeys::encode([]));
        $this->assertSame($k, json_decode(UcpBridgeApiKeys::encode($k), true));
    }
}
