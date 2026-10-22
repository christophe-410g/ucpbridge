"""Vérifie de bout en bout le transfert de panier UCP : un navigateur neuf (aucun cookie) ouvre le
continue_url renvoyé par POST /carts et doit atterrir sur la page panier de la boutique, pré-remplie.

  python3 capture_panier.py "<continue_url>" [--attendu "T-shirt imprimé colibri"] [--taille M]

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.
"""
import argparse
import os
import re
import sys
import time

from playwright.sync_api import sync_playwright

ICI = os.path.dirname(os.path.abspath(__file__))


def main():
    p = argparse.ArgumentParser()
    p.add_argument("url")
    p.add_argument("--attendu", default="T-shirt imprimé colibri")
    p.add_argument("--taille", default="M")
    p.add_argument("--sortie", default=os.path.join(ICI, "capture-panier-prerempli.png"))
    a = p.parse_args()
    if not a.url.startswith(("http://localhost", "http://127.0.0.1")):
        sys.exit("Banc d'essai : localhost uniquement.")
    with sync_playwright() as pw:
        nav = pw.chromium.launch()
        page = nav.new_page(viewport={"width": 1280, "height": 900}, locale="fr-FR")
        t0 = time.time()
        page.goto(a.url, wait_until="networkidle")
        ms = int((time.time() - t0) * 1000)
        zone = page.locator(".cart-overview, .cart-grid-body").first
        texte = zone.inner_text() if zone.count() else page.inner_text("body")
        alerte = page.locator(".alert-danger, .notifications-container .alert").all_inner_texts()
        ok_produit = a.attendu in texte
        ok_taille = re.search(rf"Taille\s*:\s*{re.escape(a.taille)}\b", texte) is not None
        try:
            page.locator(".cart-grid-body, .cart-container").first.scroll_into_view_if_needed(timeout=2000)
        except Exception:
            pass
        page.screenshot(path=a.sortie, full_page=False)
        print(f"URL finale : {page.url}")
        print(f"Chargement continue_url -> page panier : {ms} ms")
        print(f"Produit « {a.attendu} » affiché : {ok_produit}")
        print(f"Taille {a.taille} affichée : {ok_taille}")
        if alerte:
            print("Message boutique :", " / ".join(t.strip() for t in alerte if t.strip()))
        lignes = [l.strip() for l in texte.splitlines() if l.strip()]
        for i, l in enumerate(lignes):
            if a.attendu in l:
                print("Extrait :", " | ".join(lignes[i:i + 8]))
                break
        print(f"Capture : {a.sortie}")
        nav.close()
        sys.exit(0 if ok_produit and ok_taille else 1)


if __name__ == "__main__":
    main()
