<?php
/**
 * Fonctions utilitaires du module rentabiliteoctopia v1.2
 * Modèle de coûts complet : commission par catégorie, frais réels mensuels
 */

/**
 * Formate un montant en euros avec 2 decimales obligatoires et le symbole €.
 * Utilise comme remplacement de price() pour garantir l'unicite du format.
 *
 * Ex: roc_eur(1234.5) => "1 234,50 €"
 *     roc_eur(0)      => "0,00 €"
 *     roc_eur(null)   => "0,00 €"
 */
if (!function_exists('roc_eur')) {
    function roc_eur($montant)
    {
        $val = (float)$montant;
        // price() Dolibarr (espace insecable + separateur) ; on force min 2 decimales et max 2
        return price($val, 0, '', 1, 2, 2).' €';
    }
}


/**
 * Retourne les paramètres généraux
 */
function rentabiliteoctopia_get_params($db)
{
    global $conf;
    $params = array(
        'seuil_marge_pct' => 15,
        'taux_retour_pct' => 3,
        'cout_retour'     => 2.50,
        'commission_mode' => 'pct',
    );
    $sql = "SELECT param_key, param_value FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_params WHERE entity = ".((int)$conf->entity);
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $params[$obj->param_key] = $obj->param_value;
        }
    }
    return $params;
}

/**
 * Retourne toutes les catégories avec leur taux de commission
 */
function rentabiliteoctopia_get_categories($db)
{
    global $conf;
    $cats = array();
    $sql = "SELECT rowid, code, label, commission_pct FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie
            WHERE entity = ".((int)$conf->entity)." ORDER BY label";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $cats[$obj->rowid] = array(
                'rowid'          => (int)$obj->rowid,
                'code'           => $obj->code,
                'label'          => $obj->label,
                'commission_pct' => (float)$obj->commission_pct,
            );
        }
    }
    return $cats;
}

/**
 * Retourne les frais mensuels globaux d'un mois, indexés par type_frais
 */
function rentabiliteoctopia_get_frais($db, $annee, $mois)
{
    global $conf;
    $frais = array();
    $types = array('abonnement', 'fulfilment', 'affranchissement', 'packaging', 'publicite', 'autre');
    foreach ($types as $t) {
        $frais[$t] = array('montant' => 0, 'label' => '', 'rowid' => 0);
    }
    $sql = "SELECT rowid, type_frais, label, montant FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_frais
            WHERE annee = ".(int)$annee." AND mois = ".(int)$mois." AND entity = ".((int)$conf->entity);
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $frais[$obj->type_frais] = array(
                'rowid'   => (int)$obj->rowid,
                'montant' => (float)$obj->montant,
                'label'   => $obj->label,
            );
        }
    }
    return $frais;
}

/**
 * Retourne les ventes d'un mois avec infos produit + catégorie
 */
function rentabiliteoctopia_get_ventes($db, $annee, $mois)
{
    global $conf;
    $ventes = array();
    $sql = "SELECT v.rowid, v.fk_produit, v.qty_vendue, v.prix_ht, v.cout_achat,
                   v.commission_pct, v.commission_reel,
                   p.ref, p.designation, p.fk_categorie,
                   c.label AS cat_label, c.commission_pct AS cat_commission_pct
            FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
            INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
            LEFT  JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
            WHERE v.annee = ".(int)$annee." AND v.mois = ".(int)$mois." AND v.entity = ".((int)$conf->entity)."
            ORDER BY p.ref";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $ventes[] = array(
                'rowid'              => $obj->rowid,
                'fk_produit'         => $obj->fk_produit,
                'qty_vendue'         => (int)$obj->qty_vendue,
                'prix_ht'            => (float)$obj->prix_ht,
                'cout_achat'         => (float)$obj->cout_achat,
                'commission_pct'     => $obj->commission_pct !== null ? (float)$obj->commission_pct : null,
                'commission_reel'    => $obj->commission_reel !== null ? (float)$obj->commission_reel : null,
                'ref'                => $obj->ref,
                'designation'        => $obj->designation,
                'fk_categorie'       => $obj->fk_categorie,
                'cat_label'          => $obj->cat_label,
                'cat_commission_pct' => (float)$obj->cat_commission_pct,
            );
        }
    }
    return $ventes;
}

/**
 * Calcule la commission effective pour une vente
 * Priorité : montant réel > taux produit > taux catégorie
 */
function rentabiliteoctopia_calc_commission($v)
{
    $ca = $v['qty_vendue'] * $v['prix_ht'];
    if ($v['commission_reel'] !== null && $v['commission_reel'] > 0) {
        return (float)$v['commission_reel'];
    }
    $pct = $v['commission_pct'] !== null ? $v['commission_pct'] : $v['cat_commission_pct'];
    return $ca * $pct / 100;
}

