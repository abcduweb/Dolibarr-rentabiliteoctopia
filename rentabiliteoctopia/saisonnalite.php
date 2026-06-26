<?php
/**
 * Saisonnalite - Analyse des ventes sur 24 mois glissants.
 *
 * Objectif : identifier les pics saisonniers (Noel, rentree, soldes...) pour
 * anticiper stock et tresorerie. Particulierement utile en Q4 (Diamond Painting,
 * cadeaux, etc.).
 *
 * Affiche :
 *   - Courbe CA + quantites sur 24 mois (2 ans pour comparer N et N-1)
 *   - Comparaison mois par mois N vs N-1 (croissance/declin)
 *   - Top produits saisonniers (ceux dont les ventes sont concentrees sur peu de mois)
 *   - Heatmap mensuelle par categorie
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

$entity = (int)$conf->entity;

llxHeader('', 'Saisonnalite');
print load_fiche_titre('Saisonnalite — analyse sur 24 mois', '', 'fa-chart-line');
ModuleHelper::navBar('saisonnalite.php');

// Bouton export
print '<div style="margin-bottom:16px;text-align:right;">';
print '<a href="export.php?type=saisonnalite" class="button" style="background:#16a085;"><i class="fa fa-file-excel"></i> Exporter en CSV</a> ';
print '<a href="export.php?type=saisonnalite&format=pdf" class="button" style="background:#c0392b;"><i class="fa fa-file-pdf"></i> Exporter en PDF</a>';
print '</div>';

// ============================================================================
// DONNEES : CA + quantites par mois sur 24 mois
// ============================================================================
$moisLabels = array();
$moisData = array();   // 'YYYY-MM' => ['ca'=>, 'qty'=>, 'nb_cmd'=>]

// Generer les 24 derniers mois (du plus ancien au plus recent)
for ($i = 23; $i >= 0; $i--) {
    $ts = strtotime("first day of -$i month");
    $key = date('Y-m', $ts);
    $moisData[$key] = array('ca'=>0, 'qty'=>0, 'nb_cmd'=>0);
    $moisLabels[$key] = date('M y', $ts);
}

$dateDebut = date('Y-m-01', strtotime('first day of -23 month'));

$sql = "SELECT
            DATE_FORMAT(c.date_commande, '%Y-%m')          AS mois_key,
            COUNT(DISTINCT o.rowid)                        AS nb_cmd,
            SUM(cd.qty)                                    AS qty,
            SUM(cd.qty * cd.subprice)                      AS ca
        FROM ".MAIN_DB_PREFIX."octopia_orders o
        INNER JOIN ".MAIN_DB_PREFIX."commande c
            ON  c.rowid = o.dolibarr_order_id
            AND c.entity = ".$entity."
            AND c.fk_statut >= 1
            AND c.date_commande >= '".$dateDebut."'
        INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid AND cd.fk_product > 0
        WHERE o.entity = ".$entity."
          AND o.is_refunded = 0
          AND (o.octopia_order_status IS NULL OR o.octopia_order_status NOT IN ('CANCELLED','REFUNDED','REFUSED','CANCELED'))
        GROUP BY DATE_FORMAT(c.date_commande, '%Y-%m')
        ORDER BY mois_key";

$resql = $db->query($sql);
while ($resql && $o = $db->fetch_object($resql)) {
    if (isset($moisData[$o->mois_key])) {
        $moisData[$o->mois_key] = array(
            'ca'     => (float)$o->ca,
            'qty'    => (int)$o->qty,
            'nb_cmd' => (int)$o->nb_cmd,
        );
    }
}

// Stats globales
$totalCA = 0; $maxCA = 0; $maxMois = '';
foreach ($moisData as $k => $d) {
    $totalCA += $d['ca'];
    if ($d['ca'] > $maxCA) { $maxCA = $d['ca']; $maxMois = $k; }
}
$moyenneCA = count($moisData) > 0 ? $totalCA / count($moisData) : 0;

// ============================================================================
// COMPARAISON N vs N-1 (12 mois)
// ============================================================================
$comparaison = array();
$moisNomsCourts = array(1=>'Jan',2=>'Fev',3=>'Mar',4=>'Avr',5=>'Mai',6=>'Juin',7=>'Juil',8=>'Aout',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec');
$anneeN = (int)date('Y');
$anneeN1 = $anneeN - 1;
for ($m = 1; $m <= 12; $m++) {
    $keyN  = sprintf('%04d-%02d', $anneeN, $m);
    $keyN1 = sprintf('%04d-%02d', $anneeN1, $m);
    $caN  = isset($moisData[$keyN])  ? $moisData[$keyN]['ca']  : 0;
    $caN1 = isset($moisData[$keyN1]) ? $moisData[$keyN1]['ca'] : 0;
    $comparaison[$m] = array(
        'mois'  => $moisNomsCourts[$m],
        'ca_n'  => $caN,
        'ca_n1' => $caN1,
        'evol'  => $caN1 > 0 ? (($caN - $caN1) / $caN1 * 100) : ($caN > 0 ? 100 : null),
    );
}

// ============================================================================
// PRODUITS SAISONNIERS : concentration des ventes
// ============================================================================
// Un produit est "saisonnier" si une grande part de ses ventes annuelles
// est concentree sur 1-3 mois (indice de concentration eleve)
$sqlSaison = "SELECT
                p.ref, p.designation,
                DATE_FORMAT(c.date_commande, '%m')         AS mois_num,
                SUM(cd.qty)                                AS qty
            FROM ".MAIN_DB_PREFIX."octopia_orders o
            INNER JOIN ".MAIN_DB_PREFIX."commande c
                ON  c.rowid = o.dolibarr_order_id AND c.entity = ".$entity."
                AND c.fk_statut >= 1
                AND c.date_commande >= '".$dateDebut."'
            INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid AND cd.fk_product > 0
            INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product
            WHERE o.entity = ".$entity." AND o.is_refunded = 0
            GROUP BY p.ref, p.designation, mois_num";
$rS = $db->query($sqlSaison);
$produitsMois = array(); // ref => [mois => qty]
$produitsLabel = array();
while ($rS && $o = $db->fetch_object($rS)) {
    $produitsMois[$o->ref][(int)$o->mois_num] = (int)$o->qty;
    $produitsLabel[$o->ref] = $o->designation;
}

// Calculer l'indice de concentration (% des ventes sur le mois le plus fort)
$saisonniers = array();
foreach ($produitsMois as $ref => $mois) {
    $total = array_sum($mois);
    if ($total < 5) continue; // ignorer les produits a faible volume
    $max = max($mois);
    $moisFort = array_search($max, $mois);
    $concentration = $total > 0 ? ($max / $total * 100) : 0;
    // Saisonnier si > 40% des ventes sur un seul mois
    if ($concentration >= 40) {
        $saisonniers[] = array(
            'ref'           => $ref,
            'label'         => $produitsLabel[$ref],
            'total'         => $total,
            'mois_fort'     => $moisFort,
            'concentration' => $concentration,
            'qty_pic'       => $max,
        );
    }
}
usort($saisonniers, function($a, $b) { return $b['concentration'] <=> $a['concentration']; });

// ============================================================================
// AFFICHAGE
// ============================================================================

// KPI
print '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:24px;">';
print '<div style="background:#fff;border-left:4px solid #3498db;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">CA moyen mensuel</div>';
print '<div style="font-size:24px;font-weight:bold;color:#3498db;">'.roc_eur($moyenneCA).'</div>';
print '<div style="font-size:11px;color:#666;">Sur 24 mois</div>';
print '</div>';
print '<div style="background:#fff;border-left:4px solid #27ae60;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Meilleur mois</div>';
print '<div style="font-size:24px;font-weight:bold;color:#27ae60;">'.($maxMois ? $moisLabels[$maxMois] : '—').'</div>';
print '<div style="font-size:11px;color:#666;">'.roc_eur($maxCA).'</div>';
print '</div>';
print '<div style="background:#fff;border-left:4px solid #9b59b6;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Produits saisonniers</div>';
print '<div style="font-size:24px;font-weight:bold;color:#9b59b6;">'.count($saisonniers).'</div>';
print '<div style="font-size:11px;color:#666;">Ventes concentrees sur 1 mois</div>';
print '</div>';
print '</div>';

// Graphe principal 24 mois
print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:24px;">';
print '<h3 style="margin-top:0;">Evolution du CA sur 24 mois</h3>';
print '<div style="position:relative;height:320px;width:100%;"><canvas id="chart24"></canvas></div>';

$labelsArr = array_values($moisLabels);
$caArr = array();
$qtyArr = array();
foreach ($moisData as $k => $d) {
    $caArr[] = round($d['ca'], 2);
    $qtyArr[] = $d['qty'];
}

print '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
print '<script>
(function(){
    new Chart(document.getElementById("chart24").getContext("2d"), {
        type: "bar",
        data: {
            labels: '.json_encode($labelsArr).',
            datasets: [
                { type:"line", label:"CA (\u20ac)", data:'.json_encode($caArr).', borderColor:"#3498db", backgroundColor:"rgba(52,152,219,0.1)", borderWidth:2, fill:true, tension:0.3, yAxisID:"y", pointRadius:3 },
                { type:"bar", label:"Unites vendues", data:'.json_encode($qtyArr).', backgroundColor:"rgba(155,89,182,0.3)", yAxisID:"y1" }
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{position:"bottom"} },
            scales:{
                y:  { position:"left",  ticks:{ callback:function(v){return v.toLocaleString("fr-FR")+" \u20ac"} } },
                y1: { position:"right", grid:{drawOnChartArea:false}, title:{display:true,text:"Unites"} }
            }
        }
    });
})();
</script>';
print '</div>';

// Comparaison N vs N-1
print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:24px;">';
print '<h3 style="margin-top:0;">Comparaison '.$anneeN.' vs '.$anneeN1.'</h3>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Mois</th><th class="right">CA '.$anneeN1.'</th><th class="right">CA '.$anneeN.'</th><th class="right">Evolution</th><th>Tendance</th></tr>';
foreach ($comparaison as $m => $c) {
    print '<tr class="oddeven">';
    print '<td><b>'.$c['mois'].'</b></td>';
    print '<td class="right">'.roc_eur($c['ca_n1']).'</td>';
    print '<td class="right"><b>'.roc_eur($c['ca_n']).'</b></td>';
    print '<td class="right">';
    if ($c['evol'] === null) {
        print '<span style="color:#aaa;">—</span>';
    } else {
        $col = $c['evol'] >= 0 ? '#27ae60' : '#c0392b';
        $sign = $c['evol'] >= 0 ? '+' : '';
        print '<b style="color:'.$col.'">'.$sign.number_format($c['evol'],0,',','').'%</b>';
    }
    print '</td>';
    // Mini barre de tendance
    print '<td>';
    if ($c['ca_n'] > 0 || $c['ca_n1'] > 0) {
        $maxBar = max($c['ca_n'], $c['ca_n1']);
        $wN1 = $maxBar > 0 ? ($c['ca_n1']/$maxBar*100) : 0;
        $wN = $maxBar > 0 ? ($c['ca_n']/$maxBar*100) : 0;
        print '<div style="display:flex;flex-direction:column;gap:2px;width:120px;">';
        print '<div style="height:8px;background:#ddd;border-radius:2px;width:'.$wN1.'%;" title="'.$anneeN1.'"></div>';
        print '<div style="height:8px;background:#3498db;border-radius:2px;width:'.$wN.'%;" title="'.$anneeN.'"></div>';
        print '</div>';
    }
    print '</td>';
    print '</tr>';
}
print '</table>';
print '<p style="font-size:11px;color:#888;margin-top:8px;">Barre grise = '.$anneeN1.', barre bleue = '.$anneeN.'</p>';
print '</div>';

// Produits saisonniers
if (!empty($saisonniers)) {
    print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:24px;">';
    print '<h3 style="margin-top:0;">Produits saisonniers</h3>';
    print '<p style="font-size:12px;color:#888;">Produits dont 40%+ des ventes sont concentrees sur un seul mois. Anticipez le stock avant le pic.</p>';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><th>Reference</th><th>Designation</th><th class="right">Total vendu</th><th>Mois de pic</th><th class="right">Concentration</th></tr>';
    $moisNomsLong = array(1=>'Janvier',2=>'Fevrier',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Aout',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Decembre');
    foreach (array_slice($saisonniers, 0, 15) as $s) {
        print '<tr class="oddeven">';
        print '<td><code>'.dol_escape_htmltag($s['ref']).'</code></td>';
        print '<td>'.dol_escape_htmltag(substr($s['label'],0,40)).'</td>';
        print '<td class="right">'.$s['total'].'</td>';
        print '<td><b style="color:#9b59b6;">'.$moisNomsLong[$s['mois_fort']].'</b> ('.$s['qty_pic'].' u.)</td>';
        print '<td class="right"><b>'.number_format($s['concentration'],0,',','').'%</b></td>';
        print '</tr>';
    }
    print '</table>';
    print '</div>';
}

print '<div style="margin-top:16px;padding:12px;background:#f0f4ff;border-radius:6px;font-size:13px;">';
print '<i class="fa fa-lightbulb"></i> <b>Conseil :</b> pour les produits saisonniers, croisez cette page avec le <a href="reassort.php">Reassort</a> ';
print 'environ 1 a 2 mois avant le pic identifie, afin de constituer le stock necessaire et eviter les ruptures au pire moment.';
print '</div>';

llxFooter();
$db->close();
