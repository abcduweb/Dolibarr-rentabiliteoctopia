<?php
/**
 * Redirection vers la page d'administration principale (admin/admin.php)
 * Ce fichier est conservé pour compatibilité avec config_page_url
 * mais la vraie page admin est dans admin/admin.php
 */
$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

header('Location: '.dol_buildpath('/rentabiliteoctopia/admin/admin.php', 1));
exit;
