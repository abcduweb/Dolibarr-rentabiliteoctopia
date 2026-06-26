<?php
/**
 * Export CSV des analyses - format universel lisible par Excel/LibreOffice.
 *
 * Genere un CSV (separateur ; pour Excel FR, BOM UTF-8 pour les accents)
 * selon le type demande :
 *   ?type=produits    : liste produits + ventes + marges (annee)
 *   ?type=tresorerie  : periodes de reversement
 *   ?type=optimisation: prix reel vs ideal + manque a gagner
 *   ?type=saisonnalite: CA mensuel 24 mois
 *
 * Pas de dependance externe : CSV natif, ouvrable directement dans Excel.
 */

$res = 0;
if (!$res && file_exists('../main.inc.php'))       $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__.'/lib/rentabiliteoctopia.lib.php';
require_once __DIR__.'/lib/PricingEngine.class.php';

if (!$user->rights->rentabiliteoctopia->read) accessforbidden();

$type   = GETPOST('type', 'alpha') ?: 'produits';
$format = GETPOST('format', 'alpha') ?: 'csv';   // 'csv' (defaut) ou 'pdf'
$annee  = (int)(GETPOST('annee', 'int') ?: date('Y'));
$entity = (int)$conf->entity;
$params = rentabiliteoctopia_get_params($db);

// Nom de fichier (l'extension est ajoutee au moment de la sortie)
$filenameBase = 'export_'.$type.'_'.$annee.'_'.date('Ymd');
$rows = array();

// Titre lisible par type (pour l'entete du PDF)
$titresParType = array(
    'produits'        => 'Produits & rentabilite '.$annee,
    'optimisation'    => 'Optimisation des prix '.$annee,
    'saisonnalite'    => 'Saisonnalite (24 mois)',
    'historique_prix' => 'Historique des changements de prix',
);
$titrePdf = isset($titresParType[$type]) ? $titresParType[$type] : 'Export Rentabilite Octopia';

if ($type === 'produits') {
    $rows[] = array('Reference', 'Designation', 'Categorie', 'Qte vendue', 'CA HT', 'Cout achat', 'Commission', 'Marge nette', 'Taux marge %');
    $sql = "SELECT p.ref, p.designation, c.label AS cat,
                SUM(v.qty_vendue) AS qty,
                SUM(v.qty_vendue * v.prix_ht) AS ca,
                SUM(v.qty_vendue * v.cout_achat) AS cout,
                SUM(CASE
                    WHEN v.commission_reel IS NOT NULL THEN v.commission_reel
                    WHEN v.commission_pct IS NOT NULL THEN v.qty_vendue*v.prix_ht*v.commission_pct/100
                    WHEN c.commission_pct IS NOT NULL THEN v.qty_vendue*v.prix_ht*c.commission_pct/100
                    ELSE 0 END) AS comm
            FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
            INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
            LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
            WHERE v.annee = ".$annee." AND v.entity = ".$entity."
              AND p.ref NOT LIKE 'ORPHELIN-%' AND p.ref NOT LIKE 'LIBRE:%'
            GROUP BY p.rowid, p.ref, p.designation, c.label
            HAVING qty > 0
            ORDER BY ca DESC";
    $r = $db->query($sql);
    while ($r && $o = $db->fetch_object($r)) {
        $ca = (float)$o->ca; $cout = (float)$o->cout; $comm = (float)$o->comm;
        $marge = $ca - $cout - $comm;
        $taux = $ca > 0 ? ($marge/$ca*100) : 0;
        $rows[] = array($o->ref, $o->designation, $o->cat ?: 'Non categorise',
            (int)$o->qty, round($ca,2), round($cout,2), round($comm,2), round($marge,2), round($taux,1));
    }
}
elseif ($type === 'optimisation') {
    $margeCible = (float)($params['seuil_marge_pct'] ?? 15);
    $tauxRetour = (float)($params['taux_retour_pct'] ?? 3);
    $coutRetour = (float)($params['cout_retour'] ?? 2.50);
    $rows[] = array('Reference', 'Designation', 'Qte', 'Prix reel HT', 'Prix ideal HT', 'Ecart', 'Marge actuelle', 'Manque a gagner/an', 'Verdict');
    $sql = "SELECT p.ref, p.designation,
                SUM(v.qty_vendue) AS qty,
                SUM(v.qty_vendue*v.prix_ht)/NULLIF(SUM(v.qty_vendue),0) AS prix_reel,
                SUM(v.qty_vendue*v.cout_achat)/NULLIF(SUM(v.qty_vendue),0) AS cout,
                AVG(COALESCE(v.commission_pct, c.commission_pct, 0)) AS comm
            FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
            INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
            LEFT JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
            WHERE v.annee = ".$annee." AND v.entity = ".$entity."
              AND p.ref NOT LIKE 'ORPHELIN-%' AND p.ref NOT LIKE 'LIBRE:%'
            GROUP BY p.rowid HAVING qty > 0";
    $r = $db->query($sql);
    while ($r && $o = $db->fetch_object($r)) {
        $prixReel = (float)$o->prix_reel; $cout = (float)$o->cout;
        $qty = (int)$o->qty; $comm = (float)$o->comm;
        $ideal = PricingEngine::prixPourMarge(array('cout_achat'=>$cout,'commission_pct'=>$comm,'marge_cible'=>$margeCible,'retour_taux'=>$tauxRetour,'retour_cout'=>$coutRetour));
        $reel = PricingEngine::margePourPrix($prixReel, array('cout_achat'=>$cout,'commission_pct'=>$comm,'retour_taux'=>$tauxRetour,'retour_cout'=>$coutRetour));
        $prixIdeal = $ideal ? $ideal['pv_ht'] : 0;
        $ecart = $prixIdeal - $prixReel;
        $manque = $ideal ? max(0, ($ideal['marge_nette'] - $reel['marge_nette']) * $qty) : 0;
        $verdict = $ecart > 0.10 ? 'Augmenter' : ($ecart < -0.10 ? 'Marge confortable' : 'Optimal');
        if ($ecart <= 0.10) $manque = 0;
        $rows[] = array($o->ref, $o->designation, $qty, round($prixReel,2), round($prixIdeal,2),
            round($ecart,2), round($reel['marge_nette'],2), round($manque,2), $verdict);
    }
}
elseif ($type === 'saisonnalite') {
    $rows[] = array('Mois', 'CA HT', 'Unites vendues', 'Nb commandes');
    $dateDebut = date('Y-m-01', strtotime('first day of -23 month'));
    $sql = "SELECT DATE_FORMAT(c.date_commande, '%Y-%m') AS mois,
                SUM(cd.qty*cd.subprice) AS ca, SUM(cd.qty) AS qty, COUNT(DISTINCT o.rowid) AS nb
            FROM ".MAIN_DB_PREFIX."octopia_orders o
            INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = o.dolibarr_order_id
                AND c.entity = ".$entity." AND c.fk_statut >= 1 AND c.date_commande >= '".$dateDebut."'
            INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid AND cd.fk_product > 0
            WHERE o.entity = ".$entity." AND o.is_refunded = 0
            GROUP BY mois ORDER BY mois";
    $r = $db->query($sql);
    while ($r && $o = $db->fetch_object($r)) {
        $rows[] = array($o->mois, round((float)$o->ca,2), (int)$o->qty, (int)$o->nb);
    }
}
elseif ($type === 'historique_prix') {
    require_once __DIR__.'/lib/PrixHistorique.class.php';
    $histo = new PrixHistorique($db, $entity);
    $changements = $histo->getChangements(3);
    $rows[] = array('Reference', 'Designation', 'Periode avant', 'Periode apres', 'Prix avant', 'Prix apres',
        'Variation prix %', 'Qte avant', 'Qte apres', 'Variation volume %', 'Impact marge');
    foreach ($changements as $c) {
        $rows[] = array($c['ref'], $c['designation'], $c['mois_avant'], $c['mois_apres'],
            round($c['prix_avant'],2), round($c['prix_apres'],2), round($c['variation_pct'],1),
            $c['qty_avant'], $c['qty_apres'],
            ($c['variation_volume'] === null ? '' : round($c['variation_volume'],0)),
            round($c['impact_marge'],2));
    }
}
else {
    // Type inconnu
    header('Content-Type: text/plain; charset=UTF-8');
    print "Type d'export inconnu : ".dol_escape_htmltag($type);
    exit;
}

