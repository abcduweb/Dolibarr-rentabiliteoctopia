<?php
/**
 * Optimisation des prix - Confronte le prix de vente reel au prix ideal calcule.
 *
 * Pour chaque produit vendu :
 *   - prix de vente reel observe = moyenne ponderee des prix de vente Octopia
 *   - prix ideal = celui qui atteindrait la marge nette cible (meme formule que le simulateur)
 *   - ecart de prix + manque a gagner annuel chiffre (extrapole sur le volume vendu)
 *
 * Permet d'identifier d'un coup d'oeil :
 *   - les produits sous-tarifes (prix reel < prix ideal -> tu perds de la marge)
 *   - les produits sur-tarifes (prix reel > prix ideal -> risque de moindre volume)
 *   - le manque a gagner total annualise si tu alignais tous tes prix
 *
 * Reutilise la formule du simulateur : PV_HT = couts_fixes / (1 - commission% - marge%)
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';
require_once __DIR__.'/lib/PricingEngine.class.php';

if (!$user->rights->rentabiliteoctopia->read) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$params = rentabiliteoctopia_get_params($db);

// Parametres ajustables (depuis URL)
$annee       = (int)(GETPOST('annee', 'int') ?: date('Y'));
$margeCible  = (float)(GETPOST('marge_cible', 'alpha') !== '' ? str_replace(',', '.', GETPOST('marge_cible', 'alpha')) : ($params['seuil_marge_pct'] ?? 15));
$inclPort    = GETPOST('incl_port', 'int') === '' ? 1 : (int)GETPOST('incl_port', 'int');
$inclRetour  = GETPOST('incl_retour', 'int') === '' ? 1 : (int)GETPOST('incl_retour', 'int');
$fraisPortDef = (float)(GETPOST('frais_port', 'alpha') !== '' ? str_replace(',', '.', GETPOST('frais_port', 'alpha')) : 0);

$tauxRetour  = (float)($params['taux_retour_pct'] ?? 3);
$coutRetour  = (float)($params['cout_retour'] ?? 2.50);

// ============================================================================
// REQUETE : prix reel + cout + commission par produit
// ============================================================================
$sql = "SELECT
            p.rowid                                    AS product_id,
            p.ref                                      AS ref,
            p.designation                              AS designation,
            c.label                                    AS cat_label,
            COALESCE(c.commission_pct, 0)              AS cat_commission,
            SUM(v.qty_vendue)                          AS qty_totale,
            SUM(v.qty_vendue * v.prix_ht)              AS ca_total,
            SUM(v.qty_vendue * v.prix_ht) / NULLIF(SUM(v.qty_vendue),0) AS prix_reel_moyen,
            SUM(v.qty_vendue * v.cout_achat) / NULLIF(SUM(v.qty_vendue),0) AS cout_moyen,
            AVG(COALESCE(v.commission_pct, c.commission_pct, 0)) AS commission_pct_moy
        FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
        INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
        LEFT  JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
        WHERE v.annee = ".$annee." AND v.entity = ".((int)$conf->entity)."
          AND p.ref NOT LIKE 'ORPHELIN-%' AND p.ref NOT LIKE 'LIBRE:%'
        GROUP BY p.rowid, p.ref, p.designation, c.label, c.commission_pct
        HAVING qty_totale > 0
        LIMIT 2000";

$resql = $db->query($sql);
$produits = array();
$totalManqueGagner = 0;
$nbSousTarif = 0;
$nbSurTarif = 0;
$nbAligne = 0;

while ($resql && $o = $db->fetch_object($resql)) {
    $prixReel = (float)$o->prix_reel_moyen;
    $cout     = (float)$o->cout_moyen;
    $qty      = (int)$o->qty_totale;
    $commPct  = (float)$o->commission_pct_moy;

    // Calcul via PricingEngine centralise
    $peInputs = array(
        'cout_achat'     => $cout,
        'commission_pct' => $commPct,
        'marge_cible'    => $margeCible,
        'port_charge'    => $inclPort ? $fraisPortDef : 0,
        'retour_taux'    => $inclRetour ? $tauxRetour : 0,
        'retour_cout'    => $inclRetour ? $coutRetour : 0,
    );
    $ideal = PricingEngine::prixPourMarge($peInputs);
    $prixIdeal = ($ideal !== null) ? $ideal['pv_ht'] : null;
    $coutsFixes = ($ideal !== null) ? $ideal['couts_fixes'] : ($cout + ($inclPort?$fraisPortDef:0) + ($inclRetour?$tauxRetour/100*$coutRetour:0));

    // Marge reelle actuelle (sur le prix reel) via le mode inverse
    $reel = PricingEngine::margePourPrix($prixReel, $peInputs);
    $margeReelle = $reel['marge_nette'];
    $tauxMargeReel = $reel['marge_pct'];

    // Ecart de prix et manque a gagner
    $ecartPrix = ($prixIdeal !== null) ? ($prixIdeal - $prixReel) : 0;
    $margeIdeale = ($ideal !== null) ? $ideal['marge_nette'] : 0;
    $gainUnitaire = $margeIdeale - $margeReelle;
    $manqueGagner = $gainUnitaire * $qty;

    // Classification
    $statut = 'aligne';
    if ($prixIdeal !== null) {
        if ($prixReel < $prixIdeal - 0.10) { $statut = 'sous'; $nbSousTarif++; }
        elseif ($prixReel > $prixIdeal + 0.10) { $statut = 'sur'; $nbSurTarif++; }
        else { $nbAligne++; }
    }

    // On ne compte que le manque a gagner positif (produits sous-tarifes)
    if ($statut === 'sous' && $manqueGagner > 0) {
        $totalManqueGagner += $manqueGagner;
    }

    $produits[] = array(
        'ref'           => $o->ref,
        'designation'   => $o->designation,
        'cat'           => $o->cat_label,
        'qty'           => $qty,
        'prix_reel'     => $prixReel,
        'prix_ideal'    => $prixIdeal,
        'cout'          => $cout,
        'comm_pct'      => $commPct,
        'marge_reelle'  => $margeReelle,
        'taux_marge'    => $tauxMargeReel,
        'ecart_prix'    => $ecartPrix,
        'manque_gagner' => $manqueGagner,
        'statut'        => $statut,
        'ca_total'      => (float)$o->ca_total,
    );
}

// Tri : plus gros manque a gagner en premier
usort($produits, function($a, $b) {
    return $b['manque_gagner'] <=> $a['manque_gagner'];
});

// ============================================================================
// AFFICHAGE
// ============================================================================
llxHeader('', 'Optimisation des prix');
print load_fiche_titre('Optimisation des prix — reel vs ideal', '', 'fa-balance-scale');

// Filtres
print '<form method="GET" action="optimisation_prix.php" style="background:#f0f0f0;padding:16px;border-radius:6px;margin-bottom:20px;display:flex;gap:20px;flex-wrap:wrap;align-items:end;">';

print '<div><label style="font-size:12px;color:#666;display:block;">Annee</label>';
print '<select name="annee" class="flat">';
for ($y = date('Y'); $y >= date('Y')-2; $y--) {
    print '<option value="'.$y.'"'.($annee==$y?' selected':'').'>'.$y.'</option>';
}
print '</select></div>';

print '<div><label style="font-size:12px;color:#666;display:block;"><b>Marge nette cible (%)</b></label>';
print '<input type="number" name="marge_cible" value="'.$margeCible.'" step="0.5" min="0" max="80" style="width:90px;text-align:right;padding:6px;border:1px solid #ddd;border-radius:4px;"></div>';

print '<div><label style="font-size:12px;color:#666;display:block;">Frais de port a charge (€)</label>';
print '<input type="number" name="frais_port" value="'.$fraisPortDef.'" step="0.01" min="0" style="width:80px;text-align:right;padding:6px;border:1px solid #ddd;border-radius:4px;"></div>';

print '<div><label style="font-size:12px;color:#666;display:block;">Inclure</label>';
print '<label style="font-size:12px;"><input type="checkbox" name="incl_port" value="1"'.($inclPort?' checked':'').'> Port</label> &nbsp;';
print '<label style="font-size:12px;"><input type="checkbox" name="incl_retour" value="1"'.($inclRetour?' checked':'').'> Retours</label></div>';

print '<button type="submit" class="button">Recalculer</button>';
print '</form>';

// Bouton export CSV
print '<div style="margin-bottom:16px;display:flex;gap:8px;">';
print '<a href="export.php?type=optimisation&annee='.$annee.'" class="button" style="background:#16a085;"><i class="fa fa-file-excel"></i> Exporter en CSV</a>';
print '<a href="export.php?type=optimisation&format=pdf&annee='.$annee.'" class="button" style="background:#c0392b;"><i class="fa fa-file-pdf"></i> Exporter en PDF</a>';
print '</div>';

// KPI
print '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:24px;">';

print '<div style="background:#fff;border-left:4px solid #c0392b;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Manque a gagner annuel</div>';
print '<div style="font-size:28px;font-weight:bold;color:#c0392b;margin:4px 0;">'.roc_eur($totalManqueGagner).'</div>';
print '<div style="font-size:11px;color:#666;">Si tu alignais les prix sous-tarifes</div>';
print '</div>';

print '<div style="background:#fff;border-left:4px solid #e67e22;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Produits sous-tarifes</div>';
print '<div style="font-size:28px;font-weight:bold;color:#e67e22;margin:4px 0;">'.$nbSousTarif.'</div>';
print '<div style="font-size:11px;color:#666;">Prix reel < prix ideal</div>';
print '</div>';

print '<div style="background:#fff;border-left:4px solid #3498db;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Produits sur-tarifes</div>';
print '<div style="font-size:28px;font-weight:bold;color:#3498db;margin:4px 0;">'.$nbSurTarif.'</div>';
print '<div style="font-size:11px;color:#666;">Prix reel > prix ideal (risque volume)</div>';
print '</div>';

print '<div style="background:#fff;border-left:4px solid #27ae60;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Produits alignes</div>';
print '<div style="font-size:28px;font-weight:bold;color:#27ae60;margin:4px 0;">'.$nbAligne.'</div>';
print '<div style="font-size:11px;color:#666;">Prix optimal a ±0,10€</div>';
print '</div>';

print '</div>';

if (empty($produits)) {
    print '<div class="warning" style="padding:16px;border-radius:6px;">Aucune vente sur '.$annee.'. Verifiez la synchronisation ou changez d\'annee.</div>';
    llxFooter(); $db->close(); exit;
}

// Tableau detaille
print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>Reference</th>';
print '<th class="right">Qte</th>';
print '<th class="right">Prix reel HT</th>';
print '<th class="right">Prix ideal HT</th>';
print '<th class="right">Ecart</th>';
print '<th class="right">Marge actuelle</th>';
print '<th class="right">Manque a gagner/an</th>';
print '<th>Verdict</th>';
print '</tr>';

foreach ($produits as $p) {
    $rowBg = '';
    if ($p['statut'] === 'sous' && $p['manque_gagner'] > 5) $rowBg = 'background:#fdebe5;';
    elseif ($p['statut'] === 'sur') $rowBg = 'background:#eaf4fc;';

    print '<tr class="oddeven" style="'.$rowBg.'">';
    print '<td><b><code>'.dol_escape_htmltag($p['ref']).'</code></b><br><span style="font-size:11px;color:#888;">'.dol_escape_htmltag(substr($p['designation'],0,35)).'</span></td>';
    print '<td class="right">'.$p['qty'].'</td>';
    print '<td class="right">'.roc_eur($p['prix_reel']).'</td>';

    // Prix ideal
    print '<td class="right">';
    if ($p['prix_ideal'] !== null) {
        print '<b>'.roc_eur($p['prix_ideal']).'</b>';
    } else {
        print '<span style="color:#c0392b;font-size:11px;">impossible</span>';
    }
    print '</td>';

    // Ecart
    print '<td class="right">';
    if ($p['prix_ideal'] !== null) {
        $ecartColor = $p['ecart_prix'] > 0.10 ? '#c0392b' : ($p['ecart_prix'] < -0.10 ? '#3498db' : '#27ae60');
        $sign = $p['ecart_prix'] >= 0 ? '+' : '';
        print '<b style="color:'.$ecartColor.'">'.$sign.number_format($p['ecart_prix'],2,',','').'€</b>';
    } else {
        print '—';
    }
    print '</td>';

    // Marge actuelle
    print '<td class="right">';
    $margeColor = $p['marge_reelle'] >= 0 ? '#27ae60' : '#c0392b';
    print '<span style="color:'.$margeColor.'">'.roc_eur($p['marge_reelle']).'<br><span style="font-size:11px;">'.number_format($p['taux_marge'],1,',','').'%</span></span>';
    print '</td>';

    // Manque a gagner
    print '<td class="right">';
    if ($p['statut'] === 'sous' && $p['manque_gagner'] > 0) {
        print '<b style="color:#c0392b;font-size:15px;">'.roc_eur($p['manque_gagner']).'</b>';
    } elseif ($p['statut'] === 'sur') {
        print '<span style="color:#3498db;font-size:11px;">marge ok</span>';
    } else {
        print '<span style="color:#27ae60;">—</span>';
    }
    print '</td>';

    // Verdict
    print '<td>';
    if ($p['statut'] === 'sous') {
        print '<span style="background:#fdebe5;color:#c0392b;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:bold;">▲ Augmenter</span>';
    } elseif ($p['statut'] === 'sur') {
        print '<span style="background:#eaf4fc;color:#3498db;padding:3px 10px;border-radius:12px;font-size:11px;">▼ Marge confortable</span>';
    } else {
        print '<span style="background:#e8f8ee;color:#27ae60;padding:3px 10px;border-radius:12px;font-size:11px;">✓ Optimal</span>';
    }
    print '</td>';

    print '</tr>';
}
print '</table>';

print '<p style="font-size:12px;color:#888;margin-top:14px;">';
print '<b>Lecture :</b> le "prix ideal" est celui qui atteindrait '.number_format($margeCible,1,',','').'% de marge nette ';
print '(formule du simulateur : prix = couts_fixes / (1 − commission% − marge%)). ';
print 'Le "manque a gagner/an" = (marge ideale − marge actuelle) × quantite vendue sur '.$annee.'. ';
print 'Un produit <b>sous-tarife</b> (rouge) te fait perdre de la marge : augmenter son prix recupererait le montant indique. ';
print 'Un produit <b>sur-tarife</b> (bleu) degage plus que ta cible : tu peux baisser le prix pour gagner en volume si la concurrence presse.';
print '</p>';
print '</div>';

// Lien simulateur
print '<div style="margin-top:16px;padding:12px;background:#f0f4ff;border-radius:6px;font-size:13px;">';
print '<i class="fa fa-lightbulb"></i> Pour affiner le prix d\'un produit precis avec toutes les composantes (packaging, port refacture, TVA...), utilisez le <a href="simulateur.php"><b>Simulateur de prix</b></a>.';
print '</div>';

llxFooter();
$db->close();
