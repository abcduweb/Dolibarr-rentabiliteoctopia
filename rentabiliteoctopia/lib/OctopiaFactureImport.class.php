<?php
/**
 * Import automatique des frais depuis les factures fournisseur Dolibarr
 *
 * Lit llx_facture_fourn + llx_facture_fourn_det filtrées sur le fournisseur
 * Octopia/Cdiscount, mappe les comptes PCG vers les types de frais du module,
 * et alimente llx_rentabiliteoctopia_frais.
 *
 * Mapping PCG -> type_frais (configurable dans les paramètres du module) :
 *   613xxx / 614xxx  -> abonnement
 *   624xxx / 6241xx  -> affranchissement
 *   625xxx           -> affranchissement (transport sur achats)
 *   622xxx / 623xxx  -> publicite
 *   604xxx / 6044xx  -> packaging
 *   611xxx / 6119xx  -> fulfilment
 *   autres comptes   -> autre
 */
class OctopiaFactureImport
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /** @var array */
    public $logs = array();
    public $nb_lignes    = 0;
    public $nb_frais_maj = 0;
    public $nb_erreurs   = 0;
    public $montants     = array();

    // Mapping PCG par préfixe -> type_frais
    // Ordre important : du plus précis au plus général
    private $mappingPCG = array(
        '6132' => 'abonnement',
        '6133' => 'abonnement',
        '613'  => 'abonnement',
        '614'  => 'abonnement',
        '6241' => 'affranchissement',
        '624'  => 'affranchissement',
        '625'  => 'affranchissement',
        '6044' => 'packaging',
        '604'  => 'packaging',
        '6119' => 'fulfilment',
        '611'  => 'fulfilment',
        '6231' => 'publicite',
        '623'  => 'publicite',
        '622'  => 'publicite',
        '626'  => 'affranchissement',  // frais postaux - manquant dans la version originale
    );

    public function __construct($db, $entity = 1)
    {
        $this->db     = $db;
        $this->entity = (int)$entity;
    }

    /**
     * Résout le type_frais depuis un compte PCG
     * Utilise le mapping configurable + fallback sur 'autre'
     *
     * @param  string $compte  Compte PCG (ex: "624100", "6132")
     * @param  array  $mappingOverride  Mapping personnalisé depuis les params
     * @return string type_frais
     */
    public function resolveTypePCG($compte, $mappingOverride = array())
    {
        // Nettoyer le compte (trim espaces parasites)
        $compte = trim((string)$compte);

        // 1. EXACT MATCH prioritaire depuis le custom override (6 chiffres configures par l'admin)
        //    Garantit que 622001 -> abonnement prime sur le prefixe 622 -> publicite
        if (isset($mappingOverride[$compte])) {
            return $mappingOverride[$compte];
        }

        // 2. Exact match dans le mapping par defaut
        if (isset($this->mappingPCG[$compte])) {
            return $this->mappingPCG[$compte];
        }

        // 3. Correspondance par prefixe (du plus long au plus court)
        $mapping = array_merge($this->mappingPCG, $mappingOverride);
        uksort($mapping, function($a, $b) { return strlen($b) - strlen($a); });
        foreach ($mapping as $prefix => $type) {
            if (strpos($compte, (string)$prefix) === 0) return $type;
        }
        return 'autre';
    }

    /**
     * Recherche le fournisseur Octopia/Cdiscount par nom approximatif
     *
     * @param  string $nomFournisseur  Nom ou fragment (ex: "cdiscount", "octopia")
     * @return int|false  rowid du tiers, ou false si non trouvé
     */
    public function findFournisseur($nomFournisseur)
    {
        $sql = "SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe
                WHERE fournisseur = 1
                  AND entity IN (0, ".$this->entity.")
                  AND (nom LIKE '%".$this->db->escape($nomFournisseur)."%'
                    OR name_alias LIKE '%".$this->db->escape($nomFournisseur)."%')
                ORDER BY rowid
                LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            $this->log("Fournisseur trouvé : ".$obj->nom." (id=".$obj->rowid.")");
            return (int)$obj->rowid;
        }
        $this->log("Fournisseur introuvable pour : $nomFournisseur", 'error');
        return false;
    }

    /**
     * Point d'entrée principal : importe les frais d'un mois depuis les factures fournisseur
     *
     * @param  int    $annee
     * @param  int    $mois
     * @param  int    $fkFournisseur   rowid du tiers fournisseur
     * @param  array  $mappingOverride Mapping PCG personnalisé depuis les params
     * @param  bool   $dryRun          Si true, calcule sans écrire en base
     * @return array  Montants agrégés par type_frais
     */
    public function importMois($annee, $mois, $fkFournisseur, $mappingOverride = array(), $dryRun = false)
    {
        $this->log("=== Import frais fournisseur $annee-$mois (fk_soc=$fkFournisseur) ===");

        // BUGFIX: la table llx_facture_fourn_det n'a pas de champ account_number.
        // Le compte PCG est dans fk_code_ventilation -> llx_accounting_account.account_number
        $sql = "SELECT
                    aa.account_number                           AS compte,
                    fd.label                                    AS libelle,
                    SUM(fd.total_ht)                            AS montant_ht,
                    GROUP_CONCAT(DISTINCT f.ref SEPARATOR ', ') AS factures
                FROM ".MAIN_DB_PREFIX."facture_fourn_det fd
                INNER JOIN ".MAIN_DB_PREFIX."facture_fourn f
                    ON  f.rowid     = fd.fk_facture_fourn
                    AND f.fk_soc    = ".(int)$fkFournisseur."
                    AND f.entity    = ".$this->entity."
                    AND f.fk_statut IN (1, 2)
                    AND YEAR(f.datef)  = ".(int)$annee."
                    AND MONTH(f.datef) = ".(int)$mois."
                INNER JOIN ".MAIN_DB_PREFIX."accounting_account aa
                    ON  aa.rowid = fd.fk_code_ventilation
                    AND aa.account_number != ''
                WHERE fd.fk_code_ventilation > 0
                  AND aa.account_number LIKE '6%'
                GROUP BY aa.account_number, fd.label
                ORDER BY aa.account_number";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("ERREUR SQL : ".$this->db->lasterror(), 'error');
            $this->nb_erreurs++;
            return array();
        }

        // Agréger par type_frais
        $agreg = array(
            'abonnement'       => array('montant' => 0, 'lignes' => array()),
            'fulfilment'       => array('montant' => 0, 'lignes' => array()),
            'affranchissement' => array('montant' => 0, 'lignes' => array()),
            'packaging'        => array('montant' => 0, 'lignes' => array()),
            'publicite'        => array('montant' => 0, 'lignes' => array()),
            'autre'            => array('montant' => 0, 'lignes' => array()),
        );

        while ($obj = $this->db->fetch_object($resql)) {
            $this->nb_lignes++;
            $type    = $this->resolveTypePCG($obj->compte, $mappingOverride);
            $montant = (float)$obj->montant_ht;
            $agreg[$type]['montant'] += $montant;
            $agreg[$type]['lignes'][] = array(
                'compte'   => $obj->compte,
                'libelle'  => $obj->libelle,
                'montant'  => $montant,
                'factures' => $obj->factures,
            );
            $this->log(sprintf("  %s → %s : %.2f € (%s) [%s]",
                $obj->compte, $type, $montant, $obj->libelle, $obj->factures));
        }

        $this->montants = $agreg;

        if ($dryRun) {
            $this->log("Mode aperçu — aucune écriture en base.");
            return $agreg;
        }

        // Écriture en base dans llx_rentabiliteoctopia_frais
        foreach ($agreg as $type => $data) {
            if ($data['montant'] == 0) continue;

            // Construire un libellé synthétique depuis les lignes
            $labels = array_unique(array_column($data['lignes'], 'libelle'));
            $label  = implode(' / ', array_slice($labels, 0, 3));
            if (count($labels) > 3) $label .= '...';

            $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_frais
                        (annee, mois, type_frais, label, montant, entity, datec)
                    VALUES (".(int)$annee.", ".(int)$mois.", '".$this->db->escape($type)."',
                            '".$this->db->escape($label)."', ".((float)$data['montant']).",
                            ".$this->entity.", '".$this->db->idate(dol_now())."')
                    ON DUPLICATE KEY UPDATE
                        montant = ".((float)$data['montant']).",
                        label   = '".$this->db->escape($label)."'";

            if ($this->db->query($sql)) {
                $this->nb_frais_maj++;
                $this->log(sprintf("  → Enregistré %s : %.2f €", $type, $data['montant']));
            } else {
                $this->nb_erreurs++;
                $this->log("ERREUR écriture $type : ".$this->db->lasterror(), 'error');
            }
        }

        $this->log("Import terminé : {$this->nb_lignes} ligne(s) lue(s), {$this->nb_frais_maj} type(s) mis à jour.");
        return $agreg;
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
            $color = $l['level'] === 'error' ? '#c0392b' : ($l['level'] === 'warn' ? '#e67e22' : '#27ae60');
            $out  .= '<div style="font-size:12px;font-family:monospace;color:'.$color.'">'.
                     dol_print_date($l['ts'], 'dayhour').' — '.dol_escape_htmltag($l['msg']).'</div>';
        }
        return $out;
    }
}
