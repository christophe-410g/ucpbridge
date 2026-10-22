<?php
/**
 * Extension buyer_consent (dev.ucp.shopping.buyer_consent), logique pure.
 * Seuls les booléens explicites comptent ; une absence n'est jamais lue comme un consentement.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeConsent
{
    const KEYS = ['analytics', 'preferences', 'marketing', 'sale_of_data'];

    public static function normalize($consent)
    {
        $out = [];
        if (!is_array($consent)) {
            return $out;
        }
        foreach (self::KEYS as $k) {
            if (array_key_exists($k, $consent) && is_bool($consent[$k])) {
                $out[$k] = $consent[$k];
            }
        }

        return $out;
    }

    /** Option newsletter du client : true / false explicites, null = ne rien changer. */
    public static function newsletter(array $c)
    {
        return array_key_exists('marketing', $c) ? $c['marketing'] : null;
    }

    /**
     * Option « offres des partenaires » : suppose la communication marketing ET le partage des données à des tiers.
     * true seulement si les deux sont explicitement true ; false si l'un est explicitement false.
     */
    public static function partnerOptin(array $c)
    {
        if (false === ($c['marketing'] ?? null) || false === ($c['sale_of_data'] ?? null)) {
            return false;
        }
        if (true === ($c['marketing'] ?? null) && true === ($c['sale_of_data'] ?? null)) {
            return true;
        }

        return null;
    }

    /** Vue du journal de preuve : chaque catégorie, null quand l'acheteur ne s'est pas prononcé. */
    public static function journal(array $c)
    {
        $out = [];
        foreach (self::KEYS as $k) {
            $out[$k] = array_key_exists($k, $c) ? $c[$k] : null;
        }

        return $out;
    }
}
