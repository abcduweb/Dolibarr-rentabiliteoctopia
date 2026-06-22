<?php
/**
 * Gestion des catégories produits et taux de commission par catégorie
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
$id     = GETPOST('id', 'int');

if ($action === 'save_cat') {
    $token = GETPOST('token', 'alpha');
    // BUGFIX CSRF: comparaison directe sans appeler newToken() avant
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        $code   = strtoupper(preg_replace('/[^A-Z0-9_]/i', '_', GETPOST('code', 'alpha')));
        $label  = GETPOST('label', 'alpha');
        $pct    = (float)str_replace(',', '.', GETPOST('commission_pct', 'alpha'));
        if ($id > 0) {
            $sql = "UPDATE ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie SET
                        code='".$db->escape($code)."', label='".$db->escape($label)."', commission_pct=".((float)$pct)."
                    WHERE rowid=".(int)$id." AND entity=".((int)$conf->entity);
        } else {
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie (code, label, commission_pct, entity, datec)
                    VALUES ('".$db->escape($code)."', '".$db->escape($label)."', ".((float)$pct).", ".((int)$conf->entity).", '".$db->idate(dol_now())."')";
        }
        $db->query($sql) ? setEventMessages('Catégorie enregistrée', null, 'mesgs') : setEventMessages('Erreur : '.$db->lasterror(), null, 'errors');
        header('Location: categories.php'); exit;
    }
}

if ($action === 'delete' && $id > 0) {
    // BUGFIX CSRF: la suppression via GET sans token est une faille CSRF.
    // On exige maintenant un token POST pour toute suppression.
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide — suppression refusée', null, 'errors');
    } else {
        newToken();
        $db->query("UPDATE ".MAIN_DB_PREFIX."rentabiliteoctopia_produit SET fk_categorie=NULL WHERE fk_categorie=".(int)$id." AND entity=".((int)$conf->entity));
        $db->query("DELETE FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie WHERE rowid=".(int)$id." AND entity=".((int)$conf->entity));
        setEventMessages('Catégorie supprimée', null, 'mesgs');
        header('Location: categories.php'); exit;
    }
}

$categories = rentabiliteoctopia_get_categories($db);

llxHeader('', 'Catégories produits');
print load_fiche_titre('Catégories & taux de commission Cdiscount', '', 'fa-tags');

$currentToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken();

// Formulaire ajout/édition
$editCat = $id > 0 && isset($categories[$id]) ? $categories[$id] : null;
print '<form method="POST" action="categories.php" style="margin-bottom:16px;">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
print '<input type="hidden" name="action" value="save_cat">';
print '<input type="hidden" name="id" value="'.($editCat ? $editCat['rowid'] : 0).'">';
print '<table class="noborder"><tr class="liste_titre"><td colspan="4">'.($editCat ? 'Modifier' : 'Ajouter').' une catégorie</td></tr>';
print '<tr class="oddeven">';
print '<td><input type="text" name="code" class="flat" placeholder="CODE (ex: INFORMATIQUE)" style="width:160px" value="'.($editCat?dol_escape_htmltag($editCat['code']):'').'"></td>';
print '<td><input type="text" name="label" class="flat" placeholder="Libellé catégorie" style="width:240px" value="'.($editCat?dol_escape_htmltag($editCat['label']):'').'"></td>';
print '<td><input type="number" name="commission_pct" class="flat" placeholder="%" step="0.01" min="0" max="50" style="width:70px;text-align:right" value="'.($editCat?$editCat['commission_pct']:'').'"> %</td>';
print '<td><input type="submit" class="button" value="'.($editCat?'Modifier':'Ajouter').'"></td>';
print '</tr></table></form>';

// Liste catégories
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Code</th><th>Libellé</th><th class="right">Commission Cdiscount</th><th>Nb produits</th><th></th></tr>';

// Compter nb produits par catégorie
$nbProds = array();
$sql = "SELECT fk_categorie, COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_produit WHERE entity=".((int)$conf->entity)." GROUP BY fk_categorie";
$resql = $db->query($sql);
while ($obj = $db->fetch_object($resql)) $nbProds[$obj->fk_categorie] = (int)$obj->nb;

foreach ($categories as $cat) {
    print '<tr class="oddeven">';
    print '<td><code>'.dol_escape_htmltag($cat['code']).'</code></td>';
    print '<td>'.dol_escape_htmltag($cat['label']).'</td>';
    print '<td class="right"><b>'.$cat['commission_pct'].' %</b></td>';
    print '<td>'.(isset($nbProds[$cat['rowid']]) ? $nbProds[$cat['rowid']] : 0).' produit(s)</td>';
    print '<td class="right">';
    print '<a href="categories.php?id='.$cat['rowid'].'" class="editfielda">'.img_edit().'</a> ';
    if (empty($nbProds[$cat['rowid']])) {
        // BUGFIX CSRF: suppression via formulaire POST avec token (plus via GET)
        print '<form method="POST" action="categories.php" style="display:inline">';
        print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
        print '<input type="hidden" name="action" value="delete">';
        print '<input type="hidden" name="id" value="'.$cat['rowid'].'">';
        print '<button type="submit" class="butActionDelete smallpaddingimp" onclick="return confirm(\'Supprimer ?\')">×</button>';
        print '</form>';
    }
    print '</td>';
    print '</tr>';
}
print '</table>';

print '<br><div class="info" style="padding:8px;border-radius:4px;font-size:12px;">';
print 'Les taux de commission sont ceux pratiqués par Cdiscount/Octopia. Ils varient selon la catégorie et le type de produit. ';
print 'Consultez votre espace vendeur Octopia pour les taux exacts applicables à vos produits. ';
print 'Vous pouvez également saisir le montant réel de commission par produit directement dans la page Produits.';
print '</div>';

llxFooter();
$db->close();
