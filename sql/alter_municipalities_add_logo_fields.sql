-- Adds optional metadata and logo-management columns for municipalities.
-- Safe to run multiple times (uses IF NOT EXISTS everywhere).

ALTER TABLE municipalities
  ADD COLUMN IF NOT EXISTS slug TEXT,
  ADD COLUMN IF NOT EXISTS psgc_code TEXT,
  ADD COLUMN IF NOT EXISTS district_no SMALLINT,
  ADD COLUMN IF NOT EXISTS lgu_type TEXT CHECK (lgu_type IN ('city','municipality')),
  ADD COLUMN IF NOT EXISTS preset_logo_image TEXT,
  ADD COLUMN IF NOT EXISTS custom_logo_image TEXT,
  ADD COLUMN IF NOT EXISTS use_custom_logo BOOLEAN NOT NULL DEFAULT FALSE;

CREATE UNIQUE INDEX IF NOT EXISTS ux_municipalities_slug ON municipalities(slug);
CREATE UNIQUE INDEX IF NOT EXISTS ux_municipalities_name ON municipalities(name);

UPDATE municipalities
SET name              = 'City of General Trias',
    slug              = 'general-trias',
    lgu_type          = 'city',
    district_no       = 6,
  preset_logo_image = '/assets/City Logos/General_Trias_City_Logo.png'
WHERE municipality_id = 1;

BEGIN;

-- CITIES (IDs fixed; 1 is Gentri)
INSERT INTO municipalities (municipality_id, name, slug, lgu_type, district_no, preset_logo_image)
VALUES
  (2, 'City of Dasmariñas',  'dasmarinas',     'city', 4, '/assets/City Logos/Dasma_City_Logo.png'),
  (3, 'City of Imus',        'imus',           'city', 3, '/assets/City Logos/Imus_City_Logo.png'),
  (4, 'City of Bacoor',      'bacoor',         'city', 2, '/assets/City Logos/Bacoor_City_Logo.png'),
  (5, 'Cavite City',         'cavite-city',    'city', 1, '/assets/City Logos/Cavite_City_Logo.png'),
  (6, 'Trece Martires City', 'trece-martires', 'city', 7, '/assets/City Logos/Trece_Martires_City_Logo.png'),
  (7, 'Tagaytay City',       'tagaytay',       'city', 8, '/assets/City Logos/Tagaytay_City_Logo.png'),
  (8, 'City of Carmona',     'carmona',        'city', 5, '/assets/City Logos/Carmona_Cavite_Logo.png')
ON CONFLICT (municipality_id) DO UPDATE
SET name = EXCLUDED.name,
    slug = EXCLUDED.slug,
    lgu_type = EXCLUDED.lgu_type,
    district_no = EXCLUDED.district_no,
    preset_logo_image = EXCLUDED.preset_logo_image;

-- MUNICIPALITIES (keep 101–115)
INSERT INTO municipalities (municipality_id, name, slug, lgu_type, district_no, preset_logo_image)
VALUES
  (101, 'Kawit',                    'kawit',                    'municipality', 1, '/assets/City Logos/Kawit_Logo.png'),
  (102, 'Noveleta',                 'noveleta',                 'municipality', 1, '/assets/City Logos/Noveleta_Logo.png'),
  (103, 'Rosario',                  'rosario',                  'municipality', 1, '/assets/City Logos/Rosario_Logo.jpg'),
  (104, 'General Mariano Alvarez',  'general-mariano-alvarez',  'municipality', 5, '/assets/City Logos/General_Mariano_Alvarez_Logo.png'),
  (105, 'Silang',                   'silang',                   'municipality', 5, '/assets/City Logos/Silang_City_Logo.png'),
  (106, 'Amadeo',                   'amadeo',                   'municipality', 7, '/assets/City Logos/Amadeo_Logo.png'),
  (107, 'Indang',                   'indang',                   'municipality', 7, '/assets/City Logos/Indang_Logo.png'),
  (108, 'Tanza',                    'tanza',                    'municipality', 7, '/assets/City Logos/Tanza,_Cavite_Logo.png'),
  (109, 'Alfonso',                  'alfonso',                  'municipality', 8, '/assets/City Logos/Alfonso_Logo.png'),
  (110, 'General Emilio Aguinaldo', 'general-emilio-aguinaldo', 'municipality', 8, '/assets/City Logos/Gen_Emilio_Aguinaldo_Logo.png'),
  (111, 'Magallanes',               'magallanes',               'municipality', 8, '/assets/City Logos/Magallanes_Logo.png'),
  (112, 'Maragondon',               'maragondon',               'municipality', 8, '/assets/City Logos/Maragondon_Logo.png'),
  (113, 'Mendez-Nuñez',             'mendez-nunez',             'municipality', 8, '/assets/City Logos/Mendez_Logo.png'),
  (114, 'Naic',                     'naic',                     'municipality', 8, '/assets/City Logos/Naic_Logo.png'),
  (115, 'Ternate',                  'ternate',                  'municipality', 8, '/assets/City Logos/Ternate_Logo.png')
ON CONFLICT (municipality_id) DO UPDATE
SET name = EXCLUDED.name,
    slug = EXCLUDED.slug,
    lgu_type = EXCLUDED.lgu_type,
    district_no = EXCLUDED.district_no,
    preset_logo_image = EXCLUDED.preset_logo_image;

COMMIT;
