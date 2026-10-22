<?php
/**
 * PKCE S256 (RFC 7636), logique pure.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgePkce
{
    public static function isValidVerifier($v)
    {
        return (bool) preg_match('/^[A-Za-z0-9._~-]{43,128}$/', (string) $v);
    }

    /** Un challenge S256 est un SHA-256 en base64url sans remplissage : 43 caractères. */
    public static function isValidChallenge($c)
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', (string) $c);
    }

    public static function challenge($verifier)
    {
        return rtrim(strtr(base64_encode(hash('sha256', (string) $verifier, true)), '+/', '-_'), '=');
    }

    public static function verify($verifier, $challenge)
    {
        return self::isValidVerifier($verifier) && hash_equals((string) $challenge, self::challenge($verifier));
    }
}
