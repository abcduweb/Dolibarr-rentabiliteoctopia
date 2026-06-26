<?php
/**
 * Moteur d'alertes - detecte les situations necessitant attention
 *
 * Types d'alertes :
 *   - marge_faible    : produit vendu ce mois sous le seuil de marge
 *   - rupture_proche  : produit en rupture ou rupture imminente
 *   - dormant_reveil  : produit dormant qui s'est remis a vendre
 *   - chute_ca        : CA du jour en forte baisse vs moyenne
 *   - produit_perte   : produit vendu a marge negative
 *
 * Utilise par alertes.php (affichage) et par le mail quotidien (section optionnelle).
 */

class AlertesEngine
{
    private $db;
    private $entity;
    private $params;

    public function __construct($db, $entity, $params)
    {
        $this->db = $db;
        $this->entity = (int)$entity;
        $this->params = $params;
    }

    private function p($key, $default) {
        return isset($this->params[$key]) && $this->params[$key] !== '' ? $this->params[$key] : $default;
    }

    /**
     * Retourne toutes les alertes actives, groupees par severite.
     * @return array ['critique'=>[], 'warning'=>[], 'info'=>[]]
     */
    public function getAlertes()
    {
        $alertes = array('critique' => array(), 'warning' => array(), 'info' => array());

        $this->checkMargeFaible($alertes);
        $this->checkProduitPerte($alertes);
        $this->checkRuptureProche($alertes);
        $this->checkChuteCA($alertes);

        return $alertes;
    }

    private function checkMargeFaible(&$alertes)
    {
        $seuil = (float)$this->p('seuil_marge_pct', 15);
        $annee = (int)date('Y');
        $mois  = (int)date('m');

        $sql = "SELECT
                    p.ref, p.designation,
                    SUM(v.qty_vendue)                    AS qty,
                    SUM(v.qty_vendue * v.prix_ht)        AS ca,
                    SUM(v.qty_vendue * v.cout_achat)     AS cout,
                    SUM(CASE
                        WHEN v.commission_reel IS NOT NULL THEN v.commission_reel
                        WHEN v.commission_pct IS NOT NULL THEN v.qty_vendue * v.prix_ht * v.commission_pct/100
                        WHEN c.commission_pct IS NOT NULL THEN v.qty_vendue * v.prix_ht * c.commission_pct/100
                        ELSE 0 END)                       AS comm
                FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
                INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
                LEFT  JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
                WHERE v.annee = ".$annee." AND v.mois = ".$mois." AND v.entity = ".$this->entity."
                  AND p.ref NOT LIKE 'ORPHELIN-%' AND p.ref NOT LIKE 'LIBRE:%'
                GROUP BY p.rowid, p.ref, p.designation
                HAVING qty > 0 AND ca > 0";
        $r = $this->db->query($sql);
        while ($r && $o = $this->db->fetch_object($r)) {
            $ca = (float)$o->ca; $cout = (float)$o->cout; $comm = (float)$o->comm;
            $marge = $ca - $cout - $comm;
            $taux = $ca > 0 ? ($marge / $ca * 100) : 0;
            if ($taux >= 0 && $taux < $seuil) {
                $alertes['warning'][] = array(
                    'type'  => 'marge_faible',
                    'icone' => '📉',
                    'titre' => 'Marge faible : '.$o->ref,
                    'detail'=> $o->designation.' — '.number_format($taux,1,',','').'% (seuil '.$seuil.'%)',
                );
            }
        }
    }

    private function checkProduitPerte(&$alertes)
    {
        $annee = (int)date('Y');
        $mois  = (int)date('m');

        $sql = "SELECT
                    p.ref, p.designation,
                    SUM(v.qty_vendue * v.prix_ht)        AS ca,
                    SUM(v.qty_vendue * v.cout_achat)     AS cout,
                    SUM(CASE
                        WHEN v.commission_reel IS NOT NULL THEN v.commission_reel
                        WHEN v.commission_pct IS NOT NULL THEN v.qty_vendue * v.prix_ht * v.commission_pct/100
                        WHEN c.commission_pct IS NOT NULL THEN v.qty_vendue * v.prix_ht * c.commission_pct/100
                        ELSE 0 END)                       AS comm
                FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
                INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
                LEFT  JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
                WHERE v.annee = ".$annee." AND v.mois = ".$mois." AND v.entity = ".$this->entity."
                  AND p.ref NOT LIKE 'ORPHELIN-%' AND p.ref NOT LIKE 'LIBRE:%'
                GROUP BY p.rowid, p.ref, p.designation
                HAVING ca > 0 AND cout > 0";
        $r = $this->db->query($sql);
        while ($r && $o = $this->db->fetch_object($r)) {
            $marge = (float)$o->ca - (float)$o->cout - (float)$o->comm;
            if ($marge < 0) {
                $alertes['critique'][] = array(
                    'type'  => 'produit_perte',
                    'icone' => '🔴',
                    'titre' => 'PERTE : '.$o->ref,
                    'detail'=> $o->designation.' — marge negative de '.number_format($marge,2,',','').'€ ce mois',
                );
            }
        }
    }

