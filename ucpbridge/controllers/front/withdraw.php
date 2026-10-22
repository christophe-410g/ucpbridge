<?php
/**
 * Fonction de rétractation en ligne : page signée « Se rétracter de cette commande », ouverte depuis l'e-mail
 * de confirmation. Enregistre la demande (une par commande) et envoie un accusé de réception par e-mail
 * (support durable).
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpbridgeWithdrawModuleFrontController extends ModuleFrontController
{
    public $ssl = false;
    protected $content_only = true;

    /** @var Ucpbridge */
    public $module;

    public function display()
    {
        return true;
    }

    public function initContent()
    {
        $e = ['UcpBridgePage', 'e'];
        $order = new Order((int) Tools::getValue('order'));
        if (!$this->module->checkSigned('withdraw', ['order']) || !Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            UcpBridgePage::render('Lien invalide', '<p class="err">Lien de rétractation invalide ou expiré. Contactez la boutique.</p>', 403);
        }
        $customer = new Customer((int) $order->id_customer);
        $ref = $e($order->reference);
        $done = UcpBridgeProof::withdrawal((int) $order->id);
        if ($done) {
            UcpBridgePage::render('Rétractation', '<p class="ok">Votre demande de rétractation pour la commande ' . $ref
                . ' a déjà été enregistrée le ' . $e($done['date_add']) . '.</p>');
        }
        $error = '';
        if ('POST' === $_SERVER['REQUEST_METHOD']) {
            if (!$this->module->checkFormToken($this->context->cookie, 'withdraw|' . (int) $order->id, Tools::getValue('csrf'))) {
                $error = 'Le formulaire a expiré : rechargez la page.';
            } else {
                if (UcpBridgeProof::recordWithdrawal((int) $order->id, $customer->email, Tools::getRemoteAddr())) {
                    $sent = $this->sendAcknowledgement($order, $customer);
                    if ($sent) {
                        UcpBridgeProof::markWithdrawalMailed((int) $order->id);
                    }
                }
                UcpBridgePage::render('Rétractation enregistrée', '<p class="ok">Votre demande de rétractation pour la commande ' . $ref . ' est enregistrée.</p>'
                    . '<p>Un accusé de réception vous a été envoyé par e-mail à ' . $e($customer->email) . '. La boutique vous indiquera les modalités '
                    . 'de retour des articles et vous remboursera au plus tard 14 jours après réception de votre demande.</p>');
            }
        }
        $rows = '';
        foreach ($order->getProducts() as $p) {
            $rows .= '<tr><td>' . (int) $p['product_quantity'] . ' × ' . $e($p['product_name']) . '</td><td class="r">'
                . $e(UcpBridgePage::euros((int) round($p['total_price_tax_incl'] * 100))) . ' TTC</td></tr>';
        }
        $rows .= '<tr class="total"><td>Total payé</td><td class="r">' . $e(UcpBridgePage::euros((int) round($order->total_paid_tax_incl * 100))) . '</td></tr>';
        $body = ($error ? '<p class="err">' . $e($error) . '</p>' : '')
            . '<p>Commande ' . $ref . ' du ' . $e(Tools::displayDate($order->date_add)) . ', au nom de ' . $e($customer->firstname . ' ' . $customer->lastname) . '.</p>'
            . '<table>' . $rows . '</table>'
            . '<p>Vous disposez de 14 jours à compter de la réception de votre commande pour vous rétracter, sans avoir à vous justifier. '
            . 'En confirmant, vous notifiez à la boutique votre décision de vous rétracter de ce contrat.</p>'
            . '<form method="post" action="' . $e($_SERVER['REQUEST_URI'] ?? '') . '">'
            . '<input type="hidden" name="csrf" value="' . $e($this->module->formToken($this->context->cookie, 'withdraw|' . (int) $order->id)) . '">'
            . '<button type="submit">Confirmer la rétractation</button></form>';
        UcpBridgePage::render('Se rétracter de cette commande', $body);
    }

    private function sendAcknowledgement(Order $order, Customer $customer)
    {
        $idLang = (int) $order->id_lang ?: (int) Configuration::get('PS_LANG_DEFAULT');

        return (bool) Mail::send($idLang, 'ucp_withdrawal', 'Accusé de réception de votre demande de rétractation',
            [
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{order_name}' => $order->reference,
                '{withdrawal_date}' => Tools::displayDate(date('Y-m-d H:i:s'), true),
            ],
            $customer->email, $customer->firstname . ' ' . $customer->lastname, null, null, null, null,
            _PS_MODULE_DIR_ . $this->module->name . '/mails/', false, (int) $order->id_shop);
    }
}
