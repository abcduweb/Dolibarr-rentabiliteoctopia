<?php
/**
 * Cron quotidien — Envoi par email des KPI Cdiscount.
 *
 * A lancer chaque matin via cron o2switch :
 *   0 8 * * * /usr/local/bin/php /chemin/htdocs/custom/rentabiliteoctopia/cron/daily_kpi_mail.php \
 *             >> /tmp/rentabiliteoctopia_daily.log 2>&1
 *
 * Toute la logique d'envoi est dans lib/DailyKpiMailer.class.php
 * (partagee avec le bouton "Envoyer un mail de test" de la page admin).
 */

$sapi_type = php_sapi_name();
if (substr($sapi_type, 0, 3) !== 'cli') {
    die("Script reserve aux executions CLI (cron).\n");
}

define('NOSESSION', '1');
define('NOREQUIREUSER', '1');

// Detection chemin Dolibarr
$path = __DIR__;
for ($i = 0; $i < 8; $i++) {
    if (file_exists($path.'/master.inc.php')) break;
    if (file_exists($path.'/htdocs/master.inc.php')) { $path .= '/htdocs'; break; }
    $path = dirname($path);
}
if (!file_exists($path.'/master.inc.php')) {
    die("ERREUR : impossible de trouver master.inc.php\n");
}
require_once $path.'/master.inc.php';
require_once dirname(__DIR__).'/lib/rentabiliteoctopia.lib.php';
require_once dirname(__DIR__).'/lib/DailyKpiMailer.class.php';

$entity = !empty($conf->entity) ? (int)$conf->entity : 1;
echo "[".date('Y-m-d H:i:s')."] Demarrage daily_kpi_mail (entity=$entity)\n";

$params       = rentabiliteoctopia_get_params($db);
$emailEnabled = isset($params['daily_kpi_enabled']) ? (int)$params['daily_kpi_enabled'] : 0;
$emailTo      = isset($params['daily_kpi_email'])   ? trim($params['daily_kpi_email'])   : '';

if (!$emailEnabled) {
    echo "Rapport quotidien desactive (case decoche dans Parametres). Sortie.\n";
    exit(0);
}
if (empty($emailTo) || !filter_var(explode(',', $emailTo)[0], FILTER_VALIDATE_EMAIL)) {
    echo "Email destinataire invalide ou non configure. Sortie.\n";
    exit(1);
}

$mailer = new DailyKpiMailer($db, $entity, $params);
$ok = $mailer->send();
foreach ($mailer->logs as $l) echo $l."\n";

exit($ok ? 0 : 2);
