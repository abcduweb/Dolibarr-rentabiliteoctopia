CREATE TABLE IF NOT EXISTS llx_rentabiliteoctopia_frais (
  rowid          integer      NOT NULL AUTO_INCREMENT,
  annee          smallint(4)  NOT NULL,
  mois           tinyint(2)   NOT NULL,
  type_frais     varchar(32)  NOT NULL,
  label          varchar(255) DEFAULT NULL,
  montant        double(24,8) NOT NULL DEFAULT 0,
  entity         integer      NOT NULL DEFAULT 1,
  fk_user_creat  integer      DEFAULT NULL,
  fk_user_modif  integer      DEFAULT NULL,
  datec          datetime     DEFAULT NULL,
  tms            timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_frais_periode_type (annee, mois, type_frais, entity),
  KEY idx_periode (annee, mois)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
