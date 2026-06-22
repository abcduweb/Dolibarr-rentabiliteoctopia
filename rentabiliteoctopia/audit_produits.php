<?php
/**
 * Audit produits : compare les references vendues sur Octopia vs les produits Dolibarr existants
 *
 * Trois sections :
 *  1. Produits Octopia SANS fiche Dolibarr (a creer ou a mapper)
 *  2. Mapping manuel d'une ref Octopia vers un produit Dolibarr existant
 *  3. Creation en masse de produits Dolibarr depuis les ventes Octopia orphelines
 *
 * Le mapping est stocke dans la table llx_rentabiliteoctopia_mapping_ref :
 *   ref_octopia -> fk_product_dolibarr
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
$langs->load('products');

$action = GETPOST('action', 'alpha');
$annee  = GETPOST('annee', 'int') ?: (int)date('Y');
$mois   = GETPOST('mois',  'int') ?: 0; // 0 = tout

// Assurer existence de la table de mapping
$db->query("CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."rentabiliteoctopia_mapping_ref (
    rowid                INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    ref_octopia          VARCHAR(64)      NOT NULL,
    fk_product_dolibarr  INT(11)          NOT NULL,
    entity               INT(11)          NOT NULL DEFAULT 1,
    datec                DATETIME,
    PRIMARY KEY (rowid),
    UNIQUE KEY uk_ref_entity (ref_octopia, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

// =====================================================================
// ACTIONS
// =====================================================================
$createdCount = 0;
$mappedCount  = 0;

if ($action === 'create_bulk') {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        $refs = GETPOST('refs_to_create', 'array');
        if (!is_array($refs)) $refs = array();

        foreach ($refs as $ref) {
            $ref = trim((string)$ref);
            if (!$ref) continue;

            // Recuperer label et prix moyen depuis les commandes Octopia
            // Si ref commence par "LIBRE: ", c'est un produit non lie - matcher sur le label
            $sqlMatch = '';
            if (strpos($ref, 'LIBRE: ') === 0) {
                $labelToMatch = substr($ref, strlen('LIBRE: '));
                $sqlMatch = "AND cd.fk_product IS NULL AND LEFT(COALESCE(cd.label, cd.description, ''), 80) = '".$db->escape($labelToMatch)."'";
            } else {
                // Sinon matcher via le produit Dolibarr existant (cas rare ou un produit existe mais n'est pas lie dans la cmd)
                $sqlMatch = "AND p.ref = '".$db->escape($ref)."'";
            }
            $sql = "SELECT
                        MAX(COALESCE(cd.label, cd.description, p.label, ''))  AS label,
                        AVG(cd.subprice)                                       AS prix_moyen,
                        SUM(cd.qty)                                            AS qty_totale
                    FROM ".MAIN_DB_PREFIX."octopia_orders o
                    INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = o.dolibarr_order_id
                    INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
                    LEFT  JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product
                    WHERE o.entity = ".((int)$conf->entity)."
                      AND o.is_refunded = 0
                      ".$sqlMatch;
            $resql = $db->query($sql);
            if (!$resql || !($obj = $db->fetch_object($resql))) continue;

            $label    = $obj->label ?: $ref;
            $prixVent = round((float)$obj->prix_moyen, 2);

            // Verifier que la ref n'existe pas deja
            $sql_check = "SELECT rowid FROM ".MAIN_DB_PREFIX."product
                          WHERE ref = '".$db->escape($ref)."' AND entity IN (0, ".((int)$conf->entity).")";
            $rc = $db->query($sql_check);
            if ($rc && $db->fetch_object($rc)) continue;

            // Creer le produit
            // Generer une ref Dolibarr propre si la ref Octopia est un libelle libre
            $refDolibarr = $ref;
            if (strpos($ref, 'LIBRE: ') === 0) {
                // Generer une ref a partir du label (slug majuscules + chiffres)
                $base = preg_replace('/[^A-Z0-9]/i', '', strtoupper($label));
                $refDolibarr = 'OCTOPIA-'.substr($base, 0, 20).'-'.substr(md5($ref), 0, 6);
            }

            $product = new Product($db);
            $product->ref         = $refDolibarr;
            $product->label       = $label;
            $product->description = "Produit cree automatiquement depuis les ventes Octopia (audit_produits.php le ".dol_print_date(dol_now(), 'dayhour').")";
            $product->type        = 0; // produit (1 = service)
            $product->price_base_type = 'HT';
            $product->price       = $prixVent;
            $product->status      = 1; // a vendre
            $product->status_buy  = 0;
            $product->finished    = 1;
            $product->entity      = (int)$conf->entity;

            $newId = $product->create($user);
            if ($newId > 0) {
                $createdCount++;

                // Auto-mapper aussi dans la table de mapping
                $sqlMap = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_mapping_ref
                             (ref_octopia, fk_product_dolibarr, entity, datec)
                           VALUES ('".$db->escape($ref)."', ".(int)$newId.", ".(int)$conf->entity.", '".$db->idate(dol_now())."')
                           ON DUPLICATE KEY UPDATE fk_product_dolibarr = ".(int)$newId;
                $db->query($sqlMap);
            }
        }

        if ($createdCount > 0) {
            setEventMessages($createdCount.' produit(s) cree(s) dans Dolibarr.', null, 'mesgs');
        } else {
            setEventMessages('Aucun produit cree.', null, 'warnings');
        }
    }
}

if ($action === 'save_mapping') {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        $mappings = GETPOST('mapping', 'array');
        if (!is_array($mappings)) $mappings = array();

        foreach ($mappings as $refOctopia => $fkProduct) {
            $refOctopia = trim((string)$refOctopia);
            $fkProduct  = (int)$fkProduct;
            if (!$refOctopia || $fkProduct <= 0) continue;

            $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_mapping_ref
                       (ref_octopia, fk_product_dolibarr, entity, datec)
                    VALUES ('".$db->escape($refOctopia)."', ".$fkProduct.", ".(int)$conf->entity.", '".$db->idate(dol_now())."')
                    ON DUPLICATE KEY UPDATE fk_product_dolibarr = ".$fkProduct;
            if ($db->query($sql)) $mappedCount++;
        }

        setEventMessages($mappedCount.' mapping(s) enregistre(s).', null, 'mesgs');
    }
}

if ($action === 'delete_mapping' && GETPOST('id', 'int') > 0) {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        $db->query("DELETE FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_mapping_ref
                    WHERE rowid = ".(int)GETPOST('id', 'int')."
                      AND entity = ".((int)$conf->entity));
        setEventMessages('Mapping supprime.', null, 'mesgs');
        header('Location: audit_produits.php'); exit;
    }
}

// =====================================================================
// REQUETE PRINCIPALE : produits Octopia orphelins
// =====================================================================
$filtreMois = $mois > 0 ? "AND MONTH(c.date_commande) = ".(int)$mois : "";

// BUGFIX: llx_commandedet n'a pas de champ 'ref'. La reference produit
// vient de llx_product via fk_product. Pour les lignes libres (sans fk_product),
// on utilise cd.label (ou cd.description en fallback) comme identifiant.
// La cle de groupement est: ref produit si fk_product>0, sinon label.
$sql = "SELECT
            COALESCE(p.ref, CONCAT('LIBRE: ', LEFT(COALESCE(cd.label, cd.description, ''), 80))) AS ref_octopia,
            MAX(COALESCE(p.label, cd.label, cd.description, ''))   AS label,
            cd.fk_product                                          AS fk_product_existant,
            p.ref                                                  AS ref_dolibarr_existante,
            SUM(cd.qty)                                            AS qty_totale,
            SUM(cd.qty * cd.subprice)                              AS ca_total,
            AVG(cd.subprice)                                       AS prix_moyen,
            COUNT(DISTINCT o.rowid)                                AS nb_commandes,
            MAX(c.date_commande)                                   AS derniere_vente,
            mr.fk_product_dolibarr                                 AS fk_product_mapping,
            pm.ref                                                 AS ref_via_mapping
        FROM ".MAIN_DB_PREFIX."octopia_orders o
        INNER JOIN ".MAIN_DB_PREFIX."commande c
            ON  c.rowid  = o.dolibarr_order_id
            AND c.entity = ".((int)$conf->entity)."
            AND YEAR(c.date_commande) = ".(int)$annee."
            ".$filtreMois."
            AND c.fk_statut >= 1
        INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
        LEFT  JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product
        LEFT  JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_mapping_ref mr
            ON  mr.ref_octopia = COALESCE(p.ref, LEFT(COALESCE(cd.label, cd.description, ''), 64))
            AND mr.entity      = ".((int)$conf->entity)."
        LEFT  JOIN ".MAIN_DB_PREFIX."product pm ON pm.rowid = mr.fk_product_dolibarr
        WHERE o.entity = ".((int)$conf->entity)."
          AND o.is_refunded = 0
          AND (o.octopia_order_status IS NULL OR o.octopia_order_status NOT IN ('CANCELLED','REFUNDED','REFUSED','CANCELED'))
          -- Exclure les lignes sans fk_product (frais de port Octopia, remises, etc.)
          -- Ces lignes sont comptabilisees dans les frais mensuels via OctopiaShippingImport
          AND cd.fk_product IS NOT NULL
          AND cd.fk_product > 0
        GROUP BY COALESCE(p.ref, CONCAT('LIBRE: ', LEFT(COALESCE(cd.label, cd.description, ''), 80)))
        ORDER BY ca_total DESC";

$resql = $db->query($sql);
$lignes = array();
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        // Determiner le statut
        if ($obj->fk_product_existant && $obj->fk_product_existant > 0) {
            $statut = 'ok'; // produit Dolibarr lie dans la commande
        } elseif ($obj->fk_product_mapping && $obj->fk_product_mapping > 0) {
            $statut = 'mappe'; // mapping table existant
        } else {
            $statut = 'orphelin'; // a creer ou mapper
        }
        $lignes[] = array(
            'ref'           => $obj->ref_octopia,
            'label'         => $obj->label,
            'qty'           => (int)$obj->qty_totale,
            'ca'            => (float)$obj->ca_total,
            'prix_moyen'    => round((float)$obj->prix_moyen, 2),
            'nb_cmd'        => (int)$obj->nb_commandes,
            'derniere'      => $obj->derniere_vente,
            'statut'        => $statut,
            'ref_doli'      => $obj->ref_dolibarr_existante ?: $obj->ref_via_mapping,
            'fk_product'    => $obj->fk_product_existant ?: $obj->fk_product_mapping,
        );
    }
}

// DEBUG : afficher le SQL et le nombre de lignes brutes (active avec ?debug=1)
if (GETPOST('debug', 'int') == 1) {
    print '<div style="background:#fffae6;border:1px solid #f1c40f;padding:10px;margin:10px 0;font-family:monospace;font-size:11px;">';
    print '<b>DEBUG mode</b><br>';
    print 'Nb lignes recuperees par PHP : <b>'.count($lignes).'</b><br>';
    print 'SQL execute :<br><pre style="white-space:pre-wrap">'.htmlspecialchars($sql).'</pre>';
    print '<b>Premieres lignes brutes :</b><br>';
    foreach (array_slice($lignes, 0, 20) as $idx => $l) {
        print ($idx+1).'. ref='.dol_escape_htmltag($l['ref']).' | statut='.$l['statut'].' | qty='.$l['qty'].' | ca='.$l['ca'].'<br>';
    }
    print '</div>';
}

// Compteurs
$nbOk = $nbMap = $nbOrph = 0;
$caOrph = 0;
foreach ($lignes as $l) {
    if      ($l['statut'] === 'ok')       $nbOk++;
    elseif  ($l['statut'] === 'mappe')    $nbMap++;
    else { $nbOrph++; $caOrph += $l['ca']; }
}

// Mappings existants (pour la section dediee)
$mappingsExistants = array();
$sqlMap = "SELECT mr.rowid, mr.ref_octopia, mr.fk_product_dolibarr, p.ref AS ref_dolibarr, p.label
           FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_mapping_ref mr
           LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = mr.fk_product_dolibarr
           WHERE mr.entity = ".((int)$conf->entity)."
           ORDER BY mr.datec DESC";
$resqlMap = $db->query($sqlMap);
if ($resqlMap) {
    while ($obj = $db->fetch_object($resqlMap)) {
        $mappingsExistants[] = (array)$obj;
    }
}

// =====================================================================
// AFFICHAGE
// =====================================================================
$moisNoms = array(0=>'Toute l\'année',1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
                  7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre');

llxHeader('', 'Audit produits Octopia');
print load_fiche_titre('Audit produits Octopia ↔ Dolibarr', '', 'fa-search');

$currentToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken();

// Filtre periode
print '<form method="GET" action="audit_produits.php" style="margin-bottom:16px;">';
print '<select name="mois" class="flat">';
foreach ($moisNoms as $n => $nom) {
    print '<option value="'.$n.'"'.($mois==$n?' selected':'').'>'.$nom.'</option>';
}
print '</select> ';
print '<select name="annee" class="flat">';
for ($y = date('Y'); $y >= date('Y')-2; $y--) {
    print '<option value="'.$y.'"'.($annee==$y?' selected':'').'>'.$y.'</option>';
}
print '</select> ';
print '<input type="submit" class="button" value="Filtrer">';
print '</form>';

// KPIs
print '<div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">';
print '<div style="flex:1;min-width:180px;background:#e8f8ee;padding:12px;border-radius:4px;">';
print '<div style="font-size:11px;color:#888">Produits OK</div>';
print '<div style="font-size:24px;font-weight:bold;color:#27ae60">'.$nbOk.'</div>';
print '<div style="font-size:11px;color:#888">Liés dans la commande Dolibarr</div>';
print '</div>';
print '<div style="flex:1;min-width:180px;background:#e3f1fc;padding:12px;border-radius:4px;">';
print '<div style="font-size:11px;color:#888">Produits mappés manuellement</div>';
print '<div style="font-size:24px;font-weight:bold;color:#2980b9">'.$nbMap.'</div>';
print '<div style="font-size:11px;color:#888">Via la table de mapping</div>';
print '</div>';
print '<div style="flex:1;min-width:180px;background:#fdebe5;padding:12px;border-radius:4px;">';
print '<div style="font-size:11px;color:#888">Produits orphelins</div>';
print '<div style="font-size:24px;font-weight:bold;color:#c0392b">'.$nbOrph.'</div>';
print '<div style="font-size:11px;color:#888">'.roc_eur($caOrph).' de CA non comptabilisé</div>';
print '</div>';
print '</div>';

// =====================================================================
// SECTION 1 : Produits orphelins
// =====================================================================
if ($nbOrph > 0) {
    print '<h3 style="margin-top:24px;">⚠ Produits vendus sur Octopia mais absents de Dolibarr</h3>';
    print '<p style="color:#666;font-size:13px;">Cochez les produits à créer en masse dans Dolibarr. Le label et le prix de vente seront initialisés depuis les ventes Octopia.</p>';

    print '<form method="POST" action="audit_produits.php">';
    print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
    print '<input type="hidden" name="action" value="create_bulk">';

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th style="width:30px"><input type="checkbox" onclick="document.querySelectorAll(\'input.refchk\').forEach(c=>c.checked=this.checked)"></th>';
    print '<th>Ref Octopia</th>';
    print '<th>Libellé</th>';
    print '<th class="right">Qté</th>';
    print '<th class="right">Nb cmd</th>';
    print '<th class="right">Prix moy.</th>';
    print '<th class="right">CA total</th>';
    print '<th>Dernière vente</th>';
    print '<th>Mapping manuel</th>';
    print '</tr>';

    // Liste tous les produits Dolibarr pour le datalist (limite 500 pour perf)
    $allProducts = array();
    $sqlP = "SELECT rowid, ref, label FROM ".MAIN_DB_PREFIX."product
             WHERE entity IN (0,".((int)$conf->entity).") AND tosell = 1
             ORDER BY ref LIMIT 500";
    $resP = $db->query($sqlP);
    if ($resP) while ($op = $db->fetch_object($resP)) {
        $allProducts[$op->rowid] = $op->ref.' - '.$op->label;
    }

    foreach ($lignes as $l) {
        if ($l['statut'] !== 'orphelin') continue;
        $refEsc = dol_escape_htmltag($l['ref']);
        print '<tr class="oddeven">';
        print '<td><input type="checkbox" name="refs_to_create[]" value="'.$refEsc.'" class="refchk"></td>';
        print '<td><b><code>'.$refEsc.'</code></b></td>';
        print '<td>'.dol_escape_htmltag($l['label']).'</td>';
        print '<td class="right">'.$l['qty'].'</td>';
        print '<td class="right">'.$l['nb_cmd'].'</td>';
        print '<td class="right">'.roc_eur($l['prix_moyen']).'</td>';
        print '<td class="right"><b>'.roc_eur($l['ca']).'</b></td>';
        print '<td style="font-size:11px">'.dol_print_date(dol_stringtotime($l['derniere']), 'day').'</td>';
        print '<td>';
        print '<select name="mapping['.$refEsc.']" class="flat" style="max-width:200px;font-size:11px;" form="formMapping">';
        print '<option value="0">— Choisir un produit Dolibarr existant —</option>';
        foreach ($allProducts as $pid => $plbl) {
            print '<option value="'.$pid.'">'.dol_escape_htmltag(substr($plbl, 0, 60)).'</option>';
        }
        print '</select>';
        print '</td>';
        print '</tr>';
    }
    print '</table>';
    print '<br>';
    print '<input type="submit" class="button butActionNew" value="✚ Créer les produits cochés dans Dolibarr" onclick="return confirm(\'Créer ces produits ?\')">';
    print '</form>';

    // Formulaire mapping separé
    print '<form id="formMapping" method="POST" action="audit_produits.php" style="display:inline">';
    print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
    print '<input type="hidden" name="action" value="save_mapping">';
    print ' &nbsp; <input type="submit" class="button" value="💾 Enregistrer les mappings choisis">';
    print '</form>';
}

// =====================================================================
// SECTION 2 : Mappings existants
// =====================================================================
if (count($mappingsExistants) > 0) {
    print '<h3 style="margin-top:32px;">🔗 Mappings de références enregistrés</h3>';
    print '<p style="color:#666;font-size:13px;">Liaisons entre une ref Octopia et un produit Dolibarr existant.</p>';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><th>Ref Octopia</th><th>Produit Dolibarr lié</th><th></th></tr>';
    foreach ($mappingsExistants as $m) {
        print '<tr class="oddeven">';
        print '<td><code>'.dol_escape_htmltag($m['ref_octopia']).'</code></td>';
        print '<td>';
        if ($m['fk_product_dolibarr']) {
            print '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.(int)$m['fk_product_dolibarr'].'">';
            print dol_escape_htmltag($m['ref_dolibarr'] ?: '#'.$m['fk_product_dolibarr']).' — '.dol_escape_htmltag($m['label']);
            print '</a>';
        } else {
            print '<span style="color:#c0392b">Produit supprimé (id='.(int)$m['fk_product_dolibarr'].')</span>';
        }
        print '</td>';
        print '<td class="right">';
        print '<form method="POST" action="audit_produits.php" style="display:inline">';
        print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
        print '<input type="hidden" name="action" value="delete_mapping">';
        print '<input type="hidden" name="id" value="'.(int)$m['rowid'].'">';
        print '<button type="submit" class="butActionDelete smallpaddingimp" onclick="return confirm(\'Supprimer ce mapping ?\')">×</button>';
        print '</form>';
        print '</td>';
        print '</tr>';
    }
    print '</table>';
}

// =====================================================================
// SECTION 3 : Produits OK (collapsable)
// =====================================================================
if ($nbOk + $nbMap > 0) {
    print '<details style="margin-top:32px;">';
    print '<summary style="cursor:pointer;font-weight:bold;font-size:14px;">✓ Voir les '.($nbOk+$nbMap).' produit(s) déjà liés (cliquer pour développer)</summary>';
    print '<table class="noborder centpercent" style="margin-top:10px;">';
    print '<tr class="liste_titre"><th>Ref Octopia</th><th>Produit Dolibarr</th><th class="right">Qté</th><th class="right">CA</th><th>Statut</th></tr>';
    foreach ($lignes as $l) {
        if ($l['statut'] === 'orphelin') continue;
        print '<tr class="oddeven">';
        print '<td><code>'.dol_escape_htmltag($l['ref']).'</code></td>';
        print '<td>';
        if ($l['fk_product']) {
            print '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.(int)$l['fk_product'].'">'.dol_escape_htmltag($l['ref_doli']).'</a>';
        }
        print '</td>';
        print '<td class="right">'.$l['qty'].'</td>';
        print '<td class="right">'.roc_eur($l['ca']).'</td>';
        print '<td>';
        if ($l['statut'] === 'ok')    print '<span style="color:#27ae60">✓ Lié dans cmd</span>';
        else                          print '<span style="color:#2980b9">⇄ Mappé</span>';
        print '</td>';
        print '</tr>';
    }
    print '</table>';
    print '</details>';
}

llxFooter();
$db->close();
