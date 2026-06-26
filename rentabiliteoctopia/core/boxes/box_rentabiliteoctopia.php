<?php
/**
 * Widget (box) pour la page d'accueil de Dolibarr.
 *
 * Affiche les KPI Octopia/Cdiscount du mois en cours directement sur
 * "Mon tableau de bord" : chiffre d'affaires, marge nette, commandes.
 *
 * S'enregistre automatiquement a l'activation du module (voir $this->boxes
 * dans le descripteur). L'utilisateur peut ensuite l'ajouter/retirer de son
 * tableau de bord via le selecteur de widgets de la page d'accueil.
 */

require_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

class box_rentabiliteoctopia extends ModeleBoxes
{
    public $boxcode  = "rentabiliteoctopiakpi";
    public $boximg   = "fa-chart-line";
    public $boxlabel = "KPI Rentabilite Octopia";
    public $depends  = array("rentabiliteoctopia");

    public $db;
    public $param;

    public $info_box_head     = array();
    public $info_box_contents = array();

    public function __construct($db, $param = '')
    {
        global $langs;
        $langs->load("rentabiliteoctopia@rentabiliteoctopia");
        $this->db = $db;
        $this->boxlabel = "KPI Rentabilite Octopia";
        $this->param = $param;
    }

    /**
     * Charge le contenu du widget.
     *
     * @param int $max  nombre max de lignes (non utilise ici, contenu fixe)
     */
    public function loadBox($max = 5)
    {
        global $conf, $user, $langs;
        $this->max = $max;

        $moisNoms = array('', 'Janvier', 'Fevrier', 'Mars', 'Avril', 'Mai', 'Juin',
                          'Juillet', 'Aout', 'Septembre', 'Octobre', 'Novembre', 'Decembre');
        $annee = (int) date('Y');
        $mois  = (int) date('m');
        $entity = (int) $conf->entity;

        // En-tete du widget + lien vers la page d'accueil du module
        $this->info_box_head = array(
            'text'     => 'Rentabilite Octopia - '.$moisNoms[$mois].' '.$annee,
            'sublink'  => dol_buildpath('/rentabiliteoctopia/accueil.php', 1),
            'subpicto' => 'fa-external-link-alt',
            'subtext'  => 'Ouvrir',
            'target'   => '',
            'limit'    => 0,
        );

        // Controle de permission
        if (empty($user->rights->rentabiliteoctopia->read)) {
            $this->info_box_contents[0][] = array(
                'td'   => 'class="center"',
                'text' => $langs->trans("ReadPermissionNotAllowed"),
            );
            return;
        }

        try {
            require_once dol_buildpath('/rentabiliteoctopia/lib/rentabiliteoctopia.lib.php', 0);
            require_once dol_buildpath('/rentabiliteoctopia/lib/CacheMois.class.php', 0);

            $params = rentabiliteoctopia_get_params($this->db);
            $cache  = new CacheMois($this->db, $entity);
            $agg    = $cache->get($annee, $mois, $params);

            $ca    = isset($agg['ca']) ? (float) $agg['ca'] : 0;
            $marge = isset($agg['marge_nette']) ? (float) $agg['marge_nette'] : 0;
            $qty   = isset($agg['qty']) ? (int) $agg['qty'] : 0;
            $tauxMarge = $ca > 0 ? ($marge / $ca * 100) : 0;

            // Nombre de commandes du mois
            $nbCmd = 0;
            $sql = "SELECT COUNT(DISTINCT o.rowid) AS nb
                    FROM ".MAIN_DB_PREFIX."octopia_orders o
                    INNER JOIN ".MAIN_DB_PREFIX."commande c
                        ON c.rowid = o.dolibarr_order_id
                        AND c.entity = ".$entity." AND c.fk_statut >= 1
                        AND YEAR(c.date_commande) = ".$annee." AND MONTH(c.date_commande) = ".$mois."
                    WHERE o.entity = ".$entity." AND o.is_refunded = 0";
            $resql = $this->db->query($sql);
            if ($resql && $obj = $this->db->fetch_object($resql)) {
                $nbCmd = (int) $obj->nb;
            }

            $margeColor = $marge >= 0 ? '#27ae60' : '#c0392b';

            // Ligne 1 : CA du mois
            $line = 0;
            $this->info_box_contents[$line][] = array(
                'td'   => 'class="left"',
                'text' => 'Chiffre d\'affaires HT',
            );
            $this->info_box_contents[$line][] = array(
                'td'   => 'class="right"',
                'text' => '<b>'.roc_eur($ca).'</b>',
            );

            // Ligne 2 : Marge nette
            $line++;
            $this->info_box_contents[$line][] = array(
                'td'   => 'class="left"',
                'text' => 'Marge nette',
            );
            $this->info_box_contents[$line][] = array(
                'td'   => 'class="right"',
                'text' => '<b style="color:'.$margeColor.'">'.roc_eur($marge).' <span style="font-weight:normal;font-size:11px;">('.number_format($tauxMarge, 1, ',', ' ').'%)</span></b>',
            );

            // Ligne 3 : Commandes + unites
            $line++;
            $this->info_box_contents[$line][] = array(
                'td'   => 'class="left"',
                'text' => 'Commandes',
            );
            $this->info_box_contents[$line][] = array(
                'td'   => 'class="right"',
                'text' => '<b>'.$nbCmd.'</b> <span style="color:#888;font-size:11px;">('.$qty.' u.)</span>',
            );

        } catch (Exception $e) {
            $this->info_box_contents[0][] = array(
                'td'   => 'class="center"',
                'text' => 'Donnees indisponibles ('.dol_escape_htmltag($e->getMessage()).')',
            );
        }
    }

    /**
     * Affiche le widget.
     */
    public function showBox($head = null, $contents = null, $nooutput = 0)
    {
        return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
    }
}
