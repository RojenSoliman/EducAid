-- ===============================================
-- UNIVERSITY AND YEAR LEVEL SCHEMA UPDATE
-- Add support for university and year level in registration
-- ===============================================

-- Create universities table
CREATE TABLE IF NOT EXISTS universities (
    university_id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    code TEXT UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Create year levels table
CREATE TABLE IF NOT EXISTS year_levels (
    year_level_id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    code TEXT UNIQUE NOT NULL,
    sort_order INT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Add university and year level columns to students table
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS university_id INT REFERENCES universities(university_id),
ADD COLUMN IF NOT EXISTS year_level_id INT REFERENCES year_levels(year_level_id);

-- Insert universities with undergraduate courses in Region 4-A (CALABARZON)
INSERT INTO universities (name, code) VALUES
-- State Universities and Colleges
('Polytechnic University of the Philippines - Sto. Tomas', 'PUP_STO_TOMAS'),
('Laguna State Polytechnic University - Main Campus (Sta. Cruz)', 'LSPU_MAIN'),
('Laguna State Polytechnic University - Los Baños Campus', 'LSPU_LB'),
('Laguna State Polytechnic University - San Pablo Campus', 'LSPU_SP'),
('Laguna State Polytechnic University - Siniloan Campus', 'LSPU_SIN'),
('Batangas State University - Main Campus (Batangas City)', 'BSU_MAIN'),
('Batangas State University - ARASOF Campus (Nasugbu)', 'BSU_ARASOF'),
('Batangas State University - Alangilan Campus', 'BSU_ALANGILAN'),
('Batangas State University - Lemery Campus', 'BSU_LEMERY'),
('Batangas State University - Lipa Campus', 'BSU_LIPA'),
('Batangas State University - Malvar Campus', 'BSU_MALVAR'),
('Batangas State University - Rosario Campus', 'BSU_ROSARIO'),
('Cavite State University - Main Campus (Indang)', 'CVSU_MAIN'),
('Cavite State University - Bacoor Campus', 'CVSU_BACOOR'),
('Cavite State University - Carmona Campus', 'CVSU_CARMONA'),
('Cavite State University - Cavite City Campus', 'CVSU_CAVITE_CITY'),
('Cavite State University - Dasmariñas Campus', 'CVSU_DASMARINAS'),
('Cavite State University - General Trias Campus', 'CVSU_GENTRI'),
('Cavite State University - Imus Campus', 'CVSU_IMUS'),
('Cavite State University - Naic Campus', 'CVSU_NAIC'),
('Cavite State University - Rosario Campus', 'CVSU_ROSARIO'),
('Cavite State University - Silang Campus', 'CVSU_SILANG'),
('Cavite State University - Tanza Campus', 'CVSU_TANZA'),
('Cavite State University - Trece Martires Campus', 'CVSU_TRECE'),
('University of Rizal System - Main Campus (Tanay)', 'URS_MAIN'),
('University of Rizal System - Angono Campus', 'URS_ANGONO'),
('University of Rizal System - Antipolo Campus', 'URS_ANTIPOLO'),
('University of Rizal System - Binangonan Campus', 'URS_BINANGONAN'),
('University of Rizal System - Cainta Campus', 'URS_CAINTA'),
('University of Rizal System - Cardona Campus', 'URS_CARDONA'),
('University of Rizal System - Morong Campus', 'URS_MORONG'),
('University of Rizal System - Pililla Campus', 'URS_PILILLA'),
('University of Rizal System - Rodriguez Campus', 'URS_RODRIGUEZ'),
('Quezon Province Polytechnic University - Main Campus (Lucena)', 'QPPU_MAIN'),
('Southern Luzon State University - Main Campus (Lucban)', 'SLSU_MAIN'),
('Southern Luzon State University - Alabat Campus', 'SLSU_ALABAT'),
('Southern Luzon State University - Catanauan Campus', 'SLSU_CATANAUAN'),
('Southern Luzon State University - Gumaca Campus', 'SLSU_GUMACA'),
('Southern Luzon State University - Infanta Campus', 'SLSU_INFANTA'),
('Southern Luzon State University - Tayabas Campus', 'SLSU_TAYABAS'),
('De La Salle University - Dasmariñas', 'DLSU_DASMARINAS'),
('University of Perpetual Help System DALTA - Las Piñas', 'UPHSD_LASPINAS'),
('University of Perpetual Help System DALTA - Molino', 'UPHSD_MOLINO'),
('Lyceum of the Philippines University - Batangas', 'LPU_BATANGAS'),
('Lyceum of the Philippines University - Laguna', 'LPU_LAGUNA'),
('Technological University of the Philippines - Cavite', 'TUP_CAVITE'),
('De La Salle Lipa', 'DLSL_LIPA'),
('Malayan Colleges Laguna', 'MCL'),
('University of Asia and the Pacific', 'UA&P'),
('Assumption College San Lorenzo', 'ACSL'),
('Ateneo de Manila University - Nuvali (Laguna)', 'ADMU_NUVALI'),
('Miriam College - Nuvali', 'MC_NUVALI'),
('First Asia Institute of Technology and Humanities', 'FAITH'),
('STI College - Multiple Campuses', 'STI_REGION4A'),
('AMA Computer College - Multiple Campuses', 'AMA_REGION4A'),
('National University - Laguna', 'NU_LAGUNA'),
('Far Eastern University - Cavite', 'FEU_CAVITE'),
('Emilio Aguinaldo College - Cavite', 'EAC_CAVITE'),
('University of Batangas', 'UB'),
('Lyceum of the Philippines University - Cavite', 'LPU_CAVITE'),
('Our Lady of Fatima University - Antipolo', 'OLFU_ANTIPOLO'),
('Our Lady of Fatima University - Laguna', 'OLFU_LAGUNA'),
('Pamantasan ng Lungsod ng Maynila - Batangas Extension', 'PLM_BATANGAS'),
('Colegio de San Juan de Letran - Batangas', 'CSJL_BATANGAS'),
('Holy Angel University - Laguna Extension', 'HAU_LAGUNA'),
('University of Santo Tomas - Laguna', 'UST_LAGUNA'),
('Adamson University - Cavite Extension', 'ADU_CAVITE'),
('Jose Rizal University - Cavite', 'JRU_CAVITE'),
('Colegio ng Lungsod ng Lipa', 'CLL'),
('University of Caloocan City - Cavite Extension', 'UCC_CAVITE'),
('Centro Escolar University - Laguna', 'CEU_LAGUNA'),
('Trinity University of Asia - Quezon', 'TUA_QUEZON'),
('Manuel S. Enverga University Foundation', 'MSEUF'),
('Southern Luzon Colleges', 'SLC'),
('Quezon City University - Batangas Extension', 'QCU_BATANGAS'),
('Saint Michael College of Laguna', 'SMCL'),
('Mabini Colleges', 'MABINI'),
('Laguna College of Business and Arts', 'LCBA'),
('Batangas Eastern Colleges', 'BEC'),
('Systems Plus College Foundation', 'SPCF'),
('Columban College', 'CC'),
('Laguna Northwestern College', 'LNC'),
('Golden State College', 'GSC'),
('Rizal Technological University', 'RTU'),
('Saint Bridget College', 'SBC'),
('University of Perpetual Help Rizal', 'UPHR'),
('Colegio de San Antonio de Padua', 'CSAP'),
('Philippine Christian University - Dasmariñas', 'PCU_DASMARINAS'),
('Wesleyan University Philippines - Cavite', 'WUP_CAVITE'),
('Saint Francis College - Laguna', 'SFC_LAGUNA'),
('Philippine State College of Aeronautics', 'PHILSCA'),
('Mariners Polytechnic Colleges Foundation', 'MPCF'),
('Tanauan Institute', 'TI'),
('Other University/College (Not Listed)', 'OTHER')
ON CONFLICT (code) DO NOTHING;

-- Insert year levels
INSERT INTO year_levels (name, code, sort_order) VALUES
('1st Year', '1ST', 1),
('2nd Year', '2ND', 2),
('3rd Year', '3RD', 3),
('4th Year', '4TH', 4),
('5th Year', '5TH', 5),
('Graduate Student', 'GRAD', 6)
ON CONFLICT (code) DO NOTHING;
