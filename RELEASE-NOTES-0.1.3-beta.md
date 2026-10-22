# UCP Bridge pour PrestaShop 0.1.3-beta

Publiée le 22 octobre 2026. Module `ucpbridge`, licence AFL-3.0, Friends of Presta (auteur : Christophe Vidal).

> **Toujours une bêta, toujours un prototype : ne pas l'utiliser en production.** Le module n'est pas validé par
> Google, Universal Cart n'est pas encore ouvert en Europe et le paiement réel n'a pas été éprouvé.

## En bref, pour un e-commerçant

La 0.1.2 était la première version publiée. La 0.1.3 la rend plus sûre, plus facile à vivre au quotidien et ouverte
à beaucoup plus de boutiques.

**Votre boutique est probablement compatible.** La 0.1.2 exigeait PrestaShop 9.1. La 0.1.3 fonctionne de
PrestaShop 8.0 à 9.2, avec PHP 8.1 ou plus récent. Si votre serveur tourne sur un PHP trop ancien, le module refuse
de s'installer et vous dit quelle version il a trouvée : rien n'est cassé, il suffit de demander à votre hébergeur de
mettre PHP à jour.

**Mettre à jour sans tout perdre.** Vous passez de la 0.1.2 à la 0.1.3 en téléversant la nouvelle archive dans le
gestionnaire de modules, sans désinstaller. Clés, réglages, paniers en cours, comptes agents connectés, commandes et
preuves sont conservés.

**Vos preuves restent à l'abri.** Le journal de preuve (acceptation des CGV, informations précontractuelles), les
demandes de rétractation et le journal des paiements ne sont plus effacés quand on désinstalle le module. Ils ne
partent que si vous le demandez explicitement, et vous pouvez les exporter en CSV (ouvrable dans Excel) depuis la
configuration du module. C'est ce qu'il vous faut en cas de litige avec un client.

**Le RGPD pris en charge.** Vous choisissez combien de temps garder chaque catégorie de données (paniers abandonnés,
comptes invités, connexions des agents, paiements, preuves). Un bouton « Simuler la purge » montre ce qui serait
supprimé avant de le faire, un autre purge pour de bon, et une commande peut être planifiée sur le serveur.

Ce que la purge garantit :

- aucune commande de votre boutique n'est jamais touchée, ni son client, ni ses adresses : un client qui a commandé
  n'est jamais supprimé ;
- un acheteur débité sans que sa commande ait pu être créée, et pas encore remboursé (ou dont le débit est
  incertain), reste dans la boutique avec sa session aussi longtemps que le journal des paiements : 10 ans par
  défaut. Vous savez toujours qui rembourser ;
- les pièces que la loi impose de garder 10 ans (journal des paiements, preuves d'acceptation des CGV, demandes de
  rétractation) ne peuvent pas être réglées en dessous de 3650 jours : une faute de frappe (365 au lieu de 3650) est
  refusée avec un message clair. 0 (ne jamais purger) reste possible. Si une durée plus courte se retrouve malgré
  tout dans la configuration, la purge refuse de tourner et ne supprime rien ;
- une session en cours de paiement n'est jamais touchée, et le panier abandonné d'un client avec compte reste à la
  boutique ;
- la simulation annonce exactement ce que la purge supprime, et le compte rendu (aussi consigné au journal
  PrestaShop) compte tout ce qui part, y compris les paniers et adresses d'un invité supprimé ;
- la purge reste légère sur une grosse boutique : les sessions sont lues par lots, sans relire chaque nuit celles qui
  sont déjà nettoyées.

**Une configuration qui ressemble à PrestaShop.** La page de configuration reprend les formulaires standards du
back-office : mêmes fonctions qu'avant, présentation familière, interrupteurs oui/non à la place des cases à cocher.

**En français et en anglais.** Back-office, pages du module, e-mails envoyés aux acheteurs et messages renvoyés aux
agents d'achat sont traduits. Chacun voit la langue qui le concerne : l'employé celle de son compte, l'acheteur celle
de sa commande.

**Plus sûr pour l'argent.** Cinq corrections touchent le paiement, la sécurité des comptes et le cloisonnement :

