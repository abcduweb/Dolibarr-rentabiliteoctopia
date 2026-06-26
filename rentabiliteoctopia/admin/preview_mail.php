<?php
/**
 * Prévisualisation du mail KPI dans le navigateur.
 * Utilise la même classe DailyKpiMailer que le cron et le bouton de test,
 * mais renvoie directement le HTML au lieu de l'envoyer par mail.
 *
 * Acces : Parametres -> bouton "Apercu navigateur"
 * URL  : /custom/rentabiliteoctopia/admin/preview_mail.php
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php'))      $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php'))   $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/../lib/rentabiliteoctopia.lib.php';
require_once __DIR__.'/../lib/DailyKpiMailer.class.php';

if (!$user->rights->rentabiliteoctopia->write) accessforbidden();

$params = rentabiliteoctopia_get_params($db);
$mailer = new DailyKpiMailer($db, $conf->entity, $params);

// On envoie directement le HTML brut, sans wrapper Dolibarr
header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: SAMEORIGIN');

// Petit bandeau d'info en haut de page (clairement marque comme preview)
$banner = '<div style="position:sticky;top:0;background:#fffae6;border-bottom:2px solid #f1c40f;padding:10px 20px;font-family:Arial,sans-serif;font-size:13px;color:#7d5a00;z-index:9999;">';
$banner .= '<b>👁️ Apercu</b> du mail KPI tel qu\'il sera envoye (sujet : <code>'.htmlspecialchars($mailer->buildSubject()).'</code>). ';
$banner .= 'Cette page n\'envoie aucun mail. ';
$banner .= '<a href="admin.php" style="color:#7d5a00;font-weight:bold;">&larr; Retour aux parametres</a>';
$banner .= '</div>';

echo $banner;
echo $mailer->buildHtml();
$db->close();