// ============================================================================
// GENERATION DU FICHIER (PDF ou CSV)
// ============================================================================
if ($format === 'pdf') {
    // ---- Export PDF via TCPDF embarque dans Dolibarr (aucune dependance externe) ----
    require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

    if (empty($rows)) { $rows[] = array('Aucune donnee disponible'); }

    // Separer l'entete (1re ligne) des lignes de donnees
    $header = array_shift($rows);
    $nbCols = is_array($header) ? count($header) : 1;
    $nbLignesData = count($rows);

    // Police unicode geree par Dolibarr (accents corrects)
    $pdfFont = function_exists('pdf_getPDFFont') ? pdf_getPDFFont($langs) : 'helvetica';

    $pdf = pdf_getInstance();
    $pdf->SetCreator('Rentabilite Octopia');
    $pdf->SetTitle($titrePdf);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(10, 12, 10);
    $pdf->SetAutoPageBreak(true, 12);
    // Paysage pour les tableaux larges (plus de 5 colonnes)
    $orientation = ($nbCols > 5) ? 'L' : 'P';
    $pdf->AddPage($orientation);

    // En-tete du document
    $pdf->SetFont($pdfFont, 'B', 14);
    $pdf->SetTextColor(77, 72, 68);
    $pdf->Cell(0, 8, $titrePdf, 0, 1, 'L');
    $pdf->SetFont($pdfFont, '', 9);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 5, 'Genere le '.dol_print_date(dol_now(), 'dayhour').' - '.$nbLignesData.' ligne(s)', 0, 1, 'L');
    $pdf->Ln(3);
    $pdf->SetTextColor(0, 0, 0);

    // Tableau HTML (TCPDF::writeHTML gere les tables stylees)
    $html  = '<style>';
    $html .= 'table { border-collapse: collapse; width: 100%; }';
    $html .= 'th { background-color: #8ab734; color: #ffffff; font-weight: bold; font-size: 8px; padding: 4px; border: 0.5px solid #cccccc; }';
    $html .= 'td { font-size: 8px; padding: 3px; border: 0.5px solid #dddddd; }';
    $html .= '</style>';
    $html .= '<table><thead><tr>';
    foreach ($header as $h) {
        $html .= '<th>'.htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8').'</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>'.htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8').'</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    $pdf->writeHTML($html, true, false, false, false, '');
    $pdf->Output($filenameBase.'.pdf', 'D'); // D = telechargement

    $db->close();
    exit;
}

// ---- Export CSV (defaut) ----
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filenameBase.'.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate');

// BOM UTF-8 pour qu'Excel reconnaisse les accents
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
foreach ($rows as $row) {
    // Separateur ; pour Excel francais
    fputcsv($out, $row, ';');
}
fclose($out);

$db->close();
exit;
