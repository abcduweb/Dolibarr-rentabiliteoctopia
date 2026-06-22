-- ============================================================
-- Module rentabiliteoctopia - Schéma SQL complet v1.2
-- ============================================================

-- Catégories produits avec taux de commission Cdiscount
CREATE TABLE IF NOT EXISTS `llx_rentabiliteoctopia_categorie` (
  `rowid`          int(11)       NOT NULL AUTO_INCREMENT,
  `code`           varchar(64)   NOT NULL,
  `label`          varchar(255)  NOT NULL,
  `commission_pct` double(8,4)   NOT NULL DEFAULT 15.0000 COMMENT 'Taux commission Cdiscount en %',
  `entity`         int(11)       NOT NULL DEFAULT 1,
  `datec`          datetime      DEFAULT NULL,
  `tms`            timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`),
  UNIQUE KEY `uk_code_entity` (`code`, `entity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catégories Cdiscount par défaut avec taux réels 2025
INSERT IGNORE INTO `llx_rentabiliteoctopia_categorie` (`code`, `label`, `commission_pct`, `entity`) VALUES
('INFORMATIQUE',     'Informatique & Périphériques',  8.0,  1),
('TELEPHONIE',       'Téléphonie & GPS',             10.0,  1),
('TV_HIFI',          'TV, Image & Son',               8.0,  1),
('ELECTROMENAGER',   'Électroménager',                9.0,  1),
('JEUX_VIDEO',       'Jeux vidéo & Consoles',        10.0,  1),
('JOUETS',           'Jouets & Jeux',                12.0,  1),
('SPORT',            'Sports & Loisirs',             12.0,  1),
('MAISON',           'Maison & Jardin',              12.0,  1),
('MODE',             'Mode & Textile',               15.0,  1),
('BEAUTE',           'Beauté & Santé',               13.0,  1),
('ALIMENTATION',     'Alimentation & Épicerie',      10.0,  1),
('LIVRES',           'Livres & Médias',               7.0,  1),
('AUTO',             'Auto & Moto',                  10.0,  1),
('BRICOLAGE',        'Bricolage & Outillage',        10.0,  1),
('AUTRE',            'Autre / Non classé',           15.0,  1);

-- Produits Octopia
CREATE TABLE IF NOT EXISTS `llx_rentabiliteoctopia_produit` (
  `rowid`          int(11)       NOT NULL AUTO_INCREMENT,
  `ref`            varchar(64)   NOT NULL,
  `designation`    varchar(255)  NOT NULL,
  `fk_categorie`   int(11)       DEFAULT NULL COMMENT 'Catégorie produit -> taux commission',
  `entity`         int(11)       NOT NULL DEFAULT 1,
  `fk_user_creat`  int(11)       DEFAULT NULL,
  `datec`          datetime      DEFAULT NULL,
  `tms`            timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`),
  KEY `idx_ref` (`ref`),
  KEY `idx_entity` (`entity`),
  CONSTRAINT `fk_produit_categorie` FOREIGN KEY (`fk_categorie`)
    REFERENCES `llx_rentabiliteoctopia_categorie` (`rowid`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ventes mensuelles par produit
CREATE TABLE IF NOT EXISTS `llx_rentabiliteoctopia_vente` (
  `rowid`              int(11)       NOT NULL AUTO_INCREMENT,
  `fk_produit`         int(11)       NOT NULL,
  `annee`              smallint(4)   NOT NULL,
  `mois`               tinyint(2)    NOT NULL,
  `qty_vendue`         int(11)       NOT NULL DEFAULT 0,
  `prix_ht`            double(24,8)  NOT NULL DEFAULT 0 COMMENT 'Prix unitaire moyen HT',
  `cout_achat`         double(24,8)  NOT NULL DEFAULT 0 COMMENT 'Coût achat unitaire HT',
  `commission_pct`     double(8,4)   DEFAULT NULL COMMENT 'Taux commission réel si différent catégorie',
  `commission_reel`    double(24,8)  DEFAULT NULL COMMENT 'Montant commission réel relevé Octopia (€)',
  `entity`             int(11)       NOT NULL DEFAULT 1,
  `fk_user_creat`      int(11)       DEFAULT NULL,
  `fk_user_modif`      int(11)       DEFAULT NULL,
  `datec`              datetime      DEFAULT NULL,
  `tms`                timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`),
  UNIQUE KEY `uk_produit_periode` (`fk_produit`, `annee`, `mois`, `entity`),
  KEY `idx_periode` (`annee`, `mois`),
  CONSTRAINT `fk_vente_produit` FOREIGN KEY (`fk_produit`)
    REFERENCES `llx_rentabiliteoctopia_produit` (`rowid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Frais mensuels globaux (toutes catégories confondues)
-- Un enregistrement par type de frais par mois
CREATE TABLE IF NOT EXISTS `llx_rentabiliteoctopia_frais` (
  `rowid`        int(11)       NOT NULL AUTO_INCREMENT,
  `annee`        smallint(4)   NOT NULL,
  `mois`         tinyint(2)    NOT NULL,
  `type_frais`   varchar(32)   NOT NULL COMMENT 'abonnement|fulfilment|affranchissement|packaging|publicite|autre',
  `label`        varchar(255)  DEFAULT NULL COMMENT 'Libellé libre optionnel',
  `montant`      double(24,8)  NOT NULL DEFAULT 0,
  `entity`       int(11)       NOT NULL DEFAULT 1,
  `fk_user_creat` int(11)      DEFAULT NULL,
  `fk_user_modif` int(11)      DEFAULT NULL,
  `datec`        datetime      DEFAULT NULL,
  `tms`          timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`),
  UNIQUE KEY `uk_frais_periode_type` (`annee`, `mois`, `type_frais`, `entity`),
  KEY `idx_periode` (`annee`, `mois`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Paramètres généraux du module
CREATE TABLE IF NOT EXISTS `llx_rentabiliteoctopia_params` (
  `rowid`        int(11)       NOT NULL AUTO_INCREMENT,
  `param_key`    varchar(64)   NOT NULL,
  `param_value`  varchar(255)  NOT NULL DEFAULT '',
  `entity`       int(11)       NOT NULL DEFAULT 1,
  `tms`          timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`),
  UNIQUE KEY `uk_param_entity` (`param_key`, `entity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `llx_rentabiliteoctopia_params` (`param_key`, `param_value`, `entity`) VALUES
('seuil_marge_pct',    '15',   1),
('taux_retour_pct',    '3',    1),
('cout_retour',        '2.50', 1),
('commission_mode',    'pct',  1);
