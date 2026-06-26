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
require_once __DIR__.'/../lib/CacheMois.class.php';
require_once __DIR__.'/../lib/OctopiaFactureImport.class.php';

if (!$user->rights->rentabiliteoctopia->write) accessforbidden();
// BUGFIX: était 'rentabiliteocternity' (typo) — corrigé en 'rentabiliteoctopia'
$langs->load('rentabiliteoctopia@rentabiliteoctopia');

$action = GETPOST('action', 'alpha');

if ($action === 'purge_cache') {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        try {
            $c = new CacheMois($db, $conf->entity);
            $c->invalidateAll();
            setEventMessages('Cache vide. Les agregats mensuels seront recalcules au prochain affichage.', null, 'mesgs');
        } catch (Exception $e) {
            setEventMessages('Erreur purge cache : '.$e->getMessage(), null, 'errors');
        }
    }
}

if ($action === 'cleanup_orphelins') {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        // Approche en 3 etapes (compatible MariaDB strict) :
        // 1. Recuperer les IDs des produits orphelins (sans JOIN DELETE)
        // 2. Supprimer leurs ventes via WHERE fk_produit IN (...)
        // 3. Supprimer les produits

        $ids = array();
        $sqlGet = "SELECT rowid FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_produit
                   WHERE (ref LIKE 'ORPHELIN-%' OR ref LIKE 'LIBRE:%')
                     AND entity = ".((int)$conf->entity);
        $rGet = $db->query($sqlGet);
        if (!$rGet) {
            setEventMessages('Erreur SELECT produits orphelins : '.$db->lasterror(), null, 'errors');
        } else {
            while ($o = $db->fetch_object($rGet)) {
                $ids[] = (int)$o->rowid;
            }

            $nbV = 0; $nbP = 0;

            if (empty($ids)) {
                setEventMessages('Aucun produit orphelin a nettoyer.', null, 'mesgs');
            } else {
                $idsList = implode(',', $ids);

                // Etape 1 : supprimer les ventes liees
                $sql1 = "DELETE FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente
                         WHERE fk_produit IN (".$idsList.")
                           AND entity = ".((int)$conf->entity);
                $r1 = $db->query($sql1);
                if (!$r1) {
                    setEventMessages('Erreur DELETE ventes : '.$db->lasterror(), null, 'errors');
                } else {
                    $nbV = $db->affected_rows();

                    // Etape 2 : supprimer les produits eux-memes
                    $sql2 = "DELETE FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_produit
                             WHERE rowid IN (".$idsList.")
                               AND entity = ".((int)$conf->entity);
                    $r2 = $db->query($sql2);
                    if (!$r2) {
                        setEventMessages('Erreur DELETE produits : '.$db->lasterror().' (les ventes ont ete supprimees mais pas les produits)', null, 'errors');
                    } else {
                        $nbP = $db->affected_rows();
                        try { $c = new CacheMois($db, $conf->entity); $c->invalidateAll(); } catch (Exception $e) {}
                        setEventMessages($nbV.' ligne(s) de vente + '.$nbP.' produit(s) artificiel(s) supprime(s). Relancez une synchronisation Octopia pour recalculer proprement.', null, 'mesgs');
                    }
                }
            }
        }
    }
}

if ($action === 'fix_category_encoding') {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        // Corriger les double-encodages UTF-8 dans les libelles de categories
        // Ex: "TAÃ©lAÃ©phonie" -> "Telephonie"
        $sql = "SELECT rowid, label FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie WHERE entity = ".((int)$conf->entity);
        $r = $db->query($sql);
        $nbFix = 0;
        while ($r && $o = $db->fetch_object($r)) {
            // Detection : la chaine contient des sequences UTF-8 mal encodees
            $fixed = $o->label;
            // Tentative de decodage UTF-8 double : si la chaine est valide UTF-8 ET contient des sequences caracteristiques de double-encodage
            $hasDoubleEncoding = (
                strpos($fixed, "Ã") !== false ||  // A avec tilde (typique double encoding)
                strpos($fixed, "Â") !== false ||  // A circonflexe
                strpos($fixed, "�") !== false
            );
            if ($hasDoubleEncoding) {
                $tentative = @utf8_decode($fixed);
                if ($tentative && $tentative !== $fixed) {
                    // Verifier que le resultat est de l'UTF-8 valide (ou ASCII pur)
                    if (mb_check_encoding($tentative, 'UTF-8') || mb_check_encoding($tentative, 'ASCII')) {
                        $sqlU = "UPDATE ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie
                                 SET label = '".$db->escape($tentative)."'
                                 WHERE rowid = ".(int)$o->rowid;
                        if ($db->query($sqlU)) $nbFix++;
                    }
                }
            }
        }
        if ($nbFix > 0) {
            setEventMessages($nbFix.' libelle(s) de categorie corrige(s). Verifiez sur la page Categories & commissions.', null, 'mesgs');
        } else {
            setEventMessages('Aucune categorie a corriger (encodage deja propre).', null, 'mesgs');
        }
    }
}

