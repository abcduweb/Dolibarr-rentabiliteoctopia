<?php
/**
 * Tableau de bord - Rentabilité Octopia v1.2
 */
$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';

if (!$user->rights->rentabiliteoctopia->read) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$annee = GETPOST('annee', 'int') ?: date('Y');
$mois  = GETPOST('mois',  'int') ?: (int)date('m');

$params    = rentabiliteoctopia_get_params($db);
$ventes    = rentabiliteoctopia_get_ventes($db, $annee, $mois);
$frais     = rentabiliteoctopia_get_frais($db, $annee, $mois);
$kpis      = rentabiliteoctopia_calc_kpis($ventes, $frais, $params);
$evolution = rentabiliteoctopia_get_evolution($db, $annee, $params);

$moisNoms = array(1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
                  7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre');

llxHeader('', 'Rentabilité Octopia');
print load_fiche_titre('Tableau de bord — '.$moisNoms[$mois].' '.$annee, '', 'fa-chart-line');

// Sélecteur période
print '<form method="GET" action="index.php" class="form-inline" style="margin-bottom:20px;">';
print '<select name="mois" class="flat" style="margin-right:6px;">';
foreach ($moisNoms as $n => $nom) print '<option value="'.$n.'"'.($mois==$n?' selected':'').'>'.$nom.'</option>';
print '</select>';
print '<select name="annee" class="flat" style="margin-right:6px;">';
for ($y = date('Y'); $y >= date('Y')-3; $y--) print '<option value="'.$y.'"'.($annee==$y?' selected':'').'>'.$y.'</option>';
print '</select>';
print '<input type="submit" class="button" value="Filtrer">';
print '</form>';

// KPIs
$seuilOk = $kpis['taux_marge'] >= (float)$params['seuil_marge_pct'];
print '<div class="fichecenter"><div class="fichehalfleft">';
print '<table class="border tableforfield" style="width:100%">';
print '<tr class="liste_titre"><td colspan="2">Synthèse '.strtolower($moisNoms[$mois]).' '.$annee.'</td></tr>';
$rows = array(
    array('CA HT total',          price($kpis['ca_total']).' €'),
    array('Commissions Octopia',  price($kpis['commissions']).' €'),
    array('Coût achat total',     price($kpis['cout_achat']).' €'),
    array('Marge produits brute', price($kpis['marge_produits']).' € ('.$kpis['taux_marge'].'%)'),
    array('Frais fixes du mois',  price($kpis['frais_fixes']).' €'),
    array('Marge nette',          price($kpis['marge_nette']).' € ('.$kpis['taux_net'].'%)'),
    array('Unités vendues',       $kpis['qty_total']),
    array('Produits actifs',      $kpis['nb_produits'].' références'),
);
foreach ($rows as $r) {
    print '<tr><td class="fieldrequired">'.$r[0].'</td><td><b>'.$r[1].'</b></td></tr>';
}
print '</table>';
print '</div>';

// Détail frais fixes
print '<div class="fichehalfright">';
print '<table class="border tableforfield" style="width:100%">';
print '<tr class="liste_titre"><td colspan="2">Détail frais fixes du mois <a href="frais.php?mois='.$mois.'&annee='.$annee.'" class="butAction" style="float:right;padding:2px 8px;font-size:11px">Modifier</a></td></tr>';
$labelsFreais = array(
    'abonnement'     => 'Abonnement Cdiscount',
    'fulfilment'     => 'Fulfilment Octopia',
    'affranchissement'=> 'Affranchissement / Transport',
    'packaging'      => 'Packaging',
    'publicite'      => 'Publicité / Sponsored',
    'autre'          => 'Autres frais',
);
foreach ($labelsFreais as $type => $label) {
    $m = isset($frais[$type]) ? $frais[$type]['montant'] : 0;
    if ($m == 0 && $type !== 'abonnement') continue;
    $lbl = (!empty($frais[$type]['label'])) ? dol_escape_htmltag($frais[$type]['label']) : $label;
    print '<tr><td>'.$lbl.'</td><td class="right"><b>'.price($m).' €</b></td></tr>';
}
print '<tr class="liste_titre"><td>Total frais fixes</td><td class="right"><b>'.price($kpis['frais_fixes']).' €</b></td></tr>';
print '</table>';
if (!$seuilOk) {
    print '<div class="warning" style="padding:10px;margin-top:10px;border-radius:4px;">';
    print 'Taux de marge produits ('.$kpis['taux_marge'].'%) en dessous du seuil cible ('.$params['seuil_marge_pct'].'%).';
    print '</div>';
}
print '</div></div><br>';

// Tableau produits
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
foreach (array('Référence','Désignation','Catégorie','Qté','CA HT (€)','Commission (€)','Coût achat (€)','Marge produit (€)','Taux','Rentabilité') as $h) {
    print '<th>'.$h.'</th>';
}
print '</tr>';
if (empty($ventes)) {
    print '<tr class="oddeven"><td colspan="10" class="center">Aucune vente ce mois. <a href="produits.php">Saisir les ventes</a></td></tr>';
} else {
    foreach ($ventes as $v) {
        $c    = rentabiliteoctopia_calc_marge_produit($v, $params);
        $seuil = (float)$params['seuil_marge_pct'];
        if ($c['taux_marge'] >= $seuil) {
            $badge = '<span class="badge badge-status4 badge-status">Rentable</span>';
        } elseif ($c['taux_marge'] >= 5) {
            $badge = '<span class="badge badge-status1 badge-status">Limite</span>';
        } else {
            $badge = '<span class="badge badge-status8 badge-status">Non rentable</span>';
        }
        $commLabel = $v['commission_reel'] !== null ? 'réel' : (($v['commission_pct'] !== null ? $v['commission_pct'] : $v['cat_commission_pct']).'%');
        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag($v['ref']).'</td>';
        print '<td>'.dol_escape_htmltag($v['designation']).'</td>';
        print '<td><small>'.dol_escape_htmltag($v['cat_label'] ?: '—').'</small></td>';
        print '<td class="right">'.$v['qty_vendue'].'</td>';
        print '<td class="right">'.price($c['ca']).'</td>';
        print '<td class="right">'.price($c['commission']).' <small style="color:#888">('.$commLabel.')</small></td>';
        print '<td class="right">'.price($c['cout_total']).'</td>';
        print '<td class="right" style="font-weight:bold;color:'.($c['marge']>=0?'green':'red').'">'.price($c['marge']).'</td>';
        print '<td class="right">'.$c['taux_marge'].'%</td>';
        print '<td>'.$badge.'</td>';
        print '</tr>';
    }
}
print '</table>';

// Graphique évolution
print '<br><h3>Évolution '.$annee.'</h3>';
print '<div style="position:relative;width:100%;height:300px;"><canvas id="chartEvol"></canvas></div>';
$caData = $margeData = $netteData = array();
foreach ($evolution as $e) {
    $caData[]    = round($e['ca'], 2);
    $margeData[] = round($e['marge_produits'], 2);
    $netteData[] = round($e['marge_nette'], 2);
}
print '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>';
print '<script>
new Chart(document.getElementById("chartEvol"), {
  type: "bar",
  data: {
    labels: '.json_encode(array_values($moisNoms)).',
    datasets: [
      { label: "CA HT (€)",           data: '.json_encode($caData).',    backgroundColor: "#B5D4F4", borderRadius: 3 },
      { label: "Marge produits (€)",   data: '.json_encode($margeData).', backgroundColor: "#C0DD97", borderRadius: 3 },
      { label: "Marge nette (€)",      data: '.json_encode($netteData).', backgroundColor: "#FAC775", borderRadius: 3, type: "line", borderColor: "#BA7517", backgroundColor: "transparent", pointRadius: 4 }
    ]
  },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "top" } } }
});
</script>';

llxFooter();
$db->close();
