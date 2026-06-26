<?php
/**
 * Cache des agregats mensuels figes.
 *
 * Principe : les mois PASSES ne changent plus (sauf re-synchro), donc leurs
 * agregats (CA, marge, commissions, unites, nb commandes) peuvent etre calcules
 * une fois et stockes. Seul le mois EN COURS est recalcule a chaque fois.
 *
 * Invalidation : la methode invalidate() vide le cache d'un mois (appelee par
 * OctopiaRentabiliteSync apres chaque synchro pour forcer le recalcul).
 *
 * Table : rentabiliteoctopia_cache_mois
 *   annee, mois, entity, ca_ht, cout_achat, commissions, marge_produits,
 *   frais_fixes, marge_nette, qty, nb_produits, date_calcul
 */

class CacheMois
{
    private $db;
    private $entity;

    public function __construct($db, $entity)
    {
        $this->db = $db;
        $this->entity = (int)$entity;
        $this->ensureTable();
    }

    private function ensureTable()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."rentabiliteoctopia_cache_mois (
            rowid           INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            annee           INT(11)      NOT NULL,
            mois            INT(11)      NOT NULL,
            entity          INT(11)      NOT NULL DEFAULT 1,
            ca_ht           DOUBLE(24,8) NOT NULL DEFAULT 0,
            cout_achat      DOUBLE(24,8) NOT NULL DEFAULT 0,
            commissions     DOUBLE(24,8) NOT NULL DEFAULT 0,
            marge_produits  DOUBLE(24,8) NOT NULL DEFAULT 0,
            frais_fixes     DOUBLE(24,8) NOT NULL DEFAULT 0,
            marge_nette     DOUBLE(24,8) NOT NULL DEFAULT 0,
            qty             INT(11)      NOT NULL DEFAULT 0,
            nb_produits     INT(11)      NOT NULL DEFAULT 0,
            date_calcul     DATETIME     DEFAULT NULL,
            PRIMARY KEY (rowid),
            UNIQUE KEY uk_mois (annee, mois, entity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }

    /**
     * Determine si un mois est "fige" (passe, donc cachable).
     * Le mois en cours et le futur ne sont jamais caches.
     */
    public function estFige($annee, $mois)
    {
        $anneeCourante = (int)date('Y');
        $moisCourant   = (int)date('m');
        if ($annee < $anneeCourante) return true;
        if ($annee == $anneeCourante && $mois < $moisCourant) return true;
        return false;
    }

    /**
     * Recupere les agregats d'un mois.
     * - Mois fige + present en cache -> lecture cache (rapide)
     * - Mois fige + absent du cache  -> calcul + stockage
     * - Mois en cours                -> calcul direct (pas de cache)
     *
     * @return array agregats du mois
     */
    public function get($annee, $mois, $params)
    {
        $annee = (int)$annee; $mois = (int)$mois;

        if ($this->estFige($annee, $mois)) {
            $cached = $this->lireCache($annee, $mois);
            if ($cached !== null) {
                return $cached;
            }
            // Pas en cache -> calculer et stocker
            $agg = $this->calculer($annee, $mois, $params);
            $this->stocker($annee, $mois, $agg);
            return $agg;
        }

        // Mois en cours / futur -> calcul direct
        return $this->calculer($annee, $mois, $params);
    }

    private function lireCache($annee, $mois)
    {
        $sql = "SELECT ca_ht, cout_achat, commissions, marge_produits, frais_fixes,
                       marge_nette, qty, nb_produits
                FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_cache_mois
                WHERE annee = ".$annee." AND mois = ".$mois." AND entity = ".$this->entity;
        $r = $this->db->query($sql);
        if ($r && $o = $this->db->fetch_object($r)) {
            return array(
                'ca'             => (float)$o->ca_ht,
                'cout_achat'     => (float)$o->cout_achat,
                'commissions'    => (float)$o->commissions,
                'marge_produits' => (float)$o->marge_produits,
                'frais_fixes'    => (float)$o->frais_fixes,
                'marge_nette'    => (float)$o->marge_nette,
                'qty'            => (int)$o->qty,
                'nb_produits'    => (int)$o->nb_produits,
                'from_cache'     => true,
            );
        }
        return null;
    }

