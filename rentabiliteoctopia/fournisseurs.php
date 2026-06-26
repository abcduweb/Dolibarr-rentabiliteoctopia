<?php
/**
 * Vue par fournisseur - Rentabilite et performance des fournisseurs d'achat.
 *
 * Croise les factures fournisseur Dolibarr (achats) avec les ventes Octopia
 * pour montrer, par fournisseur :
 *   - montant total des achats
 *   - nombre de produits sourcing
 *   - CA genere par les produits de ce fournisseur
 *   - marge degagee
 *
 * Aide a la negociation et au choix des fournisseurs.
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';
require_once __DIR__.'/lib/ModuleHelper.class.php';

if (!$user->rights->rentabiliteoctopia->read) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');
$langs->load('suppliers');

$entity = (int)$conf->entity;
$annee = (int)(GETPOST('annee', 'int') ?: date('Y'));

llxHeader('', 'Vue fournisseurs');
print load_fiche_titre('Rentabilite par fournisseur', '', 'fa-industry');
ModuleHelper::navBar('fournisseurs.php');

// Filtre annee
print '<form method="GET" action="fournisseurs.php" style="margin-bottom:20px;">';
print '<select name="annee" class="flat" onchange="this.form.submit()">';
for ($y = date('Y'); $y >= date('Y')-2; $y--) {
    print '<option value="'.$y.'"'.($annee==$y?' selected':'').'>'.$y.'</option>';
}
print '</select>';
print '</form>';

// ============================================================================
// REQUETE : achats par fournisseur (factures fournisseur)
// ============================================================================
$sql = "SELECT
            s.rowid                          AS soc_id,
            s.nom                            AS fournisseur,
            COUNT(DISTINCT f.rowid)          AS nb_factures,
            COUNT(DISTINCT fd.fk_product)    AS nb_produits,
            SUM(fd.total_ht)                 AS total_achat
        FROM ".MAIN_DB_PREFIX."facture_fourn f
        INNER JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc
        INNER JOIN ".MAIN_DB_PREFIX."facture_fourn_det fd ON fd.fk_facture_fourn = f.rowid
        WHERE f.entity = ".$entity."
          AND f.fk_statut >= 1
          AND YEAR(f.datef) = ".$annee."
          AND fd.fk_product > 0
        GROUP BY s.rowid, s.nom
        ORDER BY total_achat DESC
        LIMIT 100";

$resql = $db->query($sql);
$fournisseurs = array();
while ($resql && $o = $db->fetch_object($resql)) {
    $fournisseurs[$o->soc_id] = array(
        'id'          => (int)$o->soc_id,
        'nom'         => $o->fournisseur,
        'nb_factures' => (int)$o->nb_factures,
        'nb_produits' => (int)$o->nb_produits,
        'total_achat' => (float)$o->total_achat,
        'ca_genere'   => 0,
        'marge'       => 0,
    );
}

// Pour chaque fournisseur, calculer le CA genere par ses produits
// (produits achetes chez ce fournisseur ET vendus sur Octopia)
if (!empty($fournisseurs)) {
    $socIds = implode(',', array_keys($fournisseurs));
    $sqlCA = "SELECT
                fp.fk_soc                       AS soc_id,
                SUM(v.qty_vendue * v.prix_ht)   AS ca,
                SUM(v.qty_vendue * v.cout_achat) AS cout
            FROM ".MAIN_DB_PREFIX."product_fournisseur_price fp
            INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = fp.fk_product
            INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit rp ON rp.ref = p.ref AND rp.entity = ".$entity."
            INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v ON v.fk_produit = rp.rowid
                AND v.annee = ".$annee." AND v.entity = ".$entity."
            WHERE fp.fk_soc IN (".$socIds.")
            GROUP BY fp.fk_soc";
    $rCA = $db->query($sqlCA);
    while ($rCA && $o = $db->fetch_object($rCA)) {
        if (isset($fournisseurs[$o->soc_id])) {
            $fournisseurs[$o->soc_id]['ca_genere'] = (float)$o->ca;
            $fournisseurs[$o->soc_id]['marge'] = (float)$o->ca - (float)$o->cout;
        }
    }
}

// Totaux
$totalAchat = 0; $totalCA = 0; $totalMarge = 0;
foreach ($fournisseurs as $f) {
    $totalAchat += $f['total_achat'];
    $totalCA += $f['ca_genere'];
    $totalMarge += $f['marge'];
}

// KPI
print '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:24px;">';
print '<div style="background:#fff;border-left:4px solid #3498db;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Fournisseurs actifs</div>';
print '<div style="font-size:26px;font-weight:bold;color:#3498db;">'.count($fournisseurs).'</div>';
print '</div>';
print '<div style="background:#fff;border-left:4px solid #e67e22;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Total achats</div>';
print '<div style="font-size:24px;font-weight:bold;color:#e67e22;">'.roc_eur($totalAchat).'</div>';
print '</div>';
print '<div style="background:#fff;border-left:4px solid #27ae60;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Marge totale generee</div>';
print '<div style="font-size:24px;font-weight:bold;color:#27ae60;">'.roc_eur($totalMarge).'</div>';
print '</div>';
print '</div>';

if (empty($fournisseurs)) {
    print '<div class="warning" style="padding:16px;border-radius:6px;">';
    print '<b>Aucune facture fournisseur trouvee pour '.$annee.'.</b><br>';
    print 'Pour utiliser cette vue, vos achats doivent etre saisis comme factures fournisseur dans Dolibarr, avec des lignes rattachees a des produits.';
    print '</div>';
    llxFooter(); $db->close(); exit;
}

// Tableau
print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>Fournisseur</th>';
print '<th class="right">Factures</th>';
print '<th class="right">Produits</th>';
print '<th class="right">Total achats</th>';
print '<th class="right">CA genere</th>';
print '<th class="right">Marge</th>';
print '<th class="right">ROI</th>';
print '</tr>';

// Trier par marge decroissante
uasort($fournisseurs, function($a, $b) { return $b['marge'] <=> $a['marge']; });

foreach ($fournisseurs as $f) {
    // ROI = marge / achats (combien de marge pour 1€ d'achat)
    $roi = $f['total_achat'] > 0 ? ($f['marge'] / $f['total_achat'] * 100) : 0;
    print '<tr class="oddeven">';
    print '<td><b>';
    print '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.(int)$f['id'].'">'.dol_escape_htmltag($f['nom']).'</a>';
    print '</b></td>';
    print '<td class="right">'.$f['nb_factures'].'</td>';
    print '<td class="right">'.$f['nb_produits'].'</td>';
    print '<td class="right">'.roc_eur($f['total_achat']).'</td>';
    print '<td class="right">'.roc_eur($f['ca_genere']).'</td>';
    $margeColor = $f['marge'] >= 0 ? '#27ae60' : '#c0392b';
    print '<td class="right" style="color:'.$margeColor.';font-weight:bold;">'.roc_eur($f['marge']).'</td>';
    // ROI
    print '<td class="right">';
    if ($f['ca_genere'] > 0) {
        $roiColor = $roi >= 50 ? '#27ae60' : ($roi >= 20 ? '#e67e22' : '#c0392b');
        print '<b style="color:'.$roiColor.';">'.number_format($roi,0,',','').'%</b>';
    } else {
        print '<span style="color:#aaa;">—</span>';
    }
    print '</td>';
    print '</tr>';
}
print '</table>';
print '<p style="font-size:12px;color:#888;margin-top:14px;">';
print '<b>ROI</b> = marge generee / montant des achats. Un ROI de 100% signifie que chaque euro depense en achat rapporte 1€ de marge. ';
print 'Le "CA genere" relie les produits achetes chez un fournisseur a leurs ventes Octopia (via le sourcing produit dans Dolibarr).';
print '</p>';
print '</div>';

print '<div style="margin-top:16px;padding:12px;background:#f0f4ff;border-radius:6px;font-size:13px;">';
print '<i class="fa fa-lightbulb"></i> <b>Usage :</b> identifiez vos fournisseurs les plus rentables (ROI eleve) pour concentrer vos achats, ';
print 'et ceux a faible ROI pour renegocier vos prix d\'achat ou changer de source.';
print '</div>';

llxFooter();
$db->close();
