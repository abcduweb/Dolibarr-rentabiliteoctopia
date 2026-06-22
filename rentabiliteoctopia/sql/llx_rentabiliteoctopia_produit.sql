CREATE TABLE IF NOT EXISTS llx_rentabiliteoctopia_produit (
  rowid          integer      NOT NULL AUTO_INCREMENT,
  ref            varchar(64)  NOT NULL,
  designation    varchar(255) NOT NULL,
  fk_categorie   integer      DEFAULT NULL,
  entity         integer      NOT NULL DEFAULT 1,
  fk_user_creat  integer      DEFAULT NULL,
  datec          datetime     DEFAULT NULL,
  tms            timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  KEY idx_ref (ref),
  KEY idx_entity (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