    /**
     * Calcul reel des agregats d'un mois (la requete couteuse).
     */
    private function calculer($annee, $mois, $params)
    {
        $tauxRetour = isset($params['taux_retour_pct']) ? (float)$params['taux_retour_pct'] : 3;
        $coutRetour = isset($params['cout_retour'])     ? (float)$params['cout_retour']     : 2.50;

        $sql = "SELECT
                    COALESCE(SUM(v.qty_vendue * v.prix_ht), 0)    AS ca,
                    COALESCE(SUM(v.qty_vendue * v.cout_achat), 0) AS cout,
                    COALESCE(SUM(
                        COALESCE(v.commission_reel,
                            v.qty_vendue * v.prix_ht * COALESCE(v.commission_pct, c.commission_pct, 15)/100)
                    ), 0)                                          AS commissions,
                    COALESCE(SUM(v.qty_vendue), 0)                 AS qty,
                    COUNT(DISTINCT v.fk_produit)                   AS nb_produits
                FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
                INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
                LEFT  JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
                WHERE v.annee = ".$annee." AND v.mois = ".$mois." AND v.entity = ".$this->entity."
                  AND p.ref NOT LIKE 'ORPHELIN-%' AND p.ref NOT LIKE 'LIBRE:%'";
        $r = $this->db->query($sql);
        $ca = $cout = $comm = 0; $qty = $nbProd = 0;
        if ($r && $o = $this->db->fetch_object($r)) {
            $ca = (float)$o->ca; $cout = (float)$o->cout; $comm = (float)$o->commissions;
            $qty = (int)$o->qty; $nbProd = (int)$o->nb_produits;
        }

        // Frais fixes du mois
        $sqlF = "SELECT COALESCE(SUM(montant), 0) AS f FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_frais
                 WHERE annee = ".$annee." AND mois = ".$mois." AND entity = ".$this->entity;
        $rF = $this->db->query($sqlF);
        $frais = ($rF && $oF = $this->db->fetch_object($rF)) ? (float)$oF->f : 0;

        $retour = $qty * ($tauxRetour/100) * $coutRetour;
        $margeProduits = $ca - $cout - $comm - $retour;
        $margeNette = $margeProduits - $frais;

        return array(
            'ca'             => $ca,
            'cout_achat'     => $cout,
            'commissions'    => $comm,
            'marge_produits' => $margeProduits,
            'frais_fixes'    => $frais,
            'marge_nette'    => $margeNette,
            'qty'            => $qty,
            'nb_produits'    => $nbProd,
            'from_cache'     => false,
        );
    }

    private function stocker($annee, $mois, $agg)
    {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_cache_mois
                    (annee, mois, entity, ca_ht, cout_achat, commissions, marge_produits,
                     frais_fixes, marge_nette, qty, nb_produits, date_calcul)
                VALUES (".$annee.", ".$mois.", ".$this->entity.",
                        ".(float)$agg['ca'].", ".(float)$agg['cout_achat'].", ".(float)$agg['commissions'].",
                        ".(float)$agg['marge_produits'].", ".(float)$agg['frais_fixes'].", ".(float)$agg['marge_nette'].",
                        ".(int)$agg['qty'].", ".(int)$agg['nb_produits'].", '".$this->db->idate(dol_now())."')
                ON DUPLICATE KEY UPDATE
                    ca_ht = ".(float)$agg['ca'].", cout_achat = ".(float)$agg['cout_achat'].",
                    commissions = ".(float)$agg['commissions'].", marge_produits = ".(float)$agg['marge_produits'].",
                    frais_fixes = ".(float)$agg['frais_fixes'].", marge_nette = ".(float)$agg['marge_nette'].",
                    qty = ".(int)$agg['qty'].", nb_produits = ".(int)$agg['nb_produits'].",
                    date_calcul = '".$this->db->idate(dol_now())."'";
        $this->db->query($sql);
    }

    /**
     * Invalide le cache d'un mois (force le recalcul au prochain get).
     * Appelee apres une synchro ou une modification de donnees.
     */
    public function invalidate($annee, $mois)
    {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_cache_mois
                WHERE annee = ".(int)$annee." AND mois = ".(int)$mois." AND entity = ".$this->entity;
        $this->db->query($sql);
    }

    /**
     * Invalide toute une annee (utile apres un nettoyage massif).
     */
    public function invalidateAnnee($annee)
    {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_cache_mois
                WHERE annee = ".(int)$annee." AND entity = ".$this->entity;
        $this->db->query($sql);
    }

    /**
     * Vide tout le cache.
     */
    public function invalidateAll()
    {
        $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_cache_mois WHERE entity = ".$this->entity);
    }

    /**
     * Retourne l'evolution annuelle (12 mois) en exploitant le cache.
     * Remplace rentabiliteoctopia_get_evolution() de maniere performante.
     */
    public function getEvolution($annee, $params)
    {
        $evolution = array();
        for ($m = 1; $m <= 12; $m++) {
            $agg = $this->get($annee, $m, $params);
            $evolution[$m] = array(
                'ca'             => $agg['ca'],
                'marge_produits' => $agg['marge_produits'],
                'frais_fixes'    => $agg['frais_fixes'],
                'marge_nette'    => $agg['marge_nette'],
                'qty'            => $agg['qty'],
            );
        }
        return $evolution;
    }
}
