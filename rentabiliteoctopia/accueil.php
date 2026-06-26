<?php
/**
 * Accueil - Vue d'ensemble actionnable du module.
 *
 * Regroupe en une page les indicateurs cles et les points d'action :
 *   - alertes actives, ruptures, manque a gagner, tresorerie due
 *   - acces rapide a toutes les analyses
 *
 * C'est le point d'entree recommande : on voit immediatement ou agir.
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';
require_once __DIR__.'/lib/ModuleHelper.class.php';
require_once __DIR__.'/lib/CacheMois.class.php';
require_once __DIR__.'/lib/AlertesEngine.class.php';

if (!$user->rights->rentabiliteoctopia->read) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$params = rentabiliteoctopia_get_params($db);
$entity = (int)$conf->entity;
$annee = (int)date('Y');
$mois = (int)date('m');
$seuilMarge = (float)($params['seuil_marge_pct'] ?? 15);

llxHeader('', 'Accueil Rentabilite Octopia');
print load_fiche_titre('Rentabilite Octopia — vue d\'ensemble', '', 'fa-home');
ModuleHelper::navBar('accueil.php');

// ============================================================================
// COLLECTE DES INDICATEURS (chaque bloc protege)
// ============================================================================

// --- Mois en cours ---
$caMois = 0; $margeMois = 0; $nbCmdMois = 0;
try {
    $cache = new CacheMois($db, $entity);
    $aggMois = $cache->get($annee, $mois, $params);
    $caMois = $aggMois['ca'];
    $margeMois = $aggMois['marge_nette'];
} catch (Exception $e) {}

// Commandes du mois
try {
    $sql = "SELECT COUNT(DISTINCT o.rowid) AS nb
            FROM ".MAIN_DB_PREFIX."octopia_orders o
            INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = o.dolibarr_order_id
                AND c.entity = ".$entity." AND c.fk_statut >= 1
                AND YEAR(c.date_commande) = ".$annee." AND MONTH(c.date_commande) = ".$mois."
            WHERE o.entity = ".$entity." AND o.is_refunded = 0";
    $r = $db->query($sql);
    if ($r && $o = $db->fetch_object($r)) $nbCmdMois = (int)$o->nb;
} catch (Exception $e) {}

// --- Alertes ---
$nbAlertesCritiques = 0; $nbAlertesWarning = 0;
try {
    $engine = new AlertesEngine($db, $entity, $params);
    $alertes = $engine->getAlertes();
    $nbAlertesCritiques = count($alertes['critique']);
    $nbAlertesWarning = count($alertes['warning']);
} catch (Exception $e) {}

// --- Ruptures ---
$nbRuptures = 0;
try {
    $sql = "SELECT COUNT(*) AS nb FROM (
                SELECT p.rowid, COALESCE(SUM(ps.reel),0) AS stock, COALESCE(v.qty,0) AS vendu
                FROM ".MAIN_DB_PREFIX."product p
                LEFT JOIN ".MAIN_DB_PREFIX."product_stock ps ON ps.fk_product = p.rowid
                LEFT JOIN (
                    SELECT cd.fk_product, SUM(cd.qty) AS qty
                    FROM ".MAIN_DB_PREFIX."octopia_orders o
                    INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = o.dolibarr_order_id
                        AND c.entity = ".$entity." AND c.fk_statut >= 1
                        AND c.date_commande >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid AND cd.fk_product > 0
                    WHERE o.entity = ".$entity." AND o.is_refunded = 0
                    GROUP BY cd.fk_product
                ) v ON v.fk_product = p.rowid
                WHERE p.entity IN (0,".$entity.") AND p.tosell = 1 AND p.fk_product_type = 0
                GROUP BY p.rowid, v.qty
                HAVING vendu > 0 AND stock <= 0
            ) sub";
    $r = $db->query($sql);
    if ($r && $o = $db->fetch_object($r)) $nbRuptures = (int)$o->nb;
} catch (Exception $e) {}

// --- Tresorerie due ---
$tresoDue = 0;
try {
    $freqJours = (int)($params['treso_freq_jours'] ?? 10);
    if ($freqJours < 1) $freqJours = 10;
    // Net du a Cdiscount = TTC collecte - commission reelle (par categorie) sur les ventes recentes non encore reversees
    $sql = "SELECT
                COALESCE(SUM(cd.qty * cd.subprice * (1 + COALESCE(cd.tva_tx,20)/100)), 0) AS ttc,
                COALESCE(SUM(CASE WHEN cd.fk_product IS NULL THEN 0
                    ELSE cd.qty * cd.subprice * COALESCE(cat.commission_pct, 15)/100 END), 0) AS commission
            FROM ".MAIN_DB_PREFIX."octopia_orders o
            INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = o.dolibarr_order_id
                AND c.entity = ".$entity." AND c.fk_statut >= 1
                AND c.date_commande >= DATE_SUB(CURDATE(), INTERVAL ".$freqJours." DAY)
            INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
            LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit rp
                ON rp.ref = (SELECT ref FROM ".MAIN_DB_PREFIX."product WHERE rowid = cd.fk_product)
                AND rp.entity = ".$entity."
            LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie cat
                ON cat.rowid = rp.fk_categorie AND cat.entity = ".$entity."
            WHERE o.entity = ".$entity." AND o.is_refunded = 0";
    $r = $db->query($sql);
    if ($r && $o = $db->fetch_object($r)) $tresoDue = (float)$o->ttc - (float)$o->commission;
} catch (Exception $e) {}

// --- Manque a gagner (produits sous-tarifes) ---
$manqueGagner = 0; $nbSousTarif = 0;
try {
    require_once __DIR__.'/lib/PricingEngine.class.php';
    $tauxRetour = (float)($params['taux_retour_pct'] ?? 3);
    $coutRetour = (float)($params['cout_retour'] ?? 2.50);
    $sql = "SELECT
                SUM(v.qty_vendue) AS qty,
                SUM(v.qty_vendue * v.prix_ht)/NULLIF(SUM(v.qty_vendue),0) AS prix_reel,
                SUM(v.qty_vendue * v.cout_achat)/NULLIF(SUM(v.qty_vendue),0) AS cout,
                AVG(COALESCE(v.commission_pct, c.commission_pct, 0)) AS comm
            FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
            INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
            LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
            WHERE v.annee = ".$annee." AND v.entity = ".$entity."
              AND p.ref NOT LIKE 'ORPHELIN-%' AND p.ref NOT LIKE 'LIBRE:%'
            GROUP BY p.rowid
            HAVING qty > 0";
    $r = $db->query($sql);
    while ($r && $o = $db->fetch_object($r)) {
        $prixReel = (float)$o->prix_reel; $cout = (float)$o->cout;
        $qty = (int)$o->qty; $comm = (float)$o->comm;
        $ideal = PricingEngine::prixPourMarge(array(
            'cout_achat'=>$cout, 'commission_pct'=>$comm, 'marge_cible'=>$seuilMarge,
            'retour_taux'=>$tauxRetour, 'retour_cout'=>$coutRetour,
        ));
        if ($ideal && $prixReel < $ideal['pv_ht'] - 0.10) {
            $nbSousTarif++;
            $margeReelle = $prixReel - $cout - ($tauxRetour/100*$coutRetour) - ($prixReel*$comm/100);
            $gainUnit = $ideal['marge_nette'] - $margeReelle;
            if ($gainUnit > 0) $manqueGagner += $gainUnit * $qty;
        }
    }
} catch (Exception $e) {}

// ============================================================================
// AFFICHAGE - Cartes d'action
// ============================================================================

// Bandeau resume du mois
$moisNoms = array('','Janvier','Fevrier','Mars','Avril','Mai','Juin','Juillet','Aout','Septembre','Octobre','Novembre','Decembre');
print '<div style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:24px;border-radius:8px;margin-bottom:24px;">';
print '<div style="font-size:13px;opacity:0.9;text-transform:uppercase;">'.$moisNoms[$mois].' '.$annee.'</div>';
print '<div style="display:flex;gap:40px;flex-wrap:wrap;margin-top:12px;">';
print '<div><div style="font-size:12px;opacity:0.8;">CA du mois</div><div style="font-size:28px;font-weight:bold;">'.roc_eur($caMois).'</div></div>';
print '<div><div style="font-size:12px;opacity:0.8;">Marge nette</div><div style="font-size:28px;font-weight:bold;color:'.($margeMois>=0?'#a8e6a8':'#ffb3b3').';">'.roc_eur($margeMois).'</div></div>';
print '<div><div style="font-size:12px;opacity:0.8;">Commandes</div><div style="font-size:28px;font-weight:bold;">'.$nbCmdMois.'</div></div>';
print '</div></div>';

// Cartes d'action (ce qui necessite attention)
print '<h3 style="margin-bottom:12px;">Points d\'attention</h3>';
print '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:24px;">';

// Alertes
$alerteColor = $nbAlertesCritiques > 0 ? '#c0392b' : ($nbAlertesWarning > 0 ? '#e67e22' : '#27ae60');
print '<a href="alertes.php" style="text-decoration:none;">';
print '<div style="background:#fff;border-left:4px solid '.$alerteColor.';padding:18px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);transition:transform 0.1s;cursor:pointer;">';
print '<div style="display:flex;justify-content:space-between;align-items:center;">';
print '<div><div style="font-size:11px;color:#888;text-transform:uppercase;">Alertes actives</div>';
print '<div style="font-size:30px;font-weight:bold;color:'.$alerteColor.';">'.($nbAlertesCritiques+$nbAlertesWarning).'</div></div>';
print '<i class="fa fa-bell" style="font-size:28px;color:'.$alerteColor.'33;"></i>';
print '</div>';
if ($nbAlertesCritiques > 0) print '<div style="font-size:12px;color:#c0392b;">'.$nbAlertesCritiques.' critique(s)</div>';
else print '<div style="font-size:12px;color:#888;">Voir le detail →</div>';
print '</div></a>';

// Ruptures
$ruptColor = $nbRuptures > 0 ? '#c0392b' : '#27ae60';
print '<a href="reassort.php" style="text-decoration:none;">';
print '<div style="background:#fff;border-left:4px solid '.$ruptColor.';padding:18px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);cursor:pointer;">';
print '<div style="display:flex;justify-content:space-between;align-items:center;">';
print '<div><div style="font-size:11px;color:#888;text-transform:uppercase;">Ruptures de stock</div>';
print '<div style="font-size:30px;font-weight:bold;color:'.$ruptColor.';">'.$nbRuptures.'</div></div>';
print '<i class="fa fa-truck-loading" style="font-size:28px;color:'.$ruptColor.'33;"></i>';
print '</div>';
print '<div style="font-size:12px;color:#888;">Gerer le reassort →</div>';
print '</div></a>';

// Tresorerie
print '<a href="tresorerie.php" style="text-decoration:none;">';
print '<div style="background:#fff;border-left:4px solid #e67e22;padding:18px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);cursor:pointer;">';
print '<div style="display:flex;justify-content:space-between;align-items:center;">';
print '<div><div style="font-size:11px;color:#888;text-transform:uppercase;">Cdiscount vous doit</div>';
print '<div style="font-size:24px;font-weight:bold;color:#e67e22;">'.roc_eur($tresoDue).'</div></div>';
print '<i class="fa fa-euro-sign" style="font-size:28px;color:#e67e2233;"></i>';
print '</div>';
print '<div style="font-size:12px;color:#888;">Suivi tresorerie →</div>';
print '</div></a>';

// Manque a gagner
$mgColor = $manqueGagner > 50 ? '#c0392b' : ($manqueGagner > 0 ? '#e67e22' : '#27ae60');
print '<a href="optimisation_prix.php" style="text-decoration:none;">';
print '<div style="background:#fff;border-left:4px solid '.$mgColor.';padding:18px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);cursor:pointer;">';
print '<div style="display:flex;justify-content:space-between;align-items:center;">';
print '<div><div style="font-size:11px;color:#888;text-transform:uppercase;">Manque a gagner/an</div>';
print '<div style="font-size:24px;font-weight:bold;color:'.$mgColor.';">'.roc_eur($manqueGagner).'</div></div>';
print '<i class="fa fa-balance-scale" style="font-size:28px;color:'.$mgColor.'33;"></i>';
print '</div>';
if ($nbSousTarif > 0) print '<div style="font-size:12px;color:'.$mgColor.';">'.$nbSousTarif.' produit(s) sous-tarife(s)</div>';
else print '<div style="font-size:12px;color:#888;">Optimiser les prix →</div>';
print '</div></a>';

print '</div>';

// Acces rapide aux analyses
print '<h3 style="margin-bottom:12px;">Analyses</h3>';
print '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">';
$liens = array(
    array('index.php', 'Tableau de bord detaille', 'fa-tachometer-alt', 'KPI mensuels et liste produits'),
    array('sante.php', 'Sante de l\'entreprise', 'fa-heartbeat', 'Vue annuelle, point mort, projection'),
    array('simulateur.php', 'Simulateur de prix', 'fa-calculator', 'Calculer un prix de vente'),
    array('optimisation_prix.php', 'Optimisation prix', 'fa-balance-scale', 'Reel vs ideal, manque a gagner'),
    array('historique_prix.php', 'Historique prix', 'fa-history', 'Impact des changements de prix'),
    array('saisonnalite.php', 'Saisonnalite', 'fa-chart-line', 'Pics saisonniers sur 24 mois'),
    array('fournisseurs.php', 'Vue fournisseurs', 'fa-industry', 'Rentabilite par fournisseur'),
    array('produits.php', 'Produits & ventes', 'fa-boxes', 'Saisie et consultation'),
    array('diagnostic.php', 'Diagnostic', 'fa-stethoscope', 'Verifier l\'integrite du module'),
);
foreach ($liens as $l) {
    print '<a href="'.$l[0].'" style="text-decoration:none;">';
    print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,0.08);cursor:pointer;border:1px solid #f0f0f0;">';
    print '<div style="font-size:14px;font-weight:bold;color:#333;"><i class="fa '.$l[2].'" style="color:#667eea;margin-right:8px;"></i>'.$l[1].'</div>';
    print '<div style="font-size:12px;color:#888;margin-top:4px;">'.$l[3].'</div>';
    print '</div></a>';
}
print '</div>';

llxFooter();
$db->close();
