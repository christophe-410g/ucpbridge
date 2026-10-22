<?php
/**
 * Contrat d'un adaptateur de paiement UCP. La caisse ne connaît aucun prestataire :
 * elle cherche l'adaptateur qui supporte le gestionnaire (handler) choisi par l'agent,
 * lui confie le montant, et ne crée la commande PrestaShop que sur un paiement réussi.
 *
 * Un module de paiement tiers (Stripe officiel, Adyen, Mollie, PayPlug...) fournit son
 * propre adaptateur en se greffant sur le hook actionUcpBridgeRegisterPaymentProviders
 * (voir README).
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

interface UcpBridgePaymentProvider
{
    /** Identifiant court de l'adaptateur (journal, configuration). */
    public function getName();

    /**
     * Gestionnaires annoncés dans /.well-known/ucp et dans les réponses de session,
     * au format UCP : [nom_du_handler => [[id, version, config...]]]. Vide = adaptateur inactif.
     */
    public function handlers();

    /** L'adaptateur traite-t-il ce gestionnaire (nom UCP + id d'instance choisi par l'agent) ? */
    public function supports($handlerName, $handlerId);

    /**
     * Débite le moyen de paiement.
     *
     * @param array  $credential  objet credential de l'instrument UCP ({type, token})
     * @param int    $amount      montant en centimes (unités mineures ISO 4217)
     * @param string $currency    code ISO 4217
     * @param array  $context     instrument complet, id_cart, session_id, idempotency_key, buyer
     *
     * @return UcpBridgePaymentResult
     */
    public function charge(array $credential, $amount, $currency, array $context);

    /**
     * Rembourse un débit réussi (appelé automatiquement si la commande ne peut pas être créée après le débit).
     *
     * @param string $transactionId référence renvoyée par charge()
     * @param int    $amount        montant en centimes
     * @param string $currency      code ISO 4217
     * @param array  $context       session_id
     *
     * @return UcpBridgePaymentResult succeeded + référence du remboursement, ou failed + message
     */
    public function refund($transactionId, $amount, $currency, array $context);
}

/** Résultat normalisé d'un débit : succès, échec ou action requise (3-D Secure). */
class UcpBridgePaymentResult
{
    const SUCCEEDED = 'succeeded';
    const FAILED = 'failed';
    const REQUIRES_ACTION = 'requires_action';

    public $status;
    /** Référence de transaction du prestataire (ex. ch_... ou pi_...) */
    public $transactionId = '';
    /** Libellé du paiement inscrit sur la commande PrestaShop */
    public $label = '';
    /** Message lisible en cas d'échec */
    public $message = '';
    /** URL de l'étape d'authentification forte, si requires_action */
    public $actionUrl = '';
    /** Données brutes utiles au journal (jamais renvoyées à l'agent) */
    public $raw = [];

    public static function ok($transactionId, $label, array $raw = [])
    {
        $r = new self();
        $r->status = self::SUCCEEDED;
        $r->transactionId = (string) $transactionId;
        $r->label = (string) $label;
        $r->raw = $raw;

        return $r;
    }

    public static function fail($message, array $raw = [])
    {
        $r = new self();
        $r->status = self::FAILED;
        $r->message = (string) $message;
        $r->raw = $raw;

        return $r;
    }

    public static function action($transactionId, $actionUrl, array $raw = [])
    {
        $r = new self();
        $r->status = self::REQUIRES_ACTION;
        $r->transactionId = (string) $transactionId;
        $r->actionUrl = (string) $actionUrl;
        $r->raw = $raw;

        return $r;
    }
}

/**
 * Registre des adaptateurs. Les adaptateurs du module sont inscrits d'office, puis le hook
 * actionUcpBridgeRegisterPaymentProviders reçoit ['registry' => $this] pour en ajouter.
 */
class UcpBridgePaymentRegistry
{
    /** @var UcpBridgePaymentProvider[] */
    private $providers = [];

    public function register(UcpBridgePaymentProvider $p)
    {
        $this->providers[$p->getName()] = $p;
    }

    public function all()
    {
        return $this->providers;
    }

    public function handlers()
    {
        $h = [];
        foreach ($this->providers as $p) {
            foreach ((array) $p->handlers() as $name => $list) {
                $h[$name] = array_merge($h[$name] ?? [], $list);
            }
        }

        return $h;
    }

    /** Adaptateur pour l'id d'instance choisi par l'agent (null si aucun ou gestionnaire non annoncé). */
    public function find($handlerId)
    {
        foreach ($this->providers as $p) {
            foreach ((array) $p->handlers() as $name => $list) {
                foreach ($list as $decl) {
                    if (($decl['id'] ?? null) === $handlerId && $p->supports($name, $handlerId)) {
                        return $p;
                    }
                }
            }
        }

        return null;
    }

    private static $instance;

    public static function get()
    {
        if (!self::$instance) {
            $r = new self();
            $r->register(new UcpBridgeMockProvider());
            $r->register(new UcpBridgeStripeProvider());
            Hook::exec('actionUcpBridgeRegisterPaymentProviders', ['registry' => $r], null, true);
            self::$instance = $r;
        }

        return self::$instance;
    }
}
