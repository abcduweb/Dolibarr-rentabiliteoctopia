# Rentabilité Octopia — module Dolibarr

**Tableau de pilotage de la rentabilité des ventes Octopia / Cdiscount pour Dolibarr.**

- **Version :** 1.2.1
- **Éditeur :** ABCduWeb — https://www.abcduweb.fr
- **Compatibilité :** Dolibarr 22.x, PHP 8.x, MariaDB/MySQL

---

## Présentation

Ce module calcule et suit la rentabilité réelle de vos ventes sur la marketplace Cdiscount/Octopia : chiffre d'affaires, commissions, marges, trésorerie à venir, optimisation des prix, réassort et alertes. Il s'appuie sur les commandes importées par le module **octopiaSync** et sur les factures fournisseur de Dolibarr pour produire une vision complète, du suivi quotidien à la décision tarifaire.

---

## Prérequis

- **Dolibarr 22.x** (testé sur 22.0.3).
- **PHP 8.x**, base **MariaDB/MySQL**.
- Le module **octopiaSync** installé et synchronisé (il alimente la table `llx_octopia_orders`, source des ventes).
- Module **Comptabilité** recommandé (factures fournisseur) pour le coût d'achat automatique et la vue fournisseurs.
- Module **Stock** facultatif (pour le réassort basé sur le stock réel).

---

## Installation

1. Copier le dossier `rentabiliteoctopia/` dans `htdocs/custom/` de votre Dolibarr.
2. Aller dans **Accueil → Configuration → Modules** et activer **Rentabilité Octopia**.
3. Ouvrir **Rentabilité Octopia → Paramètres** pour configurer les taux de commission, le coût d'achat, le rapport mail, etc.

> **Hébergement mutualisé (o2switch / LiteSpeed) :** après chaque mise à jour de fichiers, purger l'OPcache puis vider le cache navigateur (voir « Déploiement » plus bas).

---

## Fonctionnalités

### Pilotage
- **Accueil** — vue d'ensemble actionnable : CA/marge/commandes du mois, cartes Alertes / Ruptures / Cdiscount vous doit / Manque à gagner, accès rapide à toutes les analyses.
- **Tableau de bord** — KPI mensuels détaillés, liste des produits, graphe d'évolution + boutons d'export (CSV / PDF).
- **Santé entreprise** — vue annuelle : KPI N vs N-1, point mort, projection, graphe 12 mois, performance par catégorie, top produits, produits sous-marge et dormants.
- **Saisonnalité** — analyse sur 24 mois : courbe CA + quantités, comparaison N/N-1, détection des produits saisonniers (pics concentrés sur un mois).
- **Centre d'alertes** — détection temps réel : produits en perte, marge faible, ruptures/ruptures imminentes, chute de CA.

### Prix
- **Simulateur de prix** — calcule un prix de vente pour une marge cible (ou la marge pour un prix), avec toutes les composantes (commission, port, packaging, retours, TVA). Formule unique partagée serveur/client.
- **Optimisation prix** — confronte le prix de vente réel au prix idéal, chiffre le manque à gagner annuel et donne un verdict par produit (augmenter / marge confortable / optimal).
- **Historique prix** — snapshots mensuels des prix, détection des changements et mesure de leur impact réel sur le volume et la marge (hausse/baisse gagnante ou perdante).

### Trésorerie & stock
- **Trésorerie Octopia** — prévision des reversements Cdiscount avec commission **réelle par produit**, déduction des **remboursements** et TVA par ligne. Pointage manuel vs prévu.
- **Réassort & stock** — vitesse de vente × stock Dolibarr → jours de stock restant, date de rupture estimée, quantité à commander, coût du réassort.

### Données & paramétrage
- **Produits & ventes**, **Frais mensuels**, **Catégories & commissions** — saisie et consultation.
- **Synchronisation Octopia** — rapatrie les ventes depuis octopiaSync, capture les prix et invalide le cache automatiquement.
- **Audit produits** — réconcilie les produits Octopia/Dolibarr, crée les produits manquants, mappe les références.
- **Affectation rapide** — assignation en masse des catégories (taux de commission).
- **Coût achat auto** — récupère le prix d'achat HT depuis les factures fournisseur (dernière facture ou moyenne pondérée 12 mois) et met à jour `cost_price`.
- **Vue fournisseurs** — rentabilité par fournisseur : achats, CA généré, marge, ROI.
- **Diagnostic** — vérifie l'intégrité du module (tables, version, mail, cohérence des données) avec liens de correction.
- **Paramètres** — configuration complète (PCG, rapport mail, fréquence, cache, nettoyages, cron).

### Exports
- Export **CSV** (BOM UTF-8, séparateur `;` pour Excel FR) et **PDF** (TCPDF intégré à Dolibarr, aucune dépendance) sur : produits, optimisation, saisonnalité, historique prix.

---

## Tables créées

| Table | Rôle |
|-------|------|
| `llx_rentabiliteoctopia_categorie` | Catégories + taux de commission |
| `llx_rentabiliteoctopia_produit` | Produits suivis |
| `llx_rentabiliteoctopia_vente` | Ventes mensuelles agrégées par produit |
| `llx_rentabiliteoctopia_frais` | Frais fixes mensuels |
| `llx_rentabiliteoctopia_params` | Paramètres du module |
| `llx_rentabiliteoctopia_cache_mois` | Cache des agrégats mensuels figés |
| `llx_rentabiliteoctopia_prix_histo` | Historique des prix (snapshots mensuels) |

