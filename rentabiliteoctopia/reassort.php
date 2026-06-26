<?php
/**
 * Reassort / gestion de stock - Prevision des ruptures
 *
 * Croise la vitesse de vente Octopia (unites/jour sur les N derniers jours)
 * avec le stock physique Dolibarr (llx_product_stock) pour calculer :
 *   - le nombre de jours de stock restant
 *   - la date de rupture estimee
 *   - la quantite a commander pour couvrir X jours
 *
 * Tri par urgence (rupture la plus proche en premier).
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';

if (!$user->rights->rentabiliteoctopia->read) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');
$langs->load('stocks');

$params = rentabiliteoctopia_get_params($db);

// Parametres de calcul (depuis URL ou defauts)
$periodeAnalyse = (int)(GETPOST('periode', 'int') ?: 30);  // jours d'historique pour la vitesse
$couvertureCible = (int)(GETPOST('couverture', 'int') ?: 30); // jours de stock a couvrir
$delaiAppro = (int)(GETPOST('delai_appro', 'int') ?: 7);     // delai fournisseur

if ($periodeAnalyse < 7) $periodeAnalyse = 7;
if ($couvertureCible < 1) $couvertureCible = 30;

// ============================================================================
// REQUETE : vitesse de vente + stock
// ============================================================================
$sql = "SELECT
            p.rowid                                       AS product_id,
            p.ref                                         AS ref,
            p.label                                       AS label,
            COALESCE(SUM(ps.reel), 0)                     AS stock_actuel,
            COALESCE(vente.qty_vendue, 0)                 AS qty_vendue,
            COALESCE(vente.nb_jours_actifs, 0)            AS nb_jours_actifs,
            COALESCE(p.cost_price, 0)                     AS cout_achat,
            COALESCE(p.seuil_stock_alerte, 0)             AS seuil_alerte
        FROM ".MAIN_DB_PREFIX."product p
        LEFT JOIN ".MAIN_DB_PREFIX."product_stock ps ON ps.fk_product = p.rowid
        LEFT JOIN (
            SELECT
                cd.fk_product,
                SUM(cd.qty)                                AS qty_vendue,
                COUNT(DISTINCT DATE(c.date_commande))      AS nb_jours_actifs
            FROM ".MAIN_DB_PREFIX."octopia_orders o
            INNER JOIN ".MAIN_DB_PREFIX."commande c
                ON  c.rowid = o.dolibarr_order_id
                AND c.entity = ".((int)$conf->entity)."
                AND c.fk_statut >= 1
                AND c.date_commande >= DATE_SUB(CURDATE(), INTERVAL ".$periodeAnalyse." DAY)
            INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid AND cd.fk_product > 0
            WHERE o.entity = ".((int)$conf->entity)."
              AND o.is_refunded = 0
              AND (o.octopia_order_status IS NULL OR o.octopia_order_status NOT IN ('CANCELLED','REFUNDED','REFUSED','CANCELED'))
            GROUP BY cd.fk_product
        ) vente ON vente.fk_product = p.rowid
        WHERE p.entity IN (0, ".((int)$conf->entity).")
          AND p.tosell = 1
          AND p.fk_product_type = 0
        GROUP BY p.rowid, p.ref, p.label, vente.qty_vendue, vente.nb_jours_actifs, p.cost_price, p.seuil_stock_alerte
        HAVING qty_vendue > 0 OR stock_actuel > 0
        ORDER BY ref
        LIMIT 2000";

$resql = $db->query($sql);
$produits = array();
while ($resql && $o = $db->fetch_object($resql)) {
    $qtyVendue = (int)$o->qty_vendue;
    $stock = (int)$o->stock_actuel;

    // Vitesse = unites par jour (sur la periode d'analyse complete, pas seulement jours actifs,
    // pour lisser : on divise par la periode entiere)
    $vitesseJour = $periodeAnalyse > 0 ? ($qtyVendue / $periodeAnalyse) : 0;

    // Jours de stock restant
    $joursRestant = $vitesseJour > 0 ? ($stock / $vitesseJour) : null;

    // Date de rupture
    $dateRupture = ($joursRestant !== null) ? strtotime('+'.round($joursRestant).' days') : null;

    // Quantite a commander pour couvrir la cible + delai appro
    $besoinTotal = $vitesseJour * ($couvertureCible + $delaiAppro);
    $aCommander = max(0, ceil($besoinTotal - $stock));

    $produits[] = array(
        'id'          => (int)$o->product_id,
        'ref'         => $o->ref,
        'label'       => $o->label,
        'stock'       => $stock,
        'qty_vendue'  => $qtyVendue,
        'vitesse'     => $vitesseJour,
        'jours_restant' => $joursRestant,
        'date_rupture'=> $dateRupture,
        'a_commander' => $aCommander,
        'cout_achat'  => (float)$o->cout_achat,
        'cout_commande' => $aCommander * (float)$o->cout_achat,
    );
}

// Tri par urgence : jours restant croissant (NULL = pas de vente = en bas)
usort($produits, function($a, $b) {
    if ($a['jours_restant'] === null && $b['jours_restant'] === null) return 0;
    if ($a['jours_restant'] === null) return 1;
    if ($b['jours_restant'] === null) return -1;
    return $a['jours_restant'] <=> $b['jours_restant'];
});

// Compteurs
$nbRupture = 0;      // deja en rupture (stock 0 avec ventes)
$nbCritique = 0;     // rupture < delai appro
$nbAlerte = 0;       // rupture < couverture cible
$coutTotalReassort = 0;
foreach ($produits as $p) {
    if ($p['vitesse'] > 0) {
        if ($p['stock'] <= 0) $nbRupture++;
        elseif ($p['jours_restant'] !== null && $p['jours_restant'] <= $delaiAppro) $nbCritique++;
        elseif ($p['jours_restant'] !== null && $p['jours_restant'] <= $couvertureCible) $nbAlerte++;
    }
    $coutTotalReassort += $p['cout_commande'];
}

// ============================================================================
// AFFICHAGE
// ============================================================================
llxHeader('', 'Reassort');
print load_fiche_titre('Reassort & prevision des ruptures', '', 'fa-truck-loading');

// Filtres
print '<form method="GET" action="reassort.php" style="background:#f0f0f0;padding:14px;border-radius:6px;margin-bottom:20px;display:flex;gap:20px;flex-wrap:wrap;align-items:end;">';
print '<div><label style="font-size:12px;color:#666;display:block;">Periode d\'analyse des ventes</label>';
print '<select name="periode" class="flat">';
foreach (array(7=>'7 jours',14=>'14 jours',30=>'30 jours',60=>'60 jours',90=>'90 jours') as $v=>$lbl) {
    print '<option value="'.$v.'"'.($periodeAnalyse==$v?' selected':'').'>'.$lbl.'</option>';
}
print '</select></div>';

print '<div><label style="font-size:12px;color:#666;display:block;">Couverture cible</label>';
print '<select name="couverture" class="flat">';
foreach (array(15=>'15 jours',30=>'30 jours',45=>'45 jours',60=>'60 jours',90=>'90 jours') as $v=>$lbl) {
    print '<option value="'.$v.'"'.($couvertureCible==$v?' selected':'').'>'.$lbl.'</option>';
}
print '</select></div>';

print '<div><label style="font-size:12px;color:#666;display:block;">Delai appro fournisseur</label>';
print '<input type="number" name="delai_appro" value="'.$delaiAppro.'" min="0" max="90" style="width:70px;text-align:right;padding:6px;border:1px solid #ddd;border-radius:4px;"> jours</div>';

print '<button type="submit" class="button">Recalculer</button>';
print '</form>';

// KPI
print '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:24px;">';

$kpiCards = array(
    array('En rupture',        $nbRupture,  '#c0392b', 'Stock 0 avec ventes actives'),
    array('Critique',          $nbCritique, '#e67e22', 'Rupture sous le delai appro'),
    array('A surveiller',      $nbAlerte,   '#f39c12', 'Rupture sous la couverture cible'),
    array('Cout reassort total', roc_eur($coutTotalReassort), '#3498db', 'Pour couvrir '.$couvertureCible.'j'),
);
foreach ($kpiCards as $c) {
    print '<div style="background:#fff;border-left:4px solid '.$c[2].';padding:14px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
    print '<div style="font-size:11px;color:#888;text-transform:uppercase;">'.$c[0].'</div>';
    print '<div style="font-size:26px;font-weight:bold;color:'.$c[2].';margin:4px 0;">'.$c[1].'</div>';
    print '<div style="font-size:11px;color:#666;">'.$c[3].'</div>';
    print '</div>';
}
print '</div>';

// Tableau
print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>Reference</th>';
print '<th>Designation</th>';
print '<th class="right">Stock</th>';
print '<th class="right">Vendu ('.$periodeAnalyse.'j)</th>';
print '<th class="right">Vitesse/jour</th>';
print '<th class="right">Jours restant</th>';
print '<th>Rupture estimee</th>';
print '<th class="right">A commander</th>';
print '<th class="right">Cout</th>';
print '</tr>';

$today = strtotime(date('Y-m-d'));
foreach ($produits as $p) {
    // Couleur de ligne selon urgence
    $rowStyle = '';
    $urgenceLabel = '';
    if ($p['vitesse'] > 0) {
        if ($p['stock'] <= 0) {
            $rowStyle = 'background:#fdebe5;';
            $urgenceLabel = '<span style="color:#c0392b;font-weight:bold;">● RUPTURE</span>';
        } elseif ($p['jours_restant'] !== null && $p['jours_restant'] <= $delaiAppro) {
            $rowStyle = 'background:#fef5e7;';
            $urgenceLabel = '<span style="color:#e67e22;font-weight:bold;">● Critique</span>';
        } elseif ($p['jours_restant'] !== null && $p['jours_restant'] <= $couvertureCible) {
            $rowStyle = 'background:#fffbf0;';
            $urgenceLabel = '<span style="color:#f39c12;">● Surveiller</span>';
        } else {
            $urgenceLabel = '<span style="color:#27ae60;">● OK</span>';
        }
    } else {
        $urgenceLabel = '<span style="color:#aaa;">Pas de vente</span>';
    }

    print '<tr class="oddeven" style="'.$rowStyle.'">';
    print '<td><b><code>'.dol_escape_htmltag($p['ref']).'</code></b></td>';
    print '<td>'.dol_escape_htmltag(substr($p['label'], 0, 45)).'</td>';
    print '<td class="right"><b>'.$p['stock'].'</b></td>';
    print '<td class="right">'.$p['qty_vendue'].'</td>';
    print '<td class="right">'.number_format($p['vitesse'], 2, ',', '').'</td>';

    // Jours restant
    print '<td class="right">';
    if ($p['jours_restant'] === null) {
        print '<span style="color:#aaa;">∞</span>';
    } else {
        $jr = round($p['jours_restant']);
        $jrColor = $jr <= $delaiAppro ? '#c0392b' : ($jr <= $couvertureCible ? '#e67e22' : '#27ae60');
        print '<b style="color:'.$jrColor.'">'.$jr.' j</b>';
    }
    print '</td>';

    // Date rupture
    print '<td>';
    if ($p['date_rupture'] !== null) {
        if ($p['stock'] <= 0) {
            print '<span style="color:#c0392b;font-weight:bold;">Maintenant</span>';
        } else {
            print dol_print_date($p['date_rupture'], 'day');
        }
    } else {
        print '<span style="color:#aaa;">—</span>';
    }
    print ' '.$urgenceLabel;
    print '</td>';

    // A commander
    print '<td class="right">';
    if ($p['a_commander'] > 0) {
        print '<b style="color:#3498db;font-size:15px;">'.$p['a_commander'].'</b>';
    } else {
        print '<span style="color:#27ae60;">—</span>';
    }
    print '</td>';

    // Cout
    print '<td class="right">';
    if ($p['cout_commande'] > 0) {
        print roc_eur($p['cout_commande']);
    } elseif ($p['a_commander'] > 0 && $p['cout_achat'] == 0) {
        print '<span style="color:#e67e22;font-size:11px;" title="Cout d\'achat non renseigne">? €</span>';
    } else {
        print '<span style="color:#aaa;">—</span>';
    }
    print '</td>';

    print '</tr>';
}
print '</table>';

print '<p style="font-size:12px;color:#888;margin-top:14px;">';
print '<b>Methode de calcul :</b> Vitesse/jour = unites vendues sur '.$periodeAnalyse.' jours / '.$periodeAnalyse.'. ';
print 'Jours restant = stock actuel / vitesse. ';
print 'A commander = vitesse × (couverture cible '.$couvertureCible.'j + delai appro '.$delaiAppro.'j) − stock actuel. ';
print 'Le stock provient de Dolibarr (tous entrepots confondus).';
print '</p>';
print '</div>';

// Note si pas de stock geré
$sqlStockCheck = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."product_stock";
$rSC = $db->query($sqlStockCheck);
$nbStock = ($rSC && $oSC = $db->fetch_object($rSC)) ? (int)$oSC->nb : 0;
if ($nbStock === 0) {
    print '<div class="warning" style="padding:12px;border-radius:4px;margin-top:16px;">';
    print '<b>&#9888; Aucun stock enregistre dans Dolibarr</b><br>';
    print 'La table product_stock est vide. Pour utiliser le reassort, activez le module Stock dans Dolibarr et renseignez les quantites par entrepot. ';
    print 'En attendant, seule la vitesse de vente est exploitable (colonnes stock a 0).';
    print '</div>';
}

llxFooter();
$db->close();
