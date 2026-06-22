CREATE TABLE IF NOT EXISTS llx_rentabiliteoctopia_vente (
  rowid            integer      NOT NULL AUTO_INCREMENT,
  fk_produit       integer      NOT NULL,
  annee            smallint(4)  NOT NULL,
  mois             tinyint(2)   NOT NULL,
  qty_vendue       integer      NOT NULL DEFAULT 0,
  prix_ht          double(24,8) NOT NULL DEFAULT 0,
  cout_achat       double(24,8) NOT NULL DEFAULT 0,
  commission_pct   double(8,4)  DEFAULT NULL,
  commission_reel  double(24,8) DEFAULT NULL,
  entity           integer      NOT NULL DEFAULT 1,
  fk_user_creat    integer      DEFAULT NULL,
  fk_user_modif    integer      DEFAULT NULL,
  datec            datetime     DEFAULT NULL,
  tms              timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_produit_periode (fk_produit, annee, mois, entity),
  KEY idx_periode (annee, mois)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
