ALTER TABLE llx_rentabiliteoctopia_produit ADD CONSTRAINT fk_produit_categorie
  FOREIGN KEY (fk_categorie) REFERENCES llx_rentabiliteoctopia_categorie (rowid) ON DELETE SET NULL;
