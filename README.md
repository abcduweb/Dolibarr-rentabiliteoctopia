# 📊 RentabiliteOctopia — Module Dolibarr

**Tableau de rentabilité mensuelle des ventes Octopia / Cdiscount**  
Développé par [ABCduWeb](https://www.abcduweb.fr) — version **1.2.1**

---

## 🧭 Présentation

`rentabiliteoctopia` est un module Dolibarr qui calcule automatiquement la rentabilité de votre boutique Cdiscount/Octopia, mois par mois et produit par produit.

Il fonctionne **en parallèle** avec le module **octopiaSync** : il lit les tables d'octopiaSync et les combine avec les frais et taux de commission configurés pour produire un tableau de bord de rentabilité complet.

---

## ✅ Prérequis

| Élément | Version minimale |
|---|---|
| Dolibarr | 17.0 |
| PHP | 7.4 |
| Module **octopiaSync** | Installé et actif |
| MariaDB / MySQL | 5.7 / 10.2 |

---

## 📦 Installation

1. Décompresser le zip dans `htdocs/custom/` de votre Dolibarr :
   ```
   htdocs/custom/rentabiliteoctopia/
   ```
2. Aller dans **Accueil → Configuration → Modules/Applications**
3. Chercher **"Rentabilité Octopia"** et activer le module
4. Les tables SQL sont créées automatiquement à l'activation
5. Aller dans **Rentabilité Octopia → Paramètres** pour configurer

---

## 🗂️ Structure des fichiers

```
rentabiliteoctopia/
├── admin.php                          # Redirect → admin/admin.php (compat)
├── admin/
│   └── admin.php                      # Page paramètres principale
├── index.php                          # Tableau de bord mensuel
├── produits.php                       # Gestion produits + saisie ventes
├── frais.php                          # Saisie / import frais mensuels
├── categories.php                     # Gestion catégories + taux commission
├── sync.php                           # Synchronisation manuelle depuis octopiaSync
├── core/
│   └── modules/
│       └── modRentabiliteOctopia.class.php
├── lib/
│   ├── rentabiliteoctopia.lib.php        # Fonctions utilitaires + calculs
│   ├── OctopiaRentabiliteSync.class.php  # Synchro octopiaSync → rentabilité
│   └── OctopiaFactureImport.class.php    # Import frais depuis factures fournisseur
├── cron/
│   └── sync_octopia.php               # Script cron automatique
├── sql/
│   ├── llx_rentabiliteoctopia_categorie.sql
│   ├── llx_rentabiliteoctopia_produit.sql
│   ├── llx_rentabiliteoctopia_vente.sql
│   ├── llx_rentabiliteoctopia_frais.sql
│   ├── llx_rentabiliteoctopia_params.sql
│   ├── llx_rentabiliteoctopia_produit_fk_categorie.sql
│   ├── llx_rentabiliteoctopia_vente_fk_produit.sql
│   ├── data.sql                       # Données par défaut (catégories + params)
│   └── rentabiliteoctopia.sql         # Schéma complet (référence manuelle)
└── langs/
    └── fr_FR/
        └── rentabiliteoctopia.lang
```

---

## 🗄️ Modèle de données

```
llx_rentabiliteoctopia_categorie
  rowid | code (INFORMATIQUE…) | label | commission_pct | entity

llx_rentabiliteoctopia_produit
  rowid | ref | designation | fk_categorie → categorie | entity

llx_rentabiliteoctopia_vente
  rowid | fk_produit → produit | annee | mois
        | qty_vendue | prix_ht (moy unitaire) | cout_achat
        | commission_pct (override) | commission_reel (€ réel)
        | UNIQUE (fk_produit, annee, mois, entity)

llx_rentabiliteoctopia_frais
  rowid | annee | mois | type_frais | label | montant
        | UNIQUE (annee, mois, type_frais, entity)

llx_rentabiliteoctopia_params
  rowid | param_key | param_value | entity
        | UNIQUE (param_key, entity)
```

---

## ⚙️ Comment ça fonctionne

### 1. Synchronisation des ventes (depuis octopiaSync)

Le module est compatible avec la version d'octopiaSync qui utilise les tables :

```
llx_octopia_orders     : commandes (octopia_order_status, dolibarr_order_id, is_refunded)
```

Cette version ne stocke **pas** ses propres lignes de commande : elle référence les commandes Dolibarr natives via `dolibarr_order_id`. Les données produits viennent donc des tables standard Dolibarr :

```
llx_octopia_orders  →  dolibarr_order_id
                              ↓
                       llx_commande          (date, statut commande)
                              ↓
                       llx_commandedet       (fk_product, qty, subprice)
                              ↓
                       llx_product           (ref, label, cost_price)
```

**Filtres appliqués lors de la synchronisation :**
- `o.is_refunded = 0` — exclut les commandes remboursées
- `o.octopia_order_status NOT IN ('CANCELLED', 'REFUNDED', 'REFUSED', 'CANCELED')`
- `c.fk_statut >= 1` — commandes Dolibarr validées uniquement
- `o.dolibarr_order_id IS NOT NULL` — ignore les lignes orphelines

La classe `OctopiaRentabiliteSync` :
- Agrège CA HT + quantités par référence produit sur le mois demandé
- Crée automatiquement les produits manquants dans `llx_rentabiliteoctopia_produit`
- Fait un `INSERT … ON DUPLICATE KEY UPDATE` dans `llx_rentabiliteoctopia_vente`
- Préserve le `cout_achat` saisi manuellement si Dolibarr n'en a pas

### 2. Calcul de rentabilité

**Pour chaque produit :**
```
CA             = qty_vendue × prix_ht
Commission     = commission_reel  [si saisi manuellement]
               OU qty × prix_ht × commission_pct  [override produit]
               OU qty × prix_ht × cat_commission_pct  [taux catégorie]
Retours        = qty × taux_retour_pct% × cout_retour
Coût achat     = qty × cout_achat
Marge produit  = CA − Coût achat − Commission − Retours
Taux marge     = Marge produit / CA × 100
```

**Pour le mois :**
```
Frais fixes    = Σ frais mensuels (abonnement + fulfilment + transport…)
Marge nette    = Σ Marges produits − Frais fixes
```

### 3. Import des frais depuis les factures fournisseur

La classe `OctopiaFactureImport` :
- Lit `llx_facture_fourn_det` filtrée sur le fournisseur Cdiscount/Octopia
- Mappe les comptes PCG vers les types de frais via des préfixes configurables
- Écrit dans `llx_rentabiliteoctopia_frais`

**Mapping PCG par défaut :**

| Préfixe PCG | Type de frais |
|---|---|
| 613x / 614x | `abonnement` |
| 611x / 6119x | `fulfilment` |
| 6241x / 624x / 625x | `affranchissement` |
| 6044x / 604x | `packaging` |
| 623x / 622x | `publicite` |
| Autres | `autre` |

---

## 🔧 Configuration (Paramètres)

Aller dans **Rentabilité Octopia → Paramètres** :

| Paramètre | Défaut | Description |
|---|---|---|
| `seuil_marge_pct` | 15% | Alerte si taux de marge < seuil |
| `taux_retour_pct` | 3% | Taux de retours estimé |
| `cout_retour` | 2.50 € | Coût unitaire d'un retour |
| `nom_fournisseur` | Cdiscount | Nom dans la fiche fournisseur Dolibarr |
| `pcg_abonnement` | 613, 614 | Préfixes PCG (personnalisables) |
| `pcg_fulfilment` | 611 | Préfixes PCG |
| `pcg_affranchissement` | 624, 625, 6241 | Préfixes PCG |
| `pcg_packaging` | 604, 6044 | Préfixes PCG |
| `pcg_publicite` | 622, 623, 6231 | Préfixes PCG |

---

## ⏰ Synchronisation automatique (cron)

Ajouter dans **cPanel → Tâches Cron** (o2switch) :

```bash
0 3 * * * /usr/local/bin/php /home/USER/public_html/DOLIBARR/htdocs/rentabiliteoctopia/cron/sync_octopia.php >> /tmp/rentabiliteoctopia_cron.log 2>&1
```

Le script cron synchronise **le mois courant + le mois précédent** (pour rattraper les commandes dont le statut a changé après clôture).

Vérifier les logs :
```bash
tail -50 /tmp/rentabiliteoctopia_cron.log
```

---

## 🔐 Permissions

| Permission | Description |
|---|---|
| `rentabiliteoctopia.read` | Lecture du tableau de bord et des produits |
| `rentabiliteoctopia.write` | Saisie des frais, sync, catégories, paramètres |

---

## 🐛 Historique des corrections

### v1.2.1

| # | Fichier | Description |
|---|---|---|
| 1 | `admin/admin.php` | **Typo lang key** : `rentabiliteocternity` → `rentabiliteoctopia` (module non traduit) |
| 2 | Tous les fichiers POST | **CSRF validation cassée** : `newToken()` régénérait `$_SESSION['newtoken']` AVANT la comparaison, rendant la vérification toujours fausse. Correction : comparaison directe puis `newToken()` après usage valide |
| 3 | `modRentabiliteOctopia.class.php` | **`config_page_url`** pointait vers `admin.php` (root, obsolète) au lieu de `admin/admin.php` |
| 4 | `admin.php` (root) | Clés de paramètres obsolètes (`commission_pct`, `abonnement_mois`…) causant des PHP Warnings. Remplacé par une redirection vers `admin/admin.php` |
| 5 | `categories.php` | **Suppression sans CSRF** : le DELETE passait en GET sans token. Remplacé par un formulaire POST avec token |
| 6 | `produits.php` | **`delete_produit` sans CSRF** : même vulnérabilité, corrigée |
| 7 | `OctopiaRentabiliteSync.class.php` | **`GROUP BY p.cost_price`** pouvait créer des doublons si le `cost_price` variait. Remplacé par `MAX(COALESCE(p.cost_price, 0))` |
| 8 | `sync.php` + `OctopiaRentabiliteSync.class.php` | **Compatibilité octopiaSync** : réécriture complète des requêtes SQL pour utiliser `llx_octopia_orders` + tables Dolibarr natives (`llx_commande`, `llx_commandedet`, `llx_product`) au lieu des tables `llx_octopiaSync_order` / `llx_octopiaSync_orderline` inexistantes |

---

## 🔗 Dépendance avec octopiaSync

Ce module est un **satellite de octopiaSync**. Il ne stocke pas lui-même les commandes — il les lit depuis les tables d'octopiaSync et les tables natives Dolibarr.

Workflow complet :
```
API Octopia → [octopiaSync] → llx_octopia_orders (+ llx_commande / llx_commandedet)
                                        ↓
                           [rentabiliteoctopia sync]
                                        ↓
                  llx_rentabiliteoctopia_vente  ←  llx_rentabiliteoctopia_produit
                  llx_rentabiliteoctopia_frais  ←  import factures fournisseur
                                        ↓
                              Tableau de bord rentabilité
```

Si octopiaSync n'est pas installé (`llx_octopia_orders` absente), la page de synchronisation affiche une alerte et les boutons sont désactivés. Le tableau de bord fonctionne avec des données saisies manuellement.

---

## 👤 Auteur

**ABCduWeb** — Agence web Drôme/Ardèche  
🌐 [https://www.abcduweb.fr](https://www.abcduweb.fr)

---

## 📄 Licence

Module propriétaire — Usage interne / commercial ABCduWeb.  
Ne pas redistribuer sans autorisation.
