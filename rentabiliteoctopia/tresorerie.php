<?php
/**
 * Tresorerie Octopia - Suivi des reversements Cdiscount
 *
 * Modele : Cdiscount collecte le CA TTC aupres des clients puis reverse au vendeur
 * selon une periodicite configurable (defaut : tous les 10 jours), apres deduction
 * de la commission.
 *
 * Le module projette pour chaque commande sa date d'encaissement prevue,
 * affiche le "du" en attente, un calendrier des encaissements a venir,
 * et permet de pointer les reversements reellement recus (rapprochement).
 *
 * Parametres (table rentabiliteoctopia_params) :
 *   treso_freq_jours       : periodicite reversement en jours (defaut 10)
 *   treso_delai_jours      : delai entre fin de periode et versement effectif (defaut 0)
 *   treso_base_date        : 'commande' ou 'expedition' (date de reference)
 *
 * Pointage des reversements recus : table rentabiliteoctopia_reversement
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';

if (!$user->rights->rentabiliteoctopia->read) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

// Table de pointage des reversements recus
$db->query("CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."rentabiliteoctopia_reversement (
    rowid             INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    periode_debut     DATE        NOT NULL,
    periode_fin       DATE        NOT NULL,
    date_versement    DATE        NOT NULL,
    montant_prevu     DOUBLE(24,8) NOT NULL DEFAULT 0,
    montant_recu      DOUBLE(24,8) DEFAULT NULL,
    date_pointage     DATETIME    DEFAULT NULL,
    note              VARCHAR(255) DEFAULT NULL,
    entity            INT(11)     NOT NULL DEFAULT 1,
    PRIMARY KEY (rowid),
    UNIQUE KEY uk_periode (periode_debut, periode_fin, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

$params = rentabiliteoctopia_get_params($db);
$action = GETPOST('action', 'alpha');

// Configuration tresorerie
$freqJours  = isset($params['treso_freq_jours'])  && $params['treso_freq_jours']  !== '' ? (int)$params['treso_freq_jours']  : 10;
$delaiJours = isset($params['treso_delai_jours']) && $params['treso_delai_jours'] !== '' ? (int)$params['treso_delai_jours'] : 0;
$baseDate   = isset($params['treso_base_date']) ? $params['treso_base_date'] : 'commande';
if ($freqJours < 1) $freqJours = 10;

// ----- Actions -----
if ($action === 'save_config') {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        $cfg = array(
            'treso_freq_jours'  => (string)(int)GETPOST('treso_freq_jours', 'int'),
            'treso_delai_jours' => (string)(int)GETPOST('treso_delai_jours', 'int'),
            'treso_base_date'   => GETPOST('treso_base_date', 'alpha') === 'expedition' ? 'expedition' : 'commande',
        );
        foreach ($cfg as $k => $v) {
            $db->query("INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_params (param_key, param_value, entity)
                        VALUES ('".$db->escape($k)."', '".$db->escape($v)."', ".((int)$conf->entity).")
                        ON DUPLICATE KEY UPDATE param_value = '".$db->escape($v)."'");
        }
        setEventMessages('Configuration tresorerie enregistree.', null, 'mesgs');
        header('Location: tresorerie.php'); exit;
    }
}

if ($action === 'pointer_recu') {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        $pdeb = GETPOST('periode_debut', 'alpha');
        $pfin = GETPOST('periode_fin', 'alpha');
        $dvers = GETPOST('date_versement', 'alpha');
        $prevu = (float)str_replace(',', '.', GETPOST('montant_prevu', 'alpha'));
        $recu  = GETPOST('montant_recu', 'alpha') !== '' ? (float)str_replace(',', '.', GETPOST('montant_recu', 'alpha')) : null;

        $recuSql = ($recu === null) ? 'NULL' : (float)$recu;
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_reversement
                    (periode_debut, periode_fin, date_versement, montant_prevu, montant_recu, date_pointage, entity)
                VALUES ('".$db->escape($pdeb)."', '".$db->escape($pfin)."', '".$db->escape($dvers)."',
                        ".((float)$prevu).", ".$recuSql.", '".$db->idate(dol_now())."', ".((int)$conf->entity).")
                ON DUPLICATE KEY UPDATE
                    montant_recu = ".$recuSql.",
                    date_pointage = '".$db->idate(dol_now())."'";
        if ($db->query($sql)) {
            setEventMessages('Reversement pointe.', null, 'mesgs');
        } else {
            setEventMessages('Erreur : '.$db->lasterror(), null, 'errors');
        }
        header('Location: tresorerie.php'); exit;
    }
}

// ============================================================================
// CALCUL DES PERIODES DE REVERSEMENT
// ============================================================================
// On decoupe le temps en tranches de $freqJours jours a partir d une date d ancrage
// (1er janvier de l annee courante). Chaque commande tombe dans une tranche.
// Le versement = fin de tranche + $delaiJours.

$dateField = ($baseDate === 'expedition') ? 'c.date_livraison' : 'c.date_commande';
// Fallback si date_livraison NULL -> date_commande
$dateExpr = ($baseDate === 'expedition')
    ? "COALESCE(c.date_livraison, c.date_commande)"
    : "c.date_commande";

// Recuperer toutes les ventes des 6 derniers mois, groupees par jour.
// CALCUL FIABILISE :
//  - commission REELLE par ligne (via la categorie du produit lie, sinon defaut 15%)
//  - TVA reelle par ligne (cd.tva_tx, pas un taux global)
//  - les frais de port (lignes sans produit) ont leur propre commission a 0 (Cdiscount ne commissionne pas le port)
// La commission est rattachee au produit via le mapping rentabiliteoctopia (ref produit Dolibarr -> categorie)
$commDefaut = 15; // taux par defaut si produit non categorise

$sql = "SELECT
            DATE(".$dateExpr.")                                AS jour,
            COUNT(DISTINCT o.rowid)                            AS nb_cmd,
            SUM(cd.qty * cd.subprice)                          AS ca_ht,
            SUM(cd.qty * cd.subprice * (1 + COALESCE(cd.tva_tx,20)/100)) AS ca_ttc,
            SUM(CASE
                WHEN cd.fk_product IS NOT NULL THEN cd.qty * cd.subprice
                ELSE 0 END)                                     AS ca_produits_ht,
            SUM(CASE
                WHEN cd.fk_product IS NULL THEN cd.qty * cd.subprice
                ELSE 0 END)                                     AS ca_port_ht,
            SUM(
                CASE
                    WHEN cd.fk_product IS NULL THEN 0
                    ELSE cd.qty * cd.subprice * COALESCE(cat.commission_pct, ".$commDefaut.") / 100
                END
            )                                                   AS commission_reelle
        FROM ".MAIN_DB_PREFIX."octopia_orders o
        INNER JOIN ".MAIN_DB_PREFIX."commande c
            ON  c.rowid = o.dolibarr_order_id
            AND c.entity = ".((int)$conf->entity)."
            AND c.fk_statut >= 1
            AND ".$dateExpr." >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
        LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit rp
            ON rp.ref = (SELECT ref FROM ".MAIN_DB_PREFIX."product WHERE rowid = cd.fk_product)
            AND rp.entity = ".((int)$conf->entity)."
        LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie cat
            ON cat.rowid = rp.fk_categorie AND cat.entity = ".((int)$conf->entity)."
        WHERE o.entity = ".((int)$conf->entity)."
          AND o.is_refunded = 0
          AND (o.octopia_order_status IS NULL OR o.octopia_order_status NOT IN ('CANCELLED','REFUNDED','REFUSED','CANCELED'))
        GROUP BY DATE(".$dateExpr.")
        ORDER BY jour";

$resql = $db->query($sql);
$ventesParJour = array();
while ($resql && $o = $db->fetch_object($resql)) {
    $ventesParJour[$o->jour] = array(
        'nb_cmd'      => (int)$o->nb_cmd,
        'ca_ht'       => (float)$o->ca_ht,
        'ca_ttc'      => (float)$o->ca_ttc,
        'ca_prod'     => (float)$o->ca_produits_ht,
        'ca_port'     => (float)$o->ca_port_ht,
        'commission'  => (float)$o->commission_reelle,
    );
}

// --- Retours / remboursements : montant a deduire des reversements ---
// Cdiscount deduit les commandes remboursees (is_refunded=1) du reversement.
// On calcule le TTC rembourse par jour pour le soustraire.
$remboursParJour = array();
$sqlRemb = "SELECT
                DATE(".$dateExpr.")                                AS jour,
                SUM(cd.qty * cd.subprice * (1 + COALESCE(cd.tva_tx,20)/100)) AS ttc_rembourse
            FROM ".MAIN_DB_PREFIX."octopia_orders o
            INNER JOIN ".MAIN_DB_PREFIX."commande c
                ON  c.rowid = o.dolibarr_order_id
                AND c.entity = ".((int)$conf->entity)."
                AND ".$dateExpr." >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
            WHERE o.entity = ".((int)$conf->entity)."
              AND o.is_refunded = 1
            GROUP BY DATE(".$dateExpr.")";
$rRemb = $db->query($sqlRemb);
while ($rRemb && $o = $db->fetch_object($rRemb)) {
    $remboursParJour[$o->jour] = (float)$o->ttc_rembourse;
}

// Commission moyenne (uniquement pour affichage informatif dans la config)
$sqlComm = "SELECT AVG(commission_pct) AS avg_pct FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie
            WHERE entity = ".((int)$conf->entity)." AND commission_pct > 0";
$rComm = $db->query($sqlComm);
$commMoyenne = $commDefaut;
if ($rComm && $oC = $db->fetch_object($rComm)) {
    if ($oC->avg_pct > 0) $commMoyenne = (float)$oC->avg_pct;
}

// Decouper en periodes de freqJours
function getPeriodeStart($dateStr, $freqJours, $anchorYear) {
    $anchor = strtotime($anchorYear.'-01-01');
    $date   = strtotime($dateStr);
    $diffDays = floor(($date - $anchor) / 86400);
    $periodIndex = floor($diffDays / $freqJours);
    $periodStart = $anchor + ($periodIndex * $freqJours * 86400);
    return $periodStart;
}

$anchorYear = (int)date('Y');
$periodes = array();
foreach ($ventesParJour as $jour => $v) {
    $pStart = getPeriodeStart($jour, $freqJours, $anchorYear);
    $pEnd   = $pStart + (($freqJours - 1) * 86400);
    $key = date('Y-m-d', $pStart);
    if (!isset($periodes[$key])) {
        $periodes[$key] = array(
            'debut'      => $pStart,
            'fin'        => $pEnd,
            'versement'  => $pEnd + ($delaiJours * 86400) + 86400,
            'nb_cmd'     => 0,
            'ca_ht'      => 0,
            'ca_ttc'     => 0,
            'ca_prod'    => 0,
            'ca_port'    => 0,
            'commission' => 0,
            'rembourse'  => 0,
        );
    }
    $periodes[$key]['nb_cmd']     += $v['nb_cmd'];
    $periodes[$key]['ca_ht']      += $v['ca_ht'];
    $periodes[$key]['ca_ttc']     += $v['ca_ttc'];
    $periodes[$key]['ca_prod']    += $v['ca_prod'];
    $periodes[$key]['ca_port']    += $v['ca_port'];
    $periodes[$key]['commission'] += $v['commission'];
    // Remboursements du meme jour
    if (isset($remboursParJour[$jour])) {
        $periodes[$key]['rembourse'] += $remboursParJour[$jour];
    }
}
krsort($periodes); // plus recent en premier

// Charger les pointages existants
$pointages = array();
$sqlP = "SELECT periode_debut, periode_fin, date_versement, montant_prevu, montant_recu, date_pointage
         FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_reversement
         WHERE entity = ".((int)$conf->entity);
$rP = $db->query($sqlP);
while ($rP && $o = $db->fetch_object($rP)) {
    $pointages[$o->periode_debut] = (array)$o;
}

// Calculs synthese
$today = strtotime(date('Y-m-d'));
$totalDu       = 0;  // net reverse pas encore verse (versement futur)
$totalDuBrut   = 0;
$encaisseSemaine = array(); // projection prochaines semaines

$totalRembourse = 0;
foreach ($periodes as $key => $p) {
    // Net reverse = TTC collecte - commission reelle - remboursements TTC
    $netReverse = $p['ca_ttc'] - $p['commission'] - $p['rembourse'];
    $totalRembourse += $p['rembourse'];
    if ($p['versement'] > $today) {
        $totalDu     += $netReverse;
        $totalDuBrut += $p['ca_ttc'] - $p['rembourse'];
    }
}

// ============================================================================
// AFFICHAGE
// ============================================================================
llxHeader('', 'Tresorerie Octopia');
print load_fiche_titre('Tresorerie Octopia — reversements Cdiscount', '', 'fa-euro-sign');

$currentToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken();

// ---- Config ----
print '<details style="margin-bottom:20px;"><summary style="cursor:pointer;font-weight:bold;padding:10px;background:#f0f0f0;border-radius:4px;"><i class="fa fa-cog"></i> Configuration du modele de reversement</summary>';
print '<div style="padding:16px;background:#fafafa;border-radius:4px;margin-top:8px;">';
print '<form method="POST" action="tresorerie.php">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
print '<input type="hidden" name="action" value="save_config">';
print '<table class="noborder">';
print '<tr class="oddeven"><td>Periodicite des reversements</td>';
print '<td>Tous les <input type="number" name="treso_freq_jours" value="'.$freqJours.'" min="1" max="60" style="width:60px;text-align:right"> jours</td></tr>';
print '<tr class="oddeven"><td>Delai apres fin de periode</td>';
print '<td><input type="number" name="treso_delai_jours" value="'.$delaiJours.'" min="0" max="30" style="width:60px;text-align:right"> jours (0 = versement le lendemain de la fin de periode)</td></tr>';
print '<tr class="oddeven"><td>Date de reference</td>';
print '<td><select name="treso_base_date" class="flat">';
print '<option value="commande"'.($baseDate==='commande'?' selected':'').'>Date de commande</option>';
print '<option value="expedition"'.($baseDate==='expedition'?' selected':'').'>Date d\'expedition/livraison</option>';
print '</select></td></tr>';
print '<tr class="oddeven"><td>Commission</td><td>Calculee <b>produit par produit</b> selon la categorie (moyenne actuelle : '.number_format($commMoyenne,1,',','').' %)</td></tr>';
print '</table>';
print '<input type="submit" class="button" value="Enregistrer la configuration">';
print '</form>';
print '</div></details>';

// ---- KPI synthese ----
print '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:24px;">';

print '<div style="background:#fff;border-left:4px solid #e67e22;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Cdiscount vous doit (net)</div>';
print '<div style="font-size:28px;font-weight:bold;color:#e67e22;margin:6px 0;">'.roc_eur($totalDu).'</div>';
print '<div style="font-size:11px;color:#666;">En attente de reversement</div>';
print '</div>';

print '<div style="background:#fff;border-left:4px solid #3498db;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Brut collecte en attente</div>';
print '<div style="font-size:28px;font-weight:bold;color:#3498db;margin:6px 0;">'.roc_eur($totalDuBrut).'</div>';
print '<div style="font-size:11px;color:#666;">TTC encaisse par Cdiscount aupres des clients</div>';
print '</div>';

print '<div style="background:#fff;border-left:4px solid #9b59b6;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Periodicite</div>';
print '<div style="font-size:28px;font-weight:bold;color:#9b59b6;margin:6px 0;">'.$freqJours.' j</div>';
print '<div style="font-size:11px;color:#666;">Entre chaque reversement</div>';
print '</div>';

print '</div>';

// ---- Calendrier des encaissements a venir ----
$futurs = array();
foreach ($periodes as $key => $p) {
    if ($p['versement'] >= $today) {
        $futurs[$key] = $p;
    }
}
ksort($futurs); // ordre chronologique pour le calendrier

if (!empty($futurs)) {
    print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:24px;">';
    print '<h3 style="margin-top:0;">Encaissements a venir</h3>';
    print '<canvas id="chartTreso" style="max-height:260px;"></canvas>';

    $labels = array();
    $dataNet = array();
    $dataBrut = array();
    foreach ($futurs as $p) {
        $labels[] = date('d/m', $p['versement']);
        $net = $p['ca_ttc'] - $p['commission'] - $p['rembourse'];
        $dataNet[] = round($net, 2);
        $dataBrut[] = round($p['ca_ttc'] - $p['rembourse'], 2);
    }

    print '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
    print '<script>
    (function(){
        var ctx = document.getElementById("chartTreso").getContext("2d");
        new Chart(ctx, {
            type: "bar",
            data: {
                labels: '.json_encode($labels).',
                datasets: [
                    { label:"Net reverse (\u20ac)",  data:'.json_encode($dataNet).', backgroundColor:"rgba(230,126,34,0.7)" },
                    { label:"Brut collecte (\u20ac)", data:'.json_encode($dataBrut).', backgroundColor:"rgba(52,152,219,0.4)" }
                ]
            },
            options: {
                responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{position:"bottom"}, title:{display:true,text:"Dates de versement prevues"} },
                scales:{ y:{ ticks:{ callback:function(v){return v.toLocaleString("fr-FR")+" \u20ac"} } } }
            }
        });
    })();
    </script>';
    print '</div>';
}

// ---- Tableau detaille des periodes ----
print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<h3 style="margin-top:0;">Detail des periodes de reversement</h3>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>Periode de ventes</th>';
print '<th class="right">Cmd</th>';
print '<th class="right">Brut TTC</th>';
print '<th class="right">Commission</th>';
print '<th class="right">Rembours.</th>';
print '<th class="right">Net reverse est.</th>';
print '<th>Versement prevu</th>';
print '<th>Statut</th>';
print '<th>Recu</th>';
print '</tr>';

foreach ($periodes as $key => $p) {
    $commission = $p['commission'];
    $net = $p['ca_ttc'] - $commission - $p['rembourse'];
    $pdeb = date('Y-m-d', $p['debut']);
    $pfin = date('Y-m-d', $p['fin']);
    $dvers = date('Y-m-d', $p['versement']);

    $estPasse = ($p['versement'] < $today);
    $pointage = isset($pointages[$pdeb]) ? $pointages[$pdeb] : null;
    $estPointe = ($pointage && $pointage['montant_recu'] !== null);

    print '<tr class="oddeven">';
    print '<td><b>'.date('d/m', $p['debut']).' au '.date('d/m/Y', $p['fin']).'</b></td>';
    print '<td class="right">'.$p['nb_cmd'].'</td>';
    print '<td class="right">'.roc_eur($p['ca_ttc']).'</td>';
    print '<td class="right" style="color:#e67e22">-'.roc_eur($commission).'</td>';
    // Remboursements
    print '<td class="right">';
    if ($p['rembourse'] > 0.01) {
        print '<span style="color:#c0392b">-'.roc_eur($p['rembourse']).'</span>';
    } else {
        print '<span style="color:#ccc">—</span>';
    }
    print '</td>';
    print '<td class="right"><b>'.roc_eur($net).'</b></td>';
    print '<td>'.date('d/m/Y', $p['versement']).'</td>';

    // Statut
    print '<td>';
    if ($estPointe) {
        print '<span class="sim-badge" style="background:#e8f8ee;color:#27ae60;">Recu</span>';
    } elseif ($estPasse) {
        print '<span class="sim-badge" style="background:#fdebe5;color:#c0392b;">A pointer</span>';
    } else {
        print '<span class="sim-badge" style="background:#e3f1fc;color:#3498db;">A venir</span>';
    }
    print '</td>';

    // Pointage recu
    print '<td>';
    if ($estPointe) {
        $ecart = $pointage['montant_recu'] - $net;
        $ecartColor = abs($ecart) < 1 ? '#27ae60' : '#e67e22';
        print roc_eur($pointage['montant_recu']);
        if (abs($ecart) >= 1) {
            print ' <span style="font-size:11px;color:'.$ecartColor.'">('.($ecart>=0?'+':'').number_format($ecart,2,',','').'€)</span>';
        }
    } elseif ($estPasse || $p['versement'] <= $today + (7*86400)) {
        // Formulaire de pointage inline
        print '<form method="POST" action="tresorerie.php" style="display:flex;gap:4px;align-items:center;">';
        print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
        print '<input type="hidden" name="action" value="pointer_recu">';
        print '<input type="hidden" name="periode_debut" value="'.$pdeb.'">';
        print '<input type="hidden" name="periode_fin" value="'.$pfin.'">';
        print '<input type="hidden" name="date_versement" value="'.$dvers.'">';
        print '<input type="hidden" name="montant_prevu" value="'.number_format($net,2,'.','').'">';
        print '<input type="number" name="montant_recu" step="0.01" placeholder="'.number_format($net,2,'.','').'" style="width:90px;text-align:right;padding:4px;border:1px solid #ddd;border-radius:3px;">';
        print '<button type="submit" class="button smallpaddingimp" style="padding:4px 8px;">OK</button>';
        print '</form>';
    } else {
        print '<span style="color:#ccc;font-size:12px;">—</span>';
    }
    print '</td>';

    print '</tr>';
}
print '</table>';

print '<p style="font-size:12px;color:#888;margin-top:14px;">';
print '<b>Comment lire ce tableau :</b> chaque periode regroupe les ventes sur '.$freqJours.' jours. ';
print 'Le "net reverse estime" = brut TTC collecte par Cdiscount <b>moins la commission reelle</b> (calculee produit par produit selon la categorie) <b>moins les remboursements</b> de la periode. ';
print 'La commission moyenne de vos categories est de '.number_format($commMoyenne,1,',','').'%, mais le calcul utilise le taux exact de chaque produit. ';
print 'Quand vous recevez un virement Cdiscount, saisissez le montant reel dans la colonne "Recu" pour suivre l\'ecart avec la prevision.';
print '</p>';
print '</div>';

print '<style>.sim-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:bold;}</style>';

llxFooter();
$db->close();
