<?php
/**
 * Cron quotidien — Envoi par email des KPI Cdiscount de la veille
 *
 * Calcule les indicateurs du jour J-1 et envoie un mail HTML formate
 * a l'adresse configuree dans Parametres > daily_kpi_email.
 *
 * A lancer chaque matin (ex: 8h) via le cron o2switch :
 *   0 8 * * * /usr/local/bin/php /chemin/htdocs/custom/rentabiliteoctopia/cron/daily_kpi_mail.php \
 *             >> /tmp/rentabiliteoctopia_daily.log 2>&1
 */

// Boot Dolibarr en mode CLI
$sapi_type = php_sapi_name();
if (substr($sapi_type, 0, 3) !== 'cli') {
    die("Script reserve aux executions en ligne de commande (cron).\n");
}

define('NOSESSION', '1');
define('NOREQUIREUSER', '1');

// Detection chemin Dolibarr
$path = __DIR__;
for ($i = 0; $i < 8; $i++) {
    if (file_exists($path.'/master.inc.php')) break;
    if (file_exists($path.'/htdocs/master.inc.php')) { $path .= '/htdocs'; break; }
    $path = dirname($path);
}
if (!file_exists($path.'/master.inc.php')) {
    die("ERREUR : impossible de trouver master.inc.php\n");
}
require_once $path.'/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once dirname(__DIR__).'/lib/rentabiliteoctopia.lib.php';

$entity = !empty($conf->entity) ? (int)$conf->entity : 1;

echo "[".date('Y-m-d H:i:s')."] Demarrage daily_kpi_mail (entity=$entity)\n";

// ============================================================================
// 1. Recuperer la configuration
// ============================================================================
$params      = rentabiliteoctopia_get_params($db);
$emailTo     = isset($params['daily_kpi_email'])  ? trim($params['daily_kpi_email'])  : '';
$emailEnabled = isset($params['daily_kpi_enabled']) ? (int)$params['daily_kpi_enabled'] : 0;
$seuilMarge   = isset($params['seuil_marge_pct']) ? (float)$params['seuil_marge_pct'] : 15;

if (!$emailEnabled) {
    echo "Rapport quotidien desactive (daily_kpi_enabled=0). Sortie.\n";
    exit(0);
}
if (empty($emailTo) || !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
    echo "Email destinataire invalide ou non configure. Sortie.\n";
    exit(1);
}

// ============================================================================
// 2. Calculer les KPI de la veille J-1
// ============================================================================
$dateHier      = date('Y-m-d', strtotime('-1 day'));
$dateAvantHier = date('Y-m-d', strtotime('-2 days'));
$dateSemPrec   = date('Y-m-d', strtotime('-8 days'));

function getKpiJour($db, $entity, $date) {
    $sql = "SELECT
                COUNT(DISTINCT o.rowid)                  AS nb_commandes,
                COUNT(cd.rowid)                          AS nb_lignes,
                COALESCE(SUM(cd.qty), 0)                 AS qty,
                COALESCE(SUM(cd.qty * cd.subprice), 0)   AS ca_ht,
                COALESCE(SUM(CASE
                    WHEN cd.fk_product IS NOT NULL THEN cd.qty * cd.subprice
                    ELSE 0
                END), 0)                                  AS ca_produits,
                COALESCE(SUM(CASE
                    WHEN cd.fk_product IS NULL THEN cd.qty * cd.subprice
                    ELSE 0
                END), 0)                                  AS ca_port,
                COALESCE(SUM(p.cost_price * cd.qty), 0)  AS cout_achat
            FROM ".MAIN_DB_PREFIX."octopia_orders o
            INNER JOIN ".MAIN_DB_PREFIX."commande c
                ON  c.rowid  = o.dolibarr_order_id
                AND c.entity = ".(int)$entity."
                AND DATE(c.date_commande) = '".$db->escape($date)."'
                AND c.fk_statut >= 1
            INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
            LEFT  JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product
            WHERE o.entity = ".(int)$entity."
              AND o.is_refunded = 0
              AND (o.octopia_order_status IS NULL OR o.octopia_order_status NOT IN ('CANCELLED','REFUNDED','REFUSED','CANCELED'))";
    $r = $db->query($sql);
    if (!$r || !($o = $db->fetch_object($r))) {
        return array('nb_commandes'=>0,'nb_lignes'=>0,'qty'=>0,'ca_ht'=>0,'ca_produits'=>0,'ca_port'=>0,'cout_achat'=>0);
    }
    return array(
        'nb_commandes' => (int)$o->nb_commandes,
        'nb_lignes'    => (int)$o->nb_lignes,
        'qty'          => (int)$o->qty,
        'ca_ht'        => (float)$o->ca_ht,
        'ca_produits'  => (float)$o->ca_produits,
        'ca_port'      => (float)$o->ca_port,
        'cout_achat'   => (float)$o->cout_achat,
    );
}