/**
 * Calcule marge brute d'une ligne de vente (hors frais globaux)
 */
function rentabiliteoctopia_calc_marge_produit($v, $params)
{
    $ca         = $v['qty_vendue'] * $v['prix_ht'];
    $commission = rentabiliteoctopia_calc_commission($v);
    $retour     = $v['qty_vendue'] * ((float)$params['taux_retour_pct']/100) * (float)$params['cout_retour'];
    $cout_total = $v['qty_vendue'] * $v['cout_achat'];
    $marge      = $ca - $cout_total - $commission - $retour;
    $taux       = $ca > 0 ? round($marge / $ca * 100, 1) : 0;
    return array(
        'ca'         => $ca,
        'commission' => $commission,
        'retour'     => $retour,
        'cout_total' => $cout_total,
        'marge'      => $marge,
        'taux_marge' => $taux,
    );
}

/**
 * Calcule les KPIs agrégés du mois
 */
function rentabiliteoctopia_calc_kpis($ventes, $frais, $params)
{
    $ca_total = $marge_produits = $commissions = $couts = $qty = 0;
    foreach ($ventes as $v) {
        $c = rentabiliteoctopia_calc_marge_produit($v, $params);
        $ca_total      += $c['ca'];
        $marge_produits+= $c['marge'];
        $commissions   += $c['commission'];
        $couts         += $c['cout_total'];
        $qty           += $v['qty_vendue'];
    }
    $frais_fixes = 0;
    foreach ($frais as $f) $frais_fixes += $f['montant'];

    $marge_nette = $marge_produits - $frais_fixes;
    $taux_marge  = $ca_total > 0 ? round($marge_produits / $ca_total * 100, 1) : 0;
    $taux_net    = $ca_total > 0 ? round($marge_nette    / $ca_total * 100, 1) : 0;

    return array(
        'ca_total'       => $ca_total,
        'marge_produits' => $marge_produits,
        'marge_nette'    => $marge_nette,
        'commissions'    => $commissions,
        'cout_achat'     => $couts,
        'frais_fixes'    => $frais_fixes,
        'taux_marge'     => $taux_marge,
        'taux_net'       => $taux_net,
        'qty_total'      => $qty,
        'nb_produits'    => count($ventes),
    );
}

/**
 * Évolution mensuelle sur l'année
 */
function rentabiliteoctopia_get_evolution($db, $annee, $params)
{
    global $conf;
    $evolution = array();
    for ($m = 1; $m <= 12; $m++) {
        $evolution[$m] = array('ca' => 0, 'marge_produits' => 0, 'frais_fixes' => 0, 'marge_nette' => 0);
    }

    // CA et marge produits
    $sql = "SELECT v.mois,
                   SUM(v.qty_vendue * v.prix_ht) AS ca,
                   SUM(v.qty_vendue * v.cout_achat) AS couts,
                   SUM(COALESCE(v.commission_reel,
                       v.qty_vendue * v.prix_ht * COALESCE(v.commission_pct, c.commission_pct, 15) / 100
                   )) AS commissions,
                   SUM(v.qty_vendue) AS qty
            FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_vente v
            INNER JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_produit p ON p.rowid = v.fk_produit
            LEFT  JOIN ".MAIN_DB_PREFIX."rentabiliteoctopia_categorie c ON c.rowid = p.fk_categorie
            WHERE v.annee = ".(int)$annee." AND v.entity = ".((int)$conf->entity)."
            GROUP BY v.mois";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $m    = (int)$obj->mois;
            $ca   = (float)$obj->ca;
            $retour = (float)$obj->qty * ((float)$params['taux_retour_pct']/100) * (float)$params['cout_retour'];
            $marge  = $ca - (float)$obj->couts - (float)$obj->commissions - $retour;
            $evolution[$m]['ca']             = $ca;
            $evolution[$m]['marge_produits'] = $marge;
        }
    }

    // Frais fixes mensuels
    $sql = "SELECT mois, SUM(montant) AS total_frais FROM ".MAIN_DB_PREFIX."rentabiliteoctopia_frais
            WHERE annee = ".(int)$annee." AND entity = ".((int)$conf->entity)." GROUP BY mois";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $m = (int)$obj->mois;
            $evolution[$m]['frais_fixes'] = (float)$obj->total_frais;
            $evolution[$m]['marge_nette'] = $evolution[$m]['marge_produits'] - (float)$obj->total_frais;
        }
    }
    return $evolution;
}
