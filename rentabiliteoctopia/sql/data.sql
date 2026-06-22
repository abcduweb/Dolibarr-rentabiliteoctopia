INSERT INTO llx_rentabiliteoctopia_categorie (code, label, commission_pct, entity) VALUES
('INFORMATIQUE',   'Informatique & Périphériques',  8.0,  1),
('TELEPHONIE',     'Téléphonie & GPS',             10.0,  1),
('TV_HIFI',        'TV, Image & Son',               8.0,  1),
('ELECTROMENAGER', 'Électroménager',                9.0,  1),
('JEUX_VIDEO',     'Jeux vidéo & Consoles',        10.0,  1),
('JOUETS',         'Jouets & Jeux',                12.0,  1),
('SPORT',          'Sports & Loisirs',             12.0,  1),
('MAISON',         'Maison & Jardin',              12.0,  1),
('MODE',           'Mode & Textile',               15.0,  1),
('BEAUTE',         'Beauté & Santé',               13.0,  1),
('ALIMENTATION',   'Alimentation & Épicerie',      10.0,  1),
('LIVRES',         'Livres & Médias',               7.0,  1),
('AUTO',           'Auto & Moto',                  10.0,  1),
('BRICOLAGE',      'Bricolage & Outillage',        10.0,  1),
('AUTRE',          'Autre / Non classé',           15.0,  1)
ON DUPLICATE KEY UPDATE commission_pct = VALUES(commission_pct);

INSERT INTO llx_rentabiliteoctopia_params (param_key, param_value, entity) VALUES
('seuil_marge_pct',    '15',        1),
('taux_retour_pct',    '3',         1),
('cout_retour',        '2.50',      1),
('nom_fournisseur',    'Cdiscount', 1),
('pcg_abonnement',     '',          1),
('pcg_fulfilment',     '',          1),
('pcg_affranchissement','',         1),
('pcg_packaging',      '',          1),
('pcg_publicite',      '',          1)
ON DUPLICATE KEY UPDATE param_value = VALUES(param_value);
