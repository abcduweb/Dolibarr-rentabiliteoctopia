<?php
/**
 * Historique des prix - Suivi des changements de prix et de leur impact reel.
 *
 * Affiche :
 *   - les changements de prix detectes (hausse/baisse) avec impact sur volume et marge
 *   - l'evolution du prix moyen par produit dans le temps
 *
 * Permet de repondre a : "j'ai augmente ce prix, est-ce que ca a fait baisser
 * les ventes ? est-ce que ma marge totale a progresse malgre tout ?"
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';
require_once __DIR__.'/lib/ModuleHelper.class.php';
require_once __DIR__.'/lib/PrixHistorique.class.php';

if (!$user->rights->rentabiliteoctopia->read) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$entity = (int)$conf->entity;
$action = GETPOST('action', 'alpha');
$seuilVariation = (float)(GETPOST('seuil', 'alpha') !== '' ? str_replace(',', '.', GETPOST('seuil', 'alpha')) : 3);

$histo = new PrixHistorique($db, $entity);

// Action : capturer les snapshots
if ($action === 'capturer') {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        $nb = $histo->capturer();
        setEventMessages($nb.' snapshot(s) de prix captures. Les changements seront detectes ci-dessous.', null, 'mesgs');
        header('Location: historique_prix.php'); exit;
    }
}

$nbSnapshots = $histo->countSnapshots();
$changements = ($nbSnapshots > 0) ? $histo->getChangements($seuilVariation) : array();

llxHeader('', 'Historique des prix');
print load_fiche_titre('Historique des prix & impact des changements', '', 'fa-history');
ModuleHelper::navBar('historique_prix.php');

$currentToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken();

// Bandeau capture
print '<div style="background:#f0f4ff;border:1px solid #667eea;padding:14px;border-radius:6px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">';
print '<div style="font-size:13px;">';
print '<b>'.$nbSnapshots.' snapshot(s)</b> de prix en historique. ';
print 'Capturez regulierement (ou via le cron) pour suivre l\'evolution des prix dans le temps.';
print '</div>';
print '<form method="POST" action="historique_prix.php" style="margin:0;">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
print '<input type="hidden" name="action" value="capturer">';
print '<button type="submit" class="button butActionNew"><i class="fa fa-camera"></i> Capturer les prix maintenant</button>';
print '</form>';
print '</div>';

if ($nbSnapshots === 0) {
    print '<div class="warning" style="padding:20px;border-radius:6px;">';
    print '<b>Aucun historique de prix pour l\'instant.</b><br>';
    print 'Cliquez sur "Capturer les prix maintenant" pour creer le premier snapshot a partir de vos ventes existantes. ';
    print 'Le module calculera le prix moyen mensuel de chaque produit et pourra ensuite detecter les changements.';
    print '</div>';
    llxFooter(); $db->close(); exit;
}

// Filtre seuil
print '<form method="GET" action="historique_prix.php" style="margin-bottom:20px;">';
print '<label style="font-size:13px;margin-right:8px;">Seuil de detection des changements :</label>';
print '<input type="number" name="seuil" value="'.$seuilVariation.'" step="0.5" min="0.5" max="50" style="width:70px;text-align:right;padding:5px;border:1px solid #ddd;border-radius:4px;"> %';
print ' <button type="submit" class="button smallpaddingimp">Appliquer</button>';
print ' <a href="export.php?type=historique_prix" class="button" style="background:#16a085;margin-left:10px;"><i class="fa fa-file-excel"></i> Exporter CSV</a>';
print ' <a href="export.php?type=historique_prix&format=pdf" class="button" style="background:#c0392b;"><i class="fa fa-file-pdf"></i> Exporter PDF</a>';
print '</form>';

// KPI changements
$nbHausses = 0; $nbBaisses = 0; $impactTotal = 0;
foreach ($changements as $c) {
    if ($c['sens'] === 'hausse') $nbHausses++;
    else $nbBaisses++;
    $impactTotal += $c['impact_marge'];
}

print '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:24px;">';
print '<div style="background:#fff;border-left:4px solid #27ae60;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Hausses de prix</div>';
print '<div style="font-size:26px;font-weight:bold;color:#27ae60;">'.$nbHausses.'</div>';
print '</div>';
print '<div style="background:#fff;border-left:4px solid #e67e22;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Baisses de prix</div>';
print '<div style="font-size:26px;font-weight:bold;color:#e67e22;">'.$nbBaisses.'</div>';
print '</div>';
$impColor = $impactTotal >= 0 ? '#27ae60' : '#c0392b';
print '<div style="background:#fff;border-left:4px solid '.$impColor.';padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Impact marge cumule</div>';
print '<div style="font-size:24px;font-weight:bold;color:'.$impColor.';">'.($impactTotal>=0?'+':'').roc_eur($impactTotal).'</div>';
print '<div style="font-size:11px;color:#666;">Effet net des changements</div>';
print '</div>';
print '</div>';

if (empty($changements)) {
    print '<div style="background:#e8f8ee;padding:30px;border-radius:8px;text-align:center;">';
    print '<div style="font-size:36px;">📊</div>';
    print '<div style="font-size:16px;color:#27ae60;font-weight:bold;margin-top:10px;">Aucun changement de prix detecte (seuil '.number_format($seuilVariation,1,',','').'%)</div>';
    print '<div style="font-size:13px;color:#666;margin-top:6px;">Vos prix sont stables, ou il faut plus de snapshots dans le temps pour comparer.</div>';
    print '</div>';
    llxFooter(); $db->close(); exit;
}

// Tableau des changements
print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<h3 style="margin-top:0;">Changements de prix detectes</h3>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>Reference</th>';
print '<th>Periode</th>';
print '<th class="right">Prix avant</th>';
print '<th class="right">Prix apres</th>';
print '<th class="right">Variation prix</th>';
print '<th class="right">Variation volume</th>';
print '<th class="right">Impact marge</th>';
print '<th>Verdict</th>';
print '</tr>';

foreach ($changements as $c) {
    print '<tr class="oddeven">';
    print '<td><b><code>'.dol_escape_htmltag($c['ref']).'</code></b><br><span style="font-size:11px;color:#888;">'.dol_escape_htmltag(substr($c['designation'],0,30)).'</span></td>';
    print '<td style="font-size:12px;">'.$c['mois_avant'].' → '.$c['mois_apres'].'</td>';
    print '<td class="right">'.roc_eur($c['prix_avant']).'</td>';
    print '<td class="right"><b>'.roc_eur($c['prix_apres']).'</b></td>';

    // Variation prix
    print '<td class="right">';
    $prixColor = $c['variation_pct'] > 0 ? '#27ae60' : '#e67e22';
    $sign = $c['variation_pct'] > 0 ? '+' : '';
    print '<b style="color:'.$prixColor.';">'.$sign.number_format($c['variation_pct'],1,',','').'%</b>';
    print '</td>';

    // Variation volume
    print '<td class="right">';
    if ($c['variation_volume'] === null) {
        print '<span style="color:#aaa;">—</span>';
    } else {
        $volColor = $c['variation_volume'] >= 0 ? '#27ae60' : '#c0392b';
        $volSign = $c['variation_volume'] >= 0 ? '+' : '';
        print '<b style="color:'.$volColor.';">'.$volSign.number_format($c['variation_volume'],0,',','').'%</b>';
    }
    print '</td>';

    // Impact marge
    print '<td class="right">';
    $impColor = $c['impact_marge'] >= 0 ? '#27ae60' : '#c0392b';
    print '<b style="color:'.$impColor.';">'.($c['impact_marge']>=0?'+':'').roc_eur($c['impact_marge']).'</b>';
    print '</td>';

    // Verdict intelligent
    print '<td>';
    $verdict = ''; $vColor = '';
    if ($c['sens'] === 'hausse') {
        if ($c['variation_volume'] !== null && $c['variation_volume'] < -30) {
            $verdict = 'Volume chute fort'; $vColor = '#c0392b';
        } elseif ($c['impact_marge'] >= 0) {
            $verdict = 'Hausse gagnante'; $vColor = '#27ae60';
        } else {
            $verdict = 'Hausse perdante'; $vColor = '#e67e22';
        }
    } else {
        if ($c['variation_volume'] !== null && $c['variation_volume'] > 30 && $c['impact_marge'] >= 0) {
            $verdict = 'Baisse gagnante'; $vColor = '#27ae60';
        } elseif ($c['impact_marge'] < 0) {
            $verdict = 'Baisse perdante'; $vColor = '#c0392b';
        } else {
            $verdict = 'Effet neutre'; $vColor = '#888';
        }
    }
    print '<span style="background:'.$vColor.'22;color:'.$vColor.';padding:3px 10px;border-radius:12px;font-size:11px;font-weight:bold;">'.$verdict.'</span>';
    print '</td>';

    print '</tr>';
}
print '</table>';

print '<p style="font-size:12px;color:#888;margin-top:14px;">';
print '<b>Comment lire :</b> chaque ligne compare deux mois consecutifs ou le prix a varie de plus de '.number_format($seuilVariation,1,',','').'%. ';
print 'L\'<b>impact marge</b> = marge totale apres − marge totale avant (tient compte du volume). ';
print 'Une <b>hausse gagnante</b> = tu as augmente le prix et ta marge totale a progresse malgre une eventuelle baisse de volume. ';
print 'Une <b>baisse gagnante</b> = baisser le prix a tellement booste le volume que ta marge totale a augmente.';
print '</p>';
print '</div>';

print '<div style="margin-top:16px;padding:12px;background:#fff8f0;border-radius:6px;font-size:13px;">';
print '<i class="fa fa-info-circle"></i> <b>Astuce :</b> pour un suivi automatique, ajoutez la capture des prix au cron mensuel. ';
print 'Ainsi chaque mois un snapshot est enregistre et vous accumulez un historique fiable pour mesurer vos decisions tarifaires.';
print '</div>';

llxFooter();
$db->close();