if ($action === 'cleanup_port_charges') {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        // Nettoyage des anciennes entrees "Port Octopia (auto)" enregistrees a tort en charges.
        // Les frais de port factures aux clients sont un revenu, pas une charge.
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_frais
                WHERE label LIKE 'Port Octopia (auto%' AND entity = ".((int)$conf->entity);
        if ($db->query($sql)) {
            $nb = $db->affected_rows();
            setEventMessages('Nettoyage : '.$nb.' entree(s) "Port Octopia (auto)" supprimee(s) des frais. Les frais de port factures aux clients sont desormais comptes dans le CA, pas en charge.', null, 'mesgs');
        } else {
            setEventMessages('Erreur nettoyage : '.$db->lasterror(), null, 'errors');
        }
    }
}

if ($action === 'send_test_mail') {
    $token = GETPOST('token', 'alpha');
    if (empty($token) || $token !== $_SESSION['newtoken']) {
        setEventMessages('Token invalide', null, 'errors');
    } else {
        newToken();
        require_once __DIR__.'/../lib/DailyKpiMailer.class.php';
        $emailTestTo = trim((string)(isset($_POST['test_email']) ? $_POST['test_email'] : ''));

        // Recharger les params depuis la BDD (pour utiliser la conf actuelle, pas celle non encore enregistree)
        $paramsCurrent = rentabiliteoctopia_get_params($db);
        if (empty($emailTestTo)) {
            $emailTestTo = isset($paramsCurrent['daily_kpi_email']) ? trim($paramsCurrent['daily_kpi_email']) : '';
        }
        if (empty($emailTestTo) || !filter_var(explode(',', $emailTestTo)[0], FILTER_VALIDATE_EMAIL)) {
            setEventMessages('Adresse email invalide. Renseignez l\'email avant le test ou saisissez-le dans le champ.', null, 'errors');
        } else {
            $mailer = new DailyKpiMailer($db, $conf->entity, $paramsCurrent);
            $ok = $mailer->send($emailTestTo, true);
            if ($ok) {
                setEventMessages('Mail de test envoye a '.$emailTestTo.'. Verifiez votre boite (incluant les spams).', null, 'mesgs');
            } else {
                setEventMessages('Echec de l\'envoi : '.implode(' / ', $mailer->logs), null, 'errors');
            }
        }
    }
}

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
            'daily_kpi_email', 'daily_kpi_enabled',
            'daily_kpi_show_kpis', 'daily_kpi_show_compare_j2', 'daily_kpi_show_compare_week',
            'daily_kpi_show_detail_ca', 'daily_kpi_show_top_produits', 'daily_kpi_top_n',
            'daily_kpi_show_cumul_mois', 'daily_kpi_show_seuil_alert', 'daily_kpi_period',
            'daily_kpi_send_hour', 'daily_kpi_send_minute', 'daily_kpi_frequency', 'daily_kpi_show_alertes',
        );
        $ok = true;
        // BUGFIX: GETPOST avec 'alpha' rejette chiffres+virgules.
        // 'nohtml' filtre aussi parfois selon la version Dolibarr.
        // On utilise 'restricthtml' qui autorise tout sauf le HTML dangereux,
        // et on log ce qui est reellement recu pour faciliter le debug.
        $debug_saved = array();
        foreach ($keys as $key) {
            // Cas special : checkboxes (absentes du POST si decochees)
            $isCheckbox = ($key === 'daily_kpi_enabled' || strpos($key, 'daily_kpi_show_') === 0);
            if ($isCheckbox) {
                $val = isset($_POST[$key]) ? '1' : '0';
            } else {
                // Lecture directe POST (le plus fiable pour caracteres speciaux)
                $val = '';
                if (isset($_POST[$key])) {
                    $val = trim((string)$_POST[$key]);
                } else {
                    $val = trim((string)GETPOST($key, 'restricthtml'));
                }
            }
            $debug_saved[$key] = $val;

            $sql = "INSERT INTO ".MAIN_DB_PREFIX."rentabiliteoctopia_params (param_key, param_value, entity)
                    VALUES ('".$db->escape($key)."', '".$db->escape($val)."', ".((int)$conf->entity).")
                    ON DUPLICATE KEY UPDATE param_value = '".$db->escape($val)."'";
            if (!$db->query($sql)) $ok = false;
        }
        // Debug : afficher ce qui a ete sauvegarde pour les pcg_*
        $debug_msg = '';
        foreach ($debug_saved as $k => $v) {
            if (strpos($k, 'pcg_') === 0) {
                $debug_msg .= $k.' = '.($v !== '' ? '"'.$v.'"' : '<vide>').' ';
            }
        }
        if ($debug_msg) setEventMessages('Sauvegarde mapping : '.$debug_msg, null, 'mesgs');

        // Résolution automatique du fournisseur par nom
        $nomFourn = isset($_POST['nom_fournisseur']) ? trim((string)$_POST['nom_fournisseur']) : trim((string)GETPOST('nom_fournisseur', 'restricthtml'));
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

        // --- Piloter la tache planifiee Dolibarr selon la frequence choisie ---
        // Le job "Rapport KPI Octopia quotidien" est ainsi synchronise automatiquement,
        // sans avoir a editer la frequence a la main dans les Taches planifiees.
        $freqChoisie = isset($_POST['daily_kpi_frequency']) ? trim((string)$_POST['daily_kpi_frequency']) : 'daily';
        $hChoisi  = isset($_POST['daily_kpi_send_hour'])   ? (int)$_POST['daily_kpi_send_hour']   : 8;
        $mnChoisi = isset($_POST['daily_kpi_send_minute']) ? (int)$_POST['daily_kpi_send_minute'] : 0;
        switch ($freqChoisie) {
            case '5min':   $cronFreq = 5;  $cronUnit = 60;    break;
            case '15min':  $cronFreq = 15; $cronUnit = 60;    break;
            case '30min':  $cronFreq = 30; $cronUnit = 60;    break;
            case 'hourly': $cronFreq = 1;  $cronUnit = 3600;  break;
            default:       $cronFreq = 1;  $cronUnit = 86400; break;
        }
        if ($freqChoisie === 'daily') {
            $nextTs = dol_mktime($hChoisi, $mnChoisi, 0, (int)dol_print_date(dol_now(), '%m'), (int)dol_print_date(dol_now(), '%d'), (int)dol_print_date(dol_now(), '%Y'));
            if ($nextTs <= dol_now()) $nextTs = dol_time_plus_duree($nextTs, 1, 'd');
        } else {
            $nextTs = dol_now();
        }
        $sqlCron = "UPDATE ".MAIN_DB_PREFIX."cronjob
                    SET frequency = ".(int)$cronFreq.", unitfrequency = ".(int)$cronUnit.", datenextrun = '".$db->idate($nextTs)."'
                    WHERE entity = ".((int)$conf->entity)." AND objectname = 'RentabiliteOctopiaCron' AND methodename = 'sendDailyKpiMail'";
        $db->query($sqlCron);

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
print 'Saisissez les <b>comptes PCG exacts</b> (6 chiffres) ou des <b>préfixes</b>, séparés par des virgules. ';
print '<b>Un compte exact (ex: 622001) est toujours prioritaire sur un préfixe (ex: 622).</b> ';
print 'Laissez vide pour utiliser le mapping par défaut basé sur les préfixes PCG standards.';
print '</td></tr>';

