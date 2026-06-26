<?php
/**
 * Mise a jour automatique du cout d'achat depuis les factures fournisseur
 *
 * Pour chaque produit, recupere le prix d'achat HT de la derniere facture
 * fournisseur (llx_facture_fourn_det) et propose de mettre a jour le cost_price
 * de la fiche produit Dolibarr. La marge devient ainsi exacte partout.
 *
 * Modes :
 *   - Apercu : montre les ecarts entre cost_price actuel et prix facture
 *   - Application : met a jour cost_price (en masse ou selectif)
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

if (!$user->rights->rentabiliteoctopia->write) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$action = GETPOST('action', 'alpha');

// Methode de calcul du cout : derniere facture / moyenne / plus recent
$methode = GETPOST('methode', 'alpha') ?: 'derniere';

if ($action === 'apply_costs') {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        $updates = isset($_POST['new_cost']) && is_array($_POST['new_cost']) ? $_POST['new_cost'] : array();
        $nbMaj = 0;
        foreach ($updates as $productId => $newCost) {
            $productId = (int)$productId;
            $newCost = (float)str_replace(',', '.', $newCost);
            if ($productId <= 0 || $newCost <= 0) continue;

            // Mettre a jour cost_price directement
            $sql = "UPDATE ".MAIN_DB_PREFIX."product SET cost_price = ".((float)$newCost)."
                    WHERE rowid = ".$productId." AND entity IN (0,".((int)$conf->entity).")";
            if ($db->query($sql)) $nbMaj++;
        }
        setEventMessages($nbMaj.' produit(s) mis a jour avec leur cout d\'achat. La marge sera recalculee a la prochaine synchro.', null, 'mesgs');
        header('Location: cout_achat_auto.php'); exit;
    }
}

// ============================================================================
// REQUETE : derniere facture fournisseur par produit
// ============================================================================
// On recupere le prix unitaire HT d'achat le plus recent pour chaque produit lie

if ($methode === 'moyenne') {
    // Prix d'achat moyen pondere sur les 12 derniers mois
    $coutExpr = "SUM(fd.total_ht) / NULLIF(SUM(fd.qty), 0)";
    $sqlInner = "SELECT
                    fd.fk_product,
                    ".$coutExpr."                AS cout_calcule,
                    MAX(f.datef)                  AS derniere_date,
                    COUNT(DISTINCT f.rowid)       AS nb_factures,
                    SUM(fd.qty)                   AS qty_totale
                FROM ".MAIN_DB_PREFIX."facture_fourn_det fd
                INNER JOIN ".MAIN_DB_PREFIX."facture_fourn f
                    ON f.rowid = fd.fk_facture_fourn
                    AND f.entity = ".((int)$conf->entity)."
                    AND f.fk_statut >= 1
                    AND f.datef >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                WHERE fd.fk_product > 0
                GROUP BY fd.fk_product";
} else {
    // Derniere facture (prix unitaire de la ligne la plus recente)
    $sqlInner = "SELECT
                    fd.fk_product,
                    fd.pu_ht                      AS cout_calcule,
                    f.datef                       AS derniere_date,
                    1                             AS nb_factures,
                    fd.qty                        AS qty_totale
                FROM ".MAIN_DB_PREFIX."facture_fourn_det fd
                INNER JOIN ".MAIN_DB_PREFIX."facture_fourn f
                    ON f.rowid = fd.fk_facture_fourn
                    AND f.entity = ".((int)$conf->entity)."
                    AND f.fk_statut >= 1
                WHERE fd.fk_product > 0
                  AND f.datef = (
                      SELECT MAX(f2.datef)
                      FROM ".MAIN_DB_PREFIX."facture_fourn_det fd2
                      INNER JOIN ".MAIN_DB_PREFIX."facture_fourn f2 ON f2.rowid = fd2.fk_facture_fourn
                      WHERE fd2.fk_product = fd.fk_product
                        AND f2.entity = ".((int)$conf->entity)."
                        AND f2.fk_statut >= 1
                  )";
}

$sql = "SELECT
            p.rowid                          AS product_id,
            p.ref                            AS ref,
            p.label                          AS label,
            COALESCE(p.cost_price, 0)        AS cost_actuel,
            src.cout_calcule                 AS cout_facture,
            src.derniere_date                AS derniere_date,
            src.nb_factures                  AS nb_factures
        FROM ".MAIN_DB_PREFIX."product p
        INNER JOIN (".$sqlInner.") src ON src.fk_product = p.rowid
        WHERE p.entity IN (0,".((int)$conf->entity).")
          AND src.cout_calcule > 0
        ORDER BY p.ref
        LIMIT 2000";

$resql = $db->query($sql);
$produits = array();
$nbEcart = 0;
while ($resql && $o = $db->fetch_object($resql)) {
    $costActuel = (float)$o->cost_actuel;
    $coutFacture = round((float)$o->cout_facture, 4);
    $ecart = $coutFacture - $costActuel;
    $aUnEcart = abs($ecart) > 0.01;
    if ($aUnEcart) $nbEcart++;

    $produits[] = array(
        'id'           => (int)$o->product_id,
        'ref'          => $o->ref,
        'label'        => $o->label,
        'cost_actuel'  => $costActuel,
        'cout_facture' => $coutFacture,
        'ecart'        => $ecart,
        'a_ecart'      => $aUnEcart,
        'date'         => $o->derniere_date,
        'nb_factures'  => (int)$o->nb_factures,
    );
}

// ============================================================================
// AFFICHAGE
// ============================================================================
llxHeader('', 'Cout d\'achat auto');
print load_fiche_titre('Mise a jour du cout d\'achat depuis les factures fournisseur', '', 'fa-file-invoice-dollar');

$currentToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken();

// Methode
print '<form method="GET" action="cout_achat_auto.php" style="background:#f0f0f0;padding:14px;border-radius:6px;margin-bottom:20px;">';
print '<label style="font-size:13px;margin-right:10px;"><b>Methode de calcul du cout :</b></label>';
print '<select name="methode" class="flat" onchange="this.form.submit()">';
print '<option value="derniere"'.($methode==='derniere'?' selected':'').'>Derniere facture (prix le plus recent)</option>';
print '<option value="moyenne"'.($methode==='moyenne'?' selected':'').'>Prix moyen pondere (12 derniers mois)</option>';
print '</select>';
print '</form>';

// Resume
print '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:24px;">';
print '<div style="background:#fff;border-left:4px solid #3498db;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Produits avec facture</div>';
print '<div style="font-size:26px;font-weight:bold;color:#3498db;">'.count($produits).'</div>';
print '</div>';
print '<div style="background:#fff;border-left:4px solid #e67e22;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<div style="font-size:11px;color:#888;text-transform:uppercase;">Ecarts detectes</div>';
print '<div style="font-size:26px;font-weight:bold;color:#e67e22;">'.$nbEcart.'</div>';
print '<div style="font-size:11px;color:#666;">Cout fiche != cout facture</div>';
print '</div>';
print '</div>';

if (empty($produits)) {
    print '<div class="warning" style="padding:16px;border-radius:6px;">';
    print '<b>Aucun produit avec facture fournisseur liee.</b><br>';
    print 'Pour que ce module fonctionne, vos factures fournisseur Dolibarr doivent avoir des lignes rattachees a des produits (champ fk_product). ';
    print 'Verifiez que vos achats sont bien saisis avec le produit selectionne dans la ligne de facture.';
    print '</div>';
    llxFooter(); $db->close(); exit;
}

// Formulaire de mise a jour
print '<form method="POST" action="cout_achat_auto.php">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
print '<input type="hidden" name="action" value="apply_costs">';

print '<div style="background:#fff;padding:16px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th style="width:30px"><input type="checkbox" id="checkAll" onclick="document.querySelectorAll(\'input.costchk\').forEach(c=>{c.checked=this.checked;toggleRow(c);})" checked></th>';
print '<th>Reference</th>';
print '<th>Designation</th>';
print '<th class="right">Cout actuel</th>';
print '<th class="right">Cout facture</th>';
print '<th class="right">Ecart</th>';
print '<th>Derniere facture</th>';
print '<th class="right">Nouveau cout</th>';
print '</tr>';

foreach ($produits as $p) {
    $rowBg = $p['a_ecart'] ? 'background:#fffbf0;' : '';
    print '<tr class="oddeven" style="'.$rowBg.'">';
    print '<td><input type="checkbox" class="costchk" data-id="'.$p['id'].'" '.($p['a_ecart']?'checked':'').' onclick="toggleRow(this)"></td>';
    print '<td><b><code>'.dol_escape_htmltag($p['ref']).'</code></b></td>';
    print '<td>'.dol_escape_htmltag(substr($p['label'], 0, 40)).'</td>';
    print '<td class="right">'.roc_eur($p['cost_actuel']).'</td>';
    print '<td class="right"><b>'.roc_eur($p['cout_facture']).'</b></td>';

    // Ecart
    print '<td class="right">';
    if ($p['a_ecart']) {
        $ecartColor = $p['ecart'] > 0 ? '#c0392b' : '#27ae60';
        print '<b style="color:'.$ecartColor.'">'.($p['ecart']>=0?'+':'').number_format($p['ecart'],2,',','').'€</b>';
    } else {
        print '<span style="color:#27ae60;">✓ OK</span>';
    }
    print '</td>';

    print '<td style="font-size:12px;">'.dol_print_date(dol_stringtotime($p['date']), 'day');
    if ($p['nb_factures'] > 1) print ' <span style="color:#888">('.$p['nb_factures'].' fact.)</span>';
    print '</td>';

    // Nouveau cout (editable, pre-rempli avec cout facture)
    print '<td class="right">';
    print '<input type="number" name="new_cost['.$p['id'].']" value="'.number_format($p['cout_facture'],4,'.','').'" step="0.0001" min="0" class="cost-input-'.$p['id'].'" style="width:90px;text-align:right;padding:4px;border:1px solid #ddd;border-radius:3px;'.($p['a_ecart']?'':'opacity:0.5;').'">';
    print '</td>';

    print '</tr>';
}
print '</table>';

print '<br>';
print '<input type="submit" class="button butActionNew" value="Appliquer les couts coches aux fiches produit" onclick="return confirm(\'Mettre a jour le cost_price des produits selectionnes ?\')">';
print '</div>';
print '</form>';

print '<script>
function toggleRow(chk) {
    var id = chk.dataset.id;
    var input = document.querySelector(".cost-input-" + id);
    if (input) {
        input.style.opacity = chk.checked ? "1" : "0.5";
        input.disabled = !chk.checked;
        input.name = chk.checked ? "new_cost[" + id + "]" : "_ignore[" + id + "]";
    }
}
// Init etat au chargement
document.querySelectorAll("input.costchk").forEach(function(c){ toggleRow(c); });
</script>';

print '<div style="margin-top:16px;padding:12px;background:#f9f9f9;border-radius:6px;font-size:12px;color:#666;">';
print '<b>Comment ca marche :</b> le module lit le prix d\'achat HT de vos factures fournisseur Dolibarr et le compare au cout d\'achat enregistre sur la fiche produit. ';
print 'Cochez les produits a mettre a jour (par defaut, ceux avec un ecart sont coches), ajustez si besoin, puis appliquez. ';
print 'Le cout d\'achat corrige rendra votre marge exacte dans tout le module.';
print '</div>';

llxFooter();
$db->close();
