#!/usr/bin/env php
<?php
/**
 * Script cron : synchronisation automatique octopiaSync -> rentabiliteoctopia
 *
 * À planifier dans cPanel o2switch :
 *   0 3 * * * /usr/local/bin/php /chemin/vers/dolibarr/htdocs/rentabiliteoctopia/cron/sync_octopia.php >> /tmp/rentabiliteoctopia_cron.log 2>&1
 *
 * Ou via le scheduler Dolibarr (Administration > Agenda > Tâches planifiées)
 * en appelant la méthode : OctopiaRentabiliteSyncJob::run
 *
 * Comportement par défaut : synchronise le mois en cours + le mois précédent
 * (pour rattraper les commandes dont le statut a changé après clôture)
 */

// Vérification appel CLI ou HTTP restreint
if (php_sapi_name() !== 'cli') {
    // Autoriser l'appel HTTP depuis Dolibarr scheduler uniquement si clé valide
    if (empty($_GET['cronkey']) || $_GET['cronkey'] !== getenv('DOLIBARR_CRON_KEY')) {
        http_response_code(403);
        die('Forbidden');
    }
}

// Chargement Dolibarr
$res = 0;
$paths = array(
    __DIR__.'/../../main.inc.php',
    __DIR__.'/../../../main.inc.php',
    __DIR__.'/../../../../main.inc.php',
);
foreach ($paths as $p) {
    if (!$res && file_exists($p)) $res = @include $p;
}
if (!$res) die("Impossible de charger main.inc.php\n");

require_once DOL_DOCUMENT_ROOT.'/rentabiliteoctopia/lib/OctopiaRentabiliteSync.class.php';

// ---- Configuration ----
$annee_courante  = (int)date('Y');
$mois_courant    = (int)date('m');
$mois_precedent  = $mois_courant === 1 ? 12 : $mois_courant - 1;
$annee_precedent = $mois_courant === 1 ? $annee_courante - 1 : $annee_courante;

echo date('[Y-m-d H:i:s]')." Démarrage synchro rentabiliteoctopia\n";

$sync = new OctopiaRentabiliteSync($db, $conf->entity);

// Synchro mois en cours
echo date('[Y-m-d H:i:s]')." Synchro $annee_courante-$mois_courant...\n";
$ok1 = $sync->syncMois($annee_courante, $mois_courant);

// Synchro mois précédent (rattrapage statuts)
echo date('[Y-m-d H:i:s]')." Synchro $annee_precedent-$mois_precedent (rattrapage)...\n";
$ok2 = $sync->syncMois($annee_precedent, $mois_precedent);

// Affichage logs
foreach ($sync->logs as $l) {
    $prefix = $l['level'] === 'error' ? '[ERREUR]' : '[INFO]  ';
    echo date('[Y-m-d H:i:s]')." $prefix {$l['msg']}\n";
}

$status = ($ok1 && $ok2) ? 0 : 1;
echo date('[Y-m-d H:i:s]')." Terminé. Produits créés : {$sync->nb_produits_crees}, Lignes MàJ : {$sync->nb_ventes_maj}, Erreurs : {$sync->nb_erreurs}\n";
exit($status);