$mappingDefaut = array(
    'pcg_abonnement'      => array('label'=>'Abonnement / Commissions',   'defaut'=>'622, 613, 614',       'ex'=>'ex: 622001, 613501'),
    'pcg_fulfilment'      => array('label'=>'Fulfilment / Stockage',       'defaut'=>'611, 613',            'ex'=>'ex: 613501, 611001'),
    'pcg_affranchissement'=> array('label'=>'Affranchissement / Transport','defaut'=>'626, 624, 625, 6241', 'ex'=>'ex: 626201, 624001'),
    'pcg_packaging'       => array('label'=>'Packaging / Emballages',      'defaut'=>'604, 6044, 624',      'ex'=>'ex: 624001, 6044'),
    'pcg_publicite'       => array('label'=>'Publicité / Sponsored',       'defaut'=>'623, 622, 6231',      'ex'=>'ex: 623101, 6231'),
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

// ---- Rapport quotidien par email ----
$dkE = !empty($params['daily_kpi_enabled']);
// Defaults: tout active sauf si explicitement desactive (cle = '0')
$dkOpt = function($k, $default = 1) use ($params) {
    if (!isset($params[$k]) || $params[$k] === '') return (bool)$default;
    return (string)$params[$k] === '1';
};
$showKpis    = $dkOpt('daily_kpi_show_kpis', 1);
$showCmpJ2   = $dkOpt('daily_kpi_show_compare_j2', 1);
$showCmpWeek = $dkOpt('daily_kpi_show_compare_week', 1);
$showDetCA   = $dkOpt('daily_kpi_show_detail_ca', 1);
$showTop     = $dkOpt('daily_kpi_show_top_produits', 1);
$showCumul   = $dkOpt('daily_kpi_show_cumul_mois', 1);
$showAlert   = $dkOpt('daily_kpi_show_seuil_alert', 1);
$topN        = !empty($params['daily_kpi_top_n']) ? (int)$params['daily_kpi_top_n'] : 5;
$period      = !empty($params['daily_kpi_period']) ? $params['daily_kpi_period'] : 'j1';

print '<table class="noborder centpercent" style="margin-bottom:20px;">';
print '<tr class="liste_titre"><th colspan="3">Rapport quotidien des KPI par email</th></tr>';
print '<tr><td colspan="3" style="padding:8px;font-size:12px;color:#666;">';
print 'Recevez un email avec les KPI Cdiscount. Cochez les sections a inclure dans le mail. ';
print 'Necessite l\'ajout d\'une tache cron sur o2switch (instructions en bas de page).';
print '</td></tr>';

// Activation + email
print '<tr class="oddeven">';
print '<td style="width:28%"><b>Activer l\'envoi quotidien</b></td>';
print '<td style="width:40%"><label><input type="checkbox" name="daily_kpi_enabled" value="1"'.($dkE?' checked':'').'> Envoyer le rapport</label></td>';
print '<td style="font-size:12px;color:#888">Suspendre les envois sans toucher au cron</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td><b>Email(s) destinataire(s)</b></td>';
print '<td><input type="text" name="daily_kpi_email" class="flat" style="width:280px" value="'.dol_escape_htmltag($params['daily_kpi_email'] ?? '').'" placeholder="vous@exemple.fr, autre@..."></td>';
print '<td style="font-size:12px;color:#888">Plusieurs adresses : separer par des virgules</td>';
print '</tr>';

// Periode du rapport
print '<tr class="oddeven">';
print '<td><b>Periode du rapport</b></td>';
print '<td><select name="daily_kpi_period" class="flat">';
print '<option value="j1"'.($period==='j1'?' selected':'').'>Hier (J-1)</option>';
print '<option value="j7"'.($period==='j7'?' selected':'').'>7 derniers jours glissants</option>';
print '<option value="month_to_date"'.($period==='month_to_date'?' selected':'').'>Mois en cours (cumul a date)</option>';
print '</select></td>';
print '<td style="font-size:12px;color:#888">Periode prise comme reference pour les KPI</td>';
print '</tr>';

// Frequence + heure d'envoi (pilotent la tache Dolibarr ET la ligne cron generee)
$hourSend   = isset($params['daily_kpi_send_hour'])   && $params['daily_kpi_send_hour']   !== '' ? (int)$params['daily_kpi_send_hour']   : 8;
$minuteSend = isset($params['daily_kpi_send_minute']) && $params['daily_kpi_send_minute'] !== '' ? (int)$params['daily_kpi_send_minute'] : 0;
$frequence  = isset($params['daily_kpi_frequency'])   && $params['daily_kpi_frequency']   !== '' ? $params['daily_kpi_frequency']        : 'daily';

$freqOptions = array(
    'daily'  => 'Une fois par jour (a heure fixe)',
    '5min'   => 'Toutes les 5 minutes',
    '15min'  => 'Toutes les 15 minutes',
    '30min'  => 'Toutes les 30 minutes',
    'hourly' => 'Toutes les heures',
);
print '<tr class="oddeven">';
print '<td><b>Frequence d\'envoi</b></td>';
print '<td><select name="daily_kpi_frequency" class="flat">';
foreach ($freqOptions as $fval => $flbl) {
    print '<option value="'.$fval.'"'.($frequence === $fval ? ' selected' : '').'>'.$flbl.'</option>';
}
print '</select></td>';
print '<td style="font-size:12px;color:#888">"Toutes les X minutes" est pratique pour tester l\'envoi sans attendre une journee. Repassez sur "une fois par jour" ensuite.</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td><b>Heure d\'envoi souhaitee</b><br><span style="font-size:11px;color:#aaa;">si frequence = une fois par jour</span></td>';
print '<td>';
print '<select name="daily_kpi_send_hour" class="flat">';
for ($h = 0; $h < 24; $h++) {
    print '<option value="'.$h.'"'.($hourSend === $h ? ' selected' : '').'>'.str_pad($h, 2, '0', STR_PAD_LEFT).'</option>';
}
print '</select> : ';
print '<select name="daily_kpi_send_minute" class="flat">';
for ($mn = 0; $mn < 60; $mn += 5) {
    print '<option value="'.$mn.'"'.($minuteSend === $mn ? ' selected' : '').'>'.str_pad($mn, 2, '0', STR_PAD_LEFT).'</option>';
}
print '</select>';
print '</td>';
print '<td style="font-size:12px;color:#888">Reglage par pas de 5 minutes (ex. 10:05, 10:35). Sert a generer la ligne cron ci-dessous.</td>';
print '</tr>';

// Sections du mail (header)
print '<tr class="liste_titre"><th colspan="3" style="padding-top:10px;">Sections a inclure dans le mail</th></tr>';

$sections = array(
    array('daily_kpi_show_kpis',         $showKpis,    'KPI principaux',          'CA HT, marge brute, commandes, unites vendues'),
    array('daily_kpi_show_compare_j2',   $showCmpJ2,   'Comparaison avec la veille (J-2)',  'Pourcentage d\'evolution vs avant-hier'),
    array('daily_kpi_show_compare_week', $showCmpWeek, 'Comparaison meme jour semaine precedente', 'Evolution vs lundi/mardi/... dernier'),
    array('daily_kpi_show_detail_ca',    $showDetCA,   'Detail du CA',            'CA produits + frais de port + cout d\'achat estime'),
    array('daily_kpi_show_top_produits', $showTop,     'Top produits du jour',    'Tableau des meilleures ventes (configurable ci-dessous)'),
    array('daily_kpi_show_cumul_mois',   $showCumul,   'Cumul du mois en cours',  'Total commandes / unites / CA depuis le 1er du mois'),
    array('daily_kpi_show_seuil_alert',  $showAlert,   'Alerte si marge sous seuil', 'Encart rouge si taux de marge inferieur au seuil configure'),
    array('daily_kpi_show_alertes',      $dkOpt('daily_kpi_show_alertes', 1), 'Centre d\'alertes', 'Inclut les alertes actives (ruptures, pertes, marges faibles, chute CA)'),
);
foreach ($sections as $s) {
    print '<tr class="oddeven">';
    print '<td>'.$s[2].'</td>';
    print '<td><label><input type="checkbox" name="'.$s[0].'" value="1"'.($s[1]?' checked':'').'> Inclure</label></td>';
    print '<td style="font-size:12px;color:#888">'.$s[3].'</td>';
    print '</tr>';
}

// Top N
print '<tr class="oddeven">';
print '<td>Nombre de produits dans le top</td>';
print '<td><input type="number" name="daily_kpi_top_n" class="flat" style="width:70px;text-align:right" value="'.$topN.'" min="1" max="20"></td>';
print '<td style="font-size:12px;color:#888">Combien de lignes dans le tableau "Top produits"</td>';
print '</tr>';

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

// ---- Maintenance : nettoyage des produits orphelins ----
$sqlOrph = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_produit
            WHERE (ref LIKE 'ORPHELIN-%' OR ref LIKE 'LIBRE:%')
              AND entity = ".((int)$conf->entity);
$rOrph = $db->query($sqlOrph);
$nbOrph = ($rOrph && $oO = $db->fetch_object($rOrph)) ? (int)$oO->nb : 0;

$sqlBadCat = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie
              WHERE (label LIKE '%Ã%' OR label LIKE '%�%')
                AND entity = ".((int)$conf->entity);
$rBC = $db->query($sqlBadCat);
$nbBadCat = ($rBC && $oBC = $db->fetch_object($rBC)) ? (int)$oBC->nb : 0;

if ($nbOrph > 0 || $nbBadCat > 0) {
    print '<br><div style="background:#fff3e0;border:1px solid #e67e22;padding:14px;border-radius:4px;margin-top:20px;">';
    print '<h4 style="margin-top:0;color:#e67e22;">&#128296; Maintenance des donnees</h4>';

    if ($nbOrph > 0) {
        print '<div style="margin-bottom:14px;padding:10px;background:#fff;border-radius:4px;">';
        print '<p style="margin:0 0 8px 0;font-size:13px;">';
        print '<b>'.$nbOrph.' produit(s) artificiel(s)</b> (ORPHELIN-* ou LIBRE:*) presents dans le catalogue. ';
        print 'Ce sont des agregats de lignes sans fk_product (frais de port Octopia, lignes libres) qui faussent le top des produits et les stats.';
        print '</p>';
        print '<form method="POST" action="admin.php" style="display:inline;">';
        print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? dol_escape_htmltag($_SESSION['newtoken']) : newToken()).'">';
        print '<input type="hidden" name="action" value="cleanup_orphelins">';
        print '<button type="submit" class="button butActionDelete" onclick="return confirm(\'Supprimer '.$nbOrph.' produit(s) artificiel(s) + leurs ventes ? Vous devrez relancer une synchro ensuite.\')">Nettoyer les produits orphelins</button>';
        print '</form>';
        print '</div>';
    }

    if ($nbBadCat > 0) {
        print '<div style="padding:10px;background:#fff;border-radius:4px;">';
        print '<p style="margin:0 0 8px 0;font-size:13px;">';
        print '<b>'.$nbBadCat.' categorie(s)</b> ont un libelle avec un double-encodage UTF-8 (ex: "TA&Atilde;&copy;lA&Atilde;&copy;phonie" au lieu de "Telephonie").';
        print '</p>';
        print '<form method="POST" action="admin.php" style="display:inline;">';
        print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? dol_escape_htmltag($_SESSION['newtoken']) : newToken()).'">';
        print '<input type="hidden" name="action" value="fix_category_encoding">';
        print '<button type="submit" class="button" onclick="return confirm(\'Corriger l\\\'encodage de '.$nbBadCat.' categorie(s) ?\')">Corriger l\'encodage</button>';
        print '</form>';
        print '</div>';
    }

    print '</div>';
}

