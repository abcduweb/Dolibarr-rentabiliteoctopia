<?php
/**
 * PricingEngine - Moteur de calcul de prix et de marge CENTRALISE.
 *
 * Source unique de verite pour toute la logique tarifaire du module.
 * Avant cette classe, la formule etait dupliquee (JS dans simulateur.php,
 * PHP dans optimisation_prix.php, variantes dans AlertesEngine).
 * Desormais tout passe par ici -> un seul endroit a maintenir.
 *
 * Formule centrale (la commission depend du prix de vente) :
 *   PV_HT = couts_fixes / (1 - commission% - marge%)
 * ou couts_fixes = cout_achat + port_a_charge + packaging + retours + autres - port_refacture
 */

class PricingEngine
{
    /**
     * Calcule le prix de vente HT pour atteindre une marge nette cible.
     *
     * @param array $inputs [
     *     'cout_achat'    => float,  // obligatoire
     *     'commission_pct'=> float,  // % commission Cdiscount (0 si non applicable)
     *     'marge_cible'   => float,  // % marge nette visee
     *     'port_charge'   => float,  // frais de port a ta charge
     *     'port_refac'    => float,  // port refacture au client (revenu)
     *     'packaging'     => float,
     *     'retour_taux'   => float,  // % de retours
     *     'retour_cout'   => float,  // cout unitaire d'un retour
     *     'autres'        => float,
     *     'tva_pct'       => float,  // pour calculer le TTC
     * ]
     * @return array|null  resultat detaille, ou null si calcul impossible (comm+marge >= 100%)
     */
    public static function prixPourMarge($inputs)
    {
        $coutAchat  = isset($inputs['cout_achat']) ? (float)$inputs['cout_achat'] : 0;
        $commPct    = isset($inputs['commission_pct']) ? (float)$inputs['commission_pct'] : 0;
        $margeCible = isset($inputs['marge_cible']) ? (float)$inputs['marge_cible'] : 0;
        $portCharge = isset($inputs['port_charge']) ? (float)$inputs['port_charge'] : 0;
        $portRefac  = isset($inputs['port_refac']) ? (float)$inputs['port_refac'] : 0;
        $packaging  = isset($inputs['packaging']) ? (float)$inputs['packaging'] : 0;
        $retourTaux = isset($inputs['retour_taux']) ? (float)$inputs['retour_taux'] : 0;
        $retourCout = isset($inputs['retour_cout']) ? (float)$inputs['retour_cout'] : 0;
        $autres     = isset($inputs['autres']) ? (float)$inputs['autres'] : 0;
        $tvaPct     = isset($inputs['tva_pct']) ? (float)$inputs['tva_pct'] : 20;

        $retour = ($retourTaux / 100) * $retourCout;
        $coutsFixes = $coutAchat + $portCharge + $packaging + $retour + $autres - $portRefac;

        $denom = 1 - ($commPct / 100) - ($margeCible / 100);
        if ($denom <= 0) return null;

        $pvHT = $coutsFixes / $denom;
        $commission = $pvHT * $commPct / 100;
        $margeNette = $pvHT - $coutsFixes - $commission;
        $pvTTC = $pvHT * (1 + $tvaPct / 100);
        $coef = $coutAchat > 0 ? ($pvHT / $coutAchat) : 0;

        return array(
            'pv_ht'        => $pvHT,
            'pv_ttc'       => $pvTTC,
            'commission'   => $commission,
            'marge_nette'  => $margeNette,
            'marge_pct'    => $pvHT > 0 ? ($margeNette / $pvHT * 100) : 0,
            'couts_fixes'  => $coutsFixes,
            'coefficient'  => $coef,
            'cout_achat'   => $coutAchat,
            'retour'       => $retour,
        );
    }

