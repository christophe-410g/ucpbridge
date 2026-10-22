<?php
/**
 * Clés API des plateformes (mode invité) : une clé par plateforme, nommée par un identifiant court.
 * L'identifiant de la plateforme fait partie de l'identité de l'appelant (propriétaire des paniers et des sessions,
 * espace des clés d'idempotence) : deux plateformes ne voient jamais les données l'une de l'autre.
 *
 * Stockage : UCPBRIDGE_API_KEYS = {"<id>": {"label": "...", "key": "ucpk_...", "date_add": "..."}}.
 * La clé unique des versions précédentes (UCPBRIDGE_API_KEY) devient la plateforme « default », dont l'identité reste
 * « platform » : les paniers et sessions créés avant la migration restent accessibles.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeApiKeys
{
    const CONFIG = 'UCPBRIDGE_API_KEYS';
    /** Clé unique des versions 0.1.2 et antérieures, migrée vers la plateforme DEFAULT_ID. */
    const LEGACY = 'UCPBRIDGE_API_KEY';
    const PREFIX = 'ucpk_';
    const DEFAULT_ID = 'default';
    const DEFAULT_LABEL = 'Plateforme par défaut';

    /* ------------------------------ Logique pure (testée sans PrestaShop) ------------------------------ */

    /** Identifiant de plateforme : minuscules, chiffres, tiret, souligné, 32 caractères au plus. */
    public static function isValidId($id)
    {
        return is_string($id) && 1 === preg_match('/^[a-z0-9][a-z0-9_-]{0,31}\z/', $id);
    }

    public static function generate()
    {
        return self::PREFIX . bin2hex(random_bytes(24));
    }

    /**
     * Plateforme à qui appartient la clé présentée (null si aucune). Toutes les clés sont comparées en temps constant,
     * sans sortie anticipée.
     */
    public static function match(array $keys, $presented)
    {
        $presented = (string) $presented;
        $found = null;
        if (0 !== strpos($presented, self::PREFIX)) {
            return null;
        }
        foreach ($keys as $id => $k) {
            $key = (string) ($k['key'] ?? '');
            if ('' !== $key && hash_equals($key, $presented) && null === $found) {
                $found = (string) $id;
            }
        }

        return $found;
    }

    /** Identité de l'appelant invité : « platform » pour la plateforme par défaut (compatibilité), sinon « platform:<id> ». */
    public static function callerId($platformId)
    {
        return self::DEFAULT_ID === (string) $platformId ? 'platform' : 'platform:' . $platformId;
    }

    /** Ajoute la clé unique des versions précédentes comme plateforme par défaut, si elle n'y est pas déjà. */
    public static function migrate(array $keys, $legacy, $now)
    {
        $legacy = (string) $legacy;
        if ('' === $legacy || isset($keys[self::DEFAULT_ID]) || null !== self::match($keys, $legacy)) {
            return $keys;
        }

        return [self::DEFAULT_ID => ['label' => self::DEFAULT_LABEL, 'key' => $legacy, 'date_add' => (string) $now]] + $keys;
    }

    /* ------------------------------ Configuration de la boutique ------------------------------ */

    public static function all()
    {
        $keys = json_decode((string) Configuration::get(self::CONFIG), true);
        $keys = is_array($keys) ? $keys : [];
        $legacy = (string) Configuration::get(self::LEGACY);
        if ('' !== $legacy) {
            // Migration à la première lecture (module mis à jour sans réinstallation).
            $keys = self::migrate($keys, $legacy, date('Y-m-d H:i:s'));
            self::save($keys);
            Configuration::deleteByName(self::LEGACY);
        }

        return $keys;
    }

    private static function save(array $keys)
    {
        return Configuration::updateValue(self::CONFIG, json_encode((object) $keys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Plateforme authentifiée par la clé présentée, ou null. */
    public static function authenticate($presented)
    {
        return self::match(self::all(), $presented);
    }

    /** Nouvelle plateforme : renvoie sa clé, ou null si l'identifiant est invalide ou déjà pris. */
    public static function add($id, $label)
    {
        $keys = self::all();
        if (!self::isValidId($id) || isset($keys[$id])) {
            return null;
        }
        $key = self::generate();
        $keys[$id] = ['label' => mb_substr(trim((string) $label), 0, 64) ?: $id, 'key' => $key, 'date_add' => date('Y-m-d H:i:s')];

        return self::save($keys) ? $key : null;
    }

    /** Nouvelle clé pour une plateforme existante (même identité : ses paniers et sessions restent les siens). */
    public static function regenerate($id)
    {
        $keys = self::all();
        if (!isset($keys[$id])) {
            return null;
        }
        $keys[$id]['key'] = self::generate();

        return self::save($keys) ? $keys[$id]['key'] : null;
    }

    public static function remove($id)
    {
        $keys = self::all();
        unset($keys[$id]);

        return self::save($keys);
    }
}
