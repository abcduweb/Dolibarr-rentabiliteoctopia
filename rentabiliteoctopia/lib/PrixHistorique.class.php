<?php
/**
 * PrixHistorique - Historise les prix de vente et mesure l'impact des changements.
 *
 * Principe :
 *   - Un snapshot mensuel du prix de vente moyen de chaque produit est calcule
 *     depuis les ventes Octopia (rentabiliteoctopia_vente.prix_ht).
 *   - Quand le prix change d'un mois a l'autre au-dela d'un seuil, on enregistre
 *     un "evenement de changement de prix".
 *   - Pour chaque changement, on compare les ventes AVANT et APRES (volume, marge)
 *     pour mesurer l'impact reel.
 *
 * Table : rentabiliteoctopia_prix_histo
 *   fk_produit, annee, mois, prix_moyen, qty, cout_moyen, entity, date_snapshot
 *
 * La capture se fait :
 *   - automatiquement via le cron (ou la synchro)
 *   - ou manuellement via le bouton "Capturer les prix" dans la page
 */

class PrixHistorique
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
        $this->db->query("CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."rentabiliteoctopia_prix_histo (
            rowid          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            fk_produit     INT(11)      NOT NULL,
            annee          INT(11)      NOT NULL,
            mois           INT(11)      NOT NULL,
            prix_moyen     DOUBLE(24,8) NOT NULL DEFAULT 0,
            qty            INT(11)      NOT NULL DEFAULT 0,
            cout_moyen     DOUBLE(24,8) NOT NULL DEFAULT 0,
            entity         INT(11)      NOT NULL DEFAULT 1,
            date_snapshot  DATETIME     DEFAULT NULL,
            PRIMARY KEY (rowid),
            UNIQUE KEY uk_produit_mois (fk_produit, annee, mois, entity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }

    /**
     * Capture un snapshot des prix moyens pour tous les mois ayant des ventes.
     * Idempotent : reecrit les snapshots existants (ON DUPLICATE KEY).
     *
     * @param int|null $anneeLimit  si fourni, ne capture que cette annee
     * @return int  nombre de snapshots ecrits
     */
    public function capturer($anneeLimit = null)
    {
        $filtreAnnee = $anneeLimit ? "AND v.annee = ".(int)$anneeLimit : "";

        $sql = "SELECT
                    v.fk_produit, v.annee, v.mois,
                    SUM(v.qty_vendue * v.prix_ht) / NULLIF(SUM(v.qty_vendue),0) AS prix_moyen,
                    SUM(v.qty_vendue)                                            AS qty,
                    SUM(v.qty_vendue * v.cout_achat) / NULLIF(SUM(v.qty_vendue),0) AS cout_moyen
                FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
                INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
                WHERE v.entity = ".$this->entity."
                  ".$filtreAnnee."
                  AND p.ref NOT LIKE 'ORPHELIN-%' AND p.ref NOT LIKE 'LIBRE:%'
                GROUP BY v.fk_produit, v.annee, v.mois
                HAVING qty > 0";
        $r = $this->db->query($sql);
        $nb = 0;
        while ($r && $o = $this->db->fetch_object($r)) {
            $sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_prix_histo
                          (fk_produit, annee, mois, prix_moyen, qty, cout_moyen, entity, date_snapshot)
                       VALUES (".(int)$o->fk_produit.", ".(int)$o->annee.", ".(int)$o->mois.",
                               ".(float)$o->prix_moyen.", ".(int)$o->qty.", ".(float)$o->cout_moyen.",
                               ".$this->entity.", '".$this->db->idate(dol_now())."')
                       ON DUPLICATE KEY UPDATE
                           prix_moyen = ".(float)$o->prix_moyen.",
                           qty = ".(int)$o->qty.",
                           cout_moyen = ".(float)$o->cout_moyen.",
                           date_snapshot = '".$this->db->idate(dol_now())."'";
            if ($this->db->query($sqlIns)) $nb++;
        }
        return $nb;
    }

    /**
     * Detecte les changements de prix significatifs (> seuil %) entre mois consecutifs.
     *
     * @param float $seuilPct  variation minimale pour considerer un changement (defaut 3%)
     * @return array  liste des changements avec impact mesure
     */
    public function getChangements($seuilPct = 3)
    {
        // Recuperer tout l'historique ordonne par produit puis date
        $sql = "SELECT h.fk_produit, p.ref, p.designation, h.annee, h.mois,
                       h.prix_moyen, h.qty, h.cout_moyen
                FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_prix_histo h
                INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = h.fk_produit
                WHERE h.entity = ".$this->entity."
                ORDER BY h.fk_produit, h.annee, h.mois";
        $r = $this->db->query($sql);

        // Grouper par produit
        $parProduit = array();
        while ($r && $o = $this->db->fetch_object($r)) {
            $parProduit[$o->fk_produit][] = array(
                'ref'        => $o->ref,
                'designation'=> $o->designation,
                'annee'      => (int)$o->annee,
                'mois'       => (int)$o->mois,
                'prix'       => (float)$o->prix_moyen,
                'qty'        => (int)$o->qty,
                'cout'       => (float)$o->cout_moyen,
            );
        }

        $changements = array();
        foreach ($parProduit as $fkProduit => $snapshots) {
            $n = count($snapshots);
            for ($i = 1; $i < $n; $i++) {
                $avant = $snapshots[$i-1];
                $apres = $snapshots[$i];

                if ($avant['prix'] <= 0) continue;
                $variationPct = ($apres['prix'] - $avant['prix']) / $avant['prix'] * 100;

                if (abs($variationPct) >= $seuilPct) {
                    // Marge avant/apres (simplifiee : prix - cout)
                    $margeAvant = $avant['prix'] - $avant['cout'];
                    $margeApres = $apres['prix'] - $apres['cout'];

                    // Impact volume
                    $variationVolume = $avant['qty'] > 0 ? (($apres['qty'] - $avant['qty']) / $avant['qty'] * 100) : null;

                    // Impact marge totale (marge unitaire x volume)
                    $margeTotaleAvant = $margeAvant * $avant['qty'];
                    $margeTotaleApres = $margeApres * $apres['qty'];

                    $changements[] = array(
                        'ref'              => $avant['ref'],
                        'designation'      => $avant['designation'],
                        'mois_avant'       => sprintf('%02d/%04d', $avant['mois'], $avant['annee']),
                        'mois_apres'       => sprintf('%02d/%04d', $apres['mois'], $apres['annee']),
                        'prix_avant'       => $avant['prix'],
                        'prix_apres'       => $apres['prix'],
                        'variation_pct'    => $variationPct,
                        'qty_avant'        => $avant['qty'],
                        'qty_apres'        => $apres['qty'],
                        'variation_volume' => $variationVolume,
                        'marge_avant'      => $margeAvant,
                        'marge_apres'      => $margeApres,
                        'marge_totale_avant' => $margeTotaleAvant,
                        'marge_totale_apres' => $margeTotaleApres,
                        'impact_marge'     => $margeTotaleApres - $margeTotaleAvant,
                        'sens'             => $variationPct > 0 ? 'hausse' : 'baisse',
                    );
                }
            }
        }

        // Trier par date de changement la plus recente
        usort($changements, function($a, $b) {
            return strcmp($b['mois_apres'], $a['mois_apres']);
        });

        return $changements;
    }

    /**
     * Recupere l'historique de prix d'un produit precis (pour mini-graphe).
     */
    public function getHistoriqueProduit($fkProduit)
    {
        $sql = "SELECT annee, mois, prix_moyen, qty
                FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_prix_histo
                WHERE fk_produit = ".(int)$fkProduit." AND entity = ".$this->entity."
                ORDER BY annee, mois";
        $r = $this->db->query($sql);
        $histo = array();
        while ($r && $o = $this->db->fetch_object($r)) {
            $histo[] = array(
                'periode' => sprintf('%02d/%04d', (int)$o->mois, (int)$o->annee),
                'prix'    => (float)$o->prix_moyen,
                'qty'     => (int)$o->qty,
            );
        }
        return $histo;
    }

    /**
     * Compte le nombre de snapshots stockes.
     */
    public function countSnapshots()
    {
        $r = $this->db->query("SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_prix_histo WHERE entity = ".$this->entity);
        return ($r && $o = $this->db->fetch_object($r)) ? (int)$o->nb : 0;
    }
}
