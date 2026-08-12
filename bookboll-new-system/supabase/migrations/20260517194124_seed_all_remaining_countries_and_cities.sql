/*
  # Seed All Remaining Countries and Major Cities

  ## Overview
  Adds all UN-recognized countries not yet present in the database, along with
  their major/capital cities. Only genuine cities are included — administrative
  regions, governorates, and districts are excluded.

  ## Notes
  - Uses INSERT ... ON CONFLICT DO NOTHING so re-running is safe
  - All new countries default to status = 'inactive' (opt-in via Countries page)
  - Cities inherit country status; major city per country always included
*/

-- ── INSERT MISSING COUNTRIES ──────────────────────────────────────────────────
INSERT INTO countries (name, iso_code, currency_code, currency_symbol, timezone, date_format, status)
VALUES
  ('Afghanistan',               'AF', 'AFN', '؋',  'Asia/Kabul',             'DD/MM/YYYY', 'inactive'),
  ('Albania',                   'AL', 'ALL', 'L',  'Europe/Tirane',           'DD/MM/YYYY', 'inactive'),
  ('Andorra',                   'AD', 'EUR', '€',  'Europe/Andorra',          'DD/MM/YYYY', 'inactive'),
  ('Antigua and Barbuda',       'AG', 'XCD', '$',  'America/Antigua',         'DD/MM/YYYY', 'inactive'),
  ('Bahamas',                   'BS', 'BSD', '$',  'America/Nassau',          'DD/MM/YYYY', 'inactive'),
  ('Barbados',                  'BB', 'BBD', '$',  'America/Barbados',        'DD/MM/YYYY', 'inactive'),
  ('Belarus',                   'BY', 'BYN', 'Br', 'Europe/Minsk',            'DD/MM/YYYY', 'inactive'),
  ('Belize',                    'BZ', 'BZD', '$',  'America/Belize',          'DD/MM/YYYY', 'inactive'),
  ('Benin',                     'BJ', 'XOF', 'CFA','Africa/Porto-Novo',       'DD/MM/YYYY', 'inactive'),
  ('Bhutan',                    'BT', 'BTN', 'Nu', 'Asia/Thimphu',            'DD/MM/YYYY', 'inactive'),
  ('Bolivia',                   'BO', 'BOB', 'Bs', 'America/La_Paz',          'DD/MM/YYYY', 'inactive'),
  ('Bosnia and Herzegovina',    'BA', 'BAM', 'KM', 'Europe/Sarajevo',         'DD/MM/YYYY', 'inactive'),
  ('Botswana',                  'BW', 'BWP', 'P',  'Africa/Gaborone',         'DD/MM/YYYY', 'inactive'),
  ('Brunei',                    'BN', 'BND', '$',  'Asia/Brunei',             'DD/MM/YYYY', 'inactive'),
  ('Bulgaria',                  'BG', 'BGN', 'лв', 'Europe/Sofia',            'DD/MM/YYYY', 'inactive'),
  ('Burkina Faso',              'BF', 'XOF', 'CFA','Africa/Ouagadougou',      'DD/MM/YYYY', 'inactive'),
  ('Burundi',                   'BI', 'BIF', 'Fr', 'Africa/Bujumbura',        'DD/MM/YYYY', 'inactive'),
  ('Cabo Verde',                'CV', 'CVE', '$',  'Atlantic/Cape_Verde',     'DD/MM/YYYY', 'inactive'),
  ('Cambodia',                  'KH', 'KHR', '៛', 'Asia/Phnom_Penh',         'DD/MM/YYYY', 'inactive'),
  ('Central African Republic',  'CF', 'XAF', 'CFA','Africa/Bangui',          'DD/MM/YYYY', 'inactive'),
  ('Chad',                      'TD', 'XAF', 'CFA','Africa/Ndjamena',         'DD/MM/YYYY', 'inactive'),
  ('Comoros',                   'KM', 'KMF', 'Fr', 'Indian/Comoro',           'DD/MM/YYYY', 'inactive'),
  ('Congo (Brazzaville)',        'CG', 'XAF', 'CFA','Africa/Brazzaville',     'DD/MM/YYYY', 'inactive'),
  ('Congo (Kinshasa)',           'CD', 'CDF', 'FC', 'Africa/Kinshasa',         'DD/MM/YYYY', 'inactive'),
  ('Costa Rica',                'CR', 'CRC', '₡', 'America/Costa_Rica',      'DD/MM/YYYY', 'inactive'),
  ('Croatia',                   'HR', 'EUR', '€',  'Europe/Zagreb',           'DD/MM/YYYY', 'inactive'),
  ('Cuba',                      'CU', 'CUP', '$',  'America/Havana',          'DD/MM/YYYY', 'inactive'),
  ('Cyprus',                    'CY', 'EUR', '€',  'Asia/Nicosia',            'DD/MM/YYYY', 'inactive'),
  ('Djibouti',                  'DJ', 'DJF', 'Fr', 'Africa/Djibouti',         'DD/MM/YYYY', 'inactive'),
  ('Dominica',                  'DM', 'XCD', '$',  'America/Dominica',        'DD/MM/YYYY', 'inactive'),
  ('Dominican Republic',        'DO', 'DOP', '$',  'America/Santo_Domingo',   'DD/MM/YYYY', 'inactive'),
  ('East Timor',                'TL', 'USD', '$',  'Asia/Dili',               'DD/MM/YYYY', 'inactive'),
  ('El Salvador',               'SV', 'USD', '$',  'America/El_Salvador',     'DD/MM/YYYY', 'inactive'),
  ('Equatorial Guinea',         'GQ', 'XAF', 'CFA','Africa/Malabo',           'DD/MM/YYYY', 'inactive'),
  ('Eritrea',                   'ER', 'ERN', 'Nfk','Africa/Asmara',           'DD/MM/YYYY', 'inactive'),
  ('Eswatini',                  'SZ', 'SZL', 'L',  'Africa/Mbabane',          'DD/MM/YYYY', 'inactive'),
  ('Fiji',                      'FJ', 'FJD', '$',  'Pacific/Fiji',            'DD/MM/YYYY', 'inactive'),
  ('Gabon',                     'GA', 'XAF', 'CFA','Africa/Libreville',       'DD/MM/YYYY', 'inactive'),
  ('Gambia',                    'GM', 'GMD', 'D',  'Africa/Banjul',           'DD/MM/YYYY', 'inactive'),
  ('Grenada',                   'GD', 'XCD', '$',  'America/Grenada',         'DD/MM/YYYY', 'inactive'),
  ('Guatemala',                 'GT', 'GTQ', 'Q',  'America/Guatemala',       'DD/MM/YYYY', 'inactive'),
  ('Guinea',                    'GN', 'GNF', 'Fr', 'Africa/Conakry',          'DD/MM/YYYY', 'inactive'),
  ('Guinea-Bissau',             'GW', 'XOF', 'CFA','Africa/Bissau',           'DD/MM/YYYY', 'inactive'),
  ('Guyana',                    'GY', 'GYD', '$',  'America/Guyana',          'DD/MM/YYYY', 'inactive'),
  ('Haiti',                     'HT', 'HTG', 'G',  'America/Port-au-Prince',  'DD/MM/YYYY', 'inactive'),
  ('Honduras',                  'HN', 'HNL', 'L',  'America/Tegucigalpa',     'DD/MM/YYYY', 'inactive'),
  ('Iceland',                   'IS', 'ISK', 'kr', 'Atlantic/Reykjavik',      'DD/MM/YYYY', 'inactive'),
  ('Jamaica',                   'JM', 'JMD', '$',  'America/Jamaica',         'DD/MM/YYYY', 'inactive'),
  ('Kiribati',                  'KI', 'AUD', '$',  'Pacific/Tarawa',          'DD/MM/YYYY', 'inactive'),
  ('Kosovo',                    'XK', 'EUR', '€',  'Europe/Belgrade',         'DD/MM/YYYY', 'inactive'),
  ('Kyrgyzstan',                'KG', 'KGS', 'с',  'Asia/Bishkek',            'DD/MM/YYYY', 'inactive'),
  ('Laos',                      'LA', 'LAK', '₭', 'Asia/Vientiane',           'DD/MM/YYYY', 'inactive'),
  ('Latvia',                    'LV', 'EUR', '€',  'Europe/Riga',             'DD/MM/YYYY', 'inactive'),
  ('Lesotho',                   'LS', 'LSL', 'L',  'Africa/Maseru',           'DD/MM/YYYY', 'inactive'),
  ('Liberia',                   'LR', 'LRD', '$',  'Africa/Monrovia',         'DD/MM/YYYY', 'inactive'),
  ('Liechtenstein',             'LI', 'CHF', 'Fr', 'Europe/Vaduz',            'DD/MM/YYYY', 'inactive'),
  ('Lithuania',                 'LT', 'EUR', '€',  'Europe/Vilnius',          'DD/MM/YYYY', 'inactive'),
  ('Luxembourg',                'LU', 'EUR', '€',  'Europe/Luxembourg',       'DD/MM/YYYY', 'inactive'),
  ('Madagascar',                'MG', 'MGA', 'Ar', 'Indian/Antananarivo',     'DD/MM/YYYY', 'inactive'),
  ('Malawi',                    'MW', 'MWK', 'MK', 'Africa/Blantyre',         'DD/MM/YYYY', 'inactive'),
  ('Maldives',                  'MV', 'MVR', 'Rf', 'Indian/Maldives',         'DD/MM/YYYY', 'inactive'),
  ('Mali',                      'ML', 'XOF', 'CFA','Africa/Bamako',           'DD/MM/YYYY', 'inactive'),
  ('Malta',                     'MT', 'EUR', '€',  'Europe/Malta',            'DD/MM/YYYY', 'inactive'),
  ('Marshall Islands',          'MH', 'USD', '$',  'Pacific/Majuro',          'DD/MM/YYYY', 'inactive'),
  ('Mauritania',                'MR', 'MRU', 'UM', 'Africa/Nouakchott',       'DD/MM/YYYY', 'inactive'),
  ('Mauritius',                 'MU', 'MUR', '₨', 'Indian/Mauritius',         'DD/MM/YYYY', 'inactive'),
  ('Micronesia',                'FM', 'USD', '$',  'Pacific/Pohnpei',         'DD/MM/YYYY', 'inactive'),
  ('Moldova',                   'MD', 'MDL', 'L',  'Europe/Chisinau',         'DD/MM/YYYY', 'inactive'),
  ('Monaco',                    'MC', 'EUR', '€',  'Europe/Monaco',           'DD/MM/YYYY', 'inactive'),
  ('Mongolia',                  'MN', 'MNT', '₮', 'Asia/Ulaanbaatar',         'DD/MM/YYYY', 'inactive'),
  ('Montenegro',                'ME', 'EUR', '€',  'Europe/Podgorica',        'DD/MM/YYYY', 'inactive'),
  ('Mozambique',                'MZ', 'MZN', 'MT', 'Africa/Maputo',           'DD/MM/YYYY', 'inactive'),
  ('Myanmar',                   'MM', 'MMK', 'K',  'Asia/Rangoon',            'DD/MM/YYYY', 'inactive'),
  ('Namibia',                   'NA', 'NAD', '$',  'Africa/Windhoek',         'DD/MM/YYYY', 'inactive'),
  ('Nauru',                     'NR', 'AUD', '$',  'Pacific/Nauru',           'DD/MM/YYYY', 'inactive'),
  ('Nicaragua',                 'NI', 'NIO', 'C$', 'America/Managua',         'DD/MM/YYYY', 'inactive'),
  ('Niger',                     'NE', 'XOF', 'CFA','Africa/Niamey',           'DD/MM/YYYY', 'inactive'),
  ('North Korea',               'KP', 'KPW', '₩', 'Asia/Pyongyang',           'DD/MM/YYYY', 'inactive'),
  ('North Macedonia',           'MK', 'MKD', 'ден','Europe/Skopje',           'DD/MM/YYYY', 'inactive'),
  ('Palau',                     'PW', 'USD', '$',  'Pacific/Palau',           'DD/MM/YYYY', 'inactive'),
  ('Panama',                    'PA', 'PAB', 'B/', 'America/Panama',          'DD/MM/YYYY', 'inactive'),
  ('Papua New Guinea',          'PG', 'PGK', 'K',  'Pacific/Port_Moresby',   'DD/MM/YYYY', 'inactive'),
  ('Paraguay',                  'PY', 'PYG', '₲', 'America/Asuncion',         'DD/MM/YYYY', 'inactive'),
  ('Rwanda',                    'RW', 'RWF', 'Fr', 'Africa/Kigali',           'DD/MM/YYYY', 'inactive'),
  ('Saint Kitts and Nevis',     'KN', 'XCD', '$',  'America/St_Kitts',        'DD/MM/YYYY', 'inactive'),
  ('Saint Lucia',               'LC', 'XCD', '$',  'America/St_Lucia',        'DD/MM/YYYY', 'inactive'),
  ('Saint Vincent and the Grenadines', 'VC', 'XCD', '$', 'America/St_Vincent', 'DD/MM/YYYY', 'inactive'),
  ('Samoa',                     'WS', 'WST', 'T',  'Pacific/Apia',            'DD/MM/YYYY', 'inactive'),
  ('San Marino',                'SM', 'EUR', '€',  'Europe/San_Marino',       'DD/MM/YYYY', 'inactive'),
  ('Sao Tome and Principe',     'ST', 'STN', 'Db', 'Africa/Sao_Tome',         'DD/MM/YYYY', 'inactive'),
  ('Serbia',                    'RS', 'RSD', 'din','Europe/Belgrade',          'DD/MM/YYYY', 'inactive'),
  ('Seychelles',                'SC', 'SCR', '₨', 'Indian/Mahe',              'DD/MM/YYYY', 'inactive'),
  ('Sierra Leone',              'SL', 'SLL', 'Le', 'Africa/Freetown',         'DD/MM/YYYY', 'inactive'),
  ('Slovakia',                  'SK', 'EUR', '€',  'Europe/Bratislava',       'DD/MM/YYYY', 'inactive'),
  ('Slovenia',                  'SI', 'EUR', '€',  'Europe/Ljubljana',        'DD/MM/YYYY', 'inactive'),
  ('Solomon Islands',           'SB', 'SBD', '$',  'Pacific/Guadalcanal',     'DD/MM/YYYY', 'inactive'),
  ('Somalia',                   'SO', 'SOS', 'Sh', 'Africa/Mogadishu',        'DD/MM/YYYY', 'inactive'),
  ('South Sudan',               'SS', 'SSP', '£',  'Africa/Juba',             'DD/MM/YYYY', 'inactive'),
  ('Sudan',                     'SD', 'SDG', '£',  'Africa/Khartoum',         'DD/MM/YYYY', 'inactive'),
  ('Suriname',                  'SR', 'SRD', '$',  'America/Paramaribo',      'DD/MM/YYYY', 'inactive'),
  ('Tajikistan',                'TJ', 'TJS', 'SM', 'Asia/Dushanbe',           'DD/MM/YYYY', 'inactive'),
  ('Togo',                      'TG', 'XOF', 'CFA','Africa/Lome',             'DD/MM/YYYY', 'inactive'),
  ('Tonga',                     'TO', 'TOP', 'T$', 'Pacific/Tongatapu',       'DD/MM/YYYY', 'inactive'),
  ('Trinidad and Tobago',       'TT', 'TTD', '$',  'America/Port_of_Spain',   'DD/MM/YYYY', 'inactive'),
  ('Turkmenistan',              'TM', 'TMT', 'T',  'Asia/Ashgabat',           'DD/MM/YYYY', 'inactive'),
  ('Tuvalu',                    'TV', 'AUD', '$',  'Pacific/Funafuti',        'DD/MM/YYYY', 'inactive'),
  ('Uruguay',                   'UY', 'UYU', '$',  'America/Montevideo',      'DD/MM/YYYY', 'inactive'),
  ('Vanuatu',                   'VU', 'VUV', 'Vt', 'Pacific/Efate',           'DD/MM/YYYY', 'inactive'),
  ('Vatican City',              'VA', 'EUR', '€',  'Europe/Vatican',          'DD/MM/YYYY', 'inactive'),
  ('Western Sahara',            'EH', 'MAD', 'MAD','Africa/El_Aaiun',         'DD/MM/YYYY', 'inactive')
