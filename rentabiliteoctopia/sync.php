<?php
/**
 * Page de synchronisation manuelle octopiaSync -> rentabiliteoctopia
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';
require_once __DIR__.'/lib/OctopiaRentabiliteSync.class.php';

if (!$user->rights->rentabiliteoctopia->write) accessforbidden();

$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$action = GETPOST('action', 'alpha');
$annee  = GETPOST('annee', 'int') ?: (int)date('Y');
$mois   = GETPOST('mois',  'int') ?: (int)date('m');

$sync   = null;
$result = null;

// Vérifier si octopiaSync est installé et actif
$octopiaActive = false;
$sql = "SHOW TABLES LIKE '".MAIN_DB_PREFIX."octopia_orders'";
$resql = $db->query($sql);
if ($resql && $db->num_rows($resql) > 0) $octopiaActive = true;

// Action : lancer la synchro mois
if ($action === 'sync_mois' && $octopiaActive) {
    $token = GETPOST('token', 'alpha');
    // BUGFIX CSRF: comparaison directe SANS appeler newToken() d'abord.
    // newToken() régénère $_SESSION['newtoken'], rendant la comparaison toujours fausse.
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken(); // Régénérer après usage valide
        $sync = new OctopiaRentabiliteSync($db, $conf->entity);
        $result = $sync->syncMois($annee, $mois);
        if ($result) {
            setEventMessages('Synchronisation '.$mois.'/'.$annee.' réussie — '.$sync->nb_ventes_maj.' ligne(s) mises à jour.', null, 'mesgs');
        } else {
            setEventMessages('Synchronisation terminée avec '.$sync->nb_erreurs.' erreur(s). Voir les logs ci-dessous.', null, 'errors');
        }
    }
}

if ($action === 'sync_annee' && $octopiaActive) {
    $token = GETPOST('token', 'alpha');
    // BUGFIX CSRF: même correction
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken(); // Régénérer après usage valide
        $sync = new OctopiaRentabiliteSync($db, $conf->entity);
        $result = $sync->syncAnnee($annee);
        if ($result) {
            setEventMessages('Synchronisation année '.$annee.' réussie — '.$sync->nb_ventes_maj.' lignes mises à jour.', null, 'mesgs');
        } else {
            setEventMessages('Synchronisation terminée avec '.$sync->nb_erreurs.' erreur(s).', null, 'errors');
        }
    }
}

$moisNoms = array(1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
                  7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre');

// Stats octopiaSync disponibles
$statsOctopia = array();
if ($octopiaActive) {
    // Requête adaptée à la version d'octopiaSync installée (llx_octopia_orders + tables Dolibarr natives)
    $sql = "SELECT
                YEAR(c.date_commande)        AS annee,
                MONTH(c.date_commande)       AS mois,
                COUNT(DISTINCT o.rowid)      AS nb_commandes,
                SUM(cd.qty)                  AS nb_lignes,
                SUM(cd.qty * cd.subprice)    AS ca_ht
            FROM ".MAIN_DB_PREFIX."octopia_orders o
            INNER JOIN ".MAIN_DB_PREFIX."commande c
                ON  c.rowid  = o.dolibarr_order_id
                AND c.entity = ".((int)$conf->entity)."
                AND c.date_commande >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                AND c.fk_statut >= 1
            INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid
            WHERE o.entity   = ".((int)$conf->entity)."
              AND o.is_refunded = 0
              AND o.dolibarr_order_id IS NOT NULL
            GROUP BY YEAR(c.date_commande), MONTH(c.date_commande)
            ORDER BY annee DESC, mois DESC
            LIMIT 12";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $statsOctopia[] = array(
                'annee'        => $obj->annee,
                'mois'         => $obj->mois,
                'nb_commandes' => $obj->nb_commandes,
                'nb_lignes'    => $obj->nb_lignes,
                'ca_ht'        => round($obj->ca_ht, 2),
            );
        }
    }
}

// ---- Affichage ----
llxHeader('', 'Rentabilité Octopia - Synchronisation');
print load_fiche_titre('Synchronisation depuis octopiaSync', '', 'fa-sync-alt');

// Alerte si octopiaSync absent
if (!$octopiaActive) {
    print '<div class="error" style="padding:12px;border-radius:4px;margin-bottom:16px;">';
    print '<b>Module octopiaSync non détecté</b><br>';
    print 'La table <code>llx_octopia_orders</code> n\'existe pas. Vérifiez qu\'octopiaSync est installé et actif.';
    print '</div>';
} else {
    print '<div class="ok" style="padding:10px;border-radius:4px;margin-bottom:16px;">';
    print 'Module octopiaSync détecté — '.count($statsOctopia).' période(s) disponible(s) dans la base.';
    print '</div>';
}

// ---- Formulaire synchro manuelle ----
// On récupère le token courant AVANT les éventuels newToken() des formulaires
$currentToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken();

print '<table class="noborder" style="margin-bottom:20px;">';
print '<tr class="liste_titre"><td colspan="4">Lancer une synchronisation manuelle</td></tr>';
print '<tr class="oddeven"><td style="padding:12px;">';

print '<form method="POST" action="sync.php" style="display:inline-flex;gap:10px;align-items:center;flex-wrap:wrap;">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
print '<input type="hidden" name="action" value="sync_mois">';
print '<select name="mois" class="flat">';
foreach ($moisNoms as $num => $nom) {
    print '<option value="'.$num.'"'.($mois==$num?' selected':'').'>'.$nom.'</option>';
}
print '</select>';
print '<select name="annee" class="flat">';
for ($y = date('Y'); $y >= date('Y')-2; $y--) {
    print '<option value="'.$y.'"'.($annee==$y?' selected':'').'>'.$y.'</option>';
}
print '</select>';
print '<input type="submit" class="button" value="Synchro ce mois"'.(!$octopiaActive?' disabled':'').'>';
print '</form>';

print '&nbsp;&nbsp;';

print '<form method="POST" action="sync.php" style="display:inline-flex;gap:10px;align-items:center;">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
print '<input type="hidden" name="action" value="sync_annee">';
print '<select name="annee" class="flat">';
for ($y = date('Y'); $y >= date('Y')-2; $y--) {
    print '<option value="'.$y.'"'.($annee==$y?' selected':'').'>'.$y.'</option>';
}
print '</select>';
print '<input type="submit" class="button" value="Synchro année complète"'.(!$octopiaActive?' disabled':'').'>';
print '</form>';

print '</td></tr>';
print '</table>';

// ---- Logs de la synchro en cours ----
if ($sync !== null) {
    print '<br><b>Résultat de la synchronisation</b><br>';
    print '<div style="background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:10px;margin-bottom:20px;max-height:300px;overflow-y:auto;">';
    print $sync->getLogsHtml();
    print '</div>';
    print '<p>Produits créés : <b>'.$sync->nb_produits_crees.'</b> &nbsp;|&nbsp; ';
    print 'Lignes MàJ : <b>'.$sync->nb_ventes_maj.'</b> &nbsp;|&nbsp; ';
    print 'Erreurs : <b style="color:'.($sync->nb_erreurs?'red':'green').'">'.$sync->nb_erreurs.'</b></p>';
}

// ---- Tableau des données octopiaSync disponibles ----
if ($octopiaActive && !empty($statsOctopia)) {
    print '<br>';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>Période</th>';
    print '<th class="right">Commandes</th>';
    print '<th class="right">Lignes produit</th>';
    print '<th class="right">CA HT (€)</th>';
    print '<th>Action</th>';
    print '</tr>';

    foreach ($statsOctopia as $s) {
        $nomMois = isset($moisNoms[$s['mois']]) ? $moisNoms[$s['mois']] : $s['mois'];
        print '<tr class="oddeven">';
        print '<td><b>'.$nomMois.' '.$s['annee'].'</b></td>';
        print '<td class="right">'.$s['nb_commandes'].'</td>';
        print '<td class="right">'.$s['nb_lignes'].'</td>';
        print '<td class="right">'.roc_eur($s['ca_ht']).'</td>';
        print '<td>';
        print '<form method="POST" action="sync.php" style="display:inline">';
        print '<input type="hidden" name="token" value="'.dol_escape_htmltag($currentToken).'">';
        print '<input type="hidden" name="action" value="sync_mois">';
        print '<input type="hidden" name="annee" value="'.$s['annee'].'">';
        print '<input type="hidden" name="mois" value="'.$s['mois'].'">';
        print '<input type="submit" class="button smallpaddingimp" value="Synchroniser">';
        print '</form>';
        print '</td>';
        print '</tr>';
    }
    print '</table>';
}

// ---- Instructions cron ----
print '<br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Configuration du cron automatique (o2switch cPanel)</td></tr>';
print '<tr class="oddeven"><td style="padding:12px;">';
print '<p>Ajoutez cette ligne dans <b>cPanel → Tâches Cron</b> pour une synchro quotidienne à 3h du matin :</p>';
print '<code style="display:block;background:#f5f5f5;padding:10px;border-radius:4px;font-size:12px;word-break:break-all;">';
print '0 3 * * * /usr/local/bin/php '.DOL_DOCUMENT_ROOT.'/rentabiliteoctopia/cron/sync_octopia.php >> /tmp/rentabiliteoctopia_cron.log 2>&1';
print '</code>';
print '<br><p>Pour vérifier les logs du cron :</p>';
print '<code style="display:block;background:#f5f5f5;padding:8px;border-radius:4px;font-size:12px;">tail -50 /tmp/rentabiliteoctopia_cron.log</code>';
print '</td></tr>';
print '</table>';

llxFooter();
$db->close();