function getTopProduitsJour($db, $entity, $date, $limit = 5) {
    $sql = "SELECT
                COALESCE(p.ref, 'LIBRE')                  AS ref,
                COALESCE(p.label, cd.label, cd.description, '?') AS designation,
                SUM(cd.qty)                                AS qty,
                SUM(cd.qty * cd.subprice)                  AS ca,
                COALESCE(p.cost_price, 0)                  AS cost
            FROM ".MAIN_DB_PREFIX."octopia_orders o
            INNER JOIN ".MAIN_DB_PREFIX."commande c
                ON  c.rowid  = o.dolibarr_order_id
                AND c.entity = ".(int)$entity."
                AND DATE(c.date_commande) = '".$db->escape($date)."'
                AND c.fk_statut >= 1
            INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
            LEFT  JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product
            WHERE o.entity = ".(int)$entity."
              AND o.is_refunded = 0
              AND cd.fk_product IS NOT NULL
            GROUP BY p.ref, p.label, cd.label, p.cost_price
            ORDER BY ca DESC
            LIMIT ".(int)$limit;
    $r = $db->query($sql);
    $out = array();
    while ($r && $o = $db->fetch_object($r)) {
        $out[] = array(
            'ref'   => $o->ref,
            'lib'   => $o->designation,
            'qty'   => (int)$o->qty,
            'ca'    => (float)$o->ca,
            'cost'  => (float)$o->cost,
        );
    }
    return $out;
}

$kpiHier  = getKpiJour($db, $entity, $dateHier);
$kpiVeille = getKpiJour($db, $entity, $dateAvantHier);
$kpiSemPrec = getKpiJour($db, $entity, $dateSemPrec);
$top      = getTopProduitsJour($db, $entity, $dateHier, 5);

// Marge brute jour = CA produits - cout achat (commissions calculees au mois)
$margeBrut = $kpiHier['ca_produits'] - $kpiHier['cout_achat'];
$tauxBrut  = $kpiHier['ca_produits'] > 0 ? ($margeBrut / $kpiHier['ca_produits'] * 100) : 0;

// Cumul du mois en cours (pour contexte)
$debutMois = date('Y-m-01');
$sqlCum = "SELECT
            COUNT(DISTINCT o.rowid) AS nb,
            COALESCE(SUM(cd.qty), 0) AS qty,
            COALESCE(SUM(cd.qty * cd.subprice), 0) AS ca
        FROM ".MAIN_DB_PREFIX."octopia_orders o
        INNER JOIN ".MAIN_DB_PREFIX."commande c
            ON  c.rowid  = o.dolibarr_order_id
            AND c.entity = ".$entity."
            AND DATE(c.date_commande) BETWEEN '".$debutMois."' AND '".$dateHier."'
            AND c.fk_statut >= 1
        INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
        WHERE o.entity = ".$entity." AND o.is_refunded = 0";
