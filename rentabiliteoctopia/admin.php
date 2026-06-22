<?php
/**
 * Page d'administration - Paramètres de coûts Octopia
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';

if (!$user->rights->rentabiliteoctopia->write) accessforbidden();

$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$action = GETPOST('action', 'alpha');

if ($action === 'save' && $user->rights->rentabiliteoctopia->write) {
    $token = GETPOST('token', 'alpha');
    if (!newToken() || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        $paramsKeys = array('commission_pct', 'abonnement_mois', 'expedition_moy', 'retour_cout', 'taux_retour_pct', 'seuil_marge_pct');
        $ok = true;
        foreach ($paramsKeys as $key) {
            $val = str_replace(',', '.', GETPOST($key, 'alpha'));
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_params (param_key, param_value, entity)
                    VALUES ('".$db->escape($key)."', '".$db->escape($val)."', ".((int)$conf->entity).")
                    ON DUPLICATE KEY UPDATE param_value = '".$db->escape($val)."'";
            if (!$db->query($sql)) { $ok = false; }
        }
        if ($ok) {
            setEventMessages('Paramètres enregistrés', null, 'mesgs');
        } else {
            setEventMessages('Erreur : '.$db->lasterror(), null, 'errors');
        }
    }
}

$params = rentabiliteoctopia_get_params($db);

llxHeader('', 'Rentabilité Octopia - Paramètres');
print load_fiche_titre('Paramètres de coûts Octopia', '', 'fa-cog');

print '<form method="POST" action="admin.php">';
print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken()).'">';
print '<input type="hidden" name="action" value="save">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th style="width:30%">Paramètre</th><th style="width:20%">Valeur</th><th>Description</th></tr>';

$lignes = array(
    array('key'=>'commission_pct',   'label'=>'Commission Octopia (%)',     'unit'=>'%',     'desc'=>'Pourcentage du prix de vente HT prélevé par Cdiscount/Octopia'),
    array('key'=>'abonnement_mois',  'label'=>'Abonnement mensuel (€)',     'unit'=>'€',     'desc'=>'Coût fixe de l\'abonnement vendeur Cdiscount par mois'),
    array('key'=>'expedition_moy',   'label'=>'Frais expédition moyens (€)','unit'=>'€/colis','desc'=>'Coût moyen de transport par commande expédiée'),
    array('key'=>'retour_cout',      'label'=>'Coût moyen d\'un retour (€)','unit'=>'€',     'desc'=>'Coût moyen pris en charge pour chaque retour produit'),
    array('key'=>'taux_retour_pct',  'label'=>'Taux de retour (%)',          'unit'=>'%',     'desc'=>'Pourcentage estimé des commandes retournées'),
    array('key'=>'seuil_marge_pct',  'label'=>'Seuil de marge cible (%)',   'unit'=>'%',     'desc'=>'En dessous de ce seuil, le produit est signalé comme non rentable'),
);

foreach ($lignes as $l) {
    print '<tr class="oddeven">';
    print '<td class="fieldrequired">'.$l['label'].'</td>';
    print '<td><input type="number" name="'.$l['key'].'" value="'.dol_escape_htmltag($params[$l['key']]).'" step="0.01" min="0" class="flat" style="width:90px"> '.$l['unit'].'</td>';
    print '<td style="color:#888">'.$l['desc'].'</td>';
    print '</tr>';
}

print '</table>';
print '<br>';
print '<input type="submit" class="button" value="Enregistrer les paramètres">';
print '</form>';

// Section info SQL
print '<br><br>';
print '<div class="info" style="padding:10px;border-radius:4px;">';
print '<b>Installation des tables SQL</b><br>';
print 'Si les tables ne sont pas encore créées, exécutez le script suivant dans votre base de données MariaDB :<br>';
print '<code style="display:block;margin-top:6px;padding:8px;background:#f5f5f5;border-radius:4px;font-size:12px;">';
print dol_escape_htmltag(DOL_DOCUMENT_ROOT.'/../htdocs/rentabiliteoctopia/sql/rentabiliteoctopia.sql');
print '</code>';
print 'Ou activez le module depuis <b>Accueil &gt; Configuration &gt; Modules</b> pour l\'installation automatique.';
print '</div>';

llxFooter();
$db->close();
