<?php
/**
 * Diagnostic du module - verifie l'integrite et la coherence des donnees.
 *
 * Controle : tables presentes, colonnes attendues, version Dolibarr,
 * coherence des donnees (produits sans categorie, ventes orphelines,
 * couts manquants, sender email configure).
 *
 * Objectif : permettre a l'utilisateur de diagnostiquer seul les problemes.
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';
require_once __DIR__.'/lib/ModuleHelper.class.php';

if (!$user->rights->rentabiliteoctopia->read) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

llxHeader('', 'Diagnostic du module');
print load_fiche_titre('Diagnostic du module', '', 'fa-stethoscope');
ModuleHelper::navBar('diagnostic.php');

$checks = array();

function addCheck(&$checks, $categorie, $label, $statut, $detail = '', $action = '') {
    $checks[] = array('cat'=>$categorie, 'label'=>$label, 'statut'=>$statut, 'detail'=>$detail, 'action'=>$action);
}

// ===== 1. TABLES =====
$tablesAttendues = array(
    'rentabiliteoctopia_categorie', 'rentabiliteoctopia_produit', 'rentabiliteoctopia_vente',
    'rentabiliteoctopia_frais', 'rentabiliteoctopia_params', 'rentabiliteoctopia_cache_mois',
);
foreach ($tablesAttendues as $t) {
    $exists = ModuleHelper::tableExists($db, MAIN_DB_PREFIX.$t);
    addCheck($checks, 'Tables', 'Table '.$t, $exists ? 'ok' : 'error',
        $exists ? 'Presente' : 'MANQUANTE - reactivez le module ou contactez le support');
}

// Tables liees (octopiaSync)
$octopiaExists = ModuleHelper::tableExists($db, MAIN_DB_PREFIX.'octopia_orders');
addCheck($checks, 'Tables', 'Table octopia_orders (octopiaSync)', $octopiaExists ? 'ok' : 'error',
    $octopiaExists ? 'Presente' : 'MANQUANTE - le module octopiaSync doit etre installe et avoir synchronise');

// ===== 2. VERSION DOLIBARR =====
$versionDolibarr = DOL_VERSION;
$versionOk = version_compare($versionDolibarr, '16.0', '>=');
addCheck($checks, 'Environnement', 'Version Dolibarr', $versionOk ? 'ok' : 'warning',
    'Version '.$versionDolibarr.($versionOk ? ' (compatible)' : ' (non testee, >= 16 recommande)'));

// PHP version
$phpOk = version_compare(PHP_VERSION, '7.4', '>=');
addCheck($checks, 'Environnement', 'Version PHP', $phpOk ? 'ok' : 'warning',
    'PHP '.PHP_VERSION.($phpOk ? '' : ' (>= 7.4 recommande)'));

// Fonction mail()
$mailExists = function_exists('mail');
addCheck($checks, 'Environnement', 'Fonction mail() PHP', $mailExists ? 'ok' : 'error',
    $mailExists ? 'Disponible (rapport quotidien possible)' : 'INDISPONIBLE - le rapport mail ne fonctionnera pas');

// ===== 3. CONFIGURATION EMAIL =====
$emailFrom = !empty($conf->global->MAIN_MAIL_EMAIL_FROM) ? $conf->global->MAIN_MAIL_EMAIL_FROM : '';
$emailFromOk = !empty($emailFrom) && filter_var($emailFrom, FILTER_VALIDATE_EMAIL) && strpos($emailFrom, 'robot@') !== 0;
addCheck($checks, 'Email', 'Expediteur configure', $emailFromOk ? 'ok' : 'warning',
    $emailFrom ? ('Expediteur : '.$emailFrom.($emailFromOk ? '' : ' (adresse generique, Gmail risque de rejeter)')) : 'NON CONFIGURE - allez dans Accueil > Configuration > Emails',
    $emailFromOk ? '' : DOL_URL_ROOT.'/admin/mails.php');

$params = rentabiliteoctopia_get_params($db);
$kpiEmail = isset($params['daily_kpi_email']) ? $params['daily_kpi_email'] : '';
$kpiEnabled = !empty($params['daily_kpi_enabled']);
addCheck($checks, 'Email', 'Rapport quotidien', $kpiEnabled ? 'ok' : 'info',
    $kpiEnabled ? ('Active vers : '.$kpiEmail) : 'Desactive (activez-le dans Parametres si souhaite)');

// ===== 4. COHERENCE DES DONNEES =====
// Produits sans categorie
$sql = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_produit
        WHERE (fk_categorie IS NULL OR fk_categorie = 0) AND entity = ".((int)$conf->entity)."
        AND ref NOT LIKE 'ORPHELIN-%' AND ref NOT LIKE 'LIBRE:%'";
$r = $db->query($sql);
$nbSansCat = ($r && $o = $db->fetch_object($r)) ? (int)$o->nb : 0;
addCheck($checks, 'Donnees', 'Produits sans categorie', $nbSansCat == 0 ? 'ok' : 'warning',
    $nbSansCat == 0 ? 'Tous les produits sont categorises' : $nbSansCat.' produit(s) sans categorie -> commission non calculee',
    $nbSansCat > 0 ? 'affectation.php' : '');

// Produits orphelins (artificiels)
$sql = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_produit
        WHERE (ref LIKE 'ORPHELIN-%' OR ref LIKE 'LIBRE:%') AND entity = ".((int)$conf->entity);
$r = $db->query($sql);
$nbOrphelins = ($r && $o = $db->fetch_object($r)) ? (int)$o->nb : 0;
addCheck($checks, 'Donnees', 'Produits artificiels (orphelins)', $nbOrphelins == 0 ? 'ok' : 'warning',
    $nbOrphelins == 0 ? 'Aucun' : $nbOrphelins.' produit(s) ORPHELIN/LIBRE a nettoyer',
    $nbOrphelins > 0 ? 'admin/admin.php' : '');

// Produits avec cout d'achat a 0
$sql = "SELECT COUNT(DISTINCT v.fk_produit) AS nb
        FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
        INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
        WHERE v.entity = ".((int)$conf->entity)."
          AND (v.cout_achat IS NULL OR v.cout_achat = 0)
          AND v.annee = ".(int)date('Y')."
          AND p.ref NOT LIKE 'ORPHELIN-%' AND p.ref NOT LIKE 'LIBRE:%'";
$r = $db->query($sql);
$nbSansCout = ($r && $o = $db->fetch_object($r)) ? (int)$o->nb : 0;
addCheck($checks, 'Donnees', 'Couts d\'achat manquants', $nbSansCout == 0 ? 'ok' : 'warning',
    $nbSansCout == 0 ? 'Tous les produits vendus ont un cout' : $nbSansCout.' produit(s) sans cout d\'achat -> marge surestimee',
    $nbSansCout > 0 ? 'cout_achat_auto.php' : '');

// Categories sans taux de commission
$sql = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie
        WHERE (commission_pct IS NULL OR commission_pct = 0) AND entity = ".((int)$conf->entity);
$r = $db->query($sql);
$nbCatSansComm = ($r && $o = $db->fetch_object($r)) ? (int)$o->nb : 0;
addCheck($checks, 'Donnees', 'Categories sans commission', $nbCatSansComm == 0 ? 'ok' : 'info',
    $nbCatSansComm == 0 ? 'Toutes les categories ont un taux' : $nbCatSansComm.' categorie(s) a 0% de commission',
    $nbCatSansComm > 0 ? 'categories.php' : '');

// Encodage categories
$sql = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie
        WHERE (label LIKE '%\xc3\x83%' OR label LIKE '%\xc2%') AND entity = ".((int)$conf->entity);
$r = $db->query($sql);
$nbBadEnc = ($r && $o = $db->fetch_object($r)) ? (int)$o->nb : 0;
addCheck($checks, 'Donnees', 'Encodage des categories', $nbBadEnc == 0 ? 'ok' : 'warning',
    $nbBadEnc == 0 ? 'Encodage propre' : $nbBadEnc.' categorie(s) avec double-encodage UTF-8',
    $nbBadEnc > 0 ? 'admin/admin.php' : '');

// ===== 5. STOCK (pour reassort) =====
$stockTableExists = ModuleHelper::tableExists($db, MAIN_DB_PREFIX.'product_stock');
if ($stockTableExists) {
    $sql = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."product_stock";
    $r = $db->query($sql);
    $nbStock = ($r && $o = $db->fetch_object($r)) ? (int)$o->nb : 0;
    addCheck($checks, 'Stock', 'Donnees de stock', $nbStock > 0 ? 'ok' : 'info',
        $nbStock > 0 ? $nbStock.' ligne(s) de stock (reassort operationnel)' : 'Aucun stock saisi (module Stock Dolibarr inutilise) - le reassort montrera la vitesse de vente uniquement');
}

// ===== AFFICHAGE =====
// Resume global
$nbOk = $nbWarning = $nbError = $nbInfo = 0;
foreach ($checks as $c) {
    if ($c['statut'] === 'ok') $nbOk++;
    elseif ($c['statut'] === 'warning') $nbWarning++;
    elseif ($c['statut'] === 'error') $nbError++;
    else $nbInfo++;
}

$globalStatut = $nbError > 0 ? 'error' : ($nbWarning > 0 ? 'warning' : 'ok');
$globalColor = $globalStatut === 'error' ? '#c0392b' : ($globalStatut === 'warning' ? '#e67e22' : '#27ae60');
$globalText = $globalStatut === 'error' ? 'Problemes critiques detectes' : ($globalStatut === 'warning' ? 'Quelques points a ameliorer' : 'Tout est operationnel');
$globalIcon = $globalStatut === 'error' ? '✗' : ($globalStatut === 'warning' ? '⚠' : '✓');

print '<div style="background:'.$globalColor.'15;border-left:4px solid '.$globalColor.';padding:20px;border-radius:6px;margin-bottom:24px;">';
print '<div style="font-size:24px;font-weight:bold;color:'.$globalColor.';">'.$globalIcon.' '.$globalText.'</div>';
print '<div style="font-size:13px;color:#666;margin-top:8px;">';
print $nbOk.' OK · '.$nbWarning.' avertissement(s) · '.$nbError.' erreur(s) · '.$nbInfo.' info(s)';
print '</div>';
print '</div>';

// Checks groupes par categorie
$categories = array();
foreach ($checks as $c) {
    $categories[$c['cat']][] = $c;
}

foreach ($categories as $cat => $items) {
    print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:16px;">';
    print '<h3 style="margin-top:0;">'.dol_escape_htmltag($cat).'</h3>';
    print '<table class="noborder centpercent">';
    foreach ($items as $c) {
        $icon = $c['statut'] === 'ok' ? '<span style="color:#27ae60;">✓</span>'
              : ($c['statut'] === 'warning' ? '<span style="color:#e67e22;">⚠</span>'
              : ($c['statut'] === 'error' ? '<span style="color:#c0392b;">✗</span>'
              : '<span style="color:#3498db;">ℹ</span>'));
        print '<tr class="oddeven">';
        print '<td style="width:30px;text-align:center;font-size:16px;">'.$icon.'</td>';
        print '<td style="width:30%;"><b>'.dol_escape_htmltag($c['label']).'</b></td>';
        print '<td style="font-size:13px;color:#555;">'.dol_escape_htmltag($c['detail']);
        if (!empty($c['action'])) {
            print ' <a href="'.$c['action'].'" style="margin-left:8px;font-size:12px;">→ Corriger</a>';
        }
        print '</td>';
        print '</tr>';
    }
    print '</table>';
    print '</div>';
}

print '<div style="margin-top:16px;padding:12px;background:#f9f9f9;border-radius:6px;font-size:12px;color:#666;">';
print '<b>A propos du diagnostic :</b> cette page verifie l\'integrite du module en temps reel. ';
print 'Les <span style="color:#c0392b;">erreurs</span> empechent le bon fonctionnement, les <span style="color:#e67e22;">avertissements</span> degradent la precision des chiffres, les <span style="color:#3498db;">infos</span> sont des suggestions. ';
print 'Lancez ce diagnostic apres chaque mise a jour ou si un comportement vous semble anormal.';
print '</div>';

llxFooter();
$db->close();
