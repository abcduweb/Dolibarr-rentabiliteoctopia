<?php
/**
 * Sante de l'entreprise - Vue business annuelle achat-revente
 *
 * KPI globaux : CA annuel, marge brute, marge nette, point mort
 * Evolutions  : graphique 12 mois glissants
 * Top/Flop    : produits par CA, par marge, par taux
 * Dormants    : produits sans vente depuis 60j+
 * Categories  : performance comparee
 * Alertes     : produits sous le seuil de marge configure
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';

if (!$user->rights->rentabiliteoctopia->read) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$annee   = (int)(GETPOST('annee', 'int') ?: date('Y'));
$anneePrec = $annee - 1;
$params  = rentabiliteoctopia_get_params($db);
$seuilMarge = isset($params['seuil_marge_pct']) ? (float)$params['seuil_marge_pct'] : 15;

// ============================================================================
// REQUETES D'AGREGATION
// ============================================================================

// 1. KPI annuels (annee N + N-1 pour comparaison)
function getKpiAnnee($db, $annee, $entity) {
    $sql = "SELECT
                SUM(v.qty_vendue * v.prix_ht)        AS ca,
                SUM(v.qty_vendue * v.cout_achat)     AS cout_achat,
                SUM(CASE
                    WHEN v.commission_reel IS NOT NULL THEN v.commission_reel
                    WHEN v.commission_pct IS NOT NULL THEN (v.qty_vendue * v.prix_ht * v.commission_pct / 100)
                    WHEN c.commission_pct IS NOT NULL THEN (v.qty_vendue * v.prix_ht * c.commission_pct / 100)
                    ELSE 0
                END)                                  AS commissions,
                SUM(v.qty_vendue)                    AS unites
            FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
            LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
            LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
            WHERE v.annee = ".(int)$annee."
              AND v.entity = ".(int)$entity;
    $r = $db->query($sql);
    if ($r && $o = $db->fetch_object($r)) {
        return array(
            'ca'          => (float)$o->ca,
            'cout_achat'  => (float)$o->cout_achat,
            'commissions' => (float)$o->commissions,
            'unites'      => (int)$o->unites,
        );
    }
    return array('ca'=>0,'cout_achat'=>0,'commissions'=>0,'unites'=>0);
}

function getFraisAnnee($db, $annee, $entity) {
    $sql = "SELECT SUM(montant) AS total FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_frais
            WHERE annee = ".(int)$annee." AND entity = ".(int)$entity;
    $r = $db->query($sql);
    return ($r && $o = $db->fetch_object($r)) ? (float)$o->total : 0;
}

$k     = getKpiAnnee($db, $annee, $conf->entity);
$kPrec = getKpiAnnee($db, $anneePrec, $conf->entity);
$frais     = getFraisAnnee($db, $annee, $conf->entity);
$fraisPrec = getFraisAnnee($db, $anneePrec, $conf->entity);

$k['marge_brute'] = $k['ca'] - $k['cout_achat'] - $k['commissions'];
$k['marge_nette'] = $k['marge_brute'] - $frais;
$k['taux_brut']   = $k['ca'] > 0 ? $k['marge_brute'] / $k['ca'] * 100 : 0;
$k['taux_net']    = $k['ca'] > 0 ? $k['marge_nette'] / $k['ca'] * 100 : 0;
$k['frais']       = $frais;

$kPrec['marge_brute'] = $kPrec['ca'] - $kPrec['cout_achat'] - $kPrec['commissions'];
$kPrec['marge_nette'] = $kPrec['marge_brute'] - $fraisPrec;

// Evolution YoY
function pctEvol($cur, $prev) {
    if ($prev == 0) return $cur > 0 ? '+inf' : '0';
    $pct = ($cur - $prev) / abs($prev) * 100;
    return ($pct >= 0 ? '+' : '').number_format($pct, 1, ',', '').' %';
}

// 2. Evolution mensuelle CA / marge brute / marge nette
$evolutions = array();
for ($m = 1; $m <= 12; $m++) {
    $sql = "SELECT
                COALESCE(SUM(v.qty_vendue * v.prix_ht), 0)         AS ca,
                COALESCE(SUM(v.qty_vendue * v.cout_achat), 0)      AS cout,
                COALESCE(SUM(CASE
                    WHEN v.commission_reel IS NOT NULL THEN v.commission_reel
                    WHEN v.commission_pct IS NOT NULL THEN (v.qty_vendue * v.prix_ht * v.commission_pct / 100)
                    WHEN c.commission_pct IS NOT NULL THEN (v.qty_vendue * v.prix_ht * c.commission_pct / 100)
                    ELSE 0 END), 0)                                AS comm,
                COALESCE(SUM(v.qty_vendue), 0)                     AS qty
            FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
            LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
            LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
            WHERE v.annee = ".$annee." AND v.mois = ".$m." AND v.entity = ".((int)$conf->entity);
    $r = $db->query($sql);
    $obj = $r ? $db->fetch_object($r) : null;
    $ca = $obj ? (float)$obj->ca : 0;
    $cout = $obj ? (float)$obj->cout : 0;
    $comm = $obj ? (float)$obj->comm : 0;
    $mb = $ca - $cout - $comm;

    $sqlF = "SELECT COALESCE(SUM(montant), 0) AS f FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_frais
             WHERE annee = ".$annee." AND mois = ".$m." AND entity = ".((int)$conf->entity);
    $rf = $db->query($sqlF);
    $f = ($rf && $of = $db->fetch_object($rf)) ? (float)$of->f : 0;

    $evolutions[$m] = array(
        'ca'    => $ca,
        'mb'    => $mb,
        'mn'    => $mb - $f,
        'frais' => $f,
        'qty'   => $obj ? (int)$obj->qty : 0,
    );
}

// 3. Point mort (mois ou la marge brute cumulee depasse les frais cumules)
$pointMort = null;
$mbCum = 0;
$fraisCum = 0;
foreach ($evolutions as $m => $e) {
    $mbCum    += $e['mb'];
    $fraisCum += $e['frais'];
    if ($pointMort === null && $mbCum >= $fraisCum && $fraisCum > 0) {
        $pointMort = $m;
    }
}

// 4. Top 5 produits par CA, par marge nette, par taux
$sqlTop = "SELECT
                p.rowid, p.ref, p.designation,
                c.label AS cat_label,
                SUM(v.qty_vendue)                                AS qty,
                SUM(v.qty_vendue * v.prix_ht)                    AS ca,
                SUM(v.qty_vendue * v.cout_achat)                 AS cout,
                SUM(CASE
                    WHEN v.commission_reel IS NOT NULL THEN v.commission_reel
                    WHEN v.commission_pct IS NOT NULL THEN (v.qty_vendue * v.prix_ht * v.commission_pct / 100)
                    WHEN c.commission_pct IS NOT NULL THEN (v.qty_vendue * v.prix_ht * c.commission_pct / 100)
                    ELSE 0
                END)                                              AS comm,
                MAX(v.mois)                                       AS dernier_mois
            FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
            INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
            LEFT  JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
            WHERE v.annee = ".$annee." AND v.entity = ".((int)$conf->entity)."
            GROUP BY p.rowid, p.ref, p.designation, c.label
            HAVING qty > 0";
$rTop = $db->query($sqlTop);
$produits = array();
while ($rTop && $o = $db->fetch_object($rTop)) {
    $ca = (float)$o->ca; $cout = (float)$o->cout; $comm = (float)$o->comm;
    $marge = $ca - $cout - $comm;
    $produits[] = array(
        'ref'       => $o->ref,
        'designation' => $o->designation,
        'cat'       => $o->cat_label,
        'qty'       => (int)$o->qty,
        'ca'        => $ca,
        'cout'      => $cout,
        'comm'      => $comm,
        'marge'     => $marge,
        'taux'      => $ca > 0 ? $marge / $ca * 100 : 0,
        'dernier'   => (int)$o->dernier_mois,
    );
}

// Tris pour les top
$topByCA = $produits;
usort($topByCA, function($a,$b){ return $b['ca'] <=> $a['ca']; });
$topByMarge = $produits;
usort($topByMarge, function($a,$b){ return $b['marge'] <=> $a['marge']; });
$topByTaux = $produits;
usort($topByTaux, function($a,$b){ return $b['taux'] <=> $a['taux']; });
$flopByTaux = $produits;
usort($flopByTaux, function($a,$b){ return $a['taux'] <=> $b['taux']; });

// 5. Produits dormants : pas vendus dans les 2 derniers mois pleins
$moisCourant = (int)date('m');
$anneeCourante = (int)date('Y');
$moisSeuil = $moisCourant - 2;
if ($moisSeuil < 1) { $moisSeuil += 12; }

$produitsDormants = array();
foreach ($produits as $p) {
    if ($annee === $anneeCourante && $p['dernier'] < $moisSeuil) {
        $produitsDormants[] = $p;
    }
}

// 6. Performance par categorie
$sqlCat = "SELECT
                COALESCE(c.label, '— Non categorise —')           AS cat_label,
                COALESCE(c.commission_pct, 0)                       AS cat_pct,
                COUNT(DISTINCT p.rowid)                             AS nb_prod,
                SUM(v.qty_vendue)                                   AS qty,
                SUM(v.qty_vendue * v.prix_ht)                       AS ca,
                SUM(v.qty_vendue * v.cout_achat)                    AS cout,
                SUM(CASE
                    WHEN v.commission_reel IS NOT NULL THEN v.commission_reel
                    WHEN v.commission_pct IS NOT NULL THEN (v.qty_vendue * v.prix_ht * v.commission_pct / 100)
                    WHEN c.commission_pct IS NOT NULL THEN (v.qty_vendue * v.prix_ht * c.commission_pct / 100)
                    ELSE 0 END)                                     AS comm
           FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
           INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
           LEFT  JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
           WHERE v.annee = ".$annee." AND v.entity = ".((int)$conf->entity)."
           GROUP BY c.rowid, c.label, c.commission_pct
           ORDER BY ca DESC";
$rCat = $db->query($sqlCat);
$categoriesStats = array();
while ($rCat && $o = $db->fetch_object($rCat)) {
    $ca = (float)$o->ca; $cout = (float)$o->cout; $comm = (float)$o->comm;
    $marge = $ca - $cout - $comm;
    $categoriesStats[] = array(
        'label'   => $o->cat_label,
        'cat_pct' => (float)$o->cat_pct,
        'nb_prod' => (int)$o->nb_prod,
        'qty'     => (int)$o->qty,
        'ca'      => $ca,
        'marge'   => $marge,
        'taux'    => $ca > 0 ? $marge / $ca * 100 : 0,
    );
}

// 7. Projection annuelle (extrapolation lineaire sur les mois ecoules)
$moisEcoules = 0;
$caEcoule = 0;
$mnEcoule = 0;
foreach ($evolutions as $m => $e) {
    if ($e['ca'] > 0) {
        $moisEcoules = max($moisEcoules, $m);
        $caEcoule += $e['ca'];
        $mnEcoule += $e['mn'];
    }
}
$projection = array(
    'ca'           => $moisEcoules > 0 ? $caEcoule / $moisEcoules * 12 : 0,
    'marge_nette'  => $moisEcoules > 0 ? $mnEcoule / $moisEcoules * 12 : 0,
    'mois_ecoules' => $moisEcoules,
);

// ============================================================================
// AFFICHAGE
// ============================================================================
llxHeader('', 'Sante de l\'entreprise');
print load_fiche_titre('Sante de l\'entreprise — '.$annee, '', 'fa-heartbeat');

// Filtre annee
print '<form method="GET" action="sante.php" style="margin-bottom:20px;">';
print '<select name="annee" class="flat" onchange="this.form.submit()">';
for ($y = date('Y'); $y >= date('Y')-3; $y--) {
    print '<option value="'.$y.'"'.($annee==$y?' selected':'').'>'.$y.'</option>';
}
print '</select>';
print '</form>';

// ============== KPI cards ==============
print '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px;">';

$cards = array(
    array('CA HT cumule',     roc_eur($k['ca']),          '#3498db', pctEvol($k['ca'], $kPrec['ca']).' vs '.$anneePrec),
    array('Marge brute',      roc_eur($k['marge_brute']), '#27ae60', number_format($k['taux_brut'],1,',','').' % du CA'),
    array('Marge nette',      roc_eur($k['marge_nette']), $k['marge_nette']>=0?'#27ae60':'#c0392b', number_format($k['taux_net'],1,',','').' % du CA'),
    array('Frais fixes',      roc_eur($k['frais']),       '#e67e22', pctEvol($k['frais'], $fraisPrec).' vs '.$anneePrec),
    array('Unites vendues',   number_format($k['unites'], 0, ',', '\xc2\xa0'), '#9b59b6', pctEvol($k['unites'], $kPrec['unites']).' vs '.$anneePrec),
);
foreach ($cards as $c) {
    print '<div style="background:#fff;border-left:4px solid '.$c[2].';padding:14px;border-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,0.08);">';
    print '<div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:0.5px;">'.$c[0].'</div>';
    print '<div style="font-size:22px;font-weight:bold;color:'.$c[2].';margin:4px 0;">'.$c[1].'</div>';
    print '<div style="font-size:11px;color:#666;">'.$c[3].'</div>';
    print '</div>';
}
print '</div>';

// ============== Point mort + projection ==============
print '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">';

// Point mort
print '<div style="background:#fff;padding:14px;border-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,0.08);">';
print '<h4 style="margin-top:0;">🎯 Point mort '.$annee.'</h4>';
$moisNoms = array(1=>'Janvier',2=>'Fevrier',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Aout',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Decembre');
if ($pointMort !== null) {
    print '<div style="font-size:18px;color:#27ae60;font-weight:bold;">✓ Atteint en '.$moisNoms[$pointMort].'</div>';
    print '<div style="font-size:12px;color:#666;margin-top:6px;">';
    print 'Votre marge brute cumulee a depasse vos frais fixes a partir de '.$moisNoms[$pointMort].'. Tout ce qui suit est de la marge nette.';
    print '</div>';
} elseif ($k['frais'] == 0) {
    print '<div style="font-size:14px;color:#888;">Pas de frais fixes saisis — point mort non calculable</div>';
} else {
    $reste = $k['frais'] - ($k['marge_brute']);
    print '<div style="font-size:18px;color:#c0392b;font-weight:bold;">✗ Non atteint</div>';
    print '<div style="font-size:12px;color:#666;margin-top:6px;">';
    print 'Il reste <b>'.roc_eur(max(0,$reste)).'</b> de marge brute a generer pour couvrir les frais fixes.';
    print '</div>';
}
print '</div>';

// Projection
print '<div style="background:#fff;padding:14px;border-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,0.08);">';
print '<h4 style="margin-top:0;">📈 Projection annuelle</h4>';
if ($projection['mois_ecoules'] > 0) {
    print '<div style="font-size:13px;color:#666;">CA projete : <b style="color:#3498db;font-size:16px;">'.roc_eur($projection['ca']).'</b></div>';
    print '<div style="font-size:13px;color:#666;margin-top:4px;">Marge nette projetee : <b style="color:'.($projection['marge_nette']>=0?'#27ae60':'#c0392b').';font-size:16px;">'.roc_eur($projection['marge_nette']).'</b></div>';
    print '<div style="font-size:11px;color:#888;margin-top:6px;font-style:italic;">Extrapolation lineaire sur '.$projection['mois_ecoules'].' mois ecoules</div>';
} else {
    print '<div style="font-size:14px;color:#888;">Pas assez de donnees pour projeter</div>';
}
print '</div>';

print '</div>';

// ============== Graphique evolution ==============
print '<div style="background:#fff;padding:16px;border-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,0.08);margin-bottom:24px;">';
print '<h4 style="margin-top:0;">📊 Evolution mensuelle '.$annee.' (CA / Marge brute / Marge nette)</h4>';
print '<div style="position:relative;height:320px;width:100%;"><canvas id="chartEvol"></canvas></div>';

$labels = array_values($moisNoms);
$caD = array();
$mbD = array();
$mnD = array();
$fxD = array();
for ($m=1; $m<=12; $m++) {
    $caD[] = round($evolutions[$m]['ca'], 2);
    $mbD[] = round($evolutions[$m]['mb'], 2);
    $mnD[] = round($evolutions[$m]['mn'], 2);
    $fxD[] = round($evolutions[$m]['frais'], 2);
}
print '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
print '<script>
new Chart(document.getElementById("chartEvol").getContext("2d"), {
    type: "bar",
    data: {
        labels: '.json_encode($labels).',
        datasets: [
            { type:"bar",  label:"CA HT (\u20ac)",        data:'.json_encode($caD).', backgroundColor:"rgba(52,152,219,0.5)",  borderColor:"#3498db" },
            { type:"bar",  label:"Marge brute (\u20ac)",  data:'.json_encode($mbD).', backgroundColor:"rgba(39,174,96,0.6)",   borderColor:"#27ae60" },
            { type:"line", label:"Marge nette (\u20ac)",  data:'.json_encode($mnD).', borderColor:"#c0392b", backgroundColor:"transparent", borderWidth:2, pointRadius:4, tension:0.3 },
            { type:"line", label:"Frais fixes (\u20ac)",  data:'.json_encode($fxD).', borderColor:"#e67e22", backgroundColor:"transparent", borderWidth:2, borderDash:[5,5], pointRadius:3, tension:0.3 }
        ]
    },
    options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{position:"bottom"} },
        scales:{ y:{ ticks:{ callback:function(v){return v.toLocaleString("fr-FR")+" \u20ac"} } } }
    }
});
</script>';
print '</div>';

// ============== Performance par categorie ==============
if (!empty($categoriesStats)) {
    print '<div style="background:#fff;padding:14px;border-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,0.08);margin-bottom:24px;">';
    print '<h4 style="margin-top:0;">🏷️ Performance par categorie</h4>';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><th>Categorie</th><th class="right">Produits</th><th class="right">Qte</th><th class="right">CA</th><th class="right">Marge nette</th><th class="right">Taux</th></tr>';
    foreach ($categoriesStats as $c) {
        print '<tr class="oddeven">';
        print '<td><b>'.dol_escape_htmltag($c['label']).'</b>';
        if ($c['cat_pct'] > 0) print ' <span style="color:#888;font-size:11px;">(comm. '.$c['cat_pct'].'%)</span>';
        print '</td>';
        print '<td class="right">'.$c['nb_prod'].'</td>';
        print '<td class="right">'.$c['qty'].'</td>';
        print '<td class="right">'.roc_eur($c['ca']).'</td>';
        $color = $c['marge'] >= 0 ? '#27ae60' : '#c0392b';
        print '<td class="right" style="color:'.$color.';font-weight:bold;">'.roc_eur($c['marge']).'</td>';
        $tauxColor = $c['taux'] >= $seuilMarge ? '#27ae60' : '#c0392b';
        print '<td class="right" style="color:'.$tauxColor.';"><b>'.number_format($c['taux'],1,',','').' %</b></td>';
        print '</tr>';
    }
    print '</table>';
    print '</div>';
}

// ============== Top / Flop produits ==============
print '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">';

// Top 5 par CA
print '<div style="background:#fff;padding:14px;border-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,0.08);">';
print '<h4 style="margin-top:0;">🥇 Top 5 produits par CA</h4>';
print '<table class="noborder centpercent" style="font-size:13px;">';
print '<tr class="liste_titre"><th>Ref</th><th class="right">Qte</th><th class="right">CA</th><th class="right">Marge</th></tr>';
foreach (array_slice($topByCA, 0, 5) as $p) {
    print '<tr class="oddeven">';
    print '<td><code>'.dol_escape_htmltag($p['ref']).'</code></td>';
    print '<td class="right">'.$p['qty'].'</td>';
    print '<td class="right">'.roc_eur($p['ca']).'</td>';
    print '<td class="right" style="color:'.($p['marge']>=0?'#27ae60':'#c0392b').';">'.roc_eur($p['marge']).'</td>';
    print '</tr>';
}
print '</table></div>';

// Top 5 par marge €
print '<div style="background:#fff;padding:14px;border-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,0.08);">';
print '<h4 style="margin-top:0;">💰 Top 5 produits par marge nette (€)</h4>';
print '<table class="noborder centpercent" style="font-size:13px;">';
print '<tr class="liste_titre"><th>Ref</th><th class="right">Marge</th><th class="right">Taux</th></tr>';
foreach (array_slice($topByMarge, 0, 5) as $p) {
    print '<tr class="oddeven">';
    print '<td><code>'.dol_escape_htmltag($p['ref']).'</code></td>';
    print '<td class="right" style="color:'.($p['marge']>=0?'#27ae60':'#c0392b').';font-weight:bold;">'.roc_eur($p['marge']).'</td>';
    print '<td class="right">'.number_format($p['taux'],1,',','').' %</td>';
    print '</tr>';
}
print '</table></div>';

print '</div>';

// Flop = produits sous le seuil
$flop = array_filter($flopByTaux, function($p){ return $p['ca'] > 0; });
if (count($flop) > 0) {
    print '<div style="background:#fff;padding:14px;border-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,0.08);margin-bottom:24px;">';
    print '<h4 style="margin-top:0;">⚠️ Produits sous le seuil de marge ('.$seuilMarge.' %)</h4>';
    $sousSeuil = array_filter($flop, function($p) use ($seuilMarge){ return $p['taux'] < $seuilMarge; });
    if (empty($sousSeuil)) {
        print '<div style="color:#27ae60;font-weight:bold;">✓ Tous vos produits sont au-dessus du seuil</div>';
    } else {
        print '<p style="color:#888;font-size:12px;">'.count($sousSeuil).' produit(s) generent moins de '.$seuilMarge.'% de marge nette :</p>';
        print '<table class="noborder centpercent" style="font-size:13px;">';
        print '<tr class="liste_titre"><th>Ref</th><th>Designation</th><th class="right">Qte</th><th class="right">CA</th><th class="right">Marge</th><th class="right">Taux</th></tr>';
        foreach (array_slice($sousSeuil, 0, 10) as $p) {
            print '<tr class="oddeven">';
            print '<td><code>'.dol_escape_htmltag($p['ref']).'</code></td>';
            print '<td>'.dol_escape_htmltag(substr($p['designation'], 0, 50)).'</td>';
            print '<td class="right">'.$p['qty'].'</td>';
            print '<td class="right">'.roc_eur($p['ca']).'</td>';
            print '<td class="right" style="color:'.($p['marge']>=0?'#27ae60':'#c0392b').';">'.roc_eur($p['marge']).'</td>';
            print '<td class="right" style="color:#c0392b;font-weight:bold;">'.number_format($p['taux'],1,',','').' %</td>';
            print '</tr>';
        }
        print '</table>';
    }
    print '</div>';
}

// Produits dormants
if (!empty($produitsDormants)) {
    print '<div style="background:#fff;padding:14px;border-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,0.08);margin-bottom:24px;">';
    print '<h4 style="margin-top:0;">😴 Produits dormants</h4>';
    print '<p style="color:#888;font-size:12px;">Produits sans vente depuis 2 mois ou plus (sur les ventes '.$annee.') :</p>';
    print '<table class="noborder centpercent" style="font-size:13px;">';
    print '<tr class="liste_titre"><th>Ref</th><th>Designation</th><th class="right">Derniere vente</th><th class="right">CA an</th></tr>';
    foreach ($produitsDormants as $p) {
        print '<tr class="oddeven">';
        print '<td><code>'.dol_escape_htmltag($p['ref']).'</code></td>';
        print '<td>'.dol_escape_htmltag(substr($p['designation'], 0, 60)).'</td>';
        print '<td class="right">'.(isset($moisNoms[$p['dernier']])?$moisNoms[$p['dernier']]:'?').'</td>';
        print '<td class="right">'.roc_eur($p['ca']).'</td>';
        print '</tr>';
    }
    print '</table>';
    print '</div>';
}

llxFooter();
$db->close();
