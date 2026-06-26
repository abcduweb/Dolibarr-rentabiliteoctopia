<?php
/**
 * ModuleHelper - Utilitaires transverses du module.
 *
 * - Gestion d'erreur uniforme (try/catch wrapper qui evite les ecrans 500 bruts)
 * - Verification de l'existence des tables/colonnes
 * - Header commun avec barre de navigation rapide entre les pages
 */

class ModuleHelper
{
    /**
     * Execute une fonction en capturant toute erreur et en affichant
     * un message propre au lieu d'un ecran blanc 500.
     *
     * @param callable $callback  le code de la page
     * @param string   $contexte  nom de la page (pour le message d'erreur)
     */
    public static function runSafe($callback, $contexte = 'cette page')
    {
        try {
            $callback();
        } catch (Exception $e) {
            print '<div style="margin:20px;padding:20px;background:#fdebe5;border:1px solid #c0392b;border-radius:6px;">';
            print '<h3 style="margin-top:0;color:#c0392b;"><i class="fa fa-exclamation-triangle"></i> Une erreur est survenue</h3>';
            print '<p style="font-size:13px;">Le module a rencontre un probleme en chargeant '.dol_escape_htmltag($contexte).'.</p>';
            print '<details style="font-size:12px;color:#666;"><summary style="cursor:pointer;">Detail technique</summary>';
            print '<pre style="background:#fff;padding:10px;border-radius:4px;overflow:auto;">'.dol_escape_htmltag($e->getMessage()).'</pre>';
            print '</details>';
            print '<p style="font-size:12px;margin-top:10px;">Si le probleme persiste, verifiez la <a href="diagnostic.php">page de diagnostic</a> du module.</p>';
            print '</div>';
        }
    }

    /**
     * Verifie qu'une table existe.
     */
    public static function tableExists($db, $tableName)
    {
        $sql = "SHOW TABLES LIKE '".$db->escape($tableName)."'";
        $r = $db->query($sql);
        return ($r && $db->num_rows($r) > 0);
    }

    /**
     * Verifie qu'une colonne existe dans une table.
     */
    public static function columnExists($db, $tableName, $columnName)
    {
        $sql = "SHOW COLUMNS FROM ".$tableName." LIKE '".$db->escape($columnName)."'";
        $r = @$db->query($sql);
        return ($r && $db->num_rows($r) > 0);
    }

    /**
     * Barre de navigation rapide entre les pages du module.
     * Affichee en haut de chaque page pour faciliter la circulation.
     */
    public static function navBar($currentPage = '')
    {
        $pages = array(
            'index.php'             => array('Tableau de bord', 'fa-tachometer-alt'),
            'sante.php'             => array('Sante', 'fa-heartbeat'),
            'alertes.php'           => array('Alertes', 'fa-bell'),
            'optimisation_prix.php' => array('Optim. prix', 'fa-balance-scale'),
            'tresorerie.php'        => array('Tresorerie', 'fa-euro-sign'),
            'reassort.php'          => array('Reassort', 'fa-truck-loading'),
        );

        print '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;padding:8px;background:#f8f9fa;border-radius:6px;">';
        foreach ($pages as $url => $meta) {
            $active = ($currentPage === $url);
            $bg = $active ? '#667eea' : '#fff';
            $col = $active ? '#fff' : '#555';
            print '<a href="'.$url.'" style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;background:'.$bg.';color:'.$col.';text-decoration:none;border-radius:4px;font-size:12px;border:1px solid #e0e0e0;">';
            print '<i class="fa '.$meta[1].'"></i> '.$meta[0];
            print '</a>';
        }
        print '</div>';
    }
}
