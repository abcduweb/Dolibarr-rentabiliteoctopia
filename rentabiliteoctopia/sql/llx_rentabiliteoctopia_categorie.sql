CREATE TABLE IF NOT EXISTS llx_rentabiliteoctopia_categorie (
  rowid          integer      NOT NULL AUTO_INCREMENT,
  code           varchar(64)  NOT NULL,
  label          varchar(255) NOT NULL,
  commission_pct double(8,4)  NOT NULL DEFAULT 15.0000,
  entity         integer      NOT NULL DEFAULT 1,
  datec          datetime     DEFAULT NULL,
  tms            timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_code_entity (code, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
