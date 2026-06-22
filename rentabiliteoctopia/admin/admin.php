<?php
/**
 * Page d'administration - Paramètres du module rentabiliteoctopia
 */
$res = 0;
if (!$res && file_exists('../../main.inc.php'))     $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php'))  $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/../lib/rentabiliteoctopia.lib.php';
require_once __DIR__.'/../lib/OctopiaFactureImport.class.php';

if (!$user->rights->rentabiliteoctopia->write) accessforbidden();
// BUGFIX: était 'rentabiliteocternity' (typo) — corrigé en 'rentabiliteoctopia'
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$action = GETPOST('action', 'alpha');

if ($action === 'save') {
    $token = GETPOST('token', 'alpha');
    // BUGFIX CSRF: on compare le token soumis avec $_SESSION['newtoken'] AVANT
    // tout appel à newToken() qui régénèrerait la valeur en session.
    // L'ancien code appelait newToken() dans la condition, écrasant le token
    // de session AVANT la comparaison → vérification toujours fausse.
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        // Régénérer le token après usage (bonne pratique)
        newToken();
        $keys = array(
            'seuil_marge_pct', 'taux_retour_pct', 'cout_retour',
            'nom_fournisseur',
            'pcg_abonnement', 'pcg_fulfilment', 'pcg_affranchissement', 'pcg_packaging', 'pcg_publicite',
        );
        $ok = true;
        foreach ($keys as $key) {
            $val = GETPOST($key, 'alpha');
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_params (param_key, param_value, entity)
                    VALUES ('".$db->escape($key)."', '".$db->escape($val)."', ".((int)$conf->entity).")
                    ON DUPLICATE KEY UPDATE param_value = '".$db->escape($val)."'";
            if (!$db->query($sql)) $ok = false;
        }

        // Résolution automatique du fournisseur par nom
        $nomFourn = GETPOST('nom_fournisseur', 'alpha');
        if ($nomFourn) {
            $importer = new OctopiaFactureImport($db, $conf->entity);
            $fkFourn  = $importer->findFournisseur($nomFourn);
            if ($fkFourn) {
                $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_params (param_key, param_value, entity)
                        VALUES ('fk_fournisseur', '".(int)$fkFourn."', ".((int)$conf->entity).")
                        ON DUPLICATE KEY UPDATE param_value = '".(int)$fkFourn."'";
                $db->query($sql);
            }
        }

        $ok ? setEventMessages('Paramètres enregistrés', null, 'mesgs') : setEventMessages('Erreur : '.$db->lasterror(), null, 'errors');
    }
}

$params = rentabiliteoctopia_get_params($db);

// Récupérer le nom du fournisseur résolu
$nomFournResolu = '';
if (!empty($params['fk_fournisseur'])) {
    $sql = "SELECT nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".((int)$params['fk_fournisseur']);
    $resql = $db->query($sql);
    if ($resql && $obj = $db->fetch_object($resql)) $nomFournResolu = $obj->nom;
}

llxHeader('', 'Paramètres - Rentabilité Octopia');
print load_fiche_titre('Paramètres du module', '', 'fa-cog');

print '<form method="POST" action="admin.php">';
print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken()).'">';
print '<input type="hidden" name="action" value="save">';

// ---- Paramètres généraux ----
print '<table class="noborder centpercent" style="margin-bottom:20px;">';
print '<tr class="liste_titre"><th colspan="3">Paramètres généraux</th></tr>';
$generaux = array(
    array('seuil_marge_pct', 'Seuil de marge cible (%)', '%',  'Alerte si taux de marge produit inférieur à ce seuil'),
    array('taux_retour_pct', 'Taux de retour estimé (%)', '%', 'Pourcentage de commandes retournées (coût inclus dans la marge produit)'),
    array('cout_retour',     'Coût unitaire d\'un retour', '€','Coût moyen pris en charge par retour'),
);
foreach ($generaux as $g) {
    print '<tr class="oddeven"><td class="fieldrequired" style="width:28%">'.$g[1].'</td>';
    print '<td style="width:18%"><input type="number" name="'.$g[0].'" value="'.dol_escape_htmltag($params[$g[0]]).'" step="0.01" min="0" class="flat" style="width:90px"> '.$g[2].'</td>';
    print '<td style="color:#888;font-size:12px">'.$g[3].'</td></tr>';
}
print '</table>';

