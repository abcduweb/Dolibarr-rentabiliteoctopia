<?php
/**
 * Generateur d'email KPI quotidien.
 * Utilise par le cron cron/daily_kpi_mail.php et par le bouton de test dans admin/admin.php.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once __DIR__.'/AlertesEngine.class.php';

class DailyKpiMailer
{
    /** @var DoliDB */ private $db;
    /** @var int */    private $entity;
    /** @var array */  private $params;

    public $logs = array();

    public function __construct($db, $entity, $params)
    {
        $this->db = $db;
        $this->entity = (int)$entity;
        $this->params = $params;
    }

    private function log($msg) { $this->logs[] = $msg; }

    private function cfg($k, $default = 1) {
        if (!isset($this->params[$k]) || $this->params[$k] === '') return (int)$default;
        return ((string)$this->params[$k] === '1') ? 1 : 0;
    }

    private function getKpi($date, $dateFin = null)
    {
        if ($dateFin === null) $dateFin = $date;
        $sql = "SELECT
                    COUNT(DISTINCT o.rowid)                  AS nb_commandes,
                    COUNT(cd.rowid)                          AS nb_lignes,
                    COALESCE(SUM(cd.qty), 0)                 AS qty,
                    COALESCE(SUM(cd.qty * cd.subprice), 0)   AS ca_ht,
                    COALESCE(SUM(CASE WHEN cd.fk_product IS NOT NULL THEN cd.qty * cd.subprice ELSE 0 END), 0) AS ca_produits,
                    COALESCE(SUM(CASE WHEN cd.fk_product IS NULL     THEN cd.qty * cd.subprice ELSE 0 END), 0) AS ca_port,
                    COALESCE(SUM(p.cost_price * cd.qty), 0)  AS cout_achat
                FROM ".MAIN_DB_PREFIX."octopia_orders o
                INNER JOIN ".MAIN_DB_PREFIX."commande c
                    ON  c.rowid  = o.dolibarr_order_id
                    AND c.entity = ".$this->entity."
                    AND DATE(c.date_commande) BETWEEN '".$this->db->escape($date)."' AND '".$this->db->escape($dateFin)."'
                    AND c.fk_statut >= 1
                INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
                LEFT  JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product
                WHERE o.entity = ".$this->entity."
                  AND o.is_refunded = 0
                  AND (o.octopia_order_status IS NULL OR o.octopia_order_status NOT IN ('CANCELLED','REFUNDED','REFUSED','CANCELED'))";
        $r = $this->db->query($sql);
        if (!$r || !($o = $this->db->fetch_object($r))) {
            return array('nb_commandes'=>0,'nb_lignes'=>0,'qty'=>0,'ca_ht'=>0,'ca_produits'=>0,'ca_port'=>0,'cout_achat'=>0);
        }
        return array(
            'nb_commandes'=>(int)$o->nb_commandes,'nb_lignes'=>(int)$o->nb_lignes,
            'qty'=>(int)$o->qty,'ca_ht'=>(float)$o->ca_ht,
            'ca_produits'=>(float)$o->ca_produits,'ca_port'=>(float)$o->ca_port,
            'cout_achat'=>(float)$o->cout_achat,
        );
    }

    private function getTopProduits($date, $dateFin, $limit)
    {
        $sql = "SELECT
                    COALESCE(p.ref, 'LIBRE') AS ref,
                    COALESCE(p.label, cd.label, cd.description, '?') AS designation,
                    SUM(cd.qty) AS qty,
                    SUM(cd.qty * cd.subprice) AS ca,
                    COALESCE(p.cost_price, 0) AS cost
                FROM ".MAIN_DB_PREFIX."octopia_orders o
                INNER JOIN ".MAIN_DB_PREFIX."commande c
                    ON c.rowid = o.dolibarr_order_id AND c.entity = ".$this->entity."
                    AND DATE(c.date_commande) BETWEEN '".$this->db->escape($date)."' AND '".$this->db->escape($dateFin)."'
                    AND c.fk_statut >= 1
                INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
                LEFT  JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product
                WHERE o.entity = ".$this->entity."
                  AND o.is_refunded = 0
                  AND cd.fk_product IS NOT NULL
                GROUP BY p.ref, p.label, cd.label, p.cost_price
                ORDER BY ca DESC
                LIMIT ".(int)$limit;
        $r = $this->db->query($sql);
        $out = array();
        while ($r && $o = $this->db->fetch_object($r)) {
            $out[] = array(
                'ref'=>$o->ref,'lib'=>$o->designation,
                'qty'=>(int)$o->qty,'ca'=>(float)$o->ca,'cost'=>(float)$o->cost,
            );
        }
        return $out;
    }

    /**
     * Construit le HTML du mail selon la config actuelle.
     * @return string HTML complet
     */
    public function buildHtml()
    {
        $optKpis    = $this->cfg('daily_kpi_show_kpis', 1);
        $optCmpJ2   = $this->cfg('daily_kpi_show_compare_j2', 1);
        $optCmpWeek = $this->cfg('daily_kpi_show_compare_week', 1);
        $optDetCA   = $this->cfg('daily_kpi_show_detail_ca', 1);
        $optTop     = $this->cfg('daily_kpi_show_top_produits', 1);
        $optCumul   = $this->cfg('daily_kpi_show_cumul_mois', 1);
        $optAlert   = $this->cfg('daily_kpi_show_seuil_alert', 1);
        $topN       = !empty($this->params['daily_kpi_top_n']) ? max(1, min(20, (int)$this->params['daily_kpi_top_n'])) : 5;
        $period     = !empty($this->params['daily_kpi_period']) ? $this->params['daily_kpi_period'] : 'j1';
        $seuilMarge = isset($this->params['seuil_marge_pct']) ? (float)$this->params['seuil_marge_pct'] : 15;

        // Determiner les bornes de la periode courante ET des periodes de comparaison
        // Les comparaisons doivent etre coherentes : meme duree, decalee dans le temps.
        switch ($period) {
            case 'j7':
                $dateDebut    = date('Y-m-d', strtotime('-7 days'));
                $dateFin      = date('Y-m-d', strtotime('-1 day'));
                $labelPeriode = '7 derniers jours';
                // Comparaison vs 7 jours precedents (J-14..J-8)
                $dateCmp1Deb  = date('Y-m-d', strtotime('-14 days'));
                $dateCmp1Fin  = date('Y-m-d', strtotime('-8 days'));
                $labelCmp1    = '7 jours precedents';
                // Comparaison vs 7 jours il y a 4 semaines (J-35..J-29) - eq semaine 4 semaines avant
                $dateCmp2Deb  = date('Y-m-d', strtotime('-35 days'));
                $dateCmp2Fin  = date('Y-m-d', strtotime('-29 days'));
                $labelCmp2    = 'meme periode 4 semaines avant';
                break;
            case 'month_to_date':
                $dateDebut    = date('Y-m-01');
                $dateFin      = date('Y-m-d', strtotime('-1 day'));
                $labelPeriode = 'mois en cours';
                // Mois precedent du 1er au meme jour
                $jourCourant  = (int)date('d', strtotime('-1 day'));
                $dateCmp1Deb  = date('Y-m-01', strtotime('first day of last month'));
                $dateCmp1Fin  = date('Y-m-'.str_pad($jourCourant, 2, '0', STR_PAD_LEFT), strtotime('first day of last month'));
                $labelCmp1    = 'mois precedent (au '.dol_print_date(dol_stringtotime($dateCmp1Fin), 'day').')';
                // Annee precedente, meme mois jusqu'au meme jour
                $dateCmp2Deb  = date('Y-m-01', strtotime('-1 year'));
                $dateCmp2Fin  = date('Y-m-'.str_pad($jourCourant, 2, '0', STR_PAD_LEFT), strtotime('-1 year'));
                $labelCmp2    = 'meme periode an dernier';
                break;
            default: // j1
                $dateDebut    = date('Y-m-d', strtotime('-1 day'));
                $dateFin      = $dateDebut;
                $labelPeriode = 'hier';
                $dateCmp1Deb  = $dateCmp1Fin = date('Y-m-d', strtotime('-2 days'));
                $labelCmp1    = 'veille (J-2)';
                $dateCmp2Deb  = $dateCmp2Fin = date('Y-m-d', strtotime('-8 days'));
                $labelCmp2    = 'meme jour semaine precedente';
        }

        $kpi   = $this->getKpi($dateDebut,    $dateFin);
        $kpiV  = $this->getKpi($dateCmp1Deb,  $dateCmp1Fin);
        $kpiSP = $this->getKpi($dateCmp2Deb,  $dateCmp2Fin);
        $top      = $this->getTopProduits($dateDebut, $dateFin, $topN);

        $margeBrut = $kpi['ca_produits'] - $kpi['cout_achat'];
        $tauxBrut  = $kpi['ca_produits'] > 0 ? ($margeBrut / $kpi['ca_produits'] * 100) : 0;

        // Cumul mois
        $debutMois = date('Y-m-01');
        $dateHier  = date('Y-m-d', strtotime('-1 day'));
        $sqlCum = "SELECT COUNT(DISTINCT o.rowid) AS nb, COALESCE(SUM(cd.qty),0) AS qty, COALESCE(SUM(cd.qty * cd.subprice),0) AS ca
                   FROM ".MAIN_DB_PREFIX."octopia_orders o
                   INNER JOIN ".MAIN_DB_PREFIX."commande c
                       ON c.rowid = o.dolibarr_order_id AND c.entity = ".$this->entity."
                       AND DATE(c.date_commande) BETWEEN '".$debutMois."' AND '".$dateHier."'
                       AND c.fk_statut >= 1
                   INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
                   WHERE o.entity = ".$this->entity." AND o.is_refunded = 0";
        $rCum = $this->db->query($sqlCum);
        $cum = $rCum ? $this->db->fetch_object($rCum) : null;
        $cumNb  = $cum ? (int)$cum->nb : 0;
        $cumQty = $cum ? (int)$cum->qty : 0;
        $cumCa  = $cum ? (float)$cum->ca : 0;

        $fmtEur = function($v) { return number_format($v, 2, ',', "\xc2\xa0").' &euro;'; };
        $fmtPct = function($cur, $prev) {
            if ($prev == 0) return $cur > 0 ? '<span style="color:#27ae60">▲ nouveau</span>' : '—';
            $p = ($cur - $prev) / abs($prev) * 100;
            $color = $p >= 0 ? '#27ae60' : '#c0392b';
            $sign  = $p >= 0 ? '▲' : '▼';
            return '<span style="color:'.$color.';font-size:12px">'.$sign.' '.number_format(abs($p),1,',','').' %</span>';
        };

        $dateAffichage = dol_print_date(dol_stringtotime($dateDebut), 'daytext');
        $jourEn = date('l', strtotime($dateDebut));
        $nomJour = array('Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi','Sunday'=>'Dimanche');
        $jourFr = isset($nomJour[$jourEn]) ? $nomJour[$jourEn] : $jourEn;

        $urlSante = (defined('DOL_MAIN_URL_ROOT') ? DOL_MAIN_URL_ROOT : '').'/custom/rentabiliteoctopia/sante.php';

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>';
        $html .= '<body style="font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px;color:#2c3e50;">';
        $html .= '<div style="max-width:700px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08);overflow:hidden;">';

        // Header
        $html .= '<div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:24px;">';
        $html .= '<div style="font-size:13px;opacity:0.9;letter-spacing:1px;text-transform:uppercase;">Rapport KPI Cdiscount</div>';
        if ($period === 'j1') {
            $html .= '<div style="font-size:24px;font-weight:bold;margin-top:6px;">'.$jourFr.' '.$dateAffichage.'</div>';
        } else {
            $html .= '<div style="font-size:24px;font-weight:bold;margin-top:6px;">'.ucfirst($labelPeriode).' (du '.dol_print_date(dol_stringtotime($dateDebut),'day').' au '.dol_print_date(dol_stringtotime($dateFin),'day').')</div>';
        }
        $html .= '</div>';

        $html .= '<div style="padding:24px;">';

        if ($kpi['nb_commandes'] === 0) {
            $html .= '<div style="background:#fff3cd;border-left:4px solid #ffc107;padding:14px;border-radius:4px;color:#856404;">';
            $html .= '<b>Aucune commande Cdiscount sur la periode.</b><br>';
            $html .= '<span style="font-size:12px">Verifiez qu\'octopiaSync s\'est bien execute.</span>';
            $html .= '</div>';
        } else {
            if ($optKpis) {
                $html .= '<table style="width:100%;border-collapse:separate;border-spacing:8px;">';
                $html .= '<tr>';
                $html .= '<td style="background:#e8f4fd;padding:14px;border-radius:6px;width:50%;vertical-align:top;">';
                $html .= '<div style="font-size:11px;color:#3498db;text-transform:uppercase;font-weight:bold;">CA HT</div>';
                $html .= '<div style="font-size:22px;font-weight:bold;color:#3498db;margin:4px 0;">'.$fmtEur($kpi['ca_ht']).'</div>';
                if ($optCmpJ2) $html .= '<div style="font-size:11px;color:#666">'.$fmtPct($kpi['ca_ht'], $kpiV['ca_ht']).' vs '.$labelCmp1.'</div>';
                $html .= '</td>';
                $html .= '<td style="background:#e8f8ee;padding:14px;border-radius:6px;width:50%;vertical-align:top;">';
                if ($kpi['cout_achat'] == 0 && $kpi['ca_produits'] > 0) {
                    $html .= '<div style="font-size:11px;color:#888;text-transform:uppercase;font-weight:bold;">Marge brute</div>';
                    $html .= '<div style="font-size:18px;font-weight:bold;color:#888;margin:4px 0;">non disponible</div>';
                    $html .= '<div style="font-size:11px;color:#e67e22">&#9888; Cout d\'achat manquant</div>';
                } else {
                    $html .= '<div style="font-size:11px;color:#27ae60;text-transform:uppercase;font-weight:bold;">Marge brute</div>';
                    $html .= '<div style="font-size:22px;font-weight:bold;color:'.($margeBrut>=0?'#27ae60':'#c0392b').';margin:4px 0;">'.$fmtEur($margeBrut).'</div>';
                    $html .= '<div style="font-size:11px;color:#666">'.number_format($tauxBrut,1,',','').' % du CA produits</div>';
                }
                $html .= '</td>';
                $html .= '</tr><tr>';
                $html .= '<td style="background:#f4ecf7;padding:14px;border-radius:6px;vertical-align:top;">';
                $html .= '<div style="font-size:11px;color:#9b59b6;text-transform:uppercase;font-weight:bold;">Commandes</div>';
                $html .= '<div style="font-size:22px;font-weight:bold;color:#9b59b6;margin:4px 0;">'.$kpi['nb_commandes'].'</div>';
                $cmp = '';
                if ($optCmpJ2) $cmp .= $fmtPct($kpi['nb_commandes'], $kpiV['nb_commandes']).' vs '.$labelCmp1;
                if ($optCmpJ2 && $optCmpWeek) $cmp .= ' &nbsp; | &nbsp; ';
                if ($optCmpWeek) $cmp .= $fmtPct($kpi['nb_commandes'], $kpiSP['nb_commandes']).' vs '.$labelCmp2;
                if ($cmp) $html .= '<div style="font-size:11px;color:#666">'.$cmp.'</div>';
                $html .= '</td>';
                $html .= '<td style="background:#fdf5e6;padding:14px;border-radius:6px;vertical-align:top;">';
                $html .= '<div style="font-size:11px;color:#e67e22;text-transform:uppercase;font-weight:bold;">Unites vendues</div>';
                $html .= '<div style="font-size:22px;font-weight:bold;color:#e67e22;margin:4px 0;">'.$kpi['qty'].'</div>';
                $html .= '<div style="font-size:11px;color:#666">soit '.($kpi['nb_commandes']>0?number_format($kpi['qty']/$kpi['nb_commandes'],1,',',''):'0').' unites/cmd</div>';
                $html .= '</td>';
                $html .= '</tr></table>';
            }

            if ($optDetCA) {
                $html .= '<div style="margin-top:18px;padding:12px;background:#fafafa;border-radius:6px;font-size:13px;">';
                $html .= '<b>Detail du CA :</b><br>';
                $html .= '&bull; Produits : <b>'.$fmtEur($kpi['ca_produits']).'</b><br>';
                $html .= '&bull; Frais de port : <b>'.$fmtEur($kpi['ca_port']).'</b><br>';
                $html .= '&bull; Cout d\'achat estime : <b>'.$fmtEur($kpi['cout_achat']).'</b>';
                if ($kpi['cout_achat'] == 0 && $kpi['ca_produits'] > 0) {
                    $html .= '<br><br><span style="color:#e67e22;font-weight:bold;">&#9888; Cout d\'achat non renseigne sur vos fiches produit Dolibarr (champ <code>cost_price</code>). La marge brute affichee est donc surestimee. Saisissez les couts d\'achat dans Produits&nbsp;|&nbsp;Services pour avoir une marge realiste.</span>';
                } elseif ($optAlert && $tauxBrut < $seuilMarge && $margeBrut > 0) {
                    $html .= '<br><br><span style="color:#c0392b;font-weight:bold;">&#9888; Marge brute ('.number_format($tauxBrut,1,',','').'%) sous le seuil configure ('.$seuilMarge.'%).</span>';
                }
                $html .= '</div>';
            }

            if ($optTop && !empty($top)) {
                $html .= '<h3 style="color:#2c3e50;margin-top:24px;font-size:15px;">Top '.$topN.' produits ('.$labelPeriode.')</h3>';
                $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
                $html .= '<tr style="background:#34495e;color:#fff;"><th style="padding:8px;text-align:left;">Reference</th><th style="padding:8px;text-align:right;">Qte</th><th style="padding:8px;text-align:right;">CA</th></tr>';
                $alt = false;
                foreach ($top as $p) {
                    $bg = $alt ? '#f9f9f9' : '#fff';
                    $alt = !$alt;
                    $html .= '<tr style="background:'.$bg.';">';
                    $html .= '<td style="padding:8px;border-bottom:1px solid #eee;"><code style="background:#eee;padding:2px 6px;border-radius:3px;">'.htmlspecialchars($p['ref']).'</code> '.htmlspecialchars(substr($p['lib'], 0, 50)).'</td>';
                    $html .= '<td style="padding:8px;text-align:right;border-bottom:1px solid #eee;">'.$p['qty'].'</td>';
                    $html .= '<td style="padding:8px;text-align:right;border-bottom:1px solid #eee;font-weight:bold;">'.$fmtEur($p['ca']).'</td>';
                    $html .= '</tr>';
                }
                $html .= '</table>';
            }

            if ($optCumul) {
                $moisNoms = array('','Janvier','Fevrier','Mars','Avril','Mai','Juin','Juillet','Aout','Septembre','Octobre','Novembre','Decembre');
                $mc = (int)date('m');
                $html .= '<div style="margin-top:24px;padding:14px;background:#ecf0f1;border-radius:6px;">';
                $html .= '<div style="font-size:11px;color:#7f8c8d;text-transform:uppercase;letter-spacing:0.5px;">Cumul '.$moisNoms[$mc].' '.date('Y').' (au '.dol_print_date(dol_stringtotime($dateHier), 'day').')</div>';
                $html .= '<div style="font-size:14px;color:#2c3e50;margin-top:6px;">';
                $html .= '<b>'.$cumNb.'</b> commandes &nbsp;&middot;&nbsp; <b>'.$cumQty.'</b> unites &nbsp;&middot;&nbsp; <b>'.$fmtEur($cumCa).'</b> de CA';
                $html .= '</div></div>';
            }
        }

        // Section alertes (si activee)
        $optAlertes = $this->cfg('daily_kpi_show_alertes', 1);
        if ($optAlertes) {
            $engine = new AlertesEngine($this->db, $this->entity, $this->params);
            $alertesHtml = $engine->getAlertesHtml();
            if ($alertesHtml) $html .= $alertesHtml;
        }

        $html .= '<div style="margin-top:24px;text-align:center;">';
        $html .= '<a href="'.$urlSante.'" style="display:inline-block;background:#667eea;color:#fff;padding:10px 24px;text-decoration:none;border-radius:4px;font-weight:bold;font-size:13px;">Voir le tableau de bord complet &rarr;</a>';
        $html .= '</div>';

        $html .= '</div>';
        $html .= '<div style="background:#f5f5f5;padding:14px;font-size:11px;color:#888;text-align:center;border-top:1px solid #e0e0e0;">';
        $html .= 'Envoye par <b>rentabiliteoctopia</b> &middot; '.dol_print_date(dol_now(), 'dayhour').'<br>';
        $html .= 'Pour desactiver, decochez "Activer l\'envoi quotidien" dans les Parametres du module.';
        $html .= '</div>';
        $html .= '</div></body></html>';

        return $html;
    }

    /**
     * Construit le sujet selon la periode et les KPI
     */
    public function buildSubject()
    {
        $period = !empty($this->params['daily_kpi_period']) ? $this->params['daily_kpi_period'] : 'j1';
        switch ($period) {
            case 'j7':
                $dateDebut = date('Y-m-d', strtotime('-7 days'));
                $dateFin   = date('Y-m-d', strtotime('-1 day'));
                $label     = '7 jours';
                break;
            case 'month_to_date':
                $dateDebut = date('Y-m-01');
                $dateFin   = date('Y-m-d', strtotime('-1 day'));
                $label     = 'mois en cours';
                break;
            default:
                $dateDebut = date('Y-m-d', strtotime('-1 day'));
                $dateFin   = $dateDebut;
                $label     = '';
        }
        $kpi = $this->getKpi($dateDebut, $dateFin);
        $eur = number_format($kpi['ca_ht'], 2, ',', "\xc2\xa0").' EUR';
        if ($label) {
            return '[KPI Cdiscount] '.ucfirst($label).' — '.$eur.' / '.$kpi['nb_commandes'].' cmd';
        }
        $nomJour = array('Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi','Sunday'=>'Dimanche');
        $jourFr = $nomJour[date('l', strtotime($dateDebut))] ?? '';
        return '[KPI Cdiscount] '.$jourFr.' '.dol_print_date(dol_stringtotime($dateDebut), 'daytext').' — '.$eur.' / '.$kpi['nb_commandes'].' cmd';
    }

    /**
     * Envoie le mail (utilise dans cron et test)
     *
     * Utilise PHP mail() directement avec un MIME multipart/alternative propre.
     * On contourne CMailFile qui enveloppe tout en multipart/mixed meme sans piece jointe,
     * ce qui pose probleme avec certains clients mail (eM Client, Outlook anciens).
     *
     * @param string $emailTo Adresses destinataires (peut etre passe en override pour test)
     * @param bool   $isTest  Si true, prefixe le sujet avec [TEST]
     * @return bool
     */
    public function send($emailTo = null, $isTest = false)
    {
        if ($emailTo === null) {
            $emailTo = isset($this->params['daily_kpi_email']) ? trim($this->params['daily_kpi_email']) : '';
        }
        if (empty($emailTo)) {
            $this->log('Aucun destinataire defini.');
            return false;
        }

        global $conf;
        $emailFrom = !empty($conf->global->MAIN_MAIL_EMAIL_FROM) ? $conf->global->MAIN_MAIL_EMAIL_FROM : 'noreply@localhost';
        $fromName  = !empty($conf->global->MAIN_INFO_SOCIETE_NOM) ? $conf->global->MAIN_INFO_SOCIETE_NOM : 'rentabiliteoctopia';

        $html    = $this->buildHtml();
        $subject = $this->buildSubject();
        if ($isTest) $subject = '[TEST] '.$subject;

        // Generer une version texte basique a partir du HTML (fallback)
        $textPart = strip_tags(preg_replace('/<(br|tr|\/td|\/h\d|\/div|\/p)[^>]*>/i', "\n", $html));
        $textPart = html_entity_decode($textPart, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $textPart = preg_replace('/\n{3,}/', "\n\n", trim($textPart));

        // Sujet encode en UTF-8 base64 (RFC 2047)
        $subjectEnc = '=?UTF-8?B?'.base64_encode($subject).'?=';

        // Boundary unique
        $boundary = '==MULTIPART_BOUNDARY_'.md5(uniqid('', true));

        // Headers
        $headers  = 'From: =?UTF-8?B?'.base64_encode($fromName).'?= <'.$emailFrom.'>'."\r\n";
        $headers .= 'Reply-To: '.$emailFrom."\r\n";
        $headers .= 'Return-Path: '.$emailFrom."\r\n";
        $headers .= 'X-Mailer: rentabiliteoctopia (Dolibarr custom)'."\r\n";
        $headers .= 'MIME-Version: 1.0'."\r\n";
        $headers .= 'Content-Type: multipart/alternative; boundary="'.$boundary.'"'."\r\n";

        // Corps multipart/alternative (texte d'abord, HTML ensuite -> les clients prennent le HTML)
        // IMPORTANT: encodage base64 obligatoire car le HTML inline contient des lignes > 998 octets
        // (limite SMTP RFC 5322), ce qui faisait rejeter par o2switch ("message has lines too long").
        // chunk_split() coupe automatiquement a 76 caracteres avec \r\n.
        $textPart64 = chunk_split(base64_encode($textPart), 76, "\r\n");
        $htmlPart64 = chunk_split(base64_encode($html), 76, "\r\n");

        $body  = "This is a multi-part message in MIME format.\r\n\r\n";

        $body .= '--'.$boundary."\r\n";
        $body .= 'Content-Type: text/plain; charset="UTF-8"'."\r\n";
        $body .= 'Content-Transfer-Encoding: base64'."\r\n\r\n";
        $body .= $textPart64."\r\n";

        $body .= '--'.$boundary."\r\n";
        $body .= 'Content-Type: text/html; charset="UTF-8"'."\r\n";
        $body .= 'Content-Transfer-Encoding: base64'."\r\n\r\n";
        $body .= $htmlPart64."\r\n";

        $body .= '--'.$boundary.'--'."\r\n";

        // Envoyer (gerer destinataires multiples separes par virgules)
        $allOk = true;
        $recipients = array_map('trim', explode(',', $emailTo));
        foreach ($recipients as $rcpt) {
            if (!filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
                $this->log('Destinataire invalide ignore : '.$rcpt);
                continue;
            }
            $ok = @mail($rcpt, $subjectEnc, $body, $headers, '-f'.$emailFrom);
            if ($ok) {
                $this->log('Mail envoye a '.$rcpt.' (sujet: '.$subject.')');
            } else {
                $err = error_get_last();
                $this->log('ECHEC envoi a '.$rcpt.' : '.($err ? $err['message'] : 'raison inconnue'));
                $allOk = false;
            }
        }
        return $allOk;
    }
}
