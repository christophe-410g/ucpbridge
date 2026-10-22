<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

final class ConsentTest extends TestCase
{
    public function testSeulsLesBooleensExplicitesSontRetenus(): void
    {
        $c = UcpBridgeConsent::normalize(['marketing' => 'true', 'analytics' => 1, 'preferences' => false, 'sale_of_data' => null, 'autre' => true]);
        $this->assertSame(['preferences' => false], $c);
        $this->assertSame([], UcpBridgeConsent::normalize(null));
        $this->assertSame([], UcpBridgeConsent::normalize('oui'));
    }

    public function testNewsletterUniquementSurTrueExplicite(): void
    {
        $this->assertTrue(UcpBridgeConsent::newsletter(['marketing' => true]));
        $this->assertFalse(UcpBridgeConsent::newsletter(['marketing' => false]));
        $this->assertNull(UcpBridgeConsent::newsletter([]));
    }

    public function testOptinPartenaires(): void
    {
        $this->assertTrue(UcpBridgeConsent::partnerOptin(['marketing' => true, 'sale_of_data' => true]));
        $this->assertNull(UcpBridgeConsent::partnerOptin(['marketing' => true]));
        $this->assertFalse(UcpBridgeConsent::partnerOptin(['marketing' => true, 'sale_of_data' => false]));
        $this->assertFalse(UcpBridgeConsent::partnerOptin(['marketing' => false]));
        $this->assertNull(UcpBridgeConsent::partnerOptin([]));
    }

    public function testJournalSansValeurPresumee(): void
    {
        $j = UcpBridgeConsent::journal(['analytics' => true]);
        $this->assertSame(['analytics' => true, 'preferences' => null, 'marketing' => null, 'sale_of_data' => null], $j);
    }
}