// ---- Fournisseur Octopia ----
print '<table class="noborder centpercent" style="margin-bottom:20px;">';
print '<tr class="liste_titre"><th colspan="3">Fournisseur Octopia / Cdiscount</th></tr>';
print '<tr class="oddeven">';
print '<td class="fieldrequired" style="width:28%">Nom du fournisseur</td>';
print '<td style="width:40%"><input type="text" name="nom_fournisseur" class="flat" style="width:220px" value="'.dol_escape_htmltag($params['nom_fournisseur'] ?? 'Cdiscount').'" placeholder="Cdiscount"></td>';
print '<td style="font-size:12px;color:#888">Nom ou fragment du nom tel que saisi dans la fiche fournisseur Dolibarr';
if ($nomFournResolu) print ' — <span style="color:green">Résolu : <b>'.dol_escape_htmltag($nomFournResolu).'</b> (id='.(int)$params['fk_fournisseur'].')</span>';
else print ' — <span style="color:orange">Non résolu. Enregistrez pour rechercher.</span>';
print '</td></tr>';
print '</table>';

// ---- Mapping PCG ----
print '<table class="noborder centpercent" style="margin-bottom:20px;">';
print '<tr class="liste_titre"><th colspan="3">Mapping comptes PCG → types de frais</th></tr>';
print '<tr><td colspan="3" style="padding:8px;font-size:12px;color:#666;">';
print 'Saisissez les <b>préfixes de comptes PCG</b> séparés par des virgules pour chaque type de frais. ';
print 'Le matching se fait du plus long au plus court (ex: "6132" est prioritaire sur "613"). ';
print 'Laissez vide pour utiliser le mapping par défaut.';
print '</td></tr>';

$mappingDefaut = array(
    'pcg_abonnement'      => array('label'=>'Abonnement',          'defaut'=>'613, 614',         'ex'=>'ex: 6132, 614'),
    'pcg_fulfilment'      => array('label'=>'Fulfilment',           'defaut'=>'611',              'ex'=>'ex: 611, 6119'),
    'pcg_affranchissement'=> array('label'=>'Affranchissement',     'defaut'=>'624, 625, 6241',   'ex'=>'ex: 6241, 625'),
    'pcg_packaging'       => array('label'=>'Packaging',            'defaut'=>'604, 6044',        'ex'=>'ex: 6044, 604'),
    'pcg_publicite'       => array('label'=>'Publicité / Sponsored','defaut'=>'622, 623, 6231',   'ex'=>'ex: 6231, 623'),
);
foreach ($mappingDefaut as $key => $info) {
    $val = isset($params[$key]) && $params[$key] !== '' ? $params[$key] : '';
    print '<tr class="oddeven">';
    print '<td style="width:28%"><b>'.$info['label'].'</b><br><span style="font-size:11px;color:#888">Défaut : '.$info['defaut'].'</span></td>';
    print '<td style="width:40%"><input type="text" name="'.$key.'" class="flat" style="width:280px" value="'.dol_escape_htmltag($val).'" placeholder="'.$info['defaut'].'"></td>';
    print '<td style="font-size:12px;color:#888">'.$info['ex'].'</td>';
    print '</tr>';
}
print '</table>';

print '<input type="submit" class="button" value="Enregistrer les paramètres">';
print '</form>';

// ---- Mapping par défaut affiché ----
print '<br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="3">Mapping PCG actif (référence)</th></tr>';
print '<tr class="liste_titre"><th>Préfixe PCG</th><th>Type de frais</th><th>Description</th></tr>';
$mappingRef = array(
    '613x / 614x'  => array('abonnement',      'Locations, abonnements logiciels'),
    '611x / 6119x' => array('fulfilment',       'Sous-traitance, prestations logistiques'),
    '6241x / 624x' => array('affranchissement', 'Transports, ports et frais postaux'),
    '625x'         => array('affranchissement', 'Déplacements et transport'),
    '6044x / 604x' => array('packaging',        'Fournitures consommables, emballages'),
    '623x / 622x'  => array('publicite',        'Publicité, relations publiques'),
    'Autres'        => array('autre',            'Tout compte non matché ci-dessus'),
);
foreach ($mappingRef as $pcg => $info) {
    print '<tr class="oddeven"><td><code>'.$pcg.'</code></td><td>'.$info[0].'</td><td style="color:#888;font-size:12px">'.$info[1].'</td></tr>';
}
print '</table>';

llxFooter();
$db->close();
