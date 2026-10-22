#!/bin/sh
# Lance tous les tests du prototype ucpbridge sur le banc 8092 et affiche le bilan.
#   ./run-tests.sh            installation fraîche, tests unitaires PHPUnit, tests d'intégration pytest
#   ./run-tests.sh -k mcp     options transmises à pytest
#   FROM_ZIP=1 ./run-tests.sh     installe depuis dist/ucpbridge-<version>-beta.zip au lieu des sources
# Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
set -u
cd "$(dirname "$0")"
docker compose -p bench-ucp-bridge up -d mysql prestashop stripe-mock mailpit >/dev/null 2>&1

echo "== Lint PHP (php -l) dans le conteneur"
docker exec bench-ucp-bridge-prestashop-1 rm -rf /tmp/ucpbridge-lint
docker cp ucpbridge bench-ucp-bridge-prestashop-1:/tmp/ucpbridge-lint >/dev/null
LINT=$(docker exec bench-ucp-bridge-prestashop-1 sh -c 'find /tmp/ucpbridge-lint -name "*.php" -exec php -l {} \; | grep -vc "^No syntax errors"')
echo "fichiers en erreur : $LINT"

mkdir -p test-logs
echo "== Installation fraîche du module et jeu de démo"
if [ "${FROM_ZIP:-0}" = "1" ]; then MODE=--zip; else MODE=--reinstall; fi
./install-module.sh $MODE > test-logs/install.log 2>&1 || { echo "installation en échec (test-logs/install.log)"; tail -5 test-logs/install.log; exit 1; }
echo "mode : $MODE"

mkdir -p test-logs
echo "== Tests unitaires (PHPUnit, conteneur PrestaShop)"
./run-unit.sh > test-logs/unit.log 2>&1
UNIT=$?
grep -E "^(OK|Tests:|FAILURES|ERRORS)" test-logs/unit.log

echo "== Tests d'intégration (pytest, boutique http://localhost:8092)"
if python3 -c "import pytest, playwright" 2>/dev/null; then
  PY="python3 -m pytest"
else
  PY="uv run --with pytest --with playwright python -m pytest"
fi
(cd tests && $PY -q -p no:cacheprovider "$@") > "$(pwd)/test-logs/integration.log" 2>&1
INTEG=$?
grep -E "^(FAILED|ERROR)" test-logs/integration.log
tail -1 test-logs/integration.log

echo "== Bilan"
echo "unitaires   : $(grep -Eo 'OK \([0-9]+ tests' test-logs/unit.log || grep -Eo 'Tests: [0-9]+.*' test-logs/unit.log)"
echo "intégration : $(tail -1 test-logs/integration.log | sed 's/=//g')"
echo "tirets cadratins dans le module : $(grep -rl "$(printf '\342\200\224')" ucpbridge | wc -l | tr -d ' ') fichier(s)"
[ "$UNIT" -eq 0 ] && [ "$INTEG" -eq 0 ] && [ "$LINT" -eq 0 ] && echo "TOUT EST VERT" && exit 0
echo "ÉCHECS : voir test-logs/unit.log et test-logs/integration.log"
exit 1
