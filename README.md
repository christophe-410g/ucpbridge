# UCP Bridge pour PrestaShop

Module PrestaShop qui fait parler une boutique le protocole UCP (Universal Commerce Protocol, https://ucp.dev), celui
qu'utilisent Google Universal Cart et les agents d'achat : profil `/.well-known/ucp`, catalogue et déclinaisons,
panier transférable, caisse native, codes promo, comptes clients (OAuth), CGV, rétractation et serveur MCP.

> **Version bêta 0.1.2, prototype : ne pas utiliser en production.** Non validé par Google, Universal Cart n'est pas
> encore ouvert en Europe, paiement réel non éprouvé. PrestaShop 9.1 uniquement. Relectures et contributions bienvenues.

Écrit par Christophe Vidal pour l'épisode 15 du podcast **Contrib.** (Thomas Rayrat et Christophe Vidal), donné à
l'association **Friends of Presta**.

## Contenu du dépôt

- `ucpbridge/` : le module (à zipper pour l'installer, voir `build-zip.sh`), sa documentation complète
  (`ucpbridge/README.md`) et son journal des modifications.
- `tests/`, `run-tests.sh`, `docker-compose.yml`, `seed-demo.php` : banc d'essai PrestaShop 9.1 en docker et
  138 tests (61 unitaires PHPUnit, 77 d'intégration pytest et Playwright).
- `mcp_client.py` : client MCP minimal pour essayer la boutique comme un agent.

## Démarrage rapide

```sh
docker compose -p bench-ucp-bridge up -d
./run-tests.sh
./build-zip.sh      # archive installable dans dist/
```

## Licence

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib.
Licence AFL-3.0 (Academic Free License 3.0) : voir `LICENSE`.

Faille de sécurité : ne pas ouvrir d'issue publique, voir `SECURITY.md`.
