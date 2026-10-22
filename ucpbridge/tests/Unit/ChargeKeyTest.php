<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

/** Clé d'idempotence transmise au prestataire de paiement pour un débit (issue #3). */
final class ChargeKeyTest extends TestCase
{
    const SID = 'chk_0123456789abcdef0123';
    const INSTR = ['id' => 'instr_1', 'handler_id' => 'gpay_stripe', 'credential' => ['type' => 'PAYMENT_GATEWAY', 'token' => 'tok_visa']];

    public function testMemeEssaiMemeCle(): void
    {
        $this->assertSame(UcpBridgeIdempotency::chargeKey(self::SID, self::INSTR, 2390, 'EUR', 0),
            UcpBridgeIdempotency::chargeKey(self::SID, self::INSTR, 2390, 'EUR', 0));
    }

    public function testMontantDansLaCle(): void
    {
        $this->assertNotSame(UcpBridgeIdempotency::chargeKey(self::SID, self::INSTR, 2390, 'EUR', 0),
            UcpBridgeIdempotency::chargeKey(self::SID, self::INSTR, 4780, 'EUR', 0));
    }

    public function testDeviseDansLaCle(): void
    {
        $this->assertNotSame(UcpBridgeIdempotency::chargeKey(self::SID, self::INSTR, 2390, 'EUR', 0),
            UcpBridgeIdempotency::chargeKey(self::SID, self::INSTR, 2390, 'CHF', 0));
        // Casse indifférente : eur et EUR sont la même devise.
        $this->assertSame(UcpBridgeIdempotency::chargeKey(self::SID, self::INSTR, 2390, 'eur', 0),
            UcpBridgeIdempotency::chargeKey(self::SID, self::INSTR, 2390, 'EUR', 0));
    }

    public function testNouvelleTentativeApresRefusNouvelleCle(): void
    {
        $this->assertNotSame(UcpBridgeIdempotency::chargeKey(self::SID, self::INSTR, 2390, 'EUR', 0),
            UcpBridgeIdempotency::chargeKey(self::SID, self::INSTR, 2390, 'EUR', 1));
    }

    public function testInstrumentDansLaCle(): void
    {
        $autre = self::INSTR;
        $autre['credential']['token'] = 'tok_mastercard';
        $this->assertNotSame(UcpBridgeIdempotency::chargeKey(self::SID, self::INSTR, 2390, 'EUR', 0),
            UcpBridgeIdempotency::chargeKey(self::SID, $autre, 2390, 'EUR', 0));
    }

    public function testFormatAccepteParStripe(): void
    {
        $k = UcpBridgeIdempotency::chargeKey(self::SID, self::INSTR, 2390, 'EUR', 3);
        $this->assertStringStartsWith(self::SID . '-3-', $k);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{1,255}$/', $k);
    }
}
