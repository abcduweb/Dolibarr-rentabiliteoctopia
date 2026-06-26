<?php
/**
 * Classe de taches planifiees pour le scheduler natif de Dolibarr.
 *
 * Le scheduler Dolibarr (Accueil > Configuration > Modules > Taches planifiees,
 * ou menu "Outils > Taches planifiees") appelle des methodes de classes.
 * Cette classe expose les jobs du module sous une forme compatible.
 *
 * Avantage vs cron systeme o2switch :
 *   - pas de probleme de chemin PHP / variables d'environnement
 *   - declenchement gere par Dolibarr (au passage d'un utilisateur ou via le cron CLI dedie)
 *   - logs et statut visibles dans l'interface Dolibarr
 *
 * Methodes exposees :
 *   - sendDailyKpiMail()   : envoie le rapport KPI quotidien
 *   - captureProductPrices(): capture le snapshot mensuel des prix
 */

class RentabiliteOctopiaCron
{
    public $db;
    public $error;
    public $errors = array();
    public $output;
    public $result;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Job : envoi du rapport KPI quotidien par email.
     *
     * Appelable par le scheduler Dolibarr.
     * Retourne 0 si succes, > 0 si erreur (convention Dolibarr cron).
     *
     * @return int
     */
    public function sendDailyKpiMail()
    {
        global $conf, $langs;

        $this->output = '';
        $this->error = '';

        // Charger les dependances
        require_once __DIR__.'/../lib/rentabiliteoctopia.lib.php';
        require_once __DIR__.'/../lib/DailyKpiMailer.class.php';

        $entity = !empty($conf->entity) ? (int)$conf->entity : 1;
        $params = rentabiliteoctopia_get_params($this->db);

        $emailEnabled = isset($params['daily_kpi_enabled']) ? (int)$params['daily_kpi_enabled'] : 0;
        $emailTo      = isset($params['daily_kpi_email'])   ? trim($params['daily_kpi_email'])   : '';

        if (!$emailEnabled) {
            $this->output = 'Rapport quotidien desactive (case decochee dans Parametres du module). Rien a envoyer.';
            return 0; // pas une erreur : juste desactive
        }

        if (empty($emailTo) || !filter_var(explode(',', $emailTo)[0], FILTER_VALIDATE_EMAIL)) {
            $this->error = 'Email destinataire invalide ou non configure dans les Parametres du module.';
            $this->errors[] = $this->error;
            return 1;
        }

        try {
            $mailer = new DailyKpiMailer($this->db, $entity, $params);
            $ok = $mailer->send();
            $this->output = implode(' | ', $mailer->logs);

            if (!$ok) {
                $this->error = 'Echec envoi : '.implode(' / ', $mailer->logs);
                $this->errors[] = $this->error;
                return 2;
            }
            return 0;
        } catch (Exception $e) {
            $this->error = 'Exception : '.$e->getMessage();
            $this->errors[] = $this->error;
            return 3;
        }
    }

    /**
     * Job : capture mensuelle du snapshot des prix (historique).
     *
     * @return int
     */
    public function captureProductPrices()
    {
        global $conf;

        $this->output = '';
        $this->error = '';

        require_once __DIR__.'/../lib/PrixHistorique.class.php';

        $entity = !empty($conf->entity) ? (int)$conf->entity : 1;

        try {
            $histo = new PrixHistorique($this->db, $entity);
            $nb = $histo->capturer();
            $this->output = $nb.' snapshot(s) de prix captures.';
            return 0;
        } catch (Exception $e) {
            $this->error = 'Exception : '.$e->getMessage();
            $this->errors[] = $this->error;
            return 1;
        }
    }
}
