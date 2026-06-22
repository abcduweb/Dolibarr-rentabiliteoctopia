<?php
/**
 * Gestion produits + saisie ventes mensuelles v1.2
 * Commission par catégorie + possibilité montant réel ou taux override
 */
$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';
if (!$user->rights->rentabiliteoctopia->read) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$action = GETPOST('action', 'alpha');
$id     = GETPOST('id',     'int');
$annee  = GETPOST('annee',  'int') ?: date('Y');
$mois   = GETPOST('mois',   'int') ?: (int)date('m');

$params    = rentabiliteoctopia_get_params($db);
$categories = rentabiliteoctopia_get_categories($db);

// -- Actions POST --
if ($action === 'save_produit' && $user->rights->rentabiliteoctopia->write) {
    $token = GETPOST('token', 'alpha');
    if (!newToken() || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        $ref  = GETPOST('ref', 'alpha');
        $name = GETPOST('designation', 'alpha');
        $cat  = (int)GETPOST('fk_categorie', 'int');
        if ($id > 0) {
            $sql = "UPDATE ".MAIN_DB_PREFIX."rentabiliteoctopia_produit SET
                        ref='".$db->escape($ref)."', designation='".$db->escape($name)."',
                        fk_categorie=".($cat>0?$cat:'NULL')."
                    WHERE rowid=".(int)$id." AND entity=".((int)$conf->entity);
        } else {
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_produit
                        (ref, designation, fk_categorie, entity, fk_user_creat, datec)
                    VALUES ('".$db->escape($ref)."', '".$db->escape($name)."',
                            ".($cat>0?$cat:'NULL').", ".((int)$conf->entity).", ".((int)$user->id).", '".$db->idate(dol_now())."')";
        }
        $db->query($sql) ? setEventMessages('Produit enregistré', null, 'mesgs') : setEventMessages('Erreur : '.$db->lasterror(), null, 'errors');
        header('Location: produits.php?mois='.$mois.'&annee='.$annee); exit;
    }
}

if ($action === 'save_vente' && $user->rights->rentabiliteoctopia->write) {
    $token = GETPOST('token', 'alpha');
    if (!newToken() || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        $fkp    = (int)GETPOST('fk_produit', 'int');
        $qty    = (int)GETPOST('qty_vendue', 'int');
        $prix   = (float)str_replace(',', '.', GETPOST('prix_ht', 'alpha'));
        $cout   = (float)str_replace(',', '.', GETPOST('cout_achat', 'alpha'));
        $commPct  = GETPOST('commission_pct', 'alpha');
        $commReel = GETPOST('commission_reel', 'alpha');
        $commPct  = $commPct  !== '' ? (float)str_replace(',', '.', $commPct)  : null;
        $commReel = $commReel !== '' ? (float)str_replace(',', '.', $commReel) : null;

        $sqlCommPct  = $commPct  !== null ? ((float)$commPct)  : 'NULL';
        $sqlCommReel = $commReel !== null ? ((float)$commReel) : 'NULL';

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_vente
                    (fk_produit, annee, mois, qty_vendue, prix_ht, cout_achat, commission_pct, commission_reel, entity, fk_user_creat, datec)
                VALUES (".$fkp.", ".(int)$annee.", ".(int)$mois.", ".$qty.", ".$prix.", ".$cout.",
                        ".$sqlCommPct.", ".$sqlCommReel.", ".((int)$conf->entity).", ".((int)$user->id).", '".$db->idate(dol_now())."')
                ON DUPLICATE KEY UPDATE
                    qty_vendue=".$qty.", prix_ht=".$prix.", cout_achat=".$cout.",
                    commission_pct=".$sqlCommPct.", commission_reel=".$sqlCommReel.",
                    fk_user_modif=".((int)$user->id);
        $db->query($sql) ? setEventMessages('Vente enregistrée', null, 'mesgs') : setEventMessages('Erreur : '.$db->lasterror(), null, 'errors');
        header('Location: produits.php?mois='.$mois.'&annee='.$annee); exit;
    }
}

