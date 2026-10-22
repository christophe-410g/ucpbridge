<?php
/**
 * Relecture des CGV chez le marchand (option) : page signée ouverte par le continue_url d'une session
 * en requires_buyer_review. Récapitulatif, CGV, case « J'ai lu et j'accepte les CGV », puis enregistrement
 * de l'acceptation (date, version des CGV, IP) : la session redevient finalisable par l'agent.
 *
 * Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpbridgeTermsModuleFrontController extends ModuleFrontController
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
        if (!$this->module->checkSigned('terms', ['session'])) {
            UcpBridgePage::render('Lien invalide', '<p class="err">Lien invalide ou expiré : revenez à l\'assistant pour obtenir un nouveau lien.</p>', 403);
        }
        $sid = (string) Tools::getValue('session');
        $svc = new UcpBridgeCart($this->context, $this->module);
        $s = $svc->load($sid, 'checkout');
        if (!$s || UcpBridgeCart::isTerminal($s['status']) || UcpBridgeCart::isBusy($s['status'])) {
            UcpBridgePage::render('Session terminée', '<p class="err">Cette commande est déjà passée ou annulée.</p>', 410);
        }
        $error = '';
        if ('POST' === $_SERVER['REQUEST_METHOD']) {
            if (!$this->module->checkFormToken($this->context->cookie, 'terms|' . $sid, Tools::getValue('csrf'))) {
                $error = 'Le formulaire a expiré : rechargez la page.';
            } elseif (!Tools::getValue('accept_terms')) {
                $error = 'Veuillez cocher la case pour accepter les conditions générales de vente.';
            } else {
                $svc->acceptTerms($s, Tools::getRemoteAddr(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
                UcpBridgePage::render('Merci', '<p class="ok">Votre acceptation des conditions générales de vente est enregistrée.</p>'
                    . '<p>Vous pouvez revenir à votre assistant pour finaliser la commande.</p>');
            }
        } elseif (!empty($s['payload']['terms_acceptance'])) {
            UcpBridgePage::render('Déjà acceptées', '<p class="ok">Votre acceptation des CGV est déjà enregistrée. Revenez à votre assistant pour finaliser la commande.</p>');
        }

        $doc = $svc->checkoutDoc($s);
        $terms = $this->module->termsInfo();
        $rows = '';
        foreach ($doc['line_items'] as $li) {
            $rows .= '<tr><td>' . (int) $li['quantity'] . ' × ' . $e($li['item']['title']) . '</td><td class="r">'
                . $e(UcpBridgePage::euros($li['item']['price'] * $li['quantity'])) . ' TTC</td></tr>';
        }
        foreach ($doc['totals'] as $t) {
            $rows .= '<tr class="' . ('total' === $t['type'] ? 'total' : '') . '"><td>' . $e($t['display_text'] ?? $t['type']) . '</td><td class="r">'
                . $e(UcpBridgePage::euros($t['amount'])) . '</td></tr>';
        }
        $links = '';
        foreach (array_unique(array_column($doc['links'], 'title', 'url')) as $title) {
            $links .= '<li>' . $e($title) . '</li>';
        }
        $action = $e($_SERVER['REQUEST_URI'] ?? '');
        $body = ($error ? '<p class="err">' . $e($error) . '</p>' : '')
            . '<h2>Récapitulatif de votre commande</h2><table>' . $rows . '</table>'
            . '<h2>' . $e($terms['title']) . '</h2>'
            // Contenu de la page CMS saisi par le marchand dans son back-office (même rendu que la boutique).
            . '<div class="terms">' . $terms['content'] . '</div>'
            . '<p class="note">Version ' . $e($terms['version']) . '. Documents applicables : </p><ul class="note">' . $links . '</ul>'
            . '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="csrf" value="' . $e($this->module->formToken($this->context->cookie, 'terms|' . $sid)) . '">'
            . '<label class="check"><input type="checkbox" name="accept_terms" value="1"> J\'ai lu et j\'accepte les CGV</label>'
            . '<button type="submit">Enregistrer mon acceptation</button></form>';
        UcpBridgePage::render('Conditions générales de vente', $body);
    }
}