// ---- Nettoyage des frais de port comptabilises a tort ----
$sqlCheck = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_frais
             WHERE label LIKE 'Port Octopia (auto%' AND entity = ".((int)$conf->entity);
$rCheck = $db->query($sqlCheck);
$nbPortCharges = 0;
if ($rCheck && $oC = $db->fetch_object($rCheck)) $nbPortCharges = (int)$oC->nb;

if ($nbPortCharges > 0) {
    print '<br><div style="background:#fdebe5;border:1px solid #c0392b;padding:14px;border-radius:4px;margin-top:20px;">';
    print '<h4 style="margin-top:0;color:#c0392b;">&#9888; Nettoyage recommande</h4>';
    print '<p style="font-size:13px;">';
    print '<b>'.$nbPortCharges.' entree(s) "Port Octopia (auto)"</b> sont actuellement enregistrees en CHARGES dans vos frais mensuels. ';
    print 'C\'est une <b>erreur conceptuelle</b> : les frais de port factures aux clients sont un REVENU (CA reverse par Cdiscount), pas une charge.<br>';
    print 'Les charges reelles de transport viennent uniquement de vos factures fournisseur Cdiscount (PCG 624/626).';
    print '</p>';
    print '<form method="POST" action="admin.php" style="margin-top:10px;">';
    print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? dol_escape_htmltag($_SESSION['newtoken']) : newToken()).'">';
    print '<input type="hidden" name="action" value="cleanup_port_charges">';
    print '<button type="submit" class="button butActionDelete" onclick="return confirm(\'Supprimer les '.$nbPortCharges.' entree(s) Port Octopia (auto) des frais ?\')">Nettoyer maintenant</button>';
    print '</form>';
    print '</div>';
}

