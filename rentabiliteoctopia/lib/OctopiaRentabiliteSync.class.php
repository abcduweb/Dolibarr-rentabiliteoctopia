<?php
/**
 * Synchronisation octopiaSync -> rentabiliteoctopia
 *
 * Compatible avec la version d'octopiaSync qui utilise les tables :
 *   llx_octopia_orders    : commandes (octopia_order_status, dolibarr_order_id, is_refunded)
 *
 * La version installée ne stocke PAS ses propres lignes de commande :
 * elle référence les commandes Dolibarr natives via dolibarr_order_id.
 * Les données produits viennent donc de :
 *   llx_commande          : entête commande (date_commande, statut)
 *   llx_commandedet       : lignes (fk_product, qty, subprice)
 *   llx_product           : ref, label, cost_price
 *
 * Destination :
 *   llx_rentabiliteoctopia_produit  : référentiel produits
 *   llx_rentabiliteoctopia_vente    : agrégats mensuels qty + CA + cout_achat
 */

require_once __DIR__.'/CacheMois.class.php';

require_once __DIR__.'/PrixHistorique.class.php';

class OctopiaRentabiliteSync
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /** @var array Messages de log */
    public $logs = array();

    /** @var int Compteurs */
    public $nb_produits_crees  = 0;
    public $nb_ventes_maj      = 0;
    public $nb_erreurs         = 0;

    // Statuts Octopia à EXCLURE (commandes annulées / remboursées)
    private $statuts_exclus = array('CANCELLED', 'REFUNDED', 'REFUSED', 'CANCELED');

    public function __construct($db, $entity = 1)
    {
        $this->db     = $db;
        $this->entity = (int)$entity;
    }

    /**
     * Synchronise un mois donné (ou le mois courant)
     */
    public function syncMois($annee = null, $mois = null)
    {
        if ($annee === null) $annee = (int)date('Y');
        if ($mois  === null) $mois  = (int)date('m');

        $this->log("=== Début synchro Octopia -> Rentabilité : $annee-$mois ===");

        $agregats = $this->getAgregatsMois($annee, $mois);
        if ($agregats === false) return false;

        if (empty($agregats)) {
            $this->log("Aucune commande Octopia trouvée pour $mois/$annee.");
            return true;
        }

        $this->log(count($agregats)." référence(s) produit trouvée(s) pour $mois/$annee.");

        foreach ($agregats as $ref => $data) {
            $fkProduit = $this->upsertProduit($ref, $data['designation']);
            if (!$fkProduit) {
                $this->nb_erreurs++;
                continue;
            }

            $ok = $this->upsertVente($fkProduit, $annee, $mois, $data['qty'], $data['ca_ht'], $data['cout_achat']);
            if ($ok) {
                $this->nb_ventes_maj++;
            } else {
                $this->nb_erreurs++;
            }
        }

        // NOTE: on ne synchronise PAS les frais de port Octopia comme charges.
        // Les frais de port factures aux clients sont un REVENU (CA reverse par Cdiscount),
        // pas une charge. Les charges reelles de transport viennent des factures
        // fournisseur Cdiscount (PCG 624/626) via OctopiaFactureImport.

        // Invalider le cache du mois synchronise (force recalcul au prochain affichage)
        try {
            $cache = new CacheMois($this->db, $this->entity);
            $cache->invalidate((int)$annee, (int)$mois);
        } catch (Exception $e) {
            // Cache non critique - on ignore les erreurs de cache
        }

        // Capturer le snapshot de prix de l'annee synchronisee (historique)
        try {
            $histoPrix = new PrixHistorique($this->db, $this->entity);
            $histoPrix->capturer((int)$annee);
        } catch (Exception $e) {
            // Historique non critique
        }

        $this->log("Fin synchro : {$this->nb_ventes_maj} lignes mises à jour, {$this->nb_erreurs} erreur(s).");
        return ($this->nb_erreurs === 0);
    }

    /**
     * Synchronise les 12 mois d'une année complète
     */
    public function syncAnnee($annee = null)
    {
        if ($annee === null) $annee = (int)date('Y');
        $ok = true;
        for ($m = 1; $m <= 12; $m++) {
            if (!$this->syncMois($annee, $m)) $ok = false;
        }
        return $ok;
    }

    // -------------------------------------------------------------------------

    /**
     * Agrège CA HT, quantités et coût d'achat par référence produit pour un mois.
     *
     * Architecture compatible octopiaSync v2 (tables Dolibarr natives) :
     *   llx_octopia_orders (o) → dolibarr_order_id → llx_commande (c)
     *                         → llx_commandedet (cd) → llx_product (p)
     *
     * @return array|false
     */
    private function getAgregatsMois($annee, $mois)
    {
        $db = $this->db;

        // Construire la liste des statuts exclus pour la clause NOT IN
        $exclus = implode("','", array_map(array($db, 'escape'), $this->statuts_exclus));

        // BUGFIX: llx_commandedet n'a pas de champ 'ref'. La reference produit
        // vient de llx_product via cd.fk_product. Pour les lignes libres (sans fk_product),
        // on identifie le produit par son label.
        //
        // Resolution en cascade :
        //  1. cd.fk_product > 0 -> p.ref direct
        //  2. mapping manuel via le label -> pmap.ref
        //  3. fallback : "ORPHELIN-<hash du label>" pour ne pas perdre le CA
        $sql = "SELECT
                    COALESCE(
                        p.ref,
                        pmap.ref,
                        CONCAT('ORPHELIN-', SUBSTRING(MD5(COALESCE(cd.label, cd.description, '')), 1, 10))
                    )                                                             AS ref,
                    COALESCE(p.label, pmap.label, cd.label, cd.description, '?')  AS designation,
                    SUM(cd.qty)                                                   AS qty_total,
                    SUM(cd.qty * cd.subprice)                                     AS ca_ht_total,
                    MAX(COALESCE(p.cost_price, pmap.cost_price, 0))               AS cout_achat
                FROM ".MAIN_DB_PREFIX."octopia_orders o
                INNER JOIN ".MAIN_DB_PREFIX."commande c
                    ON  c.rowid  = o.dolibarr_order_id
                    AND c.entity = ".$this->entity."
                    AND YEAR(c.date_commande)  = ".(int)$annee."
                    AND MONTH(c.date_commande) = ".(int)$mois."
                    AND c.fk_statut >= 1
                INNER JOIN ".MAIN_DB_PREFIX."commandedet cd
                    ON  cd.fk_commande = c.rowid
                LEFT  JOIN ".MAIN_DB_PREFIX."product p
                    ON  p.rowid = cd.fk_product
                    AND p.entity IN (0, ".$this->entity.")
                LEFT  JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_mapping_ref mr
                    ON  mr.ref_octopia = COALESCE(p.ref, LEFT(COALESCE(cd.label, cd.description, ''), 64))
                    AND mr.entity      = ".$this->entity."
                LEFT  JOIN ".MAIN_DB_PREFIX."product pmap
                    ON  pmap.rowid = mr.fk_product_dolibarr
                WHERE o.entity = ".$this->entity."
                  AND o.is_refunded = 0
                  AND (o.octopia_order_status IS NULL
                    OR o.octopia_order_status NOT IN ('".$exclus."'))
                  AND o.dolibarr_order_id IS NOT NULL
                  -- Exclure les lignes totalement vides (frais de port Cdiscount)
                  AND NOT (cd.fk_product IS NULL
                           AND (cd.label IS NULL OR cd.label = '')
                           AND (cd.description IS NULL OR cd.description = ''))
                GROUP BY COALESCE(
                    p.ref,
                    pmap.ref,
                    CONCAT('ORPHELIN-', SUBSTRING(MD5(COALESCE(cd.label, cd.description, '')), 1, 10))
                )
                ORDER BY 1";

        $resql = $db->query($sql);
        if (!$resql) {
            $this->log("ERREUR SQL getAgregatsMois : ".$db->lasterror(), 'error');
            return false;
        }

        $agregats = array();
        while ($obj = $db->fetch_object($resql)) {
            $ref = $obj->ref;
            if (isset($agregats[$ref])) continue;
            $agregats[$ref] = array(
                'designation' => $obj->designation,
                'qty'         => (int)$obj->qty_total,
                'ca_ht'       => (float)$obj->ca_ht_total,
                'cout_achat'  => (float)$obj->cout_achat,
            );
        }
        return $agregats;
    }

    /**
     * Crée ou retourne le produit dans rentabiliteoctopia_produit
     *
     * @return int|false rowid
     */
    private function upsertProduit($ref, $designation)
    {
        $db = $this->db;

        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_produit
                WHERE ref = '".$db->escape($ref)."' AND entity = ".$this->entity;
        $resql = $db->query($sql);
        if (!$resql) {
            $this->log("ERREUR SQL upsertProduit SELECT ref=$ref : ".$db->lasterror(), 'error');
            return false;
        }

        $obj = $db->fetch_object($resql);
        if ($obj) return (int)$obj->rowid;

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_produit
                    (ref, designation, entity, datec)
                VALUES ('".$db->escape($ref)."', '".$db->escape($designation)."',
                        ".$this->entity.", '".$db->idate(dol_now())."')";
        $resql = $db->query($sql);
        if (!$resql) {
            $this->log("ERREUR SQL upsertProduit INSERT ref=$ref : ".$db->lasterror(), 'error');
            return false;
        }

        $newId = $db->last_insert_id(MAIN_DB_PREFIX.'rentabiliteoctopia_produit');
        $this->nb_produits_crees++;
        $this->log("Produit créé : $ref (id=$newId)");
        return (int)$newId;
    }

    /**
     * Crée ou met à jour la ligne de vente mensuelle
     *
     * @return bool
     */
    private function upsertVente($fkProduit, $annee, $mois, $qty, $caHt, $coutAchat)
    {
        $db = $this->db;

        $prixUnitHT = $qty > 0 ? round($caHt / $qty, 8) : 0;

        $updateCout = $coutAchat > 0
            ? ", cout_achat = ".((float)$coutAchat)
            : "";

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_vente
                    (fk_produit, annee, mois, qty_vendue, prix_ht, cout_achat, entity, datec)
                VALUES (
                    ".(int)$fkProduit.",
                    ".(int)$annee.",
                    ".(int)$mois.",
                    ".(int)$qty.",
                    ".((float)$prixUnitHT).",
                    ".((float)$coutAchat).",
                    ".$this->entity.",
                    '".$db->idate(dol_now())."'
                )
                ON DUPLICATE KEY UPDATE
                    qty_vendue = ".(int)$qty.",
                    prix_ht    = ".((float)$prixUnitHT)."
                    ".$updateCout;

        $resql = $db->query($sql);
        if (!$resql) {
            $this->log("ERREUR SQL upsertVente fk_produit=$fkProduit $annee-$mois : ".$db->lasterror(), 'error');
            return false;
        }

        $this->log("Vente MàJ : produit#$fkProduit $annee-$mois qty=$qty CA=".round($caHt,2)."€");
        return true;
    }

    private function log($msg, $level = 'info')
    {
        $this->logs[] = array('level' => $level, 'msg' => $msg, 'ts' => dol_now());
        dol_syslog('[rentabiliteoctopia] '.$msg, $level === 'error' ? LOG_ERR : LOG_INFO);
    }

    public function getLogsHtml()
    {
        $out = '';
        foreach ($this->logs as $l) {
            $color = $l['level'] === 'error' ? '#c0392b' : '#27ae60';
            $out .= '<div style="font-size:12px;font-family:monospace;color:'.$color.'">'.
                    dol_print_date($l['ts'], 'dayhour').' — '.dol_escape_htmltag($l['msg']).
                    '</div>';
        }
        return $out;
    }
}