if ($action === 'delete_produit' && $user->rights->rentabiliteoctopia->write) {
    $db->query("DELETE FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_produit WHERE rowid=".(int)$id." AND entity=".((int)$conf->entity));
    header('Location: produits.php?mois='.$mois.'&annee='.$annee); exit;
}

// -- Chargement données --
$sql = "SELECT p.rowid, p.ref, p.designation, p.fk_categorie, c.label AS cat_label, c.commission_pct AS cat_pct
        FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p
        LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
        WHERE p.entity=".((int)$conf->entity)." ORDER BY p.ref";
$resql = $db->query($sql);
$produits = array();
while ($obj = $db->fetch_object($resql)) {
    $produits[] = array('rowid'=>$obj->rowid,'ref'=>$obj->ref,'designation'=>$obj->designation,
                        'fk_categorie'=>$obj->fk_categorie,'cat_label'=>$obj->cat_label,'cat_pct'=>(float)$obj->cat_pct);
}

$ventes = rentabiliteoctopia_get_ventes($db, $annee, $mois);
$ventesByProd = array();
foreach ($ventes as $v) $ventesByProd[$v['fk_produit']] = $v;

$moisNoms = array(1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
                  7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre');

llxHeader('', 'Produits & ventes');
print load_fiche_titre('Produits & saisie des ventes — '.$moisNoms[$mois].' '.$annee, '', 'fa-boxes');

// Filtre
print '<form method="GET" action="produits.php" class="form-inline" style="margin-bottom:14px;">';
print '<select name="mois" class="flat" style="margin-right:6px;">';
foreach ($moisNoms as $n => $nom) print '<option value="'.$n.'"'.($mois==$n?' selected':'').'>'.$nom.'</option>';
print '</select>';
print '<select name="annee" class="flat" style="margin-right:6px;">';
for ($y = date('Y'); $y >= date('Y')-3; $y--) print '<option value="'.$y.'"'.($annee==$y?' selected':'').'>'.$y.'</option>';
print '</select>';
print '<input type="submit" class="button" value="Filtrer">';
print '</form>';

// Formulaire ajout produit
if ($user->rights->rentabiliteoctopia->write) {
    print '<form method="POST" action="produits.php" style="margin-bottom:14px;">';
    print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken()).'">';
    print '<input type="hidden" name="action" value="save_produit"><input type="hidden" name="id" value="0">';
    print '<input type="hidden" name="mois" value="'.$mois.'"><input type="hidden" name="annee" value="'.$annee.'">';
    print '<table class="noborder"><tr class="liste_titre"><td colspan="4">Ajouter une référence</td></tr>';
    print '<tr class="oddeven"><td><input type="text" name="ref" class="flat" placeholder="Réf." style="width:120px"></td>';
    print '<td><input type="text" name="designation" class="flat" placeholder="Désignation" style="width:220px"></td>';
    print '<td><select name="fk_categorie" class="flat"><option value="0">— Catégorie —</option>';
    foreach ($categories as $cat) print '<option value="'.$cat['rowid'].'">'.dol_escape_htmltag($cat['label']).' ('.$cat['commission_pct'].'%)</option>';
    print '</select></td>';
    print '<td><input type="submit" class="button" value="Ajouter"></td></tr></table>';
    print '</form>';
}

// Tableau saisie ventes
print '<form method="POST" action="produits.php">';
print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken()).'">';
print '<input type="hidden" name="action" value="save_vente">';
print '<input type="hidden" name="mois" value="'.$mois.'"><input type="hidden" name="annee" value="'.$annee.'">';