    private function checkRuptureProche(&$alertes)
    {
        $periode = 30;
        $delaiAppro = (int)$this->p('reassort_delai_appro', 7);

        $sql = "SELECT
                    p.ref, p.label,
                    COALESCE(SUM(ps.reel), 0)            AS stock,
                    COALESCE(vente.qty, 0)               AS qty_vendue
                FROM ".MAIN_DB_PREFIX."product p
                LEFT JOIN ".MAIN_DB_PREFIX."product_stock ps ON ps.fk_product = p.rowid
                LEFT JOIN (
                    SELECT cd.fk_product, SUM(cd.qty) AS qty
                    FROM ".MAIN_DB_PREFIX."octopia_orders o
                    INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = o.dolibarr_order_id
                        AND c.entity = ".$this->entity." AND c.fk_statut >= 1
                        AND c.date_commande >= DATE_SUB(CURDATE(), INTERVAL ".$periode." DAY)
                    INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid AND cd.fk_product > 0
                    WHERE o.entity = ".$this->entity." AND o.is_refunded = 0
                    GROUP BY cd.fk_product
                ) vente ON vente.fk_product = p.rowid
                WHERE p.entity IN (0,".$this->entity.") AND p.tosell = 1 AND p.fk_product_type = 0
                GROUP BY p.rowid, p.ref, p.label, vente.qty
                HAVING qty_vendue > 0";
        $r = $this->db->query($sql);
        while ($r && $o = $this->db->fetch_object($r)) {
            $stock = (int)$o->stock;
            $vitesse = (int)$o->qty_vendue / $periode;
            if ($vitesse <= 0) continue;
            $joursRestant = $stock / $vitesse;

            if ($stock <= 0) {
                $alertes['critique'][] = array(
                    'type'  => 'rupture',
                    'icone' => '📦',
                    'titre' => 'RUPTURE : '.$o->ref,
                    'detail'=> $o->label.' — stock 0, '.$o->qty_vendue.' vendus sur 30j',
                );
            } elseif ($joursRestant <= $delaiAppro) {
                $alertes['warning'][] = array(
                    'type'  => 'rupture_proche',
                    'icone' => '📦',
                    'titre' => 'Rupture imminente : '.$o->ref,
                    'detail'=> $o->label.' — '.round($joursRestant).'j de stock restant (delai appro '.$delaiAppro.'j)',
                );
            }
        }
    }

    private function checkChuteCA(&$alertes)
    {
        // CA d'hier vs moyenne des 7 jours precedents
        $sqlHier = "SELECT COALESCE(SUM(cd.qty * cd.subprice), 0) AS ca
                    FROM ".MAIN_DB_PREFIX."octopia_orders o
                    INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = o.dolibarr_order_id
                        AND c.entity = ".$this->entity." AND c.fk_statut >= 1
                        AND DATE(c.date_commande) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                    INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
                    WHERE o.entity = ".$this->entity." AND o.is_refunded = 0";
        $rH = $this->db->query($sqlHier);
        $caHier = ($rH && $oH = $this->db->fetch_object($rH)) ? (float)$oH->ca : 0;

        $sqlMoy = "SELECT COALESCE(SUM(cd.qty * cd.subprice), 0) / 7 AS moy
                   FROM ".MAIN_DB_PREFIX."octopia_orders o
                   INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = o.dolibarr_order_id
                       AND c.entity = ".$this->entity." AND c.fk_statut >= 1
                       AND DATE(c.date_commande) BETWEEN DATE_SUB(CURDATE(), INTERVAL 8 DAY) AND DATE_SUB(CURDATE(), INTERVAL 2 DAY)
                   INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
                   WHERE o.entity = ".$this->entity." AND o.is_refunded = 0";
        $rM = $this->db->query($sqlMoy);
        $caMoy = ($rM && $oM = $this->db->fetch_object($rM)) ? (float)$oM->moy : 0;

        if ($caMoy > 10) { // seuil minimal pour eviter le bruit
            $chute = ($caHier - $caMoy) / $caMoy * 100;
            if ($chute < -40) {
                $alertes['warning'][] = array(
                    'type'  => 'chute_ca',
                    'icone' => '📊',
                    'titre' => 'Chute du CA hier',
                    'detail'=> 'CA d\'hier ('.number_format($caHier,2,',','').'€) en baisse de '.number_format(abs($chute),0,',','').'% vs moyenne 7j ('.number_format($caMoy,2,',','').'€)',
                );
            }
        }
    }

    /**
     * Genere un bloc HTML compact des alertes (pour le mail quotidien)
     */
    public function getAlertesHtml()
    {
        $alertes = $this->getAlertes();
        $total = count($alertes['critique']) + count($alertes['warning']) + count($alertes['info']);
        if ($total === 0) return '';

        $html = '<div style="margin-top:24px;padding:14px;background:#fff8f0;border-radius:6px;border-left:4px solid #e67e22;">';
        $html .= '<h3 style="margin-top:0;color:#e67e22;font-size:15px;">&#9888; '.$total.' alerte(s)</h3>';

        foreach (array('critique', 'warning') as $niveau) {
            foreach ($alertes[$niveau] as $a) {
                $color = $niveau === 'critique' ? '#c0392b' : '#e67e22';
                $html .= '<div style="padding:6px 0;border-bottom:1px solid #f0e0d0;">';
                $html .= $a['icone'].' <b style="color:'.$color.'">'.htmlspecialchars($a['titre']).'</b><br>';
                $html .= '<span style="font-size:12px;color:#666;margin-left:20px;">'.htmlspecialchars($a['detail']).'</span>';
                $html .= '</div>';
            }
        }
        $html .= '</div>';
        return $html;
    }
}
