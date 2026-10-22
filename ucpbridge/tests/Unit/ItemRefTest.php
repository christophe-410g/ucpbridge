<?php
/** Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. */
use PHPUnit\Framework\TestCase;

final class ItemRefTest extends TestCase
{
    public function testIdentifiantVideInvalide(): void
    {
        $this->assertSame('invalid', UcpBridgeItemRef::parse('  ', '')['kind']);
    }

    public function testFormatFluxGoogle(): void
    {
        $this->assertSame(['kind' => 'pair', 'id_product' => 1, 'id_product_attribute' => 3], UcpBridgeItemRef::parse('1-3', ''));
        $this->assertSame(['kind' => 'pair', 'id_product' => 12, 'id_product_attribute' => 0], UcpBridgeItemRef::parse(' 12-0 ', ''));
    }

    public function testPaireAvecVariantIdEstUneReference(): void
    {
        $r = UcpBridgeItemRef::parse('1-3', '7');
        $this->assertSame('reference', $r['kind']);
        $this->assertSame('1-3', $r['reference']);
        $this->assertSame('7', $r['variant']);
    }

    public function testReferenceDeDeclinaison(): void
    {
        $this->assertSame(['kind' => 'reference', 'reference' => 'demo_1-M-BLANC', 'variant' => null],
            UcpBridgeItemRef::parse('demo_1-M-BLANC', ''));
    }

    public function testProduitEtVariante(): void
    {
        $this->assertSame(['kind' => 'reference', 'reference' => 'demo_1', 'variant' => '1-3'], UcpBridgeItemRef::parse('demo_1', ' 1-3 '));
    }

    public function testVarianteSousFormeDePaire(): void
    {
        $this->assertSame(['id_product_attribute' => 3], UcpBridgeItemRef::parseVariant('1-3', 1));
    }

    public function testPaireDUnAutreProduitTraiteeCommeReference(): void
    {
        $this->assertSame(['reference' => '2-3'], UcpBridgeItemRef::parseVariant('2-3', 1));
    }

    public function testVarianteNumerique(): void
    {
        $this->assertSame(['id_product_attribute' => 7], UcpBridgeItemRef::parseVariant('7', 1));
    }

    public function testVarianteParReference(): void
    {
        $this->assertSame(['reference' => 'demo_1-L-NOIR'], UcpBridgeItemRef::parseVariant('demo_1-L-NOIR', 1));
    }

    public function testCleDeVariante(): void
    {
        $this->assertSame('1-3', UcpBridgeItemRef::variantKey('1', 3));
    }
}