print '<table class="noborder centpercent" style="font-size:13px;">';
print '<tr class="liste_titre">';
foreach (array('Réf.','Désignation','Catégorie','Qté','Prix HT (€)','Coût achat (€)',
               'Comm. % (override)','Comm. réel (€)','CA HT','Marge produit','Taux','') as $h) {
    print '<th>'.$h.'</th>';
}
print '</tr>';

if (empty($produits)) {
    print '<tr class="oddeven"><td colspan="12" class="center">Aucun produit. Ajoutez une référence ci-dessus.</td></tr>';
} else {
    foreach ($produits as $p) {
        $v = isset($ventesByProd[$p['rowid']]) ? $ventesByProd[$p['rowid']] : array('qty_vendue'=>0,'prix_ht'=>0,'cout_achat'=>0,'commission_pct'=>null,'commission_reel'=>null,'cat_commission_pct'=>$p['cat_pct']);
        $ca   = $v['qty_vendue'] * $v['prix_ht'];
        $comm = rentabiliteoctopia_calc_commission($v);
        $cout = $v['qty_vendue'] * $v['cout_achat'];
        $ret  = $v['qty_vendue'] * ((float)$params['taux_retour_pct']/100) * (float)$params['cout_retour'];
        $marge = $ca - $cout - $comm - $ret;
        $taux  = $ca > 0 ? round($marge/$ca*100,1) : 0;
        $color = $taux >= (float)$params['seuil_marge_pct'] ? 'green' : ($taux >= 5 ? 'orange' : 'red');
        $commPctVal  = $v['commission_pct'] !== null ? number_format($v['commission_pct'],2,'.','') : '';
        $commReelVal = $v['commission_reel'] !== null ? number_format($v['commission_reel'],2,'.','') : '';

        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag($p['ref']).'</td>';
        print '<td>'.dol_escape_htmltag($p['designation']).'</td>';
        print '<td style="font-size:11px;color:#666">'.dol_escape_htmltag($p['cat_label'] ?: '—').'<br>'.($p['cat_pct']?$p['cat_pct'].'%':'').'</td>';

        if ($user->rights->rentabiliteoctopia->write) {
            print '<td><input type="number" name="qty_vendue_'.$p['rowid'].'" value="'.(int)$v['qty_vendue'].'" min="0" class="flat" style="width:60px;text-align:right" onchange="recalc('.$p['rowid'].')"></td>';
            print '<td><input type="number" name="prix_ht_'.$p['rowid'].'" value="'.number_format((float)$v['prix_ht'],2,'.','').'" step="0.01" class="flat" style="width:70px;text-align:right" onchange="recalc('.$p['rowid'].')"></td>';
            print '<td><input type="number" name="cout_achat_'.$p['rowid'].'" value="'.number_format((float)$v['cout_achat'],2,'.','').'" step="0.01" class="flat" style="width:70px;text-align:right" onchange="recalc('.$p['rowid'].')"></td>';
            print '<td><input type="number" name="commission_pct_'.$p['rowid'].'" value="'.$commPctVal.'" step="0.01" min="0" max="100" class="flat" style="width:60px;text-align:right" placeholder="'.$p['cat_pct'].'" onchange="recalc('.$p['rowid'].')"> %</td>';
            print '<td><input type="number" name="commission_reel_'.$p['rowid'].'" value="'.$commReelVal.'" step="0.01" min="0" class="flat" style="width:75px;text-align:right" placeholder="—" onchange="recalc('.$p['rowid'].')"> €</td>';
        } else {
            print '<td class="right">'.(int)$v['qty_vendue'].'</td>';
            print '<td class="right">'.price($v['prix_ht']).'</td>';
            print '<td class="right">'.price($v['cout_achat']).'</td>';
            print '<td class="right">'.($commPctVal ?: $p['cat_pct']).'%</td>';
            print '<td class="right">'.($commReelVal ? price($commReelVal).' €' : '—').'</td>';
        }
        print '<td class="right" id="ca_'.$p['rowid'].'">'.price($ca).'</td>';
        print '<td class="right" id="marge_'.$p['rowid'].'" style="font-weight:bold;color:'.$color.'">'.price($marge).'</td>';
        print '<td class="right" id="taux_'.$p['rowid'].'" style="color:'.$color.'">'.$taux.'%</td>';
        print '<td>';
        if ($user->rights->rentabiliteoctopia->write) {
            print '<button type="button" class="button smallpaddingimp" onclick="saveVente('.$p['rowid'].')">Enreg.</button> ';
            print '<a href="produits.php?action=delete_produit&id='.$p['rowid'].'&mois='.$mois.'&annee='.$annee.'" class="butActionDelete smallpaddingimp" onclick="return confirm(\'Supprimer ?\')">×</a>';
        }
        print '</td>';
        print '</tr>';
    }
}
print '</table></form>';

