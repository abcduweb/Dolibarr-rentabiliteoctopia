<?php
/**
 * Module RentabiliteOctopia pour Dolibarr v1.2.1
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modRentabiliteOctopia extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;
        $this->numero = 500200;
        $this->rights_class = 'rentabiliteoctopia';
        $this->family = 'crm';
        $this->module_position = 500;
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'Tableau de rentabilité mensuelle des ventes Octopia/Cdiscount';
        $this->editor_name = 'ABCduWeb';
        $this->editor_url = 'https://www.abcduweb.fr';
        $this->version = '1.2.1';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'fa-chart-line';

        $this->module_parts = array('menus' => 1);
        $this->dirs = array();
        // admin.php (racine) redirige vers admin/admin.php
        // On ne met pas admin/admin.php directement car Dolibarr ne résout
        // pas les sous-dossiers dans config_page_url depuis la liste des modules.
        $this->config_page_url = array('admin.php@rentabiliteoctopia');

        // ====== Taches planifiees natives Dolibarr ======
        // Ces jobs apparaissent dans Accueil > Configuration > Taches planifiees.
        // Ils utilisent le scheduler interne de Dolibarr (plus fiable que le cron systeme).
        $this->cronjobs = array(
            array(
                'label'      => 'Rapport KPI Octopia quotidien',
                'jobtype'    => 'method',
                'class'      => '/rentabiliteoctopia/class/rentabiliteoctopiacron.class.php',
                'objectname' => 'RentabiliteOctopiaCron',
                'method'     => 'sendDailyKpiMail',
                'parameters' => '',
                'comment'    => 'Envoie chaque matin le rapport KPI Cdiscount par email (selon la config du module).',
                'frequency'  => 1,
                'unitfrequency' => 86400,  // tous les jours
                'status'     => 1,
                'priority'   => 50,
                'datestart'  => dol_mktime(7, 0, 0, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y')),
            ),
            array(
                'label'      => 'Capture mensuelle des prix Octopia',
                'jobtype'    => 'method',
                'class'      => '/rentabiliteoctopia/class/rentabiliteoctopiacron.class.php',
                'objectname' => 'RentabiliteOctopiaCron',
                'method'     => 'captureProductPrices',
                'parameters' => '',
                'comment'    => 'Capture le snapshot mensuel des prix de vente (historique des prix).',
                'frequency'  => 1,
                'unitfrequency' => 86400,  // tous les jours (idempotent, ecrase le mois courant)
                'status'     => 1,
                'priority'   => 51,
            ),
        );

        // ====== Widget (box) page d'accueil Dolibarr ======
        // S'insere dans llx_boxes_def a l'activation du module. L'utilisateur peut
        // ensuite l'ajouter/retirer de "Mon tableau de bord" via le selecteur de widgets.
        $this->boxes = array(
            0 => array(
                'file'             => 'box_rentabiliteoctopia.php@rentabiliteoctopia',
                'note'             => 'KPI Rentabilite Octopia du mois en cours',
                'enabledbydefault' => 1,
            ),
        );

        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Diagnostic',
            'prefix'   => '<i class="fa fa-stethoscope pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'diagnostic',
            'url'      => '/rentabiliteoctopia/diagnostic.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 200,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->hidden = false;
        $this->depends = array();
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array('rentabiliteoctopia@rentabiliteoctopia');
        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(17, 0);

        // Permissions
        $this->rights = array();
        $r = 0;
        $this->rights[$r][0] = $this->numero + 1;
        $this->rights[$r][1] = 'Lire le tableau de rentabilité';
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'read';
        $r++;
        $this->rights[$r][0] = $this->numero + 2;
        $this->rights[$r][1] = 'Écrire dans le tableau de rentabilité';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';

        // Menu
        $this->menu = array();
        $r = 0;

        $this->menu[$r++] = array(
            'fk_menu'  => '', 'type' => 'top',
            'titre'    => 'Rentabilité Octopia',
            'prefix'   => '<i class="fa fa-chart-line pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => '',
            'url'      => '/rentabiliteoctopia/index.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 100,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Accueil',
            'prefix'   => '<i class="fa fa-home pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'accueil',
            'url'      => '/rentabiliteoctopia/accueil.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 100,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Tableau de bord',
            'prefix'   => '<i class="fa fa-tachometer-alt pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'dashboard',
            'url'      => '/rentabiliteoctopia/index.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 110,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Sante entreprise',
            'prefix'   => '<i class="fa fa-heartbeat pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'sante',
            'url'      => '/rentabiliteoctopia/sante.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 115,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Saisonnalite',
            'prefix'   => '<i class="fa fa-chart-line pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'saisonnalite',
            'url'      => '/rentabiliteoctopia/saisonnalite.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 116,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Simulateur de prix',
            'prefix'   => '<i class="fa fa-calculator pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'simulateur',
            'url'      => '/rentabiliteoctopia/simulateur.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 117,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Optimisation prix',
            'prefix'   => '<i class="fa fa-balance-scale pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'optimisation',
            'url'      => '/rentabiliteoctopia/optimisation_prix.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 117,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Historique prix',
            'prefix'   => '<i class="fa fa-history pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'histoprix',
            'url'      => '/rentabiliteoctopia/historique_prix.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 117,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Tresorerie Octopia',
            'prefix'   => '<i class="fa fa-euro-sign pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'tresorerie',
            'url'      => '/rentabiliteoctopia/tresorerie.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 118,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Produits & ventes',
            'prefix'   => '<i class="fa fa-boxes pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'produits',
            'url'      => '/rentabiliteoctopia/produits.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 120,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Frais mensuels',
            'prefix'   => '<i class="fa fa-file-invoice-dollar pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'frais',
            'url'      => '/rentabiliteoctopia/frais.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 125,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->write',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Catégories & commissions',
            'prefix'   => '<i class="fa fa-tags pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'categories',
            'url'      => '/rentabiliteoctopia/categories.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 130,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->write',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Synchronisation Octopia',
            'prefix'   => '<i class="fa fa-sync-alt pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'sync',
            'url'      => '/rentabiliteoctopia/sync.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 140,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->write',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Audit produits',
            'prefix'   => '<i class="fa fa-search pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'audit',
            'url'      => '/rentabiliteoctopia/audit_produits.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 145,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->write',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Affectation rapide',
            'prefix'   => '<i class="fa fa-magic pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'affectation',
            'url'      => '/rentabiliteoctopia/affectation.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 147,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->write',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Reassort & stock',
            'prefix'   => '<i class="fa fa-truck-loading pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'reassort',
            'url'      => '/rentabiliteoctopia/reassort.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 119,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Centre d\'alertes',
            'prefix'   => '<i class="fa fa-bell pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'alertes',
            'url'      => '/rentabiliteoctopia/alertes.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 116,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Cout achat auto',
            'prefix'   => '<i class="fa fa-file-invoice-dollar pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'coutachat',
            'url'      => '/rentabiliteoctopia/cout_achat_auto.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 148,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->write',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Vue fournisseurs',
            'prefix'   => '<i class="fa fa-industry pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'fournisseurs',
            'url'      => '/rentabiliteoctopia/fournisseurs.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 149,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->read',
            'target'   => '', 'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=rentabiliteoctopia', 'type' => 'left',
            'titre'    => 'Paramètres',
            'prefix'   => '<i class="fa fa-cog pictofixedwidth"></i>',
            'mainmenu' => 'rentabiliteoctopia', 'leftmenu' => 'parametres',
            'url'      => '/rentabiliteoctopia/admin/admin.php',
            'langs'    => 'rentabiliteoctopia@rentabiliteoctopia',
            'position' => 150,
            'enabled'  => '$conf->rentabiliteoctopia->enabled',
            'perms'    => '$user->rights->rentabiliteoctopia->write',
            'target'   => '', 'user' => 2,
        );

        $this->tabs = array();
        $this->dictionaries = array();
        $this->export_fields_array = array();
    }

    /**
     * Création des tables à l'activation du module
     */
    public function init($options = '')
    {
        global $conf;

        $result = $this->_load_tables('/rentabiliteoctopia/sql/');
        if ($result < 0) return -1;

        return $this->_init(array(), $options);
    }

    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
