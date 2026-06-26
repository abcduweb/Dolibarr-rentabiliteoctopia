<?php
/**
 * Affectation rapide des categories aux produits
 *
 * Page d'edition en masse : tous les produits sur une seule page,
 * dropdown categorie par ligne, un seul bouton "Enregistrer tout".
 *
 * Permet aussi d'overrider le taux % par produit (sans toucher a la categorie).
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

if ($action === 'save_all') {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        $cats     = isset($_POST['fk_categorie']) && is_array($_POST['fk_categorie']) ? $_POST['fk_categorie'] : array();
        $pcts     = isset($_POST['commission_pct']) && is_array($_POST['commission_pct']) ? $_POST['commission_pct'] : array();
        $nbMaj    = 0;
        $nbErr    = 0;

        foreach ($cats as $idProduit => $fkCat) {
            $idProduit = (int)$idProduit;
            if ($idProduit <= 0) continue;

            $fkCatVal = ((int)$fkCat > 0) ? (int)$fkCat : 'NULL';
            $sql = "UPDATE ".MAIN_DB_PREFIX."rentabiliteoctopia_produit
                    SET fk_categorie = ".$fkCatVal."
                    WHERE rowid = ".$idProduit." AND entity = ".((int)$conf->entity);
            if ($db->query($sql)) $nbMaj++; else $nbErr++;
        }

        // Override % : applique a TOUS les mois de l'annee en cours pour ce produit
        // (modifie llx_rentabiliteoctopia_vente.commission_pct si une valeur est saisie,
        //  ou met a NULL si vide pour retomber sur le taux de categorie)
        $anneeFiltre = (int)date('Y');
        foreach ($pcts as $idProduit => $pctRaw) {
            $idProduit = (int)$idProduit;
            if ($idProduit <= 0) continue;
            $pctRaw = trim((string)$pctRaw);
            if ($pctRaw === '') {
                // Effacer l'override sur toutes les ventes de l'annee
                $sql = "UPDATE ".MAIN_DB_PREFIX."rentabiliteoctopia_vente
                        SET commission_pct = NULL
                        WHERE fk_produit = ".$idProduit."
                          AND annee      = ".$anneeFiltre."
                          AND entity     = ".((int)$conf->entity);
                $db->query($sql);
            } else {
                $pct = (float)str_replace(',', '.', $pctRaw);
                $sql = "UPDATE ".MAIN_DB_PREFIX."rentabiliteoctopia_vente
                        SET commission_pct = ".$pct."
                        WHERE fk_produit = ".$idProduit."
                          AND annee      = ".$anneeFiltre."
                          AND entity     = ".((int)$conf->entity);
                $db->query($sql);
            }
        }

        if ($nbErr === 0) {
            setEventMessages($nbMaj.' produit(s) mis a jour. Les % overrides s\'appliquent a toute l\'annee '.$anneeFiltre.'.', null, 'mesgs');
        } else {
            setEventMessages($nbMaj.' OK, '.$nbErr.' erreur(s).', null, 'warnings');
        }
    }
}

// Recuperer toutes les categories
$categories = rentabiliteoctopia_get_categories($db);

// Recuperer tous les produits avec leurs stats annuelles
$annee = (int)date('Y');
$sql = "SELECT
            p.rowid, p.ref, p.designation, p.fk_categorie,
            c.code AS cat_code, c.label AS cat_label, c.commission_pct AS cat_pct,
            COALESCE(SUM(v.qty_vendue), 0)            AS qty_an,
            COALESCE(SUM(v.qty_vendue * v.prix_ht), 0) AS ca_an,
            MAX(v.commission_pct)                      AS override_pct
        FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p
        LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c
            ON c.rowid = p.fk_categorie AND c.entity = ".((int)$conf->entity)."
        LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
            ON v.fk_produit = p.rowid AND v.annee = ".$annee." AND v.entity = ".((int)$conf->entity)."
        WHERE p.entity = ".((int)$conf->entity)."
        GROUP BY p.rowid, p.ref, p.designation, p.fk_categorie, c.code, c.label, c.commission_pct
        ORDER BY ca_an DESC, p.ref
        LIMIT 2000";

$resql = $db->query($sql);
$produits = array();
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $produits[] = (array)$obj;
    }
}

llxHeader('', 'Affectation rapide');
print load_fiche_titre('Affectation rapide des categories — annee '.$annee, '', 'fa-magic');

print '<div class="info" style="padding:10px;border-radius:4px;margin-bottom:16px;font-size:13px;">';
print '<b>Mode d\'emploi :</b> selectionnez une categorie pour chaque produit pour appliquer son taux automatiquement. ';
print 'L\'override % (colonne droite) prend le dessus sur le taux de categorie pour CE produit. ';
print 'Toutes les ventes de l\'annee '.$annee.' seront recalculees avec ces taux.';
print '</div>';

if (empty($categories)) {
    print '<div class="warning" style="padding:10px;border-radius:4px;">';
    print 'Aucune categorie definie. <a href="categories.php" class="butAction">Creer des categories</a> avant de commencer.';
    print '</div>';
    llxFooter(); exit;
}

$currentToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken();

print '<form method="POST" action="affectation.php">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
print '<input type="hidden" name="action" value="save_all">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>Reference</th>';
print '<th>Designation</th>';
print '<th class="right">Qte '.$annee.'</th>';
print '<th class="right">CA '.$annee.'</th>';
print '<th>Categorie</th>';
print '<th>Taux applique</th>';
print '<th>Override % (optionnel)</th>';
print '</tr>';

foreach ($produits as $p) {
    print '<tr class="oddeven">';
    print '<td><b><code>'.dol_escape_htmltag($p['ref']).'</code></b></td>';
    print '<td>'.dol_escape_htmltag($p['designation']).'</td>';
    print '<td class="right">'.(int)$p['qty_an'].'</td>';
    print '<td class="right">'.roc_eur($p['ca_an']).'</td>';

    // Dropdown categorie
    print '<td>';
    print '<select name="fk_categorie['.(int)$p['rowid'].']" class="flat" style="min-width:180px">';
    print '<option value="0">— Aucune —</option>';
    foreach ($categories as $cat) {
        $sel = ((int)$p['fk_categorie'] === (int)$cat['rowid']) ? ' selected' : '';
        print '<option value="'.(int)$cat['rowid'].'"'.$sel.'>';
        print dol_escape_htmltag($cat['label']).' ('.$cat['commission_pct'].' %)';
        print '</option>';
    }
    print '</select>';
    print '</td>';

    // Taux applique (affichage)
    print '<td>';
    if (!empty($p['override_pct']) && $p['override_pct'] !== null) {
        print '<span style="color:#2980b9"><b>'.number_format((float)$p['override_pct'], 2, ',', '').' %</b> (override)</span>';
    } elseif (!empty($p['cat_pct'])) {
        print '<span style="color:#27ae60"><b>'.number_format((float)$p['cat_pct'], 2, ',', '').' %</b> (categorie)</span>';
    } else {
        print '<span style="color:#c0392b">0 % (aucun)</span>';
    }
    print '</td>';

    // Override % par produit
    print '<td>';
    $overrideVal = !empty($p['override_pct']) ? number_format((float)$p['override_pct'], 2, '.', '') : '';
    print '<input type="number" name="commission_pct['.(int)$p['rowid'].']" ';
    print 'class="flat" style="width:70px;text-align:right" ';
    print 'step="0.01" min="0" max="50" placeholder="—" ';
    print 'value="'.$overrideVal.'"> %';
    print '</td>';

    print '</tr>';
}

print '</table>';

print '<br>';
print '<input type="submit" class="button butAction" value="Enregistrer toutes les affectations" ';
print 'onclick="return confirm(\'Appliquer ces taux a toute l\\\'annee '.$annee.' ?\')">';
print ' &nbsp; <a href="index.php" class="button">Retour au tableau de bord</a>';
print '</form>';

print '<div style="margin-top:20px;padding:10px;background:#f9f9f9;border-radius:4px;font-size:12px;color:#666">';
print '<b>Hierarchie des taux (du plus prioritaire au moins prioritaire) :</b><br>';
print '1. <code>commission_reel</code> en euros (saisi sur la page Produits & ventes pour un mois precis) — non modifie ici<br>';
print '2. <code>commission_pct</code> override sur la ligne de vente — modifie par la colonne "Override %"<br>';
print '3. <code>commission_pct</code> de la categorie — appliquee automatiquement si pas d\'override<br>';
print '4. Sinon 0 %';
print '</div>';

llxFooter();
$db->close();
