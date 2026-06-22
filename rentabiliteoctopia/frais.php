<?php
/**
 * Saisie et import des frais mensuels globaux
 * Import automatique depuis les factures fournisseur Dolibarr (mapping PCG)
 */
$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';
require_once __DIR__.'/lib/OctopiaFactureImport.class.php';

if (!$user->rights->rentabiliteoctopia->write) accessforbidden();
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$annee  = GETPOST('annee',  'int') ?: date('Y');
$mois   = GETPOST('mois',   'int') ?: (int)date('m');
$action = GETPOST('action', 'alpha');

$params = rentabiliteoctopia_get_params($db);

// Récupérer le fournisseur configuré
$fkFourn  = isset($params['fk_fournisseur']) ? (int)$params['fk_fournisseur'] : 0;
$nomFourn = isset($params['nom_fournisseur']) ? $params['nom_fournisseur'] : 'Cdiscount';

// Mapping PCG personnalisé depuis les params
$mappingOverride = array();
$mappingKeys = array('pcg_abonnement','pcg_fulfilment','pcg_affranchissement','pcg_packaging','pcg_publicite');
foreach ($mappingKeys as $mk) {
    if (!empty($params[$mk])) {
        $type = str_replace('pcg_', '', $mk);
        foreach (explode(',', $params[$mk]) as $prefix) {
            $prefix = trim($prefix);
            if ($prefix) $mappingOverride[$prefix] = $type;
        }
    }
}

$importer = null;
$apercu   = null;

// -- Actions --
if ($action === 'save_frais') {
    $token = GETPOST('token', 'alpha');
    // BUGFIX CSRF: comparaison directe sans régénérer le token avant
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        $typesFreais = array('abonnement','fulfilment','affranchissement','packaging','publicite','autre');
        $ok = true;
        foreach ($typesFreais as $type) {
            $montant = (float)str_replace(',', '.', GETPOST('montant_'.$type, 'alpha'));
            $label   = GETPOST('label_'.$type, 'alpha');
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_frais
                        (annee, mois, type_frais, label, montant, entity, fk_user_creat, datec)
                    VALUES (".(int)$annee.", ".(int)$mois.", '".$db->escape($type)."',
                            '".$db->escape($label)."', ".((float)$montant).",
                            ".((int)$conf->entity).", ".((int)$user->id).", '".$db->idate(dol_now())."')
                    ON DUPLICATE KEY UPDATE montant=".((float)$montant).", label='".$db->escape($label)."', fk_user_modif=".((int)$user->id);
            if (!$db->query($sql)) $ok = false;
        }
        $ok ? setEventMessages('Frais enregistrés', null, 'mesgs') : setEventMessages('Erreur : '.$db->lasterror(), null, 'errors');
        header('Location: frais.php?mois='.$mois.'&annee='.$annee); exit;
    }
}

// BUGFIX: apercu_import nécessite aussi un token (même si lecture seule, cohérence CSRF)
if ($action === 'apercu_import' && $fkFourn > 0) {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        $importer = new OctopiaFactureImport($db, $conf->entity);
        $apercu   = $importer->importMois($annee, $mois, $fkFourn, $mappingOverride, true);
    }
}

if ($action === 'import_factures' && $fkFourn > 0) {
    $token = GETPOST('token', 'alpha');
    // BUGFIX CSRF: même correction
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        $importer = new OctopiaFactureImport($db, $conf->entity);
        $apercu   = $importer->importMois($annee, $mois, $fkFourn, $mappingOverride, false);
        if ($importer->nb_erreurs === 0) {
            setEventMessages('Import réussi — '.$importer->nb_frais_maj.' type(s) de frais mis à jour depuis les factures.', null, 'mesgs');
        } else {
            setEventMessages('Import terminé avec '.$importer->nb_erreurs.' erreur(s).', null, 'errors');
        }
    }
}