// ---- Bouton de test ----
$emailDefaut = isset($params['daily_kpi_email']) ? $params['daily_kpi_email'] : '';
print '<br><div style="background:#e3f1fc;border:1px solid #3498db;padding:14px;border-radius:4px;margin-top:20px;">';
print '<h4 style="margin-top:0;"><i class="fa fa-paper-plane"></i> Envoyer un mail de test maintenant</h4>';
print '<p style="font-size:13px;">Envoyez immediatement un mail avec la configuration <b>actuellement enregistree</b> (pensez a sauvegarder vos changements avant le test).</p>';
print '<form method="POST" action="admin.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? dol_escape_htmltag($_SESSION['newtoken']) : newToken()).'">';
print '<input type="hidden" name="action" value="send_test_mail">';
print '<input type="email" name="test_email" class="flat" style="width:260px;" placeholder="Laisser vide pour utiliser l\'email configure" value="">';
print '<button type="submit" class="button butActionNew"><i class="fa fa-paper-plane"></i> Envoyer le test</button>';
print '</form>';

// Bouton apercu navigateur (formulaire separe car cible un nouvel onglet)
print '<a href="preview_mail.php" target="_blank" class="button" style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;text-decoration:none;"><i class="fa fa-eye"></i> Apercu navigateur</a>';

