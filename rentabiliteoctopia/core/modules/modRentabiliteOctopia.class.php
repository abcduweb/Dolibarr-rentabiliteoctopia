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
        // BUGFIX: pointe vers admin/admin.php (la vraie page admin complète)
        // et non plus admin.php à la racine (page obsolète)
        $this->config_page_url = array('admin/admin.php@rentabiliteoctopia');
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
