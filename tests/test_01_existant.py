"""Non-régression des capacités existantes du prototype ucpbridge (REST).

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import urllib.parse
import uuid

import pytest

from conftest import (BASE, BUYER, CLIENT_ID, DEST_BE, DEST_FR, M_BLANC, REDIRECT, appel, flux_autorisation,
                      id_commande, jeton_client, jeton_gpay, secret_client_test, somme_coherente, sql, sql_val, total)


# ------------------------------------ Profil ------------------------------------

def test_profil_public(boutique):
    p = boutique.profil
    assert p["version"] == "2026-04-08"
    rest = [s for s in p["services"]["dev.ucp.shopping"] if s["transport"] == "rest"]
    assert rest and rest[0]["endpoint"].endswith("/ucp/v1")
    for cap in ("dev.ucp.shopping.catalog.search", "dev.ucp.shopping.cart", "dev.ucp.shopping.checkout",
                "dev.ucp.shopping.fulfillment", "dev.ucp.shopping.order", "dev.ucp.shopping.discount",
                "dev.ucp.common.identity_linking"):
        assert cap in p["capabilities"], cap
    assert "fr.410gone.ucpbridge.mock" in p["payment_handlers"]
    assert "com.google.pay" in p["payment_handlers"]


def test_api_refuse_sans_cle(boutique):
    r = appel("GET", boutique.api + "/carts/x")
    assert r.code == 401
    r = appel("GET", boutique.api + "/carts/x", jeton="ucpk_faux")
    assert r.code == 401


# ------------------------------- Catalogue et déclinaisons -------------------------------

def produit_colibri(boutique):
    r = boutique.post("/catalog/search", {"query": "t-shirt colibri", "pagination": {"limit": 5}})
    assert r.code == 200
    return r.doc["products"][0]


def test_catalogue_declinaisons(boutique):
    p = produit_colibri(boutique)
    assert "colibri" in p["title"].lower()
    assert len(p["variants"]) == 8
    skus = {v["sku"] for v in p["variants"]}
    assert "demo_1-M-BLANC" in skus
    s_noir = next(v for v in p["variants"] if v["sku"] == "demo_1-S-NOIR")
    assert s_noir["availability"]["available"] is False
    xl = next(v for v in p["variants"] if v["sku"] == "demo_1-XL-BLANC")
    m = next(v for v in p["variants"] if v["sku"] == "demo_1-M-BLANC")
    # +2 € HT d'impact sur XL, TVA 20 %, remise produit -20 % : +1,92 € TTC
    assert xl["price"]["amount"] - m["price"]["amount"] == 192


def test_variant_required(boutique):
    p = produit_colibri(boutique)
    r = boutique.post("/checkout-sessions", {"line_items": [{"item": {"id": p["id"]}, "quantity": 1}]})
    assert r.doc["ucp"]["status"] == "error"
    m = r.doc["messages"][0]
    assert m["code"] == "variant_required"
    assert len(m["variants"]) == 8


@pytest.mark.parametrize("item", [{"id": "demo_1-M-BLANC"}, {"id": "demo_1", "variant_id": "1-3"}, {"id": "1-3"}])
def test_trois_formes_d_identifiant(boutique, item):
    r = boutique.post("/carts", {"line_items": [{"item": item, "quantity": 1}]})
    assert r.code == 201, r.texte[:300]
    assert r.doc["line_items"][0]["item"]["variant_id"] == "1-3"
    assert r.doc["line_items"][0]["item"]["sku"] == "demo_1-M-BLANC"


def test_stock_par_declinaison(boutique):
    r = boutique.post("/carts", {"line_items": [{"item": {"id": "demo_1-S-NOIR"}, "quantity": 1}]})
    assert r.doc["messages"][0]["code"] == "out_of_stock"
    r = boutique.post("/carts", {"line_items": [{"item": {"id": "demo_1-S-BLANC"}, "quantity": 1}]})
    assert r.code == 201


# ------------------------------- Panier et continue_url -------------------------------

def test_panier_continue_url_signe(boutique):
    r = boutique.post("/carts", {"line_items": [{"item": M_BLANC, "quantity": 2}]})
    assert r.code == 201
    url = r.doc["continue_url"]
    q = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(url).query))
    assert {"cart", "exp", "sig"} <= set(q)
    assert sql_val(f"SELECT SUM(quantity) FROM ps_cart_product WHERE id_cart={int(q['cart'])}") == "2"
    assert somme_coherente(r.doc)
    # GET et PUT du panier
    g = boutique.get(f"/carts/{r.doc['id']}")
    assert g.code == 200 and g.doc["id"] == r.doc["id"]
    u = boutique.put(f"/carts/{r.doc['id']}", {"line_items": [{"item": M_BLANC, "quantity": 3}]})
    assert u.doc["line_items"][0]["quantity"] == 3


def test_continue_url_ouvre_le_panier_dans_le_navigateur(boutique):
    from playwright.sync_api import sync_playwright
    r = boutique.post("/carts", {"line_items": [{"item": M_BLANC, "quantity": 1}]})
    url = r.doc["continue_url"]
    falsifie = url.replace("sig=", "sig=00")
    with sync_playwright() as pw:
        nav = pw.chromium.launch()
        page = nav.new_page(locale="fr-FR")
        page.goto(url, wait_until="networkidle")
        texte = page.inner_text("body")
        assert "colibri" in texte.lower() and "M" in texte
        assert "controller=cart" in page.url or "/panier" in page.url
        page2 = nav.new_page(locale="fr-FR")
        page2.goto(falsifie, wait_until="networkidle")
        assert "invalide" in page2.inner_text("body").lower()
        nav.close()


# ------------------------------- Caisse native, TVA -------------------------------

def test_caisse_native_commande(boutique):
    s = boutique.session_prete(lignes=[{"item": {"id": "demo_1", "variant_id": "1-6"}, "quantity": 1}])
    assert s["status"] == "ready_for_complete"
    assert somme_coherente(s)
    attendu = total(s)
    r = boutique.payer_mock(s["id"])
    assert r.doc["status"] == "completed", r.texte[:800]
    oid = id_commande(r.doc["order"]["id"])
    assert sql_val(f"SELECT product_attribute_id FROM ps_order_detail WHERE id_order={oid}") == "6"
    assert int(sql_val(f"SELECT ROUND(total_paid_tax_incl*100) FROM ps_orders WHERE id_order={oid}")) == attendu
    o = boutique.get(f"/orders/{r.doc['order']['id']}")
    assert o.code == 200 and o.doc["id"] == r.doc["order"]["id"]
    assert somme_coherente(o.doc) and total(o.doc) == attendu
    assert "continue_url" not in r.doc  # état terminal


@pytest.mark.parametrize("dest,pays,taux", [(DEST_FR, "FR", 0.20), (DEST_BE, "BE", 0.21)])
def test_tva_selon_pays(boutique, dest, pays, taux):
    s = boutique.session_prete(dest=dest)
    tva = next(t for t in s["totals"] if t["type"] == "tax")
    assert pays in tva["display_text"]
    ht = total(s) - tva["amount"]
    assert abs(tva["amount"] / ht - taux) < 0.002
    assert somme_coherente(s)


# ------------------------------------ Idempotence ------------------------------------

def test_idempotence(boutique):
    cle = str(uuid.uuid4())
    corps = {"line_items": [{"item": M_BLANC, "quantity": 1}], "buyer": BUYER}
    a = boutique.post("/checkout-sessions", corps, idem=cle)
    b = boutique.post("/checkout-sessions", corps, idem=cle)
    assert a.doc["id"] == b.doc["id"]
    assert b.entetes.get("idempotent-replayed") == "true"
    c = boutique.post("/checkout-sessions", dict(corps, line_items=[{"item": M_BLANC, "quantity": 2}]), idem=cle)
    assert c.code == 409


def test_double_clic_payer(boutique):
    s = boutique.session_prete()
    cle = str(uuid.uuid4())
    a = boutique.payer_mock(s["id"], idem=cle)
    b = boutique.payer_mock(s["id"], idem=cle)
    assert a.doc["order"]["id"] == b.doc["order"]["id"]
    assert sql_val(f"SELECT COUNT(*) FROM ps_orders WHERE id_cart=(SELECT id_cart FROM ps_orders WHERE id_order={id_commande(a.doc['order']['id'])})") == "1"


# ------------------------------------ Codes promo ------------------------------------

def test_codes_promo(boutique):
    s = boutique.session_prete(lignes=[{"item": M_BLANC, "quantity": 3}],
                               codes=["bienvenue10", "EXPIRE2025", "FIDELE5", "NIMPORTEQUOI"])
    codes = {m["code"] for m in s.get("messages", []) if m["type"] == "warning"}
    assert {"discount_code_expired", "discount_code_user_not_logged_in", "discount_code_invalid"} <= codes
    appliques = [a.get("code") for a in s["discounts"]["applied"]]
    assert "BIENVENUE10" in appliques
    assert any(a.get("automatic") for a in s["discounts"]["applied"])  # livraison offerte dès 50 €
    assert total(s, "discount") < 0 and somme_coherente(s)
    attendu = total(s)
    r = boutique.payer_mock(s["id"])
    oid = id_commande(r.doc["order"]["id"])
    assert int(sql_val(f"SELECT ROUND(total_paid_tax_incl*100) FROM ps_orders WHERE id_order={oid}")) == attendu
    assert sql_val(f"SELECT COUNT(*) FROM ps_order_cart_rule WHERE id_order={oid}") == "2"
    o = boutique.get(f"/orders/{r.doc['order']['id']}").doc
    assert somme_coherente(o) and total(o) == attendu


# ------------------------------------ Google Pay ------------------------------------

def instrument_gpay(boutique, token):
    return {"id": "instr_gpay", "handler_id": boutique.handler_id("com.google.pay"), "type": "card", "selected": True,
            "credential": {"type": "PAYMENT_GATEWAY", "token": token}}


def test_google_pay_test_stripe_mock(boutique):
    h = boutique.profil["payment_handlers"]["com.google.pay"][0]
    assert h["config"]["environment"] == "TEST"
    assert h["config"]["allowed_payment_methods"][0]["tokenization_specification"]["parameters"]["gateway"] == "stripe"
    s = boutique.session_prete()
    r = boutique.post(f"/checkout-sessions/{s['id']}/complete", {"payment": {"instruments": [instrument_gpay(boutique, "pas-un-jeton")]}})
    assert r.doc["status"] == "ready_for_complete"
    assert any(m["code"] == "payment_failed" for m in r.doc["messages"])
    r = boutique.post(f"/checkout-sessions/{s['id']}/complete", {"payment": {"instruments": [instrument_gpay(boutique, jeton_gpay())]}})
    assert r.doc["status"] == "completed", r.texte[:600]
    assert r.doc["payment"]["transaction_id"].startswith("ch_")
    oid = id_commande(r.doc["order"]["id"])
    assert sql_val(f"SELECT op.transaction_id FROM ps_order_payment op JOIN ps_orders o ON o.reference=op.order_reference "
                   f"WHERE o.id_order={oid}").startswith("ch_")


# ------------------------------- OAuth compte client -------------------------------

def test_oauth_metadonnees(oauth_meta):
    assert oauth_meta["code_challenge_methods_supported"] == ["S256"]
    assert oauth_meta["token_endpoint"].endswith("/ucp/oauth/token")


def test_oauth_flux_complet(boutique, oauth_meta):
    secret = secret_client_test()
    code, verifier = flux_autorisation(oauth_meta)
    corps = {"grant_type": "authorization_code", "code": code, "redirect_uri": REDIRECT, "code_verifier": "x" * 43}
    r = appel("POST", oauth_meta["token_endpoint"], form=corps, basic=(CLIENT_ID, secret))
    assert r.code == 400 and r.doc["error"] == "invalid_grant"  # PKCE faux

    code, verifier = flux_autorisation(oauth_meta)
    corps = {"grant_type": "authorization_code", "code": code, "redirect_uri": REDIRECT, "code_verifier": verifier}
    assert appel("POST", oauth_meta["token_endpoint"], form=corps, basic=(CLIENT_ID, "mauvais")).code == 401
    jetons = appel("POST", oauth_meta["token_endpoint"], form=corps, basic=(CLIENT_ID, secret)).doc
    assert jetons["access_token"].startswith("ucpat_")
    rejeu = appel("POST", oauth_meta["token_endpoint"], form=corps, basic=(CLIENT_ID, secret))
    assert rejeu.code == 400
    refresh = appel("POST", oauth_meta["token_endpoint"], form={"grant_type": "refresh_token", "refresh_token": jetons["refresh_token"]},
                    basic=(CLIENT_ID, secret))
    assert refresh.code == 400  # révoqué par le rejeu du code


def test_oauth_commande_au_nom_du_client(boutique, oauth_meta):
    jetons = jeton_client(oauth_meta)
    acces = jetons["access_token"]
    r = boutique.post("/checkout-sessions", {"line_items": [{"item": M_BLANC, "quantity": 1}], "discounts": {"codes": ["FIDELE5"]}}, acces)
    assert r.code == 201
    assert r.doc["buyer"]["email"] == "pub@prestashop.com"
    assert any(a.get("code") == "FIDELE5" for a in r.doc["discounts"].get("applied", []))
    assert boutique.get(f"/checkout-sessions/{r.doc['id']}").doc["ucp"]["status"] == "error"  # invité : refusé
    m = r.doc["fulfillment"]["methods"][0]
    m["groups"][0]["selected_option_id"] = m["groups"][0]["options"][0]["id"]
    s = boutique.put(f"/checkout-sessions/{r.doc['id']}", {"fulfillment": {"methods": [m]}}, acces).doc
    assert s["status"] == "ready_for_complete"
    s = boutique.payer_mock(s["id"], jeton=acces).doc
    oid = id_commande(s["order"]["id"])
    assert sql_val(f"SELECT c.is_guest FROM ps_orders o JOIN ps_customer c USING (id_customer) WHERE o.id_order={oid}") == "0"
    assert boutique.get(f"/orders/{s['order']['id']}", jeton=acces).doc["id"] == s["order"]["id"]
    appel("POST", oauth_meta["revocation_endpoint"], form={"token": acces}, basic=(CLIENT_ID, secret_client_test()))
    r = boutique.post("/checkout-sessions", {"line_items": [{"item": M_BLANC, "quantity": 1}]}, acces)
    assert r.code == 401


# ------------------------- Cas limites découverts en écrivant les tests -------------------------

def test_caisse_depuis_un_panier(boutique):
    p = boutique.post("/carts", {"line_items": [{"item": M_BLANC, "quantity": 2}], "discounts": {"codes": ["BIENVENUE10"]}}).doc
    r = boutique.post("/checkout-sessions", {"cart_id": p["id"], "buyer": BUYER})
    assert r.code == 201
    assert r.doc["line_items"][0]["quantity"] == 2
    assert r.doc["discounts"]["codes"] == ["BIENVENUE10"]


def test_changement_de_pays_apres_transporteur(boutique):
    s = boutique.session_prete(dest=DEST_FR)
    assert s["status"] == "ready_for_complete"
    m = {"type": "shipping", "destinations": [DEST_BE], "selected_destination_id": DEST_BE["id"]}
    r = boutique.put(f"/checkout-sessions/{s['id']}", {"fulfillment": {"methods": [m]}}).doc
    tva = next(t for t in r["totals"] if t["type"] == "tax")
    assert "BE" in tva["display_text"] and somme_coherente(r)


def test_panier_d_un_client_inaccessible_a_un_invite(boutique, oauth_meta):
    acces = jeton_client(oauth_meta)["access_token"]
    p = boutique.post("/carts", {"line_items": [{"item": M_BLANC, "quantity": 1}]}, acces).doc
    assert boutique.get(f"/carts/{p['id']}").doc["ucp"]["status"] == "error"
    # Conversion du panier du client en session de caisse par un appelant invité : refusée.
    r = boutique.post("/checkout-sessions", {"cart_id": p["id"]})
    assert r.doc["ucp"]["status"] == "error"
    assert r.doc["messages"][0]["code"] == "not_found"


def test_idempotence_cloisonnee_par_appelant(boutique, oauth_meta):
    acces = jeton_client(oauth_meta)["access_token"]
    cle = str(uuid.uuid4())
    corps = {"line_items": [{"item": M_BLANC, "quantity": 1}]}
    a = boutique.post("/carts", corps, acces, idem=cle)
    b = boutique.post("/carts", corps, idem=cle)  # même clé, autre appelant (invité)
    assert a.code == 201 and b.code == 201
    assert a.doc["id"] != b.doc["id"]
    assert b.entetes.get("idempotent-replayed") is None