    /**
     * Calcule la marge a partir d'un prix de vente connu (mode inverse).
     *
     * @param float $pvHT  prix de vente HT connu
     * @param array $inputs  memes cles que prixPourMarge (sauf marge_cible ignoree)
     * @return array  resultat detaille
     */
    public static function margePourPrix($pvHT, $inputs)
    {
        $pvHT = (float)$pvHT;
        $coutAchat  = isset($inputs['cout_achat']) ? (float)$inputs['cout_achat'] : 0;
        $commPct    = isset($inputs['commission_pct']) ? (float)$inputs['commission_pct'] : 0;
        $portCharge = isset($inputs['port_charge']) ? (float)$inputs['port_charge'] : 0;
        $portRefac  = isset($inputs['port_refac']) ? (float)$inputs['port_refac'] : 0;
        $packaging  = isset($inputs['packaging']) ? (float)$inputs['packaging'] : 0;
        $retourTaux = isset($inputs['retour_taux']) ? (float)$inputs['retour_taux'] : 0;
        $retourCout = isset($inputs['retour_cout']) ? (float)$inputs['retour_cout'] : 0;
        $autres     = isset($inputs['autres']) ? (float)$inputs['autres'] : 0;
        $tvaPct     = isset($inputs['tva_pct']) ? (float)$inputs['tva_pct'] : 20;

        $retour = ($retourTaux / 100) * $retourCout;
        $coutsFixes = $coutAchat + $portCharge + $packaging + $retour + $autres - $portRefac;
        $commission = $pvHT * $commPct / 100;
        $margeNette = $pvHT - $coutsFixes - $commission;
        $pvTTC = $pvHT * (1 + $tvaPct / 100);

        return array(
            'pv_ht'        => $pvHT,
            'pv_ttc'       => $pvTTC,
            'commission'   => $commission,
            'marge_nette'  => $margeNette,
            'marge_pct'    => $pvHT > 0 ? ($margeNette / $pvHT * 100) : 0,
            'couts_fixes'  => $coutsFixes,
            'coefficient'  => $coutAchat > 0 ? ($pvHT / $coutAchat) : 0,
            'cout_achat'   => $coutAchat,
            'retour'       => $retour,
        );
    }

    /**
     * Retourne la configuration JS (formule cote client) pour le simulateur.
     * Permet d'avoir EXACTEMENT la meme formule en JS qu'en PHP.
     */
    public static function getJsFormula()
    {
        return "
        // Formule centralisee (identique a PricingEngine.php cote serveur)
        function pricingPrixPourMarge(i) {
            var retour = (i.retour_taux/100) * i.retour_cout;
            var coutsFixes = i.cout_achat + i.port_charge + i.packaging + retour + i.autres - i.port_refac;
            var denom = 1 - (i.commission_pct/100) - (i.marge_cible/100);
            if (denom <= 0) return null;
            var pvHT = coutsFixes / denom;
            var commission = pvHT * i.commission_pct / 100;
            var margeNette = pvHT - coutsFixes - commission;
            return {
                pv_ht: pvHT,
                pv_ttc: pvHT * (1 + i.tva_pct/100),
                commission: commission,
                marge_nette: margeNette,
                marge_pct: pvHT > 0 ? (margeNette/pvHT*100) : 0,
                couts_fixes: coutsFixes,
                coefficient: i.cout_achat > 0 ? (pvHT/i.cout_achat) : 0,
                retour: retour
            };
        }
        function pricingMargePourPrix(pvHT, i) {
            var retour = (i.retour_taux/100) * i.retour_cout;
            var coutsFixes = i.cout_achat + i.port_charge + i.packaging + retour + i.autres - i.port_refac;
            var commission = pvHT * i.commission_pct / 100;
            var margeNette = pvHT - coutsFixes - commission;
            return {
                pv_ht: pvHT,
                pv_ttc: pvHT * (1 + i.tva_pct/100),
                commission: commission,
                marge_nette: margeNette,
                marge_pct: pvHT > 0 ? (margeNette/pvHT*100) : 0,
                couts_fixes: coutsFixes,
                coefficient: i.cout_achat > 0 ? (pvHT/i.cout_achat) : 0,
                retour: retour
            };
        }
        ";
    }
}
