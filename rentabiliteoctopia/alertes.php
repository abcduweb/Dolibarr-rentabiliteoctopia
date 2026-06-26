<?php
/**
 * Centre d'alertes - affichage de toutes les situations necessitant attention
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';
require_once __DIR__.'/lib/AlertesEngine.class.php';

if (!$user->rights->rentabiliteoctopia->read) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$params = rentabiliteoctopia_get_params($db);
$engine = new AlertesEngine($db, $conf->entity, $params);
$alertes = $engine->getAlertes();

$nbCritique = count($alertes['critique']);
$nbWarning  = count($alertes['warning']);
$nbInfo     = count($alertes['info']);
$total = $nbCritique + $nbWarning + $nbInfo;

llxHeader('', 'Centre d\'alertes');
print load_fiche_titre('Centre d\'alertes', '', 'fa-bell');

// Resume
print '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px;">';
$cards = array(
    array('Critiques', $nbCritique, '#c0392b'),
    array('Avertissements', $nbWarning, '#e67e22'),
    array('Informations', $nbInfo, '#3498db'),
);
foreach ($cards as $c) {
    print '<div style="background:#fff;border-left:4px solid '.$c[2].';padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
    print '<div style="font-size:11px;color:#888;text-transform:uppercase;">'.$c[0].'</div>';
    print '<div style="font-size:30px;font-weight:bold;color:'.$c[2].';">'.$c[1].'</div>';
    print '</div>';
}
print '</div>';

if ($total === 0) {
    print '<div style="background:#e8f8ee;padding:40px;border-radius:8px;text-align:center;">';
    print '<div style="font-size:48px;">✓</div>';
    print '<div style="font-size:18px;color:#27ae60;font-weight:bold;margin-top:10px;">Aucune alerte active</div>';
    print '<div style="font-size:13px;color:#666;margin-top:6px;">Tout va bien. Vos produits sont rentables et vos stocks suffisants.</div>';
    print '</div>';
} else {
    // Affichage par niveau
    $niveaux = array(
        'critique' => array('Critiques', '#c0392b', '#fdebe5'),
        'warning'  => array('Avertissements', '#e67e22', '#fef5e7'),
        'info'     => array('Informations', '#3498db', '#e3f1fc'),
    );

    foreach ($niveaux as $key => $meta) {
        if (empty($alertes[$key])) continue;
        print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:16px;">';
        print '<h3 style="margin-top:0;color:'.$meta[1].';">'.$meta[0].' ('.count($alertes[$key]).')</h3>';
        foreach ($alertes[$key] as $a) {
            print '<div style="padding:12px;margin-bottom:8px;background:'.$meta[2].';border-radius:6px;border-left:3px solid '.$meta[1].';">';
            print '<div style="font-size:15px;font-weight:bold;color:'.$meta[1].';">'.$a['icone'].' '.dol_escape_htmltag($a['titre']).'</div>';
            print '<div style="font-size:13px;color:#555;margin-top:4px;">'.dol_escape_htmltag($a['detail']).'</div>';
            print '</div>';
        }
        print '</div>';
    }
}

// Lien vers config
print '<div style="margin-top:20px;padding:12px;background:#f9f9f9;border-radius:6px;font-size:12px;color:#666;">';
print '<b>Comment fonctionnent les alertes :</b> elles sont calculees en temps reel a partir de vos ventes, stocks et seuils configures. ';
print 'Le seuil de marge ('.((float)($params['seuil_marge_pct'] ?? 15)).'%) se regle dans <a href="admin/admin.php">Parametres</a>. ';
print 'Vous pouvez aussi recevoir ces alertes dans le mail quotidien (option a activer dans Parametres).';
print '</div>';

llxFooter();
$db->close();
