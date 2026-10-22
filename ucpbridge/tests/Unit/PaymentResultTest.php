<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/payment/UcpBridgePaymentProvider.php';
require_once __DIR__ . '/../../classes/payment/UcpBridgeStripeProvider.php';

final class PaymentResultTest extends TestCase
{
    public function testResultatInconnuDistinctDuRefus(): void
    {
        $r = UcpBridgePaymentResult::unknown('réponse perdue');
        $this->assertSame(UcpBridgePaymentResult::UNKNOWN, $r->status);
        $this->assertNotSame(UcpBridgePaymentResult::FAILED, $r->status);
        $this->assertSame('réponse perdue', $r->message);
        $this->assertSame('', $r->transactionId);
    }

    public function testErreursCurlAvantEnvoiSontDesRefus(): void
    {
        // Résolution DNS, proxy, connexion refusée : la requête n'est jamais partie.
        foreach ([5, 6, 7] as $errno) {
            $this->assertTrue(UcpBridgeStripeProvider::neverSent($errno), (string) $errno);
        }
    }

    public function testErreursCurlApresEnvoiLaissentLeResultatInconnu(): void
    {
        // Délai dépassé (28), réponse vide (52), erreur d'envoi (55) ou de réception (56) : Stripe a pu débiter.
        foreach ([28, 52, 55, 56, 35] as $errno) {
            $this->assertFalse(UcpBridgeStripeProvider::neverSent($errno), (string) $errno);
        }
    }
}