$rCum = $db->query($sqlCum);
$cum = $rCum ? $db->fetch_object($rCum) : null;
$cumNb  = $cum ? (int)$cum->nb : 0;
$cumQty = $cum ? (int)$cum->qty : 0;
$cumCa  = $cum ? (float)$cum->ca : 0;

echo "KPI ".$dateHier." : ".$kpiHier['nb_commandes']." cmds, ".$kpiHier['qty']." units, ".number_format($kpiHier['ca_ht'],2)." EUR HT\n";

// ============================================================================
// 3. Construction email HTML
// ============================================================================
function fmtEur($v) {
    return number_format($v, 2, ',', "\xc2\xa0").' &euro;';
}
function fmtPct($cur, $prev) {
    if ($prev == 0) return $cur > 0 ? '<span style="color:#27ae60">▲ nouveau</span>' : '—';
    $p = ($cur - $prev) / abs($prev) * 100;
    $color = $p >= 0 ? '#27ae60' : '#c0392b';
    $sign  = $p >= 0 ? '▲' : '▼';
    return '<span style="color:'.$color.';font-size:12px">'.$sign.' '.number_format(abs($p),1,',','').' %</span>';
}

$dateAffichage = dol_print_date(dol_stringtotime($dateHier), 'daytext');
$jourSemaine   = strftime('%A', strtotime($dateHier));
if (function_exists('mb_convert_case')) $jourSemaine = mb_convert_case($jourSemaine, MB_CASE_TITLE, 'UTF-8');

$nomJour = array(
    'Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi',
    'Friday'=>'Vendredi','Saturday'=>'Samedi','Sunday'=>'Dimanche'
);
$jourEn = date('l', strtotime($dateHier));
$jourFr = isset($nomJour[$jourEn]) ? $nomJour[$jourEn] : $jourEn;

$urlSante = (isset($conf->global->RENTABILITEOCTOPIA_URL) ? $conf->global->RENTABILITEOCTOPIA_URL : '').'/custom/rentabiliteoctopia/sante.php';

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>KPI Cdiscount '.$dateHier.'</title></head>';
$html .= '<body style="font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px;color:#2c3e50;">';
$html .= '<div style="max-width:700px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08);overflow:hidden;">';

// Header
$html .= '<div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:24px;">';
$html .= '<div style="font-size:13px;opacity:0.9;letter-spacing:1px;text-transform:uppercase;">Rapport quotidien Cdiscount</div>';
$html .= '<div style="font-size:24px;font-weight:bold;margin-top:6px;">'.$jourFr.' '.$dateAffichage.'</div>';
$html .= '</div>';

// Resume
$html .= '<div style="padding:24px;">';

