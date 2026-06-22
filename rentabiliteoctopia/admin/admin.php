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
if ($emailDefaut) print '<span style="font-size:12px;color:#666;">Email configure : <b>'.dol_escape_htmltag($emailDefaut).'</b></span>';
else print '<span style="font-size:12px;color:#c0392b;">Aucun email configure dans les parametres.</span>';
print '</form>';
print '<p style="font-size:11px;color:#888;margin-top:10px;">Le mail de test inclura le prefixe <code>[TEST]</code> dans son sujet. La case "Activer l\'envoi quotidien" n\'a pas besoin d\'etre cochee pour le test.</p>';
print '</div>';

// ---- Instructions cron ----
print '<br><div style="background:#fffae6;border:1px solid #f1c40f;padding:14px;border-radius:4px;margin-top:20px;">';
print '<h4 style="margin-top:0;">⏰ Configuration du cron quotidien</h4>';
print '<p style="font-size:13px;">Ajoutez cette ligne dans <b>cPanel o2switch &rarr; Taches Cron</b> pour recevoir le rapport tous les matins a 8h :</p>';
$cronCmd = '0 8 * * * /usr/local/bin/php '.DOL_DOCUMENT_ROOT.'/custom/rentabiliteoctopia/cron/daily_kpi_mail.php >> /tmp/rentabiliteoctopia_daily.log 2>&1';
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

llxFooter();
$db->close();