print '<form style="display:none">'; // dummy pour fermer ci-dessous
if ($emailDefaut) print '<span style="font-size:12px;color:#666;">Email configure : <b>'.dol_escape_htmltag($emailDefaut).'</b></span>';
else print '<span style="font-size:12px;color:#c0392b;">Aucun email configure dans les parametres.</span>';
print '</form>';
print '<p style="font-size:11px;color:#888;margin-top:10px;">Le mail de test inclura le prefixe <code>[TEST]</code> dans son sujet. La case "Activer l\'envoi quotidien" n\'a pas besoin d\'etre cochee pour le test.</p>';
print '</div>';

// ---- Option recommandee : scheduler Dolibarr ----
print '<br><div style="background:#e8f8ee;border:1px solid #27ae60;padding:14px;border-radius:4px;margin-top:20px;">';
print '<h4 style="margin-top:0;color:#27ae60;"><i class="fa fa-check-circle"></i> Methode recommandee : taches planifiees Dolibarr</h4>';
print '<p style="font-size:13px;">Plus fiable que le cron systeme o2switch (pas de probleme de chemin PHP). Le module a enregistre 2 taches automatiquement :</p>';
print '<ol style="font-size:13px;line-height:1.7;">';
print '<li>Allez dans <b>Accueil &rarr; Configuration &rarr; Taches planifiees</b> (ou <code>'.DOL_URL_ROOT.'/cron/list.php</code>)</li>';
print '<li>Vous y verrez <b>"Rapport KPI Octopia quotidien"</b> et <b>"Capture mensuelle des prix Octopia"</b></li>';
print '<li>Verifiez qu\'elles sont <b>activees</b> (statut "Active"). Reglez l\'heure de declenchement souhaitee dans la colonne "Prochaine execution".</li>';
print '<li><b>Important :</b> pour que le scheduler tourne, une tache cron systeme minimale doit appeler le lanceur Dolibarr. Si ce n\'est pas deja fait, ajoutez UNE seule ligne cron o2switch :</li>';
print '</ol>';
$cronLauncher = '*/5 * * * * /usr/local/bin/php '.DOL_DOCUMENT_ROOT.'/../scripts/cron/cron_run_jobs.php '.(!empty($conf->file->cron_securekey) ? $conf->file->cron_securekey : 'VOTRE_CLE').' superadmin >> /tmp/dolibarr_cron.log 2>&1';
print '<code style="display:block;background:#fff;padding:10px;border-radius:4px;font-size:11px;word-break:break-all;border:1px solid #ddd;">'.dol_escape_htmltag($cronLauncher).'</code>';
print '<p style="font-size:12px;color:#666;margin-top:8px;">';
print 'Ce lanceur unique (toutes les 5 min) execute TOUTES vos taches planifiees Dolibarr, pas seulement celles de ce module. ';
print 'La cle securisee se trouve dans <b>Accueil &rarr; Configuration &rarr; Taches planifiees</b> (lien "Information" en haut).';
print '</p>';
print '<p style="font-size:12px;color:#888;margin-top:6px;font-style:italic;">';
print 'Avec cette methode, vous configurez tout dans l\'interface Dolibarr (heure, activation) sans toucher au cron a chaque changement.';
print '</p>';
print '</div>';

