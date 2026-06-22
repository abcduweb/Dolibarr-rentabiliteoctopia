CREATE TABLE IF NOT EXISTS llx_rentabiliteoctopia_params (
  rowid        integer      NOT NULL AUTO_INCREMENT,
  param_key    varchar(64)  NOT NULL,
  param_value  varchar(255) NOT NULL DEFAULT '',
  entity       integer      NOT NULL DEFAULT 1,
  tms          timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_param_entity (param_key, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