// JS recalcul inline
$seuilMarge = (float)$params['seuil_marge_pct'];
$tauxRet    = (float)$params['taux_retour_pct'] / 100;
$coutRet    = (float)$params['cout_retour'];

// Tableau des taux par produit pour le JS
$catPcts = array();
foreach ($produits as $p) $catPcts[$p['rowid']] = $p['cat_pct'];

print '<script>
var SEUIL = '.$seuilMarge.', TRET = '.$tauxRet.', CRET = '.$coutRet.';
var CAT_PCTS = '.json_encode($catPcts).';

function recalc(id) {
    var qty   = parseFloat(document.querySelector("[name=qty_vendue_"+id+"]").value)||0;
    var prix  = parseFloat(document.querySelector("[name=prix_ht_"+id+"]").value)||0;
    var cout  = parseFloat(document.querySelector("[name=cout_achat_"+id+"]").value)||0;
    var cPct  = document.querySelector("[name=commission_pct_"+id+"]").value;
    var cReel = document.querySelector("[name=commission_reel_"+id+"]").value;
    var ca    = qty * prix;
    var comm;
    if (cReel !== "" && parseFloat(cReel) > 0) {
        comm = parseFloat(cReel);
    } else {
        var pct = cPct !== "" ? parseFloat(cPct) : (CAT_PCTS[id]||15);
        comm = ca * pct / 100;
    }
    var retour = qty * TRET * CRET;
    var marge  = ca - qty*cout - comm - retour;
    var taux   = ca > 0 ? (marge/ca*100) : 0;
    var color  = taux >= SEUIL ? "green" : (taux >= 5 ? "orange" : "red");
    document.getElementById("ca_"+id).textContent    = ca.toFixed(2) + " \u20ac";
    document.getElementById("marge_"+id).textContent = marge.toFixed(2) + " \u20ac";
    document.getElementById("marge_"+id).style.color = color;
    document.getElementById("taux_"+id).textContent  = taux.toFixed(1) + "%";
    document.getElementById("taux_"+id).style.color  = color;
}

function saveVente(id) {
    var form = document.createElement("form");
    form.method = "POST"; form.action = "produits.php";
    var fields = {
        token:            document.querySelector("[name=token]").value,
        action:           "save_vente",
        fk_produit:       id,
        mois:             '.$mois.',
        annee:            '.$annee.',
        qty_vendue:       document.querySelector("[name=qty_vendue_"+id+"]").value,
        prix_ht:          document.querySelector("[name=prix_ht_"+id+"]").value,
        cout_achat:       document.querySelector("[name=cout_achat_"+id+"]").value,
        commission_pct:   document.querySelector("[name=commission_pct_"+id+"]").value,
        commission_reel:  document.querySelector("[name=commission_reel_"+id+"]").value
    };
    for (var k in fields) {
        var i = document.createElement("input");
        i.type="hidden"; i.name=k; i.value=fields[k];
        form.appendChild(i);
    }
    document.body.appendChild(form);
    form.submit();
}
</script>';

llxFooter();
$db->close();