- un débit dont on ne connaît pas le résultat (délai dépassé, panne du prestataire) n'est plus pris pour un refus : la
  session est mise de côté pour vérification au lieu de laisser l'agent repayer ;
- un débit réussi n'est plus oublié si l'écriture du journal échoue juste après ;
- un panier modifié ou un nouvel essai après un refus n'est plus bloqué par Stripe ;
- un code de connexion OAuth ne peut plus être grillé par un autre client que son propriétaire ;
- chaque plateforme d'achat (agent) a sa propre clé API : les paniers, commandes et sessions d'une plateforme sont
  invisibles des autres.

## Installation et mise à jour

- **Nouvelle installation** : back-office > Modules > Gestionnaire de modules > Téléverser un module, archive
  `ucpbridge-0.1.3-beta.zip`.
- **Mise à jour depuis la 0.1.2** : téléverser la même archive, sans désinstaller. Ne jamais passer par une
  désinstallation : la 0.1.2 effaçait encore les preuves à la désinstallation.
- Version antérieure à la 0.1.2 (versions de test) : désinstaller, puis installer la 0.1.3.

## Partie technique, pour les intégrateurs

### Compatibilité éprouvée

Suite complète (installation fraîche, mise à jour depuis la 0.1.2, unitaires, intégration) passée sur les images
officielles `prestashop/prestashop` :

| PrestaShop | Image | PHP |
|---|---|---|
| 8.0 | `8.0.5-8.1-apache` | 8.1 |
| 8.1 | `8.1.7-8.1-apache` | 8.1 |
| 8.2 | `8.2.8-8.1-apache` | 8.1 |
| 9.0 | `9.0.3` | 8.4 |
| 9.1 | `9.1.5` | 8.5 |
| 9.2 | `9.2.0` | 8.5 |

`ps_versions_compliancy` minimum 8.0.0. `Ucpbridge::PHP_MIN` = 8.1.0, vérifié dans `install()`. PrestaShop 1.7 non
pris en charge.

### Mise à jour (#11)

Scripts `upgrade/upgrade-X.Y.Z.php` à partir de la 0.1.2, lancés par le gestionnaire de modules ou par
`php bin/console prestashop:module upgrade ucpbridge`. `Ucpbridge::upgradeInPlace()` crée tables, colonnes
(`Ucpbridge::ADDED_COLUMNS`), hooks et clés de configuration manquants sans modifier aucune valeur existante ; il est
rejouable. La clé unique `UCPBRIDGE_API_KEY` est migrée vers la plateforme `default` de `UCPBRIDGE_API_KEYS`. Le test
de mise à jour compare le schéma obtenu à celui d'une installation fraîche. L'ancienne clé n'est supprimée qu'après
l'enregistrement réussi de la nouvelle liste (`UcpBridgeApiKeys::migrateLegacy()`) ; si l'écriture échoue, la
mise à jour échoue et la plateforme en place garde son accès.

### Désinstallation et export (#12)

Tables conservées par défaut : `ps_ucpbridge_proof`, `ps_ucpbridge_withdrawal`, `ps_ucpbridge_payment`,
`ps_ucpbridge_customer`. Suppression seulement si `UCPBRIDGE_UNINSTALL_PURGE` vaut 1 (case de la configuration,
valable pour la désinstallation suivante). Export CSV (`UcpBridgeExport`) : UTF-8 avec BOM, séparateur `;`, valeurs
commençant par `=`, `+`, `-` ou `@` neutralisées contre l'injection de formules.

### RGPD (#13)

Durées par catégorie : `UCPBRIDGE_RETENTION_SESSIONS`, `_GUESTS`, `_OAUTH` (30 jours), `_PAYMENTS`, `_PROOFS`
(3650 jours), 0 = jamais. Purge par `UcpBridgePurge`, en ligne de commande (`bin/purge.php`, `--dry-run`) ou depuis
la configuration, avec compte rendu par catégorie consigné au journal PrestaShop. Nouvelle table
`ps_ucpbridge_customer` (invités créés par le module).

- Planchers légaux : `UcpBridgeRetention::LEGAL_MIN_DAYS` (`payments` et `proofs` : 3650 jours, 0 toujours permis).
  Durée plus courte refusée à l'enregistrement ; si la configuration est en dessous (écrite par un autre moyen), la
  purge est refusée sans rien supprimer : message d'erreur au back-office, `bin/purge.php` sort en code 2 avec le code
  `[retention_too_short]`.
