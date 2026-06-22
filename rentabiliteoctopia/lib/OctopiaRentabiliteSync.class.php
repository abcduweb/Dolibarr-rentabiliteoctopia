<?php
/**
 * Synchronisation octopiaSync -> rentabiliteoctopia
 *
 * Agrège les lignes de commandes Octopia importées par octopiaSync
 * et alimente les tables de rentabilité par produit/mois.
 *
 * Sources :
 *   llx_octopiaSync_order      : commandes (status, purchasedAt)
 *   llx_octopiaSync_orderline  : lignes (product_ref, product_name, qty, unit_price_ht)
 *   llx_product                : coût d'achat (cost_price) via ref
 *
 * Destination :
 *   llx_rentabiliteoctopia_produit  : référentiel produits
 *   llx_rentabiliteoctopia_vente    : agrégats mensuels qty + CA + cout_achat
 */

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

    // Statuts de commandes Octopia à inclure dans le calcul
    // On exclut les commandes annulées
    private $statuts_inclus = array('WAITING_DEBIT_PAYMENT', 'PAYMENT_ACCEPTED', 'ACCEPTED',
                                    'SHIPPED', 'DELIVERED', 'SELLER_SHIPPED');

    public function __construct($db, $entity = 1)
    {
        $this->db     = $db;
        $this->entity = (int)$entity;
    }

    /**
     * Point d'entrée principal : synchronise un mois donné (ou le mois courant)
     *
     * @param  int $annee  Année (défaut : année courante)
     * @param  int $mois   Mois 1-12 (défaut : mois courant)
     * @return bool
     */
    public function syncMois($annee = null, $mois = null)
    {
        if ($annee === null) $annee = (int)date('Y');
        if ($mois  === null) $mois  = (int)date('m');

        $this->log("=== Début synchro Octopia -> Rentabilité : $annee-$mois ===");

        // 1. Agréger les ventes depuis octopiaSync
        $agregats = $this->getAgregatsMois($annee, $mois);
        if ($agregats === false) return false;

        if (empty($agregats)) {
            $this->log("Aucune commande Octopia trouvée pour $mois/$annee.");
            return true;
        }

        $this->log(count($agregats)." référence(s) produit trouvée(s) pour $mois/$annee.");

        // 2. Pour chaque produit, s'assurer qu'il existe dans rentabiliteoctopia
        //    puis mettre à jour ou créer la ligne de vente mensuelle
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
    // Méthodes privées
    // -------------------------------------------------------------------------

    /**
     * Agrège CA HT, quantités et coût d'achat par référence produit
     * pour un mois donné, depuis les tables octopiaSync + llx_product
     *
     * @return array|false  Tableau indexé par ref produit, ou false si erreur SQL
     */
    private function getAgregatsMois($annee, $mois)
    {
        $db = $this->db;

        // Construire la liste des statuts inclus pour la clause IN
        $statuts = implode("','", array_map(array($db, 'escape'), $this->statuts_inclus));

        // Jointure octopiaSync_order + octopiaSync_orderline + llx_product (LEFT JOIN pour cost_price)
        // On groupe par ref produit
        $sql = "SELECT
                    ol.product_ref                          AS ref,
                    ol.product_name                         AS designation,
                    SUM(ol.qty)                             AS qty_total,
                    SUM(ol.qty * ol.unit_price_ht)          AS ca_ht_total,
                    COALESCE(p.cost_price, 0)               AS cout_achat
                FROM ".MAIN_DB_PREFIX."octopiaSync_orderline ol
                INNER JOIN ".MAIN_DB_PREFIX."octopiaSync_order o
                    ON o.rowid = ol.fk_order
                    AND o.entity = ".$this->entity."
                    AND o.status IN ('".$statuts."')
                    AND YEAR(o.purchasedAt)  = ".(int)$annee."
                    AND MONTH(o.purchasedAt) = ".(int)$mois."
                LEFT JOIN ".MAIN_DB_PREFIX."product p
                    ON p.ref = ol.product_ref
                    AND p.entity IN (0, ".$this->entity.")
                GROUP BY ol.product_ref, ol.product_name, p.cost_price
                ORDER BY ol.product_ref";

        $resql = $db->query($sql);
        if (!$resql) {
            $this->log("ERREUR SQL getAgregatsMois : ".$db->lasterror(), 'error');
            return false;
        }

        $agregats = array();
        while ($obj = $db->fetch_object($resql)) {
            $ref = $obj->ref;
            // Si même ref avec cost_price différents (multi-entity), on prend le premier
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
     * Crée ou met à jour le produit dans rentabiliteoctopia_produit
     * Ne modifie la désignation que si le produit n'existe pas encore
     *
     * @return int|false rowid du produit
     */
    private function upsertProduit($ref, $designation)
    {
        $db = $this->db;

        // Vérifier si le produit existe déjà
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_produit
                WHERE ref = '".$db->escape($ref)."' AND entity = ".$this->entity;
        $resql = $db->query($sql);
        if (!$resql) {
            $this->log("ERREUR SQL upsertProduit SELECT ref=$ref : ".$db->lasterror(), 'error');
            return false;
        }

        $obj = $db->fetch_object($resql);
        if ($obj) {
            return (int)$obj->rowid;
        }

        // Créer le produit
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
     * IMPORTANT : on ne remplace le cout_achat que s'il est > 0 dans llx_product
     * pour ne pas écraser une valeur saisie manuellement par 0
     *
     * @return bool
     */
    private function upsertVente($fkProduit, $annee, $mois, $qty, $caHt, $coutAchat)
    {
        $db = $this->db;

        // Calculer prix unitaire moyen HT
        $prixUnitHT = $qty > 0 ? round($caHt / $qty, 8) : 0;

        // Mise à jour cout_achat seulement si Dolibarr en a un (> 0)
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

        $this->log("Vente mise à jour : produit#$fkProduit $annee-$mois qty=$qty CA=".round($caHt, 2)."€");
        return true;
    }

    /**
     * Ajoute un message de log interne
     */
    private function log($msg, $level = 'info')
    {
        $this->logs[] = array('level' => $level, 'msg' => $msg, 'ts' => dol_now());
        dol_syslog('[rentabiliteoctopia] '.$msg, $level === 'error' ? LOG_ERR : LOG_INFO);
    }

    /**
     * Retourne les logs formatés pour affichage HTML
     */
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