// ---- Instructions cron ----
print '<br><div style="background:#fffae6;border:1px solid #f1c40f;padding:14px;border-radius:4px;margin-top:20px;">';
print '<h4 style="margin-top:0;">⏰ Alternative : cron systeme direct (si vous n\'utilisez pas le scheduler Dolibarr)</h4>';
print '<p style="font-size:13px;"><b>Etapes</b> :</p>';
print '<ol style="font-size:13px;line-height:1.7;">';
print '<li>Connectez-vous a votre <b>cPanel o2switch</b> : <a href="https://wings.o2switch.net:2083" target="_blank">wings.o2switch.net:2083</a></li>';
print '<li>Cherchez <b>"Taches Cron"</b> dans la barre de recherche (ou section "Avance")</li>';
print '<li>Dans <b>"Ajouter une nouvelle tache Cron"</b>, saisissez :<br>';
print '<table style="margin-left:20px;margin-top:8px;font-size:12px;">';
print '<tr><td style="padding:2px 12px 2px 0;color:#888">Minute :</td><td><code style="background:#fff;padding:2px 6px;border:1px solid #ddd;border-radius:3px;">0</code></td></tr>';
print '<tr><td style="padding:2px 12px 2px 0;color:#888">Heure :</td><td><code style="background:#fff;padding:2px 6px;border:1px solid #ddd;border-radius:3px;">'.$hourCron.'</code></td></tr>';
print '<tr><td style="padding:2px 12px 2px 0;color:#888">Jour / Mois / Jour semaine :</td><td><code style="background:#fff;padding:2px 6px;border:1px solid #ddd;border-radius:3px;">*</code> &nbsp;<code style="background:#fff;padding:2px 6px;border:1px solid #ddd;border-radius:3px;">*</code> &nbsp;<code style="background:#fff;padding:2px 6px;border:1px solid #ddd;border-radius:3px;">*</code></td></tr>';
print '<tr><td style="padding:2px 12px 2px 0;color:#888;vertical-align:top;">Commande :</td><td><code style="background:#fff;padding:6px 10px;border:1px solid #ddd;border-radius:3px;font-size:11px;display:inline-block;max-width:600px;word-break:break-all;" id="cron_cmd_only">/usr/local/bin/php '.DOL_DOCUMENT_ROOT.'/custom/rentabiliteoctopia/cron/daily_kpi_mail.php >> /tmp/rentabiliteoctopia_daily.log 2>&1</code></td></tr>';
print '</table></li>';
print '<li>Cliquez sur <b>"Ajouter une nouvelle tache Cron"</b></li>';
print '</ol>';

