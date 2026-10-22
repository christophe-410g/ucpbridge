<?php
/**
 * Transport MCP du service dev.ucp.shopping (JSON-RPC 2.0 sur HTTP streamable, réponses JSON).
 * Aucune logique métier ici : chaque outil est traduit vers la même route que l'API REST
 * (routeur injecté), puis la réponse UCP est renvoyée en structuredContent et en texte.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeMcp
{
    /** Versions du protocole MCP acceptées, la plus récente en premier. */
    const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
    const UCP_SCHEMAS = 'https://ucp.dev/2026-04-08/schemas/shopping/';

    /**
     * Outils UCP (binding MCP, mcp.openrpc.json) : méthode HTTP, chemin REST, objet de charge utile,
     * identifiant requis, Idempotency-Key requise.
     */
    const TOOLS = [
        'search_catalog' => ['POST', 'catalog/search', 'catalog', false, false, 'Recherche dans le catalogue (texte libre, catalog.query). Renvoie les produits avec leurs variantes, prix et disponibilité.'],
        'get_product' => ['POST', 'catalog/product', 'catalog', false, false, 'Détail d\'un produit par identifiant (catalog.id : référence produit, référence de déclinaison ou {id_produit}-{id_declinaison}), avec toutes ses variantes.'],
        'create_cart' => ['POST', 'carts', 'cart', false, true, 'Crée un panier transférable (cart.line_items). meta.idempotency-key obligatoire. Renvoie le panier et son continue_url : lien signé qui ouvre ce panier sur la boutique.'],
        'get_cart' => ['GET', 'carts/{id}', null, true, false, 'Lit un panier et son continue_url.'],
        'update_cart' => ['PUT', 'carts/{id}', 'cart', true, true, 'Remplace le contenu d\'un panier (line_items, discounts.codes).'],
        'cancel_cart' => ['POST', 'carts/{id}/cancel', null, true, true, 'Annule un panier.'],
        'create_checkout' => ['POST', 'checkout-sessions', 'checkout', false, true, 'Crée une session de caisse (line_items ou cart_id, buyer, fulfillment, discounts).'],
        'get_checkout' => ['GET', 'checkout-sessions/{id}', null, true, false, 'Lit une session de caisse : statut, totaux, livraison, liens légaux, mentions, continue_url.'],
        'update_checkout' => ['PUT', 'checkout-sessions/{id}', 'checkout', true, true, 'Met à jour une session de caisse (acheteur, adresse et transporteur, codes promo, consentements).'],
        'complete_checkout' => ['POST', 'checkout-sessions/{id}/complete', 'checkout', true, true, 'Finalise la session et passe la commande (checkout.payment.instruments). meta.idempotency-key obligatoire.'],
        'cancel_checkout' => ['POST', 'checkout-sessions/{id}/cancel', null, true, true, 'Annule une session de caisse. meta.idempotency-key obligatoire.'],
        'get_order' => ['GET', 'orders/{id}', null, true, false, 'Lit une commande passée via UCP.'],
    ];

    const SCHEMA_FILES = ['catalog' => 'catalog_search.json', 'cart' => 'cart.json', 'checkout' => 'checkout.json'];

    /** @var callable function ($method, $path, array $body, $idempotencyKey, $agent): [code HTTP, document UCP] */
    private $router;
    /** @var array */
    private $serverInfo;

    public function __construct(callable $router, array $serverInfo)
    {
        $this->router = $router;
        $this->serverInfo = $serverInfo;
    }

    private static function reply($id, $result)
    {
        return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]];
    }

    private static function error($id, $code, $message, $data = null, $status = 200)
    {
        $err = ['code' => (int) $code, 'message' => (string) $message];
        if (null !== $data) {
            $err['data'] = $data;
        }

        return ['status' => (int) $status, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err]];
    }

    public function handle($raw)
    {
        $req = json_decode((string) $raw, true);
        if (!is_array($req)) {
            return self::error(null, -32700, 'Parse error', null, 400);
        }
        if (array_keys($req) === range(0, count($req) - 1)) {
            return self::error(null, -32600, 'Les lots JSON-RPC ne sont pas pris en charge', null, 400);
        }
        $id = $req['id'] ?? null;
        $isNotification = !array_key_exists('id', $req);
        if ('2.0' !== ($req['jsonrpc'] ?? null) || !is_string($req['method'] ?? null)) {
            return self::error($id, -32600, 'Invalid Request', null, 400);
        }
        if ($isNotification) {
            return ['status' => 202, 'body' => null];
        }
        $params = is_array($req['params'] ?? null) ? $req['params'] : [];
        switch ($req['method']) {
            case 'initialize':
                $asked = (string) ($params['protocolVersion'] ?? '');

                return self::reply($id, [
                    'protocolVersion' => in_array($asked, self::PROTOCOL_VERSIONS, true) ? $asked : self::PROTOCOL_VERSIONS[0],
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => $this->serverInfo,
                    'instructions' => 'Boutique UCP (Universal Commerce Protocol 2026-04-08). Chaque outil exige arguments.meta["ucp-agent"].profile. '
                        . 'Les déclinaisons sont obligatoires : si un produit en a, précisez la variante (sinon variant_required). '
                        . 'Les outils qui modifient l\'état (create_*, update_*, cancel_*, complete_checkout) exigent meta["idempotency-key"]. '
                        . 'create_cart renvoie un continue_url à ouvrir par l\'acheteur ; une session de caisse dont le continue_url est ouvert '
                        . 'ne peut plus être finalisée par l\'API.',
                ]);
            case 'ping':
                return self::reply($id, new stdClass());
            case 'tools/list':
                return self::reply($id, ['tools' => $this->tools()]);
            case 'tools/call':
                return $this->call($id, $params);
        }

        return self::error($id, -32601, 'Method not found: ' . $req['method']);
    }

    public function tools()
    {
        $out = [];
        foreach (self::TOOLS as $name => $t) {
            list(, , $payload, $needsId, $needsIdem, $description) = $t;
            $meta = [
                'type' => 'object',
                'description' => 'Métadonnées UCP : ucp-agent.profile (URL du profil de la plateforme), idempotency-key (UUID)',
                'properties' => [
                    'ucp-agent' => ['type' => 'object', 'properties' => ['profile' => ['type' => 'string']], 'required' => ['profile']],
                    'idempotency-key' => ['type' => 'string'],
                ],
                'required' => $needsIdem ? ['ucp-agent', 'idempotency-key'] : ['ucp-agent'],
            ];
            $props = ['meta' => $meta];
            $required = ['meta'];
            if ($needsId) {
                $props['id'] = ['type' => 'string', 'description' => 'Identifiant de la ressource'];
                $required[] = 'id';
            }
            if ($payload) {
                $props[$payload] = ['type' => 'object', 'description' => 'Objet UCP, schéma ' . self::UCP_SCHEMAS . self::SCHEMA_FILES[$payload]];
                $required[] = $payload;
            }
            $out[] = [
                'name' => $name,
                'description' => $description,
                'inputSchema' => ['type' => 'object', 'properties' => $props, 'required' => $required],
                'outputSchema' => ['type' => 'object'],
            ];
        }

        return $out;
    }

    private function call($id, array $params)
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if (!isset(self::TOOLS[$name])) {
            return self::error($id, -32602, 'Outil inconnu : ' . $name);
        }
        list($method, $path, $payload, $needsId, $needsIdem) = self::TOOLS[$name];
        $meta = is_array($args['meta'] ?? null) ? $args['meta'] : [];
        $profile = $meta['ucp-agent']['profile'] ?? null;
        if (!is_string($profile) || '' === trim($profile)) {
            return self::error($id, -32602, 'meta["ucp-agent"].profile est obligatoire');
        }
        $idem = isset($meta['idempotency-key']) && is_string($meta['idempotency-key']) ? trim($meta['idempotency-key']) : '';
        if ($needsIdem && '' === $idem) {
            return self::error($id, -32602, 'meta["idempotency-key"] est obligatoire pour ' . $name);
        }
        if ($needsId) {
            $rid = $args['id'] ?? null;
            if (!is_scalar($rid) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $rid)) {
                return self::error($id, -32602, 'id absent ou invalide');
            }
            $path = str_replace('{id}', (string) $rid, $path);
        }
        $body = [];
        if ($payload) {
            $body = is_array($args[$payload] ?? null) ? $args[$payload] : [];
        }
        $agent = 'profile="' . str_replace(['"', '\\', "\r", "\n"], '', $profile) . '"';
        list($code, $doc) = call_user_func($this->router, $method, $path, $body, '' !== $idem ? $idem : null, $agent);

        if ($code >= 500) {
            return self::error($id, -32603, 'Erreur interne', $doc, 500);
        }
        if ($code >= 300) {
            // Erreurs protocolaires (authentification, conflit d'idempotence, ressource inconnue) : JSON-RPC error.
            return self::error($id, 400 === (int) $code ? -32602 : -32000, (string) ($doc['content'] ?? ($doc['code'] ?? 'Erreur')), $doc, $code);
        }

        return self::reply($id, [
            'structuredContent' => $doc,
            'content' => [['type' => 'text', 'text' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'isError' => 'error' === ($doc['ucp']['status'] ?? null),
        ]);
    }
}
