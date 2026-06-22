ALTER TABLE llx_rentabiliteoctopia_vente ADD CONSTRAINT fk_vente_produit
  FOREIGN KEY (fk_produit) REFERENCES llx_rentabiliteoctopia_produit (rowid) ON DELETE CASCADE;