if ($kpiHier['nb_commandes'] === 0) {
    $html .= '<div style="background:#fff3cd;border-left:4px solid #ffc107;padding:14px;border-radius:4px;color:#856404;">';
    $html .= '<b>Aucune commande Cdiscount enregistree pour cette journee.</b><br>';
    $html .= '<span style="font-size:12px">Verifiez qu\'octopiaSync s\'est bien execute pour importer les commandes du '.$dateHier.'.</span>';
    $html .= '</div>';
} else {
    // KPI cards en grille 2x2
    $html .= '<table style="width:100%;border-collapse:separate;border-spacing:8px;">';
    $html .= '<tr>';
    $html .= '<td style="background:#e8f4fd;padding:14px;border-radius:6px;width:50%;vertical-align:top;">';
    $html .= '<div style="font-size:11px;color:#3498db;text-transform:uppercase;letter-spacing:0.5px;font-weight:bold;">CA HT</div>';
    $html .= '<div style="font-size:22px;font-weight:bold;color:#3498db;margin:4px 0;">'.fmtEur($kpiHier['ca_ht']).'</div>';
    $html .= '<div style="font-size:11px;color:#666">'.fmtPct($kpiHier['ca_ht'], $kpiVeille['ca_ht']).' vs '.dol_print_date(dol_stringtotime($dateAvantHier),'day').'</div>';
    $html .= '</td>';

    $html .= '<td style="background:#e8f8ee;padding:14px;border-radius:6px;width:50%;vertical-align:top;">';
    $html .= '<div style="font-size:11px;color:#27ae60;text-transform:uppercase;letter-spacing:0.5px;font-weight:bold;">Marge brute</div>';
    $html .= '<div style="font-size:22px;font-weight:bold;color:'.($margeBrut>=0?'#27ae60':'#c0392b').';margin:4px 0;">'.fmtEur($margeBrut).'</div>';
    $html .= '<div style="font-size:11px;color:#666">'.number_format($tauxBrut, 1, ',', '').' % du CA produits</div>';
    $html .= '</td>';
    $html .= '</tr>';

    $html .= '<tr>';
    $html .= '<td style="background:#f4ecf7;padding:14px;border-radius:6px;vertical-align:top;">';
    $html .= '<div style="font-size:11px;color:#9b59b6;text-transform:uppercase;letter-spacing:0.5px;font-weight:bold;">Commandes</div>';
    $html .= '<div style="font-size:22px;font-weight:bold;color:#9b59b6;margin:4px 0;">'.$kpiHier['nb_commandes'].'</div>';
    $html .= '<div style="font-size:11px;color:#666">'.fmtPct($kpiHier['nb_commandes'], $kpiVeille['nb_commandes']).' vs veille &nbsp; | &nbsp; '.fmtPct($kpiHier['nb_commandes'], $kpiSemPrec['nb_commandes']).' vs '.$jourFr.' dernier</div>';
    $html .= '</td>';

    $html .= '<td style="background:#fdf5e6;padding:14px;border-radius:6px;vertical-align:top;">';
    $html .= '<div style="font-size:11px;color:#e67e22;text-transform:uppercase;letter-spacing:0.5px;font-weight:bold;">Unites vendues</div>';
    $html .= '<div style="font-size:22px;font-weight:bold;color:#e67e22;margin:4px 0;">'.$kpiHier['qty'].'</div>';
    $html .= '<div style="font-size:11px;color:#666">soit '.($kpiHier['nb_commandes']>0?number_format($kpiHier['qty']/$kpiHier['nb_commandes'],1,',',''):'0').' unites/commande</div>';
    $html .= '</td>';
    $html .= '</tr>';
    $html .= '</table>';

    // Detail CA
    $html .= '<div style="margin-top:18px;padding:12px;background:#fafafa;border-radius:6px;font-size:13px;">';
    $html .= '<b>Detail du CA :</b><br>';
    $html .= '&bull; Produits : <b>'.fmtEur($kpiHier['ca_produits']).'</b><br>';
    $html .= '&bull; Frais de port : <b>'.fmtEur($kpiHier['ca_port']).'</b><br>';
    $html .= '&bull; Cout d\'achat estime : <b>'.fmtEur($kpiHier['cout_achat']).'</b>';
    if ($tauxBrut < $seuilMarge && $margeBrut > 0) {
        $html .= '<br><br><span style="color:#c0392b;font-weight:bold;">&#9888; Marge brute du jour ('.number_format($tauxBrut,1,',','').'%) sous le seuil configure ('.$seuilMarge.'%).</span>';
    }
    $html .= '</div>';

    // Top 5 produits
    if (!empty($top)) {
        $html .= '<h3 style="color:#2c3e50;margin-top:24px;font-size:15px;">Top 5 produits du '.$dateAffichage.'</h3>';
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        $html .= '<tr style="background:#34495e;color:#fff;"><th style="padding:8px;text-align:left;">Reference</th><th style="padding:8px;text-align:right;">Qte</th><th style="padding:8px;text-align:right;">CA</th></tr>';
        $alt = false;
        foreach ($top as $p) {
            $bg = $alt ? '#f9f9f9' : '#fff';
            $alt = !$alt;
            $html .= '<tr style="background:'.$bg.';">';
            $html .= '<td style="padding:8px;border-bottom:1px solid #eee;"><code style="background:#eee;padding:2px 6px;border-radius:3px;">'.htmlspecialchars($p['ref']).'</code> '.htmlspecialchars(substr($p['lib'], 0, 50)).'</td>';
            $html .= '<td style="padding:8px;text-align:right;border-bottom:1px solid #eee;">'.$p['qty'].'</td>';
            $html .= '<td style="padding:8px;text-align:right;border-bottom:1px solid #eee;font-weight:bold;">'.fmtEur($p['ca']).'</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }

    // Cumul mois
    $moisNoms = array('','Janvier','Fevrier','Mars','Avril','Mai','Juin','Juillet','Aout','Septembre','Octobre','Novembre','Decembre');
    $moisCourant = (int)date('m');
    $html .= '<div style="margin-top:24px;padding:14px;background:#ecf0f1;border-radius:6px;">';
    $html .= '<div style="font-size:11px;color:#7f8c8d;text-transform:uppercase;letter-spacing:0.5px;">Cumul '.$moisNoms[$moisCourant].' '.date('Y').' (au '.$dateHier.')</div>';
    $html .= '<div style="font-size:14px;color:#2c3e50;margin-top:6px;">';
    $html .= '<b>'.$cumNb.'</b> commandes &nbsp;&middot;&nbsp; <b>'.$cumQty.'</b> unites &nbsp;&middot;&nbsp; <b>'.fmtEur($cumCa).'</b> de CA';
    $html .= '</div>';
    $html .= '</div>';
}

// Lien tableau de bord complet
$html .= '<div style="margin-top:24px;text-align:center;">';
$html .= '<a href="'.$urlSante.'" style="display:inline-block;background:#667eea;color:#fff;padding:10px 24px;text-decoration:none;border-radius:4px;font-weight:bold;font-size:13px;">Voir le tableau de bord complet &rarr;</a>';
$html .= '</div>';

$html .= '</div>'; // /padding

// Footer
$html .= '<div style="background:#f5f5f5;padding:14px;font-size:11px;color:#888;text-align:center;border-top:1px solid #e0e0e0;">';
$html .= 'Rapport envoye automatiquement par <b>rentabiliteoctopia</b> &middot; '.dol_print_date(dol_now(), 'dayhour').'<br>';
$html .= 'Pour desactiver, decochez "Envoi quotidien" dans les Parametres du module.';
$html .= '</div>';

$html .= '</div></body></html>';

// ============================================================================
// 4. Envoi email
// ============================================================================
$subject = '[KPI Cdiscount] '.$jourFr.' '.$dateAffichage.' — '.fmtEur($kpiHier['ca_ht']).' / '.$kpiHier['nb_commandes'].' cmd';
// Strip HTML entities for subject
$subject = strip_tags(html_entity_decode($subject, ENT_QUOTES, 'UTF-8'));

$emailFrom = !empty($conf->global->MAIN_MAIL_EMAIL_FROM) ? $conf->global->MAIN_MAIL_EMAIL_FROM : 'noreply@'.parse_url($conf->global->MAIN_INFO_SOCIETE_NAME ?? 'localhost', PHP_URL_HOST);
if (empty($emailFrom)) $emailFrom = 'noreply@localhost';

$mail = new CMailFile($subject, $emailTo, $emailFrom, $html, array(), array(), array(), '', '', 0, 1);
if ($mail->error) {
    echo "ERREUR construction mail : ".$mail->error."\n";
    exit(2);
}

$ok = $mail->sendfile();
if ($ok) {
    echo "Email envoye a $emailTo (sujet: $subject)\n";
    exit(0);
} else {
    echo "ERREUR envoi mail : ".$mail->error."\n";
    exit(3);
}