$frais = rentabiliteoctopia_get_frais($db, $annee, $mois);
$moisNoms = array(1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
                  7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre');
$labelsTypes = array(
    'abonnement'      => 'Abonnement Cdiscount',
    'fulfilment'      => 'Fulfilment Octopia',
    'affranchissement'=> 'Affranchissement / Transport',
    'packaging'       => 'Packaging / Emballages',
    'publicite'       => 'Publicité / Sponsored Products',
    'autre'           => 'Autres frais',
);

llxHeader('', 'Frais mensuels');
print load_fiche_titre('Frais mensuels — '.$moisNoms[$mois].' '.$annee, '', 'fa-file-invoice-dollar');

// Filtre période
print '<form method="GET" action="frais.php" class="form-inline" style="margin-bottom:16px;">';
print '<select name="mois" class="flat" style="margin-right:6px;">';
foreach ($moisNoms as $n => $nom) print '<option value="'.$n.'"'.($mois==$n?' selected':'').'>'.$nom.'</option>';
print '</select>';
print '<select name="annee" class="flat" style="margin-right:6px;">';
for ($y = date('Y'); $y >= date('Y')-3; $y--) print '<option value="'.$y.'"'.($annee==$y?' selected':'').'>'.$y.'</option>';
print '</select>';
print '<input type="submit" class="button" value="Filtrer">';
print '</form>';

$currentToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken();

// ---- SECTION IMPORT AUTOMATIQUE ----
print '<div style="background:var(--colorbacktitle,#f0f0f0);border-radius:4px;padding:12px 16px;margin-bottom:20px;">';
print '<b><i class="fa fa-magic"></i> Import automatique depuis les factures fournisseur</b><br><br>';

if ($fkFourn <= 0) {
    print '<div class="warning" style="padding:8px;border-radius:4px;">';
    print 'Fournisseur Octopia/Cdiscount non configuré. ';
    print '<a href="admin/admin.php">Configurer dans Paramètres</a>';
    print '</div>';
} else {
    $sql = "SELECT COUNT(*) AS nb, SUM(f.total_ht) AS ca_ht
            FROM ".MAIN_DB_PREFIX."facture_fourn f
            WHERE f.fk_soc = ".$fkFourn." AND f.entity = ".((int)$conf->entity)."
              AND f.fk_statut IN (1,2)
              AND YEAR(f.datef) = ".(int)$annee." AND MONTH(f.datef) = ".(int)$mois;
    $resql    = $db->query($sql);
    $statFact = $db->fetch_object($resql);
    $nbFact   = $statFact ? (int)$statFact->nb : 0;
    $caHT     = $statFact ? (float)$statFact->ca_ht : 0;

    if ($nbFact === 0) {
        print '<span style="color:#888">Aucune facture fournisseur validée trouvée pour '.$nomFourn.' sur '.$moisNoms[$mois].' '.$annee.'.</span>';
    } else {
        print '<span style="color:#27ae60"><b>'.$nbFact.' facture(s)</b> trouvée(s) — total HT : <b>'.roc_eur($caHT).'</b></span><br><br>';
        // Aperçu (POST avec token)
        print '<form method="POST" action="frais.php" style="display:inline;">';
        print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
        print '<input type="hidden" name="annee" value="'.$annee.'"><input type="hidden" name="mois" value="'.$mois.'">';
        print '<input type="hidden" name="action" value="apercu_import">';
        print '<button type="submit" class="button">Aperçu de l\'import</button>';
        print '</form>&nbsp;&nbsp;';
        // Import réel
        print '<form method="POST" action="frais.php" style="display:inline;">';
        print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
        print '<input type="hidden" name="annee" value="'.$annee.'"><input type="hidden" name="mois" value="'.$mois.'">';
        print '<input type="hidden" name="action" value="import_factures">';
        print '<button type="submit" class="button butAction" onclick="return confirm(\'Écraser les frais actuels avec ceux des factures ?\')">Importer et enregistrer</button>';
        print '</form>';
    }
}
print '</div>';

// ---- APERÇU / RÉSULTAT IMPORT ----
if ($apercu !== null && $importer !== null) {
    print '<br><b>'.($action === 'apercu_import' ? 'Aperçu import' : 'Résultat import').'</b><br>';
    print '<table class="noborder centpercent" style="margin-bottom:16px;">';
    print '<tr class="liste_titre"><th>Type de frais</th><th class="right">Montant HT (€)</th><th>Comptes PCG détectés</th><th>Factures</th></tr>';
    foreach ($apercu as $type => $data) {
        if (empty($data['lignes'])) continue;
        print '<tr class="oddeven">';
        print '<td><b>'.dol_escape_htmltag($labelsTypes[$type]).'</b></td>';
        print '<td class="right"><b>'.roc_eur($data['montant']).'</b></td>';
        print '<td style="font-size:12px;">';
        foreach ($data['lignes'] as $l) {
            print '<span style="color:#444">'.dol_escape_htmltag($l['compte']).'</span> ';
            print '<span style="color:#888">'.dol_escape_htmltag($l['libelle']).'</span> ('.roc_eur($l['montant']).')<br>';
        }
        print '</td>';
        print '<td style="font-size:11px;color:#888">'.dol_escape_htmltag($data['lignes'][0]['factures'] ?? '').'</td>';
        print '</tr>';
    }
    print '</table>';
    print '<details style="font-size:12px;margin-bottom:16px;"><summary style="cursor:pointer;color:#888">Logs détaillés</summary>';
    print '<div style="background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:10px;max-height:200px;overflow-y:auto;">';
    print $importer->getLogsHtml();
    print '</div></details>';
}

// ---- SAISIE MANUELLE ----
print '<form method="POST" action="frais.php">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
print '<input type="hidden" name="action" value="save_frais">';
print '<input type="hidden" name="mois" value="'.$mois.'"><input type="hidden" name="annee" value="'.$annee.'">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th style="width:26%">Type de frais</th>';
print '<th style="width:34%">Libellé (optionnel)</th>';
print '<th style="width:18%">Montant HT (€)</th>';
print '<th>Info</th>';
print '</tr>';

$infos = array(
    'abonnement'      => 'Forfait mensuel fixe — PCG 613xxx / 614xxx',
    'fulfilment'      => 'Stockage + préparation Octopia — PCG 611xxx',
    'affranchissement'=> 'Transport / port — PCG 624xxx / 625xxx',
    'packaging'       => 'Emballages, cartons — PCG 604xxx',
    'publicite'       => 'Sponsored Products, CPC — PCG 622xxx / 623xxx',
    'autre'           => 'Autres charges liées à Octopia',
);
$total = 0;
foreach ($labelsTypes as $type => $defLabel) {
    $f = isset($frais[$type]) ? $frais[$type] : array('montant'=>0,'label'=>'');
    $total += $f['montant'];
    print '<tr class="oddeven">';
    print '<td><b>'.$defLabel.'</b></td>';
    print '<td><input type="text" name="label_'.$type.'" class="flat" style="width:98%" value="'.dol_escape_htmltag($f['label']).'" placeholder="'.$defLabel.'"></td>';
    print '<td><input type="number" name="montant_'.$type.'" id="mt_'.$type.'" class="flat" style="width:100px;text-align:right" value="'.number_format($f['montant'],2,'.','').'" step="0.01" min="0" onchange="majTotal()"> €</td>';
    print '<td style="color:#888;font-size:12px">'.dol_escape_htmltag($infos[$type]).'</td>';
    print '</tr>';
}
print '<tr class="liste_titre">';
print '<td colspan="2"><b>Total frais fixes</b></td>';
print '<td id="totalFreais" style="font-weight:bold;text-align:right">'.roc_eur($total).'</td>';
print '<td></td></tr>';
print '</table>';
print '<br><input type="submit" class="button" value="Enregistrer les frais manuellement">';
print ' &nbsp; <a href="index.php?mois='.$mois.'&annee='.$annee.'" class="button">Retour au tableau de bord</a>';
print '</form>';

print '<script>
function fmtEUR(n) {
    var fixed = n.toFixed(2);
    var parts = fixed.split(".");
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, "\u00a0");
    return parts.join(",") + "\u00a0\u20ac";
}
function majTotal() {
    var total = 0;
    ["abonnement","fulfilment","affranchissement","packaging","publicite","autre"].forEach(function(t) {
        total += parseFloat(document.getElementById("mt_"+t).value)||0;
    });
    document.getElementById("totalFreais").textContent = fmtEUR(total);
}
</script>';

llxFooter();
$db->close();