print '<p style="font-size:13px;margin-top:14px;"><b>Ou ligne complete</b> (pour copier-coller dans une console SSH ou un fichier crontab) :</p>';
$hourCron   = isset($params['daily_kpi_send_hour'])   && $params['daily_kpi_send_hour']   !== '' ? (int)$params['daily_kpi_send_hour']   : 8;
$minuteCron = isset($params['daily_kpi_send_minute']) && $params['daily_kpi_send_minute'] !== '' ? (int)$params['daily_kpi_send_minute'] : 0;
$freqCron   = isset($params['daily_kpi_frequency'])   && $params['daily_kpi_frequency']   !== '' ? $params['daily_kpi_frequency']        : 'daily';
switch ($freqCron) {
    case '5min':   $cronSchedule = '*/5 * * * *';  break;
    case '15min':  $cronSchedule = '*/15 * * * *'; break;
    case '30min':  $cronSchedule = '*/30 * * * *'; break;
    case 'hourly': $cronSchedule = '0 * * * *';    break;
    default:       $cronSchedule = $minuteCron.' '.$hourCron.' * * *'; break;
}
$cronCmd  = $cronSchedule.' /usr/local/bin/php '.DOL_DOCUMENT_ROOT.'/custom/rentabiliteoctopia/cron/daily_kpi_mail.php >> /tmp/rentabiliteoctopia_daily.log 2>&1';
print '<code style="display:block;background:#fff;padding:10px;border-radius:4px;font-size:12px;word-break:break-all;border:1px solid #ddd;">'.dol_escape_htmltag($cronCmd).'</code>';
print '<p style="font-size:12px;color:#666;margin-top:10px;">';
print '<b>Pour tester manuellement :</b><br>';
print '<code style="background:#fff;padding:4px 8px;border-radius:3px;border:1px solid #ddd;">php '.DOL_DOCUMENT_ROOT.'/custom/rentabiliteoctopia/cron/daily_kpi_mail.php</code>';
print '</p>';
print '<p style="font-size:12px;color:#666;margin-top:10px;">';
print '<b>Pour verifier les logs d\'envoi :</b><br>';
print '<code style="background:#fff;padding:4px 8px;border-radius:3px;border:1px solid #ddd;">tail -50 /tmp/rentabiliteoctopia_daily.log</code>';
print '</p>';
print '</div>';

// ---- Cache des performances ----
$cacheInfo = new CacheMois($db, $conf->entity);
$sqlCacheCount = "SELECT COUNT(*) AS nb, MAX(date_calcul) AS derniere FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_cache_mois WHERE entity = ".((int)$conf->entity);
$rCC = $db->query($sqlCacheCount);
$nbCache = 0; $derniereCalc = null;
if ($rCC && $oCC = $db->fetch_object($rCC)) { $nbCache = (int)$oCC->nb; $derniereCalc = $oCC->derniere; }

print '<br><div style="background:#f0f4ff;border:1px solid #667eea;padding:14px;border-radius:4px;margin-top:20px;">';
print '<h4 style="margin-top:0;"><i class="fa fa-bolt"></i> Cache des performances</h4>';
print '<p style="font-size:13px;">Les mois passes sont mis en cache pour accelerer l\'affichage du tableau de bord et de la page Sante. ';
print 'Le cache se met a jour automatiquement a chaque synchronisation.</p>';
print '<p style="font-size:13px;"><b>'.$nbCache.' mois</b> actuellement en cache';
if ($derniereCalc) print ' &middot; dernier calcul : '.dol_print_date(dol_stringtotime($derniereCalc), 'dayhour');
print '</p>';
print '<form method="POST" action="admin.php" style="display:inline;">';
print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? dol_escape_htmltag($_SESSION['newtoken']) : newToken()).'">';
print '<input type="hidden" name="action" value="purge_cache">';
print '<button type="submit" class="button">Vider le cache (forcer recalcul)</button>';
print '</form>';
print '<p style="font-size:11px;color:#888;margin-top:8px;">A utiliser uniquement si les chiffres affiches semblent obsoletes apres un changement manuel en base.</p>';
print '</div>';

llxFooter();
$db->close();
