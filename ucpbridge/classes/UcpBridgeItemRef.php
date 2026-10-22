<?php
/**
 * Analyse des identifiants d'articles UCP (logique pure, sans base de données).
 * Trois formes : référence de déclinaison, référence produit + variant_id, {id_product}-{id_product_attribute}.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeItemRef
{
    /** Identifiant public d'une déclinaison, au format des flux Google. */
    public static function variantKey($idProduct, $idPa)
    {
        return (int) $idProduct . '-' . (int) $idPa;
    }

    /**
     * @return array kind = invalid | pair (id_product, id_product_attribute) | reference (reference, variant)
     */
    public static function parse($id, $variant)
    {
        $id = trim((string) $id);
        $variant = trim((string) $variant);
        if ('' === $id) {
            return ['kind' => 'invalid'];
        }
        if ('' === $variant && preg_match('/^(\d+)-(\d+)$/', $id, $m)) {
            return ['kind' => 'pair', 'id_product' => (int) $m[1], 'id_product_attribute' => (int) $m[2]];
        }

        return ['kind' => 'reference', 'reference' => $id, 'variant' => '' !== $variant ? $variant : null];
    }

    /** variant_id d'un produit connu : {p}-{pa} du même produit, id numérique, sinon référence de déclinaison. */
    public static function parseVariant($variant, $idProduct)
    {
        $variant = trim((string) $variant);
        if (preg_match('/^(\d+)-(\d+)$/', $variant, $m) && (int) $m[1] === (int) $idProduct) {
            return ['id_product_attribute' => (int) $m[2]];
        }
        if (ctype_digit($variant)) {
            return ['id_product_attribute' => (int) $variant];
        }

        return ['reference' => $variant];
    }
}