- Session `failed` (débit non remboursé, adaptateur introuvable, débit incertain) : supprimée seulement avec le
  journal des paiements ; l'invité qui en a une n'est pas purgé (il le redevient ensuite, s'il n'a aucune commande).
- « Aucune commande » vérifié avant toute suppression des sessions d'un invité.
- Sessions lues par lots (`UcpBridgePurge::BATCH` = 500, pagination par identifiant), sessions déjà minimisées
  écartées en SQL.
- Compte rendu exact : paniers et adresses supprimés avec un invité comptés, simulation identique à la purge.

### Back-office et découpage (#17)

Configuration en `HelperForm` et gabarits Smarty (`views/templates/admin/`, `views/templates/hook/`), noms de champs,
boutons et jeton anti-CSRF `ucp_csrf` inchangés. Identifiant de plateforme reçu en POST échappé dans les messages de
confirmation. `UcpBridgeCart` devient une façade, API publique et constantes
conservées, sur les classes de `classes/cart/` (`UcpBridgeSessionStore`, `UcpBridgeCartLines`, `UcpBridgeBuyer`,
`UcpBridgeFulfillment`, `UcpBridgeCheckoutSession`, `UcpBridgeCheckoutCompletion`, `UcpBridgeReconciler`, etc.). Un
module tiers qui appelle `UcpBridgeCart` n'a rien à changer.

### Traductions (#15)

Nouveau système de traduction (`isUsingNewTranslationSystem()`), domaines `Modules.Ucpbridge.Admin`, `.Shop` et
`.Api`, textes sources en anglais, catalogues XLIFF `translations/fr-FR/` et `translations/en-US/` (342 chaînes).
`tools/translations.py` tient les catalogues à jour (`--check` en contrôle). Les **codes UCP renvoyés aux agents ne
changent pas** ; seuls les messages lisibles sont traduits. Codes stables ajoutés pour les scripts : `data-ucp-page`,
`data-ucp-row`, `data-ucp-code`, code entre crochets en fin de ligne de `bin/purge.php` et `bin/reconcile.php`. Un
script qui lisait les libellés français doit lire ces codes. Restent en français : le journal PrestaShop et les
en-têtes de colonnes de l'export CSV.

### Corrections (#1 à #5)

- `UcpBridgePaymentResult::UNKNOWN` : session laissée `complete_in_progress`, l'agent reçoit
  `payment_verification`, le rapprochement la classe en débit incertain.
- `$this->charged` posé dès la réponse du prestataire, avant le journal des paiements.
- `UcpBridgeIdempotency::chargeKey()` : montant en centimes, devise et numéro de tentative dans la clé Stripe.
- `redeemCode()` vérifie le client avant de consommer le code (`invalid_grant` sinon, sans révocation).
- `UcpBridgeApiKeys` : identité `platform:<id>` par plateforme, gestion des clés dans la configuration.

### Banc d'essai et intégration continue (#16, #14)

221 tests : 100 unitaires PHPUnit 12, 121 d'intégration pytest et Playwright. Banc docker paramétrable
(`PS_IMAGE`, `UCP_PROJECT`, `UCP_PORT`, `MAILPIT_PORT`, `STRIPE_MOCK_PORT`, `UCP_WAIT`, `UNIT_PHP`), workflow GitHub
Actions avec lint sous PHP 8.1, 8.3 et 8.5 et une intégration par version de PrestaShop. Le détail complet est dans
`ucpbridge/CHANGELOG.md`.

### Limites connues

- Bêta : paiement réel non éprouvé, pas de signatures HTTP, module non validé par Google.
- Tables de preuve conservées sans module pour les lire après une désinstallation : exporter avant, ou réinstaller.
- Traductions fournies en français et en anglais seulement ; l'écran Traductions du back-office n'a pas été éprouvé
  avec ce module.
- Sur PrestaShop 8, la mise à jour depuis la 0.1.2 est éprouvée en forçant l'installation de la 0.1.2 (qui exigeait
  9.1) : aucun marchand n'a pu suivre ce chemin, une boutique PrestaShop 8 installe directement la 0.1.3.