ON CONFLICT (iso_code) DO NOTHING;

-- ── INSERT CITIES FOR NEW COUNTRIES ──────────────────────────────────────────
-- Uses CTEs to resolve country IDs by iso_code

WITH c AS (SELECT id, iso_code FROM countries)
INSERT INTO cities (country_id, name, status)
SELECT c.id, v.city, 'inactive'
FROM (VALUES
  -- Afghanistan
  ('AF','Kabul'),('AF','Kandahar'),('AF','Herat'),('AF','Mazar-i-Sharif'),('AF','Jalalabad'),
  -- Albania
  ('AL','Tirana'),('AL','Durrës'),('AL','Vlorë'),('AL','Shkodër'),
  -- Andorra
  ('AD','Andorra la Vella'),
  -- Antigua and Barbuda
  ('AG','Saint John''s'),
  -- Bahamas
  ('BS','Nassau'),('BS','Freeport'),
  -- Barbados
  ('BB','Bridgetown'),
  -- Belarus
  ('BY','Minsk'),('BY','Gomel'),('BY','Mogilev'),('BY','Vitebsk'),('BY','Grodno'),('BY','Brest'),
  -- Belize
  ('BZ','Belmopan'),('BZ','Belize City'),
  -- Benin
  ('BJ','Cotonou'),('BJ','Porto-Novo'),('BJ','Parakou'),
  -- Bhutan
  ('BT','Thimphu'),('BT','Phuentsholing'),
  -- Bolivia
  ('BO','La Paz'),('BO','Santa Cruz de la Sierra'),('BO','Cochabamba'),('BO','Sucre'),('BO','Oruro'),
  -- Bosnia and Herzegovina
  ('BA','Sarajevo'),('BA','Banja Luka'),('BA','Mostar'),('BA','Tuzla'),
  -- Botswana
  ('BW','Gaborone'),('BW','Francistown'),('BW','Maun'),
  -- Brunei
  ('BN','Bandar Seri Begawan'),('BN','Kuala Belait'),
  -- Bulgaria
  ('BG','Sofia'),('BG','Plovdiv'),('BG','Varna'),('BG','Burgas'),('BG','Stara Zagora'),
  -- Burkina Faso
  ('BF','Ouagadougou'),('BF','Bobo-Dioulasso'),('BF','Koudougou'),
  -- Burundi
  ('BI','Gitega'),('BI','Bujumbura'),('BI','Muyinga'),
  -- Cabo Verde
  ('CV','Praia'),('CV','Mindelo'),
  -- Cambodia
  ('KH','Phnom Penh'),('KH','Siem Reap'),('KH','Battambang'),('KH','Sihanoukville'),
  -- Central African Republic
  ('CF','Bangui'),('CF','Bimbo'),
  -- Chad
  ('TD','N''Djamena'),('TD','Moundou'),('TD','Sarh'),
  -- Comoros
  ('KM','Moroni'),
  -- Congo (Brazzaville)
  ('CG','Brazzaville'),('CG','Pointe-Noire'),
  -- Congo (Kinshasa)
  ('CD','Kinshasa'),('CD','Lubumbashi'),('CD','Goma'),('CD','Bukavu'),('CD','Mbuji-Mayi'),
  -- Costa Rica
  ('CR','San José'),('CR','Cartago'),('CR','Alajuela'),('CR','Heredia'),
  -- Croatia
  ('HR','Zagreb'),('HR','Split'),('HR','Rijeka'),('HR','Osijek'),('HR','Dubrovnik'),
  -- Cuba
  ('CU','Havana'),('CU','Santiago de Cuba'),('CU','Camagüey'),('CU','Holguín'),
  -- Cyprus
  ('CY','Nicosia'),('CY','Limassol'),('CY','Larnaca'),('CY','Paphos'),
  -- Djibouti
  ('DJ','Djibouti City'),
  -- Dominica
  ('DM','Roseau'),
  -- Dominican Republic
  ('DO','Santo Domingo'),('DO','Santiago'),('DO','La Romana'),('DO','San Pedro de Macorís'),
  -- East Timor
  ('TL','Dili'),
  -- El Salvador
  ('SV','San Salvador'),('SV','Santa Ana'),('SV','San Miguel'),
  -- Equatorial Guinea
  ('GQ','Malabo'),('GQ','Bata'),
  -- Eritrea
  ('ER','Asmara'),('ER','Massawa'),
  -- Eswatini
  ('SZ','Mbabane'),('SZ','Manzini'),
  -- Fiji
  ('FJ','Suva'),('FJ','Nadi'),('FJ','Lautoka'),
  -- Gabon
  ('GA','Libreville'),('GA','Port-Gentil'),
  -- Gambia
  ('GM','Banjul'),('GM','Serekunda'),
  -- Grenada
  ('GD','Saint George''s'),
  -- Guatemala
  ('GT','Guatemala City'),('GT','Quetzaltenango'),('GT','Escuintla'),('GT','Villa Nueva'),
  -- Guinea
  ('GN','Conakry'),('GN','Kankan'),('GN','Nzérékoré'),
  -- Guinea-Bissau
  ('GW','Bissau'),
  -- Guyana
  ('GY','Georgetown'),('GY','Linden'),
  -- Haiti
  ('HT','Port-au-Prince'),('HT','Cap-Haïtien'),('HT','Gonaïves'),
  -- Honduras
  ('HN','Tegucigalpa'),('HN','San Pedro Sula'),('HN','Choloma'),('HN','La Ceiba'),
  -- Iceland
  ('IS','Reykjavik'),('IS','Akureyri'),('IS','Kópavogur'),
  -- Jamaica
  ('JM','Kingston'),('JM','Montego Bay'),('JM','Portmore'),
  -- Kiribati
  ('KI','South Tarawa'),
  -- Kosovo
  ('XK','Pristina'),('XK','Prizren'),('XK','Peja'),
  -- Kyrgyzstan
  ('KG','Bishkek'),('KG','Osh'),('KG','Jalal-Abad'),
  -- Laos
  ('LA','Vientiane'),('LA','Pakse'),('LA','Savannakhet'),('LA','Luang Prabang'),
  -- Latvia
  ('LV','Riga'),('LV','Daugavpils'),('LV','Liepāja'),('LV','Jēkabpils'),
  -- Lesotho
  ('LS','Maseru'),
  -- Liberia
  ('LR','Monrovia'),('LR','Gbarnga'),
  -- Liechtenstein
  ('LI','Vaduz'),
  -- Lithuania
  ('LT','Vilnius'),('LT','Kaunas'),('LT','Klaipėda'),('LT','Šiauliai'),
  -- Luxembourg
  ('LU','Luxembourg City'),('LU','Esch-sur-Alzette'),
  -- Madagascar
  ('MG','Antananarivo'),('MG','Toamasina'),('MG','Antsirabe'),('MG','Mahajanga'),
  -- Malawi
  ('MW','Lilongwe'),('MW','Blantyre'),('MW','Mzuzu'),
  -- Maldives
  ('MV','Malé'),('MV','Addu City'),
  -- Mali
  ('ML','Bamako'),('ML','Sikasso'),('ML','Mopti'),('ML','Ségou'),
  -- Malta
  ('MT','Valletta'),('MT','Birkirkara'),('MT','Mosta'),('MT','Qormi'),
  -- Marshall Islands
  ('MH','Majuro'),
  -- Mauritania
  ('MR','Nouakchott'),('MR','Nouadhibou'),
  -- Mauritius
  ('MU','Port Louis'),('MU','Vacoas-Phoenix'),('MU','Curepipe'),
  -- Micronesia
  ('FM','Palikir'),
  -- Moldova
  ('MD','Chișinău'),('MD','Tiraspol'),('MD','Bălți'),
  -- Monaco
  ('MC','Monaco'),
  -- Mongolia
  ('MN','Ulaanbaatar'),('MN','Erdenet'),('MN','Darkhan'),
  -- Montenegro
  ('ME','Podgorica'),('ME','Nikšić'),('ME','Budva'),
  -- Mozambique
  ('MZ','Maputo'),('MZ','Matola'),('MZ','Nampula'),('MZ','Beira'),('MZ','Chimoio'),
  -- Myanmar
  ('MM','Naypyidaw'),('MM','Yangon'),('MM','Mandalay'),('MM','Mawlamyine'),('MM','Bago'),
  -- Namibia
  ('NA','Windhoek'),('NA','Walvis Bay'),('NA','Swakopmund'),('NA','Rundu'),
  -- Nauru
  ('NR','Yaren'),
  -- Nicaragua
  ('NI','Managua'),('NI','León'),('NI','Masaya'),('NI','Matagalpa'),
  -- Niger
  ('NE','Niamey'),('NE','Zinder'),('NE','Maradi'),('NE','Agadez'),
  -- North Korea
  ('KP','Pyongyang'),('KP','Hamhung'),('KP','Chongjin'),
  -- North Macedonia
  ('MK','Skopje'),('MK','Bitola'),('MK','Kumanovo'),('MK','Tetovo'),
  -- Palau
  ('PW','Ngerulmud'),('PW','Koror'),
  -- Panama
  ('PA','Panama City'),('PA','Colón'),('PA','David'),
  -- Papua New Guinea
  ('PG','Port Moresby'),('PG','Lae'),('PG','Mount Hagen'),
  -- Paraguay
  ('PY','Asunción'),('PY','Ciudad del Este'),('PY','San Lorenzo'),
  -- Rwanda
  ('RW','Kigali'),('RW','Butare'),('RW','Gisenyi'),
  -- Saint Kitts and Nevis
  ('KN','Basseterre'),
  -- Saint Lucia
  ('LC','Castries'),
  -- Saint Vincent and the Grenadines
  ('VC','Kingstown'),
  -- Samoa
  ('WS','Apia'),
  -- San Marino
  ('SM','San Marino City'),
  -- Sao Tome and Principe
  ('ST','São Tomé'),
  -- Serbia
  ('RS','Belgrade'),('RS','Novi Sad'),('RS','Niš'),('RS','Kragujevac'),
  -- Seychelles
  ('SC','Victoria'),
  -- Sierra Leone
  ('SL','Freetown'),('SL','Bo'),('SL','Kenema'),
  -- Slovakia
  ('SK','Bratislava'),('SK','Košice'),('SK','Prešov'),('SK','Žilina'),
  -- Slovenia
  ('SI','Ljubljana'),('SI','Maribor'),('SI','Celje'),('SI','Kranj'),
  -- Solomon Islands
  ('SB','Honiara'),
  -- Somalia
  ('SO','Mogadishu'),('SO','Hargeisa'),('SO','Bosaso'),('SO','Kismayo'),
  -- South Sudan
  ('SS','Juba'),('SS','Wau'),('SS','Malakal'),
  -- Sudan
  ('SD','Khartoum'),('SD','Omdurman'),('SD','Port Sudan'),('SD','Kassala'),
  -- Suriname
  ('SR','Paramaribo'),
  -- Tajikistan
  ('TJ','Dushanbe'),('TJ','Khujand'),('TJ','Kulob'),
  -- Togo
  ('TG','Lomé'),('TG','Sokodé'),('TG','Kara'),
  -- Tonga
  ('TO','Nuku''alofa'),
  -- Trinidad and Tobago
  ('TT','Port of Spain'),('TT','San Fernando'),('TT','Chaguanas'),
  -- Turkmenistan
  ('TM','Ashgabat'),('TM','Turkmenbashi'),('TM','Mary'),
  -- Tuvalu
  ('TV','Funafuti'),
  -- Uruguay
  ('UY','Montevideo'),('UY','Salto'),('UY','Ciudad de la Costa'),('UY','Paysandú'),
  -- Vanuatu
  ('VU','Port Vila'),('VU','Luganville'),
  -- Vatican City
  ('VA','Vatican City'),
  -- Western Sahara
  ('EH','Laayoune'),('EH','Dakhla')
) AS v(iso, city)
JOIN c ON c.iso_code = v.iso
ON CONFLICT DO NOTHING;