> Les tables `_cache_mois` et `_prix_histo` sont créées automatiquement à la première utilisation (`CREATE TABLE IF NOT EXISTS`).

---

## Tâches planifiées (scheduler Dolibarr)

Le module enregistre **2 tâches** dans **Accueil → Configuration → Tâches planifiées** :

1. **Rapport KPI Octopia quotidien** — envoie le rapport KPI par email.
2. **Capture mensuelle des prix Octopia** — enregistre le snapshot des prix.

> Pour créer ces tâches, **désactiver puis réactiver le module** une fois après mise à jour.

Le scheduler nécessite **un seul** lanceur cron système (qui exécute toutes les tâches Dolibarr). Pour une précision à 5 minutes, le faire tourner toutes les 5 min :

```
*/5 * * * * /usr/local/bin/php /chemin/scripts/cron/cron_run_jobs.php VOTRE_CLE superadmin >> /tmp/dolibarr_cron.log 2>&1
```

La clé sécurisée se trouve dans **Tâches planifiées → Information**. La commande exacte (avec la clé pré-remplie) est affichée dans **Paramètres** du module.

---

## Widget tableau de bord

Un widget **KPI Rentabilité Octopia** affiche le CA, la marge nette et les commandes du mois sur la page d'accueil de Dolibarr (« Mon tableau de bord »). Il s'enregistre à l'activation du module ; si besoin, l'ajouter via le sélecteur de widgets de la page d'accueil.

---

## Rapport mail quotidien

Configurable dans **Paramètres → Rapport quotidien des KPI par email** :
- Activation, destinataires (séparés par des virgules), période de référence (J-1, 7 jours, mois en cours).
- **Fréquence d'envoi** : une fois par jour à heure fixe, ou toutes les 5 / 15 / 30 minutes / toutes les heures (utile pour tester).
- **Heure d'envoi** par pas de 5 minutes, interprétée dans **votre fuseau horaire local**.
- Sections incluses configurables (KPI, comparaison, détail CA, top produits, cumul mois, alertes…).
- Boutons **Tester** et **Aperçu** disponibles.

> La page Tâches planifiées affiche les heures en **heure serveur** : la « Prochaine exécution » peut donc apparaître décalée par rapport à l'heure locale que vous avez choisie — c'est normal.

---

## Déploiement (mise à jour des fichiers)

Sur hébergement mutualisé (o2switch / LiteSpeed), après tout envoi de fichiers :

1. **Upload FTP** vers `htdocs/custom/rentabiliteoctopia/`.
2. **Purge OPcache** : `https://prod.abcduweb.fr/clear-opcache.php?key=abcduweb2025`
3. **LiteSpeed** : « Purger tout ».
4. **Ctrl+F5** (navigation privée recommandée).
5. Pour les changements de **menus, tâches planifiées ou widget** : **désactiver puis réactiver le module**.

---

## Dépannage

- **Page Diagnostic** : premier réflexe, elle vérifie tables, version, mail, expéditeur et cohérence des données avec liens de correction directe.
- **Chiffres obsolètes** après une modification manuelle en base : **Paramètres → Vider le cache**.
- **Mail non reçu** : vérifier dans Paramètres que l'expéditeur est bien configuré (Accueil → Configuration → Emails), éviter une adresse générique type `robot@` (rejetée par Gmail). Vérifier aussi les spams.
- **Mauvaise heure d'envoi** : l'heure est en fuseau local ; la page Tâches planifiées, elle, affiche l'heure serveur.

---

## Architecture technique

**Classes (`lib/`)**
- `PricingEngine` — calcul de prix/marge centralisé (source unique, formule identique PHP et JS).
- `CacheMois` — cache des agrégats mensuels figés (perf), invalidation automatique à la synchro.
- `PrixHistorique` — capture et comparaison des prix dans le temps.
- `AlertesEngine` — détection des alertes (perte, marge, rupture, chute CA).
- `DailyKpiMailer` — construction et envoi du rapport KPI (multipart base64, partagé cron/aperçu).
- `ModuleHelper` — gestion d'erreur uniforme, vérif tables/colonnes, barre de navigation.
- `OctopiaRentabiliteSync` — synchronisation des ventes depuis octopiaSync.
- `OctopiaFactureImport` — import des factures.
- `rentabiliteoctopia.lib.php` — helpers (formatage €, KPI, catégories, ventes…).

**Autres**
- `class/rentabiliteoctopiacron.class.php` — méthodes appelées par le scheduler Dolibarr.
- `core/boxes/box_rentabiliteoctopia.php` — widget tableau de bord.
- `core/modules/modRentabiliteOctopia.class.php` — descripteur du module (menus, droits, tâches, widget).

---

## Notes

- Les frais de port refacturés au client sont traités comme du **chiffre d'affaires** (reversé par Cdiscount), pas comme une charge. Les charges de transport réelles proviennent uniquement des factures fournisseur.
- La commission est calculée **par produit** (selon sa catégorie), pas via une moyenne globale.

---

*Module développé par ABCduWeb. Usage interne et commercial.*
