<?php
/**
 * Coeur métier : panier UCP transférable (dev.ucp.shopping.cart), caisse native
 * (dev.ucp.shopping.checkout + fulfillment), commande (dev.ucp.shopping.order).
 * Chaque session UCP s'appuie sur un vrai panier PrestaShop (ps_cart) : prix, TVA,
 * transporteurs et stock sont ceux de la boutique, sans recalcul parallèle.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBridgeCart
{
    const TTL = 86400;
    /** Statuts : voir UcpBridgeSessionStore (constantes reprises ici pour les appelants existants). */
    const LOCKED = UcpBridgeSessionStore::LOCKED;
    const TERMINAL = UcpBridgeSessionStore::TERMINAL;
    const BUSY = UcpBridgeSessionStore::BUSY;
    const EDITABLE = UcpBridgeSessionStore::EDITABLE;

    /** @var Context */
    private $context;
    /** @var Ucpbridge */
    private $module;
    /** @var UcpBridgeCatalog */
    private $catalog;
    /** @var UcpBridgeCaller */
    private $caller;
    /** @var UcpBridgeSessionStore */
    private $store;
    /** @var UcpBridgeCartLines */
    private $lines;
    /** @var UcpBridgeBuyer */
    private $buyer;
    /** @var UcpBridgeFulfillment */
    private $fulfillment;
    /** @var UcpBridgeCheckoutStatus */
    private $status;
    /** @var UcpBridgeCheckoutView */
    private $view;
    /** @var UcpBridgeCheckoutCompletion */
    private $completion;

    public function __construct(Context $context, Ucpbridge $module)
    {
        $this->context = $context;
        $this->module = $module;
        $this->catalog = new UcpBridgeCatalog($context);
        $this->caller = new UcpBridgeCaller();
        $this->store = new UcpBridgeSessionStore($this->caller);
        $this->lines = new UcpBridgeCartLines($context, $this->catalog);
        $this->buyer = new UcpBridgeBuyer($context, $this->caller);
        $this->fulfillment = new UcpBridgeFulfillment($context, $this->lines);
        $this->status = new UcpBridgeCheckoutStatus($module, $this->catalog, $this->fulfillment);
        $this->view = new UcpBridgeCheckoutView($context, $module, $this->lines, $this->fulfillment, $this->status);
        $this->completion = new UcpBridgeCheckoutCompletion($context, $module, $this->caller, $this->store, $this->lines, $this->buyer,
            $this->status, $this->view);
    }

    public function catalog()
    {
        return $this->catalog;
    }

    /* ======================= Gestionnaires de paiement ======================= */

    /** Gestionnaires annoncés : ceux des adaptateurs inscrits au registre (module + modules tiers). */
    public static function paymentHandlers()
    {
        return UcpBridgePaymentRegistry::get()->handlers();
    }

    /* ====================== Appelant (identity linking, plateforme) ====================== */

    public function setUser($idCustomer)
    {
        $this->caller->idUser = (int) $idCustomer;
    }

    public function setAgent($agent)
    {
        $this->caller->agent = substr(trim((string) $agent), 0, 512);
    }

    public function setCaller($owner)
    {
        $this->caller->owner = (string) $owner;
    }

    /** Cloisonnement strict par appelant (voir UcpBridgeCaller::owns). */
    public function ownedByCaller(array $s)
    {
        return $this->caller->owns($s);
    }

    public static function isTerminal($status)
    {
        return UcpBridgeSessionStore::isTerminal($status);
    }

    public static function isBusy($status)
    {
        return UcpBridgeSessionStore::isBusy($status);
    }

    public static function exposedStatus($status)
    {
        return UcpBridgeSessionStore::exposedStatus($status);
    }

    /* ============================ Stockage sessions ============================ */

    /** @return array [lignes résolues, messages d'erreur] */
    public function resolveLines($lineItems)
    {
        return $this->lines->resolveLines($lineItems);
    }

    public function load($id, $kind)
    {
        return $this->store->load($id, $kind);
    }

    public function loadByOrderRef($ref)
    {
        return $this->store->loadByOrderRef($ref);
    }

    /* ============================== Lignes d'articles ============================== */

    /* ================================== Paniers ================================== */

    private function cartEnvelope()
    {
        return UcpBridgeMessage::envelope(['dev.ucp.shopping.cart', 'dev.ucp.shopping.checkout', 'dev.ucp.shopping.discount'],
            Ucpbridge::UCP_VERSION);
    }

    public function errorDoc(array $messages, array $capabilities)
    {
        return UcpBridgeMessage::errorDoc($messages, $capabilities, Ucpbridge::UCP_VERSION, $this->context->link->getPageLink('index', true));
    }

    public function createCart(array $body)
    {
        list($lines, $errors) = $this->lines->resolveLines($body['line_items'] ?? null);
        if ($errors) {
            return [200, $this->errorDoc($errors, ['dev.ucp.shopping.cart'])];
        }
        $cart = $this->lines->newPsCart();
        if (!$this->lines->fillCart($cart, $lines)) {
            return [200, $this->errorDoc([UcpBridgeMessage::error('out_of_stock', 'Impossible d\'ajouter les articles au panier', 'unrecoverable')], ['dev.ucp.shopping.cart'])];
        }
        if ($this->caller->idUser) {
            $cart->id_customer = $this->caller->idUser;
            $cart->secure_key = (new Customer($this->caller->idUser))->secure_key;
            $cart->update();
        }
        $s = [
            'id_session' => 'cart_' . bin2hex(random_bytes(10)),
            'kind' => 'cart',
            'owner' => $this->caller->owner,
            'id_cart' => (int) $cart->id,
            'status' => 'active',
            'payload' => ['lines' => $lines, 'context' => $body['context'] ?? null, 'buyer' => $body['buyer'] ?? null,
                'user_customer' => $this->caller->idUser],
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL),
        ];
        $this->lines->bindContext($cart);
        $msgs = $this->discounts()->sync($s, $cart, $body['discounts']['codes'] ?? [], false);
        $this->store->save($s);

        return [201, $this->cartDoc($s, $msgs)];
    }

    /** Détail d'un produit (get_product) : produit et toutes ses variantes. */
    public function productDoc($id)
    {
        $idProduct = $this->catalog->productIdFor($id);
        if (!$idProduct) {
            return $this->errorDoc([UcpBridgeMessage::error('not_found', 'Produit introuvable : ' . $id, 'unrecoverable', '$.id')], ['dev.ucp.shopping.catalog.search']);
        }

        return [
            'ucp' => UcpBridgeMessage::envelope(['dev.ucp.shopping.catalog.search'], Ucpbridge::UCP_VERSION),
            'product' => $this->catalog->productView($idProduct),
        ];
    }

    public function discounts()
    {
        return new UcpBridgeDiscount($this->context);
    }

    public function cartDoc(array $s, $msgs = null)
    {
        if ('handed_off' === $s['status']) {
            return $this->errorDoc([self::handedOffMsg()], ['dev.ucp.shopping.cart']);
        }
        if ('active' !== $s['status'] && 'updating' !== $s['status']) {
            return $this->errorDoc([UcpBridgeMessage::error('not_found', 'Panier expiré ou annulé', 'unrecoverable')], ['dev.ucp.shopping.cart']);
        }
        $cart = new Cart((int) $s['id_cart']);
        $this->lines->bindContext($cart);
        $lines = $this->lines->renderLines($cart, $s['payload']['lines']);
        $totals = UcpBridgeTotals::cart(UcpBridgeCartLines::cents($cart->getOrderTotal(true, Cart::ONLY_PRODUCTS)),
            UcpBridgeCartLines::cents($cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING)));
        $exp = strtotime($s['expires_at']);
        $doc = [
            'ucp' => $this->cartEnvelope(),
            'id' => $s['id_session'],
            'line_items' => $lines,
            'currency' => $this->context->currency->iso_code,
            'totals' => $totals,
            'discounts' => $this->discounts()->render($s, $cart, true, false),
            // Lien signé (HMAC, 24 h) : rattache CE panier PrestaShop à la session du visiteur.
            'continue_url' => $this->module->buildContinueUrl((int) $s['id_cart'], max(60, $exp - time())),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $exp),
            'links' => $this->view->links(),
        ];
        if (!empty($s['payload']['buyer'])) {
            $doc['buyer'] = $s['payload']['buyer'];
        }
        $msgs = null === $msgs ? ($s['payload']['discount_messages'] ?? []) : $msgs;
        $msgs = array_merge(array_values($msgs), $this->view->disclosures($s['payload']['context']['address_country'] ?? null));
        if ($msgs) {
            $doc['messages'] = array_values($msgs);
        }

        return $doc;
    }

    private static function handedOffMsg()
    {
        return UcpBridgeMessage::error('checkout_handed_off', 'L\'acheteur a repris ce panier sur la boutique (lien de reprise ouvert) : '
            . 'il ne peut plus être modifié par l\'API', 'unrecoverable');
    }

    public function updateCart(array $s, array $body)
    {
        if ('handed_off' === $s['status']) {
            return [409, $this->cartDoc($s)];
        }
        if ('active' !== $s['status'] && 'updating' !== $s['status']) {
            return [200, $this->cartDoc($s)];
        }
        list($lines, $errors) = $this->lines->resolveLines($body['line_items'] ?? null);
        if ($errors) {
            return [200, $this->errorDoc($errors, ['dev.ucp.shopping.cart'])];
        }
        // Réservation AVANT de toucher au panier PrestaShop : le lien de reprise (handoff) et une autre mise à jour
        // ne peuvent plus s'intercaler. Refus 409 si la session est déjà réservée ou sortie de l'API.
        if (!$this->store->reserve($s['id_session'], ['active'], 'updating')) {
            $cur = $this->load($s['id_session'], 'cart') ?: $s;
            if ('handed_off' === $cur['status']) {
                return [409, $this->cartDoc($cur)];
            }

            return [409, $this->errorDoc([UcpBridgeMessage::error('not_modifiable', 'Panier en cours de modification : réessayer', 'recoverable')], ['dev.ucp.shopping.cart'])];
        }
        $s = $this->load($s['id_session'], 'cart');
        $s['status'] = 'active';
        try {
            $cart = new Cart((int) $s['id_cart']);
            if ($cart->orderExists()) {
                $this->store->save($s, ['updating']);

                return [200, $this->errorDoc([UcpBridgeMessage::error('not_found', 'Ce panier a déjà donné lieu à une commande', 'unrecoverable')], ['dev.ucp.shopping.cart'])];
            }
            $this->lines->fillCart($cart, $lines);
            $s['payload']['lines'] = $lines;
            if (isset($body['buyer'])) {
                $s['payload']['buyer'] = $body['buyer'];
            }
            $this->lines->bindContext($cart);
            $msgs = $this->discounts()->sync($s, $cart, $body['discounts']['codes'] ?? null, false);
        } catch (Throwable $e) {
            $this->store->save($s, ['updating']);
            throw $e;
        }
        $this->store->save($s, ['updating']);

        return [200, $this->cartDoc($s, $msgs)];
    }

    public function cancelCart(array $s)
    {
        $s['status'] = 'canceled';
        $this->store->save($s);

        return [200, $this->errorDoc([UcpBridgeMessage::error('canceled', 'Panier annulé', 'unrecoverable')], ['dev.ucp.shopping.cart'])];
    }

    /* =============================== Caisse native =============================== */

    public function createCheckout(array $body)
    {
        $lineItems = $body['line_items'] ?? null;
        if (!$lineItems && !empty($body['cart_id'])) {
            // Conversion d'un panier UCP existant en session de caisse.
            $c = $this->load((string) $body['cart_id'], 'cart');
            if (!$c || 'active' !== $c['status'] || !$this->ownedByCaller($c)) {
                return [404, $this->errorDoc([UcpBridgeMessage::error('not_found', 'Panier introuvable', 'unrecoverable', '$.cart_id')], ['dev.ucp.shopping.checkout'])];
            }
            $lineItems = array_map(function ($l) {
                return ['id' => $l['id'], 'item' => array_filter(['id' => $l['requested_id'], 'variant_id' => $l['requested_variant_id']]), 'quantity' => $l['quantity']];
            }, $c['payload']['lines']);
            if (!isset($body['discounts']) && !empty($c['payload']['discount_codes'])) {
                $body['discounts'] = ['codes' => $c['payload']['discount_codes']];
            }
        }
        list($lines, $errors) = $this->lines->resolveLines($lineItems);
        if ($errors) {
            return [200, $this->errorDoc($errors, ['dev.ucp.shopping.checkout'])];
        }
        $cart = $this->lines->newPsCart();
        if (!$this->lines->fillCart($cart, $lines)) {
            return [200, $this->errorDoc([UcpBridgeMessage::error('out_of_stock', 'Impossible d\'ajouter les articles', 'unrecoverable')], ['dev.ucp.shopping.checkout'])];
        }
        $s = [
            'id_session' => 'chk_' . bin2hex(random_bytes(10)),
            'kind' => 'checkout',
            'owner' => $this->caller->owner,
            'id_cart' => (int) $cart->id,
            'status' => 'incomplete',
            'payload' => ['lines' => $lines, 'discount_codes' => []],
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL),
        ];
        if ($this->caller->idUser) {
            $this->buyer->attachUser($s, $cart);
        }
        if (!isset($body['discounts'])) {
            $body['discounts'] = ['codes' => []];
        }
        $msgs = $this->applyUpdate($s, $cart, $body, false);
        $this->store->save($s);

        return [201, $this->view->checkoutDoc($s, $msgs)];
    }

    public function updateCheckout(array $s, array $body)
    {
        if (self::isTerminal($s['status'])) {
            return [200, $this->view->checkoutDoc($s, [UcpBridgeMessage::error('not_modifiable', 'Session ' . self::exposedStatus($s['status']) . ' : modification impossible', 'unrecoverable')])];
        }
        // Réservation AVANT toute modification du panier PrestaShop (même statut que celui lu : une écriture concurrente
        // fait échouer la réservation). Une session réservée (finalisation, autre mise à jour, rapprochement) est refusée
        // en 409 avec son état réel : jamais de totaux d'une mise à jour non enregistrée.
        $prev = $s['status'];
        if (self::isBusy($prev) || !$this->store->reserve($s['id_session'], [$prev], 'updating')) {
            $cur = $this->load($s['id_session'], 'checkout') ?: $s;
            if (self::isTerminal($cur['status'])) {
                return [200, $this->view->checkoutDoc($cur, [UcpBridgeMessage::error('not_modifiable', 'Session ' . self::exposedStatus($cur['status']) . ' : modification impossible', 'unrecoverable')])];
            }

            return [409, $this->view->checkoutDoc($cur, [UcpBridgeMessage::error('not_modifiable', 'Session réservée (finalisation ou mise à jour en cours) : modification refusée', 'recoverable')])];
        }
        $s = $this->load($s['id_session'], 'checkout');
        $s['status'] = $prev;
        try {
            $cart = new Cart((int) $s['id_cart']);
            $msgs = $this->applyUpdate($s, $cart, $body, true);
        } catch (Throwable $e) {
            $s['status'] = $prev;
            $this->store->save($s, ['updating']);
            throw $e;
        }
        $this->store->save($s, ['updating']);

        return [200, $this->view->checkoutDoc($s, $msgs)];
    }

    /** Applique line_items, buyer et fulfillment. Retourne les messages d'erreur de saisie. */
    private function applyUpdate(array &$s, Cart $cart, array $body, $replaceLines)
    {
        $msgs = [];
        $this->lines->bindContext($cart);
        if ($replaceLines && isset($body['line_items'])) {
            list($lines, $errors) = $this->lines->resolveLines($body['line_items']);
            if ($errors) {
                $msgs = array_merge($msgs, $errors);
            } else {
                $this->lines->fillCart($cart, $lines);
                $s['payload']['lines'] = $lines;
            }
        }
        if (isset($body['buyer']['consent'])) {
            // buyer_consent : seuls les booléens explicites sont retenus, jamais de valeur présumée.
            $s['payload']['consent'] = UcpBridgeConsent::normalize($body['buyer']['consent']);
        }
        if (isset($body['buyer']) && is_array($body['buyer']) && !empty($s['payload']['user_customer'])) {
            // Client authentifié : l'identité vient du compte PrestaShop, seul le téléphone est repris.
            if (!empty($body['buyer']['phone_number'])) {
                $s['payload']['buyer']['phone_number'] = (string) $body['buyer']['phone_number'];
            }
        } elseif (isset($body['buyer']) && is_array($body['buyer'])) {
            $b = $body['buyer'];
            if (!empty($b['email']) && !Validate::isEmail($b['email'])) {
                $msgs[] = UcpBridgeMessage::error('invalid', 'Adresse e-mail invalide', 'recoverable', '$.buyer.email');
                unset($b['email']);
            }
            $s['payload']['buyer'] = array_merge($s['payload']['buyer'] ?? [], array_intersect_key($b, array_flip(['email', 'first_name', 'last_name', 'phone_number'])));
            $this->buyer->syncCustomer($s, $cart);
        }
        $method = $body['fulfillment']['methods'][0] ?? null;
        if (is_array($method)) {
            $dests = $method['destinations'] ?? null;
            if (is_array($dests) && $dests) {
                $selected = $method['selected_destination_id'] ?? null;
                $dest = $dests[0];
                foreach ($dests as $d) {
                    if ($selected && ($d['id'] ?? null) === $selected) {
                        $dest = $d;
                    }
                }
                $dest['id'] = $dest['id'] ?? 'dest_1';
                // Adresse du carnet renvoyée telle quelle par l'agent : rien à changer.
                $unchanged = !empty($s['payload']['address_saved']) && $dest['id'] === ($s['payload']['destination']['id'] ?? null);
                $err = $unchanged ? null : $this->fulfillment->applyDestination($s, $cart, $dest);
                if ($err) {
                    $msgs[] = $err;
                }
            }
            $opt = $method['groups'][0]['selected_option_id'] ?? null;
            if ($opt) {
                $err = $this->fulfillment->applyCarrier($s, $cart, (string) $opt);
                if ($err) {
                    $msgs[] = $err;
                }
            }
        }
        // Codes promo en dernier : les règles peuvent dépendre du pays et du transporteur.
        $codes = isset($body['discounts']) ? ($body['discounts']['codes'] ?? []) : null;
        $msgs = array_merge($msgs, $this->discounts()->sync($s, $cart, $codes, !empty($s['payload']['id_carrier'])));
        $s['status'] = $this->status->computeStatus($s, $cart);

        return $msgs;
    }

    /** Acceptation des CGV sur la page du module : date, version, IP ; la session redevient finalisable. */
    public function acceptTerms(array $s, $ip, $userAgent)
    {
        $t = $this->module->termsInfo();
        $s['payload']['terms_acceptance'] = [
            'accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => $t['version'],
            'id_cms' => $t['id'],
            'ip' => (string) $ip,
            'user_agent' => substr((string) $userAgent, 0, 255),
        ];
        $cart = new Cart((int) $s['id_cart']);
        $this->lines->bindContext($cart);
        $s['status'] = $this->status->computeStatus($s, $cart);
        $this->store->save($s);

        return $s;
    }

    public function checkoutDoc(array $s, array $extraMsgs = [])
    {
        return $this->view->checkoutDoc($s, $extraMsgs);
    }

    /**
     * Rapprochement des sessions bloquées depuis plus de $minutes minutes (voir UcpBridgeReconciler).
     *
     * @return array liste de [id_session, action]
     */
    public function reconcile($minutes = 15)
    {
        return (new UcpBridgeReconciler($this->store, $this->lines, $this->status, $this->completion))->reconcile($minutes);
    }

    /** Finalisation : voir UcpBridgeCheckoutCompletion. */
    public function completeCheckout(array $s, array $body)
    {
        return $this->completion->completeCheckout($s, $body);
    }

    public function cancelCheckout(array $s)
    {
        if (!in_array($s['status'], self::LOCKED, true)) {
            $s['status'] = 'canceled';
            if (!$this->store->save($s)) {
                $s = $this->load($s['id_session'], 'checkout');
            }
        }
        $busy = 'completed' === $s['status'] || self::isBusy($s['status']);

        return [200, $this->view->checkoutDoc($s, $busy ? [UcpBridgeMessage::error('not_modifiable', 'completed' === $s['status'] ? 'Commande déjà passée'
            : ('updating' === $s['status'] ? 'Mise à jour en cours' : 'Finalisation en cours'), 'unrecoverable')] : [])];
    }

    /* ========================== Rapprochement des sessions bloquées ========================== */

    /* ================================== Commandes ================================== */

    /* ================================== Commandes ================================== */

    /** @return array [code HTTP, document] */
    public function orderDoc($ref)
    {
        return (new UcpBridgeOrderView($this->context, $this->caller, $this->store, $this->view))->orderDoc($ref);
    }
}
