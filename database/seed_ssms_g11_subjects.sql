-- =============================================================================
-- Strengthened SHS Grade 11 Subject Registry
-- =============================================================================
-- Derived from DepEd DO-017 s. 2026, SHAPE Paper Annex A, and DM 12 s. 2026
-- Run after schema.sql against the currently selected database.
-- Safe to re-run (uses INSERT IGNORE).
-- =============================================================================

SELECT
    CASE
        WHEN COUNT(*) = 1 THEN 'subjects table is ready'
        ELSE 'ERROR: Run database/schema.sql before this seed'
    END AS seed_prerequisite
FROM information_schema.tables
WHERE table_schema = DATABASE()
AND table_name = 'subjects';

-- =============================================================================
-- CORE SUBJECTS (Required for ALL Grade 11 learners, both tracks)
-- Year-long: 160 hours across Term 1 + Term 2 + Term 3
-- =============================================================================
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('ELECTCOM',    'Effective Communication',                                      'core', 11, NULL, 3, 'strengthened_shs'),
('MK',          'Mabisang Komunikasyon',                                        'core', 11, NULL, 3, 'strengthened_shs'),
('GENMATH',     'General Mathematics',                                          'core', 11, NULL, 3, 'strengthened_shs'),
('GENSCI',      'General Science',                                              'core', 11, NULL, 3, 'strengthened_shs'),
('LIFECARE',    'Life and Career Skills',                                       'core', 11, NULL, 3, 'strengthened_shs'),
('KKLP',        'Pag-aaral ng Kasaysayan at Lipunang Pilipino',                'core', 11, NULL, 3, 'strengthened_shs');

-- =============================================================================
-- ACADEMIC TRACK ELECTIVES (80 hours each, single term)
-- Students take at least 9 electives (960 hours total)
-- =============================================================================

