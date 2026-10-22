<?php
/**
 * Liens signés (HMAC SHA-256) du module : reprise de panier, relecture des CGV, rétractation.
 * L'usage fait partie de la signature : un lien d'un usage n'ouvre jamais un autre usage.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeSigner
{
    public static function sign($secret, $purpose, array $params)
    {
        ksort($params);
        $parts = [(string) $purpose];
        foreach ($params as $k => $v) {
            $parts[] = $k . '=' . $v;
        }

        return hash_hmac('sha256', implode('|', $parts), (string) $secret);
    }

    /** Paramètres de lien : valeurs + exp (horodatage) + sig. */
    public static function signedParams($secret, $purpose, array $params, $ttl, $now = null)
    {
        $params['exp'] = (int) (null === $now ? time() : $now) + (int) $ttl;
        $params['sig'] = self::sign($secret, $purpose, $params);

        return $params;
    }

    public static function verify($secret, $purpose, array $params, $now = null)
    {
        if ('' === (string) $secret || !isset($params['sig'], $params['exp'])) {
            return false;
        }
        if ((int) $params['exp'] < (null === $now ? time() : (int) $now)) {
            return false;
        }
        $sig = (string) $params['sig'];
        unset($params['sig']);

        return hash_equals(self::sign($secret, $purpose, $params), $sig);
    }
}
