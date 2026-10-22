"""Issue #4 : un code d'autorisation présenté par un autre client OAuth n'est ni consommé ni source de révocation.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import json

from conftest import CLIENT_ID, REDIRECT, appel, php, pkce, secret_client_test
from test_04_relecture import id_client_demo, jeton_direct

AUTRE = "agent-indiscret-test"
AUTRE_SECRET = "secret-agent-indiscret-2026"
SCOPE = "dev.ucp.shopping.checkout:manage dev.ucp.shopping.order:read"


def code_pour(client_id, challenge):
    out = php("Module::getInstanceByName('ucpbridge'); "
              f"echo UcpBridgeOAuth::issueCode({json.dumps(client_id)}, {id_client_demo()}, {json.dumps(REDIRECT)}, "
              f"{json.dumps(challenge)}, {json.dumps(SCOPE)});")
    return out.splitlines()[-1]


def echanger(meta, code, verifier, basic):
    return appel("POST", meta["token_endpoint"], form={"grant_type": "authorization_code", "code": code,
                                                      "redirect_uri": REDIRECT, "code_verifier": verifier}, basic=basic)


def jeton_valide(boutique, acces):
    # Jeton valide : la commande inexistante répond 404 ; jeton révoqué : 401.
    return boutique.get("/orders/ord_" + "0" * 24, jeton=acces).code == 404


def test_code_d_un_autre_client_ni_consomme_ni_revocation(boutique, oauth_meta):
    php("Module::getInstanceByName('ucpbridge'); "
        f"UcpBridgeOAuth::registerClient({json.dumps(AUTRE)}, 'Agent indiscret', ['http://localhost:8767/cb'], {json.dumps(AUTRE_SECRET)});")
    acces_existant = jeton_direct(CLIENT_ID)
    assert jeton_valide(boutique, acces_existant)
    verifier, challenge = pkce()
    code = code_pour(CLIENT_ID, challenge)
    # Le code fuit vers un autre client enregistré, qui le présente deux fois.
    for _ in range(2):
        r = echanger(oauth_meta, code, verifier, (AUTRE, AUTRE_SECRET))
        assert r.code == 400 and r.doc["error"] == "invalid_grant", r.texte
    # Aucune révocation des jetons du vrai client, et le code reste utilisable par lui.
    assert jeton_valide(boutique, acces_existant)
    r = echanger(oauth_meta, code, verifier, (CLIENT_ID, secret_client_test()))
    assert r.code == 200, r.texte
    assert jeton_valide(boutique, r.doc["access_token"])


def test_rejeu_par_le_proprietaire_revoque_toujours(boutique, oauth_meta):
    verifier, challenge = pkce()
    code = code_pour(CLIENT_ID, challenge)
    r = echanger(oauth_meta, code, verifier, (CLIENT_ID, secret_client_test()))
    assert r.code == 200, r.texte
    acces = r.doc["access_token"]
    assert jeton_valide(boutique, acces)
    # RFC 6749 4.1.2 : le même client rejoue le code, les jetons émis avec lui sont révoqués.
    r = echanger(oauth_meta, code, verifier, (CLIENT_ID, secret_client_test()))
    assert r.code == 400 and r.doc["error"] == "invalid_grant"
    assert not jeton_valide(boutique, acces)