-- Cluster 1: Arts, Social Sciences, and Humanities
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('ACOLIT1',     'Contemporary Literature 1',                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACOLIT2',     'Contemporary Literature 2',                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACRECOMP1',   'Creative Composition 1',                                       'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACRECOMP2',   'Creative Composition 2',                                       'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AFILART',     'Filipino Identity Through the Arts',                           'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AFIL1',       'Filipino 1 (Wika at Komunikasyon sa Akademikong Filipino)',    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AFIL2TP',     'Filipino 2 (Filipino sa Larang Teknikal Propesyonal)',         'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AFIL2IS',     'Filipino 2 (Filipino sa Isports)',                             'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AFIL2SD',     'Filipino 2 (Filipino sa Sining at Disenyo)',                   'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AARTS1',      'Arts 1 (Creative Industries - Visual, Literary, Media, Applied, Traditional Art)', 'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AARTS2',      'Arts 2 (Creative Industries - Music, Dance, Theater)',         'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APHIL1',      'Introduction to Philosophy',                                   'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ALEADART',    'Leadership and Management in the Arts',                        'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AMALPAG',     'Malikhaing Pagsulat',                                          'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APHGOV',      'Philippine Governance (Philippine Politics and Governance)',   'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ASOSTP',      'Social Sciences Theory and Practice',                          'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACITCIV',     'Citizenship and Civic Engagement',                             'academic_elective', 11, 'academic', 1, 'strengthened_shs');

-- Cluster 2: Business and Entrepreneurship
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('ABUS1',       'Business 1 (Basic Accounting)',                                'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AINTOM',      'Introduction to Organization and Management',                  'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ABUS2',       'Business 2 (Business Finance and Income Taxation)',            'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ABUS3',       'Business 3 (Business Economics)',                              'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACONMKG',     'Contemporary Marketing',                                       'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AENTREP',     'Entrepreneurship',                                             'academic_elective', 11, 'academic', 1, 'strengthened_shs');

-- Cluster 3: STEM
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('AFINM1',      'Finite Mathematics 1',                                         'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AFINM2',      'Finite Mathematics 2',                                         'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ABIO1',       'Biology 1',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ABIO2',       'Biology 2',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACHEM1',      'Chemistry 1',                                                  'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACHEM2',      'Chemistry 2',                                                  'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AESS1',       'Earth and Space Science 1',                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AESS2',       'Earth and Space Science 2',                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APHYS1',      'Physics 1',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APHYS2',      'Physics 2',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AADVM1',      'Advanced Mathematics 1',                                       'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AADVM2',      'Advanced Mathematics 2',                                       'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ABIO3',       'Biology 3',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ABIO4',       'Biology 4',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACHEM3',      'Chemistry 3',                                                  'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACHEM4',      'Chemistry 4',                                                  'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AESS3',       'Earth and Space Science 3',                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AESS4',       'Earth and Space Science 4',                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APHYS3',      'Physics 3',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APHYS4',      'Physics 4',                                                    'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACALC1',      'Calculus 1',                                                   'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ACALC2',      'Calculus 2',                                                   'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ATRIG1',      'Trigonometry 1',                                               'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ATRIG2',      'Trigonometry 2',                                               'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AEMPTECH',    'Empowerment Technologies',                                     'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ADATAMGT',    'Database Management',                                          'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ADATAAN',     'Fundamentals of Data Analytics and Management',                'academic_elective', 11, 'academic', 1, 'strengthened_shs');

-- Cluster 4: Sports, Health, and Wellness
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('AHUMOV1',     'Human Movement 1 (Basic Anatomy in Sports and Exercise)',      'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APE1',        'Physical Education 1 (Fitness and Recreation)',                'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AHUMOV2',     'Human Movement 2 (Motor Skills Development)',                  'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('APE2',        'Physical Education 2 (Sports and Dance)',                      'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ASPTACT',     'Sports Activity Management',                                   'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ASPTCOA',     'Sports Coaching',                                              'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ASPTOFF',     'Sports Officiating',                                           'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('AEXSPR',      'Exercise and Sports Programming',                              'academic_elective', 11, 'academic', 1, 'strengthened_shs'),
('ASAFFirstAid','Safety and First Aid',                                         'academic_elective', 11, 'academic', 1, 'strengthened_shs');

-- =============================================================================
-- TECHPRO TRACK ELECTIVES (320 hours each, full year in Grade 11)
-- Students take at least 2 TechPro electives (640 hours)
-- =============================================================================

-- Cluster 1: Aesthetic, Wellness, and Human Care
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TESPAESTH',   'Aesthetic Services (Beauty Care)',                             'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESPBARB',    'Barbering Services',                                           'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESPCARGIV',  'Caregiving (Adult Care)',                                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESPCARKID',  'Caregiving (Child Care)',                                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESPHAIR',    'Hairdressing Services',                                        'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESPWELL',    'Wellness Services (Hilot/Massage)',                            'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 2: Agri-Fishery Business and Food Innovation
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEAGRCRP',    'Agricultural Crops Production',                                'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEAGRAGR',    'Agro-entrepreneurship',                                        'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEAQUACUL',   'Aquaculture',                                                  'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEFISHCAP',   'Fish Capture Operation',                                       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEFOODPRC',   'Food Processing',                                              'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEORGAGR',    'Organic Agriculture Production',                               'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEPOULCHR',   'Poultry Production (Chicken)',                                 'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TERUMPROD',   'Ruminants Production',                                         'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESWIPROD',   'Swine Production',                                             'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 3: Artisanry and Creative Enterprise
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEGARPART',   'Garments Artisanry',                                           'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEHANDWEAV',  'Handicrafts (Weaving)',                                        'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 4: Automotive and Small Engine Technologies
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEAUTOELEC',  'Automotive Servicing (Electrical Repair)',                     'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEAUTOECHR',  'Automotive Servicing (Engine and Chassis Repairs)',            'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEAUTOALL',   'Driving and Automotive Servicing',                             'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEMOTOENG',   'Motorcycle and Small Engine Servicing',                        'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 5: Construction and Building Technologies
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TECARP',      'Carpentry',                                                    'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TECONOP',     'Construction Operation',                                       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEWELD',      'Manual Metal Arc Welding',                                     'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TETECHDFT',   'Technical Drafting',                                           'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 6: Creative Arts and Design Technologies
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEANIM',      'Animation',                                                    'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEILLUS',     'Illustration',                                                 'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEVISGDES',   'Visual Graphic Design',                                        'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 7: Hospitality and Tourism
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEBAKERY',    'Bakery Operation',                                             'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEEVTMGT',    'Events Management Services',                                   'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEFBOPER',    'Food and Beverage Operation',                                  'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEHOTELFO',   'Hotel Operation (Front Office Services)',                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEHOTELHS',   'Hotel Operation (Housekeeping Services)',                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEKITCHEN',   'Kitchen Operation',                                            'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TETOURISM',   'Tourism Services',                                             'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 8: ICT Support and Computer Programming Technologies
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEBROADINS',  'Broadband Installation',                                       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TECOMPROG1',  'Computer Programming (Java)',                                  'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TECOMPROG2',  'Computer Programming (.NET Technology)',                       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TECOMPROG3',  'Computer Programming (Oracle Database)',                       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TECOMPSERV',  'Computer Systems Servicing',                                   'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TECONCTR',    'Contact Center Services',                                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 9: Industrial Technologies
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEACINS',     'Commercial Air-Conditioning Installation and Servicing',       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TERACSERV',   'Domestic Refrigeration and Air-Conditioning Servicing',        'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEELCINS',    'Electrical Installation and Maintenance',                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEELCPROD',   'Electronics Product Assembly and Servicing',                   'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEMECHTRN',   'Mechatronics',                                                 'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEPHOTVS',    'Photovoltaic Systems Installation',                            'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- Cluster 10: Maritime Transport
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('TEMARENG',    'Marine Engineering at the Support Level',                       'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TEMARTRANS',  'Marine Transportation at the Support Level',                   'techpro_elective', 11, 'techpro', 3, 'strengthened_shs'),
('TESHPCAT',    'Ships Catering Services',                                      'techpro_elective', 11, 'techpro', 3, 'strengthened_shs');

-- =============================================================================
-- WORK IMMERSION (TechPro mandatory in G12, Academic optional)
-- 320-640 hours, typically Grade 12
-- =============================================================================
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('WORKIMM',     'Work Immersion',                                               'work_immersion', 11, NULL, 3, 'strengthened_shs');

-- =============================================================================
-- FIELD EXPERIENCE (Academic track, mainly Grade 12)
-- =============================================================================
INSERT IGNORE INTO subjects (subject_code, subject_name, subject_category, grade_level, track, term_count, curriculum) VALUES
('FIELDEXP',    'Field Experience / Exposure',                                  'field_experience_elective', 11, 'academic', 1, 'strengthened_shs');

SELECT
    COUNT(*) AS strengthened_g11_subject_count
FROM subjects
WHERE grade_level = 11
AND curriculum = 'strengthened_shs';
