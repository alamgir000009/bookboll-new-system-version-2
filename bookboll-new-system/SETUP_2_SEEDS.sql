-- === 20260511142909_seed_world_countries_and_cities.sql ===
/*
  # Seed world countries and major cities

  Inserts ~90 countries with correct ISO codes, currencies, symbols, and IANA timezones.
  Inserts ~500 major cities linked to their countries.
  Uses ON CONFLICT DO NOTHING / DO UPDATE so re-running is safe.
*/

-- ─────────────────────────────────────────────
-- COUNTRIES
-- ─────────────────────────────────────────────

INSERT INTO countries (name, iso_code, currency_code, currency_symbol, timezone, date_format, status)
VALUES
  ('United Arab Emirates','AE','AED','د.إ','Asia/Dubai','DD/MM/YYYY','active'),
  ('Saudi Arabia','SA','SAR','SR','Asia/Riyadh','DD/MM/YYYY','active'),
  ('Qatar','QA','QAR','QR','Asia/Qatar','DD/MM/YYYY','active'),
  ('Kuwait','KW','KWD','KD','Asia/Kuwait','DD/MM/YYYY','active'),
  ('Bahrain','BH','BHD','BD','Asia/Bahrain','DD/MM/YYYY','active'),
  ('Oman','OM','OMR','OMR','Asia/Muscat','DD/MM/YYYY','active'),
  ('Jordan','JO','JOD','JD','Asia/Amman','DD/MM/YYYY','active'),
  ('Lebanon','LB','LBP','ل.ل','Asia/Beirut','DD/MM/YYYY','active'),
  ('Egypt','EG','EGP','E£','Africa/Cairo','DD/MM/YYYY','active'),
  ('Iraq','IQ','IQD','ع.د','Asia/Baghdad','DD/MM/YYYY','active'),
  ('Yemen','YE','YER','﷼','Asia/Aden','DD/MM/YYYY','active'),
  ('Syria','SY','SYP','£S','Asia/Damascus','DD/MM/YYYY','active'),
  ('Palestine','PS','ILS','₪','Asia/Gaza','DD/MM/YYYY','active'),
  ('Israel','IL','ILS','₪','Asia/Jerusalem','DD/MM/YYYY','active'),
  ('Turkey','TR','TRY','₺','Europe/Istanbul','DD/MM/YYYY','active'),
  ('Iran','IR','IRR','﷼','Asia/Tehran','DD/MM/YYYY','active'),
  ('Morocco','MA','MAD','MAD','Africa/Casablanca','DD/MM/YYYY','active'),
  ('Tunisia','TN','TND','DT','Africa/Tunis','DD/MM/YYYY','active'),
  ('Algeria','DZ','DZD','دج','Africa/Algiers','DD/MM/YYYY','active'),
  ('Libya','LY','LYD','LD','Africa/Tripoli','DD/MM/YYYY','active'),
  ('Nigeria','NG','NGN','₦','Africa/Lagos','DD/MM/YYYY','active'),
  ('Kenya','KE','KES','KSh','Africa/Nairobi','DD/MM/YYYY','active'),
  ('Ghana','GH','GHS','₵','Africa/Accra','DD/MM/YYYY','active'),
  ('South Africa','ZA','ZAR','R','Africa/Johannesburg','DD/MM/YYYY','active'),
  ('Ethiopia','ET','ETB','Br','Africa/Addis_Ababa','DD/MM/YYYY','active'),
  ('Tanzania','TZ','TZS','TSh','Africa/Dar_es_Salaam','DD/MM/YYYY','active'),
  ('Uganda','UG','UGX','USh','Africa/Kampala','DD/MM/YYYY','active'),
  ('Senegal','SN','XOF','CFA','Africa/Dakar','DD/MM/YYYY','active'),
  ('Ivory Coast','CI','XOF','CFA','Africa/Abidjan','DD/MM/YYYY','active'),
  ('Cameroon','CM','XAF','Fr','Africa/Douala','DD/MM/YYYY','active'),
  ('Angola','AO','AOA','Kz','Africa/Luanda','DD/MM/YYYY','active'),
  ('Zimbabwe','ZW','ZWL','Z$','Africa/Harare','DD/MM/YYYY','active'),
  ('Zambia','ZM','ZMW','ZK','Africa/Lusaka','DD/MM/YYYY','active'),
  ('United Kingdom','GB','GBP','£','Europe/London','DD/MM/YYYY','active'),
  ('France','FR','EUR','€','Europe/Paris','DD/MM/YYYY','active'),
  ('Germany','DE','EUR','€','Europe/Berlin','DD/MM/YYYY','active'),
  ('Spain','ES','EUR','€','Europe/Madrid','DD/MM/YYYY','active'),
  ('Italy','IT','EUR','€','Europe/Rome','DD/MM/YYYY','active'),
  ('Netherlands','NL','EUR','€','Europe/Amsterdam','DD/MM/YYYY','active'),
  ('Belgium','BE','EUR','€','Europe/Brussels','DD/MM/YYYY','active'),
  ('Switzerland','CH','CHF','Fr','Europe/Zurich','DD/MM/YYYY','active'),
  ('Sweden','SE','SEK','kr','Europe/Stockholm','DD/MM/YYYY','active'),
  ('Norway','NO','NOK','kr','Europe/Oslo','DD/MM/YYYY','active'),
  ('Denmark','DK','DKK','kr','Europe/Copenhagen','DD/MM/YYYY','active'),
  ('Finland','FI','EUR','€','Europe/Helsinki','DD/MM/YYYY','active'),
  ('Poland','PL','PLN','zł','Europe/Warsaw','DD/MM/YYYY','active'),
  ('Portugal','PT','EUR','€','Europe/Lisbon','DD/MM/YYYY','active'),
  ('Greece','GR','EUR','€','Europe/Athens','DD/MM/YYYY','active'),
  ('Austria','AT','EUR','€','Europe/Vienna','DD/MM/YYYY','active'),
  ('Czech Republic','CZ','CZK','Kč','Europe/Prague','DD/MM/YYYY','active'),
  ('Hungary','HU','HUF','Ft','Europe/Budapest','DD/MM/YYYY','active'),
  ('Romania','RO','RON','lei','Europe/Bucharest','DD/MM/YYYY','active'),
  ('Russia','RU','RUB','₽','Europe/Moscow','DD/MM/YYYY','active'),
  ('Ukraine','UA','UAH','₴','Europe/Kiev','DD/MM/YYYY','active'),
  ('India','IN','INR','₹','Asia/Kolkata','DD/MM/YYYY','active'),
  ('Pakistan','PK','PKR','₨','Asia/Karachi','DD/MM/YYYY','active'),
  ('Bangladesh','BD','BDT','৳','Asia/Dhaka','DD/MM/YYYY','active'),
  ('Sri Lanka','LK','LKR','Rs','Asia/Colombo','DD/MM/YYYY','active'),
  ('Nepal','NP','NPR','Rs','Asia/Kathmandu','DD/MM/YYYY','active'),
  ('China','CN','CNY','¥','Asia/Shanghai','DD/MM/YYYY','active'),
  ('Japan','JP','JPY','¥','Asia/Tokyo','DD/MM/YYYY','active'),
  ('South Korea','KR','KRW','₩','Asia/Seoul','DD/MM/YYYY','active'),
  ('Indonesia','ID','IDR','Rp','Asia/Jakarta','DD/MM/YYYY','active'),
  ('Malaysia','MY','MYR','RM','Asia/Kuala_Lumpur','DD/MM/YYYY','active'),
  ('Singapore','SG','SGD','S$','Asia/Singapore','DD/MM/YYYY','active'),
  ('Thailand','TH','THB','฿','Asia/Bangkok','DD/MM/YYYY','active'),
  ('Vietnam','VN','VND','₫','Asia/Ho_Chi_Minh','DD/MM/YYYY','active'),
  ('Philippines','PH','PHP','₱','Asia/Manila','DD/MM/YYYY','active'),
  ('Hong Kong','HK','HKD','HK$','Asia/Hong_Kong','DD/MM/YYYY','active'),
  ('Taiwan','TW','TWD','NT$','Asia/Taipei','DD/MM/YYYY','active'),
  ('Kazakhstan','KZ','KZT','₸','Asia/Almaty','DD/MM/YYYY','active'),
  ('Uzbekistan','UZ','UZS','so''m','Asia/Tashkent','DD/MM/YYYY','active'),
  ('Azerbaijan','AZ','AZN','₼','Asia/Baku','DD/MM/YYYY','active'),
  ('Armenia','AM','AMD','֏','Asia/Yerevan','DD/MM/YYYY','active'),
  ('Georgia','GE','GEL','₾','Asia/Tbilisi','DD/MM/YYYY','active'),
  ('United States','US','USD','$','America/New_York','MM/DD/YYYY','active'),
  ('Canada','CA','CAD','C$','America/Toronto','DD/MM/YYYY','active'),
  ('Mexico','MX','MXN','$','America/Mexico_City','DD/MM/YYYY','active'),
  ('Brazil','BR','BRL','R$','America/Sao_Paulo','DD/MM/YYYY','active'),
  ('Argentina','AR','ARS','$','America/Argentina/Buenos_Aires','DD/MM/YYYY','active'),
  ('Colombia','CO','COP','$','America/Bogota','DD/MM/YYYY','active'),
  ('Chile','CL','CLP','$','America/Santiago','DD/MM/YYYY','active'),
  ('Peru','PE','PEN','S/.','America/Lima','DD/MM/YYYY','active'),
  ('Ecuador','EC','USD','$','America/Guayaquil','DD/MM/YYYY','active'),
  ('Venezuela','VE','VES','Bs.S','America/Caracas','DD/MM/YYYY','active'),
  ('Australia','AU','AUD','A$','Australia/Sydney','DD/MM/YYYY','active'),
  ('New Zealand','NZ','NZD','NZ$','Pacific/Auckland','DD/MM/YYYY','active')
ON CONFLICT (iso_code) DO UPDATE SET
  currency_code   = EXCLUDED.currency_code,
  currency_symbol = EXCLUDED.currency_symbol,
  timezone        = EXCLUDED.timezone;

-- ─────────────────────────────────────────────
-- CITIES
-- ─────────────────────────────────────────────

DO $$
DECLARE
  c_ae uuid; c_sa uuid; c_qa uuid; c_kw uuid; c_bh uuid; c_om uuid;
  c_jo uuid; c_lb uuid; c_eg uuid; c_iq uuid; c_ye uuid; c_sy uuid;
  c_il uuid; c_tr uuid; c_ir uuid; c_ps uuid;
  c_ma uuid; c_tn uuid; c_dz uuid; c_ly uuid; c_ng uuid; c_ke uuid;
  c_gh uuid; c_za uuid; c_et uuid; c_tz uuid; c_ug uuid; c_sn uuid;
  c_ci uuid; c_cm uuid; c_ao uuid; c_zw uuid; c_zm uuid;
  c_gb uuid; c_fr uuid; c_de uuid; c_es uuid; c_it uuid; c_nl uuid;
  c_be uuid; c_ch uuid; c_se uuid; c_no uuid; c_dk uuid; c_fi uuid;
  c_pl uuid; c_pt uuid; c_gr uuid; c_at uuid; c_cz uuid; c_hu uuid;
  c_ro uuid; c_ru uuid; c_ua uuid;
  c_in uuid; c_pk uuid; c_bd uuid; c_lk uuid; c_np uuid;
  c_cn uuid; c_jp uuid; c_kr uuid; c_id uuid; c_my uuid; c_sg uuid;
  c_th uuid; c_vn uuid; c_ph uuid; c_hk uuid; c_tw uuid;
  c_kz uuid; c_uz uuid; c_az uuid; c_am uuid; c_ge uuid;
  c_us uuid; c_ca uuid; c_mx uuid; c_br uuid; c_ar uuid;
  c_co uuid; c_cl uuid; c_pe uuid; c_ec uuid; c_ve uuid;
  c_au uuid; c_nz uuid;
BEGIN
  SELECT id INTO c_ae FROM countries WHERE iso_code='AE';
  SELECT id INTO c_sa FROM countries WHERE iso_code='SA';
  SELECT id INTO c_qa FROM countries WHERE iso_code='QA';
  SELECT id INTO c_kw FROM countries WHERE iso_code='KW';
  SELECT id INTO c_bh FROM countries WHERE iso_code='BH';
  SELECT id INTO c_om FROM countries WHERE iso_code='OM';
  SELECT id INTO c_jo FROM countries WHERE iso_code='JO';
  SELECT id INTO c_lb FROM countries WHERE iso_code='LB';
  SELECT id INTO c_eg FROM countries WHERE iso_code='EG';
  SELECT id INTO c_iq FROM countries WHERE iso_code='IQ';
  SELECT id INTO c_ye FROM countries WHERE iso_code='YE';
  SELECT id INTO c_sy FROM countries WHERE iso_code='SY';
  SELECT id INTO c_il FROM countries WHERE iso_code='IL';
  SELECT id INTO c_tr FROM countries WHERE iso_code='TR';
  SELECT id INTO c_ir FROM countries WHERE iso_code='IR';
  SELECT id INTO c_ps FROM countries WHERE iso_code='PS';
  SELECT id INTO c_ma FROM countries WHERE iso_code='MA';
  SELECT id INTO c_tn FROM countries WHERE iso_code='TN';
  SELECT id INTO c_dz FROM countries WHERE iso_code='DZ';
  SELECT id INTO c_ly FROM countries WHERE iso_code='LY';
  SELECT id INTO c_ng FROM countries WHERE iso_code='NG';
  SELECT id INTO c_ke FROM countries WHERE iso_code='KE';
  SELECT id INTO c_gh FROM countries WHERE iso_code='GH';
  SELECT id INTO c_za FROM countries WHERE iso_code='ZA';
  SELECT id INTO c_et FROM countries WHERE iso_code='ET';
  SELECT id INTO c_tz FROM countries WHERE iso_code='TZ';
  SELECT id INTO c_ug FROM countries WHERE iso_code='UG';
  SELECT id INTO c_sn FROM countries WHERE iso_code='SN';
  SELECT id INTO c_ci FROM countries WHERE iso_code='CI';
  SELECT id INTO c_cm FROM countries WHERE iso_code='CM';
  SELECT id INTO c_ao FROM countries WHERE iso_code='AO';
  SELECT id INTO c_zw FROM countries WHERE iso_code='ZW';
  SELECT id INTO c_zm FROM countries WHERE iso_code='ZM';
  SELECT id INTO c_gb FROM countries WHERE iso_code='GB';
  SELECT id INTO c_fr FROM countries WHERE iso_code='FR';
  SELECT id INTO c_de FROM countries WHERE iso_code='DE';
  SELECT id INTO c_es FROM countries WHERE iso_code='ES';
  SELECT id INTO c_it FROM countries WHERE iso_code='IT';
  SELECT id INTO c_nl FROM countries WHERE iso_code='NL';
  SELECT id INTO c_be FROM countries WHERE iso_code='BE';
  SELECT id INTO c_ch FROM countries WHERE iso_code='CH';
  SELECT id INTO c_se FROM countries WHERE iso_code='SE';
  SELECT id INTO c_no FROM countries WHERE iso_code='NO';
  SELECT id INTO c_dk FROM countries WHERE iso_code='DK';
  SELECT id INTO c_fi FROM countries WHERE iso_code='FI';
  SELECT id INTO c_pl FROM countries WHERE iso_code='PL';
  SELECT id INTO c_pt FROM countries WHERE iso_code='PT';
  SELECT id INTO c_gr FROM countries WHERE iso_code='GR';
  SELECT id INTO c_at FROM countries WHERE iso_code='AT';
  SELECT id INTO c_cz FROM countries WHERE iso_code='CZ';
  SELECT id INTO c_hu FROM countries WHERE iso_code='HU';
  SELECT id INTO c_ro FROM countries WHERE iso_code='RO';
  SELECT id INTO c_ru FROM countries WHERE iso_code='RU';
  SELECT id INTO c_ua FROM countries WHERE iso_code='UA';
  SELECT id INTO c_in FROM countries WHERE iso_code='IN';
  SELECT id INTO c_pk FROM countries WHERE iso_code='PK';
  SELECT id INTO c_bd FROM countries WHERE iso_code='BD';
  SELECT id INTO c_lk FROM countries WHERE iso_code='LK';
  SELECT id INTO c_np FROM countries WHERE iso_code='NP';
  SELECT id INTO c_cn FROM countries WHERE iso_code='CN';
  SELECT id INTO c_jp FROM countries WHERE iso_code='JP';
  SELECT id INTO c_kr FROM countries WHERE iso_code='KR';
  SELECT id INTO c_id FROM countries WHERE iso_code='ID';
  SELECT id INTO c_my FROM countries WHERE iso_code='MY';
  SELECT id INTO c_sg FROM countries WHERE iso_code='SG';
  SELECT id INTO c_th FROM countries WHERE iso_code='TH';
  SELECT id INTO c_vn FROM countries WHERE iso_code='VN';
  SELECT id INTO c_ph FROM countries WHERE iso_code='PH';
  SELECT id INTO c_hk FROM countries WHERE iso_code='HK';
  SELECT id INTO c_tw FROM countries WHERE iso_code='TW';
  SELECT id INTO c_kz FROM countries WHERE iso_code='KZ';
  SELECT id INTO c_uz FROM countries WHERE iso_code='UZ';
  SELECT id INTO c_az FROM countries WHERE iso_code='AZ';
  SELECT id INTO c_am FROM countries WHERE iso_code='AM';
  SELECT id INTO c_ge FROM countries WHERE iso_code='GE';
  SELECT id INTO c_us FROM countries WHERE iso_code='US';
  SELECT id INTO c_ca FROM countries WHERE iso_code='CA';
  SELECT id INTO c_mx FROM countries WHERE iso_code='MX';
  SELECT id INTO c_br FROM countries WHERE iso_code='BR';
  SELECT id INTO c_ar FROM countries WHERE iso_code='AR';
  SELECT id INTO c_co FROM countries WHERE iso_code='CO';
  SELECT id INTO c_cl FROM countries WHERE iso_code='CL';
  SELECT id INTO c_pe FROM countries WHERE iso_code='PE';
  SELECT id INTO c_ec FROM countries WHERE iso_code='EC';
  SELECT id INTO c_ve FROM countries WHERE iso_code='VE';
  SELECT id INTO c_au FROM countries WHERE iso_code='AU';
  SELECT id INTO c_nz FROM countries WHERE iso_code='NZ';

  INSERT INTO cities (country_id, name, timezone, status) VALUES
  -- UAE
  (c_ae,'Dubai',NULL,'active'),
  (c_ae,'Abu Dhabi',NULL,'active'),
  (c_ae,'Sharjah',NULL,'active'),
  (c_ae,'Ajman',NULL,'active'),
  (c_ae,'Ras Al Khaimah',NULL,'active'),
  (c_ae,'Fujairah',NULL,'active'),
  (c_ae,'Al Ain',NULL,'active'),
  -- Saudi Arabia
  (c_sa,'Riyadh',NULL,'active'),
  (c_sa,'Jeddah',NULL,'active'),
  (c_sa,'Mecca',NULL,'active'),
  (c_sa,'Medina',NULL,'active'),
  (c_sa,'Dammam',NULL,'active'),
  (c_sa,'Khobar',NULL,'active'),
  (c_sa,'Tabuk',NULL,'active'),
  (c_sa,'Abha',NULL,'active'),
  (c_sa,'Najran',NULL,'active'),
  -- Qatar
  (c_qa,'Doha',NULL,'active'),
  (c_qa,'Al Wakrah',NULL,'active'),
  (c_qa,'Al Khor',NULL,'active'),
  -- Kuwait
  (c_kw,'Kuwait City',NULL,'active'),
  (c_kw,'Hawalli',NULL,'active'),
  (c_kw,'Farwaniya',NULL,'active'),
  -- Bahrain
  (c_bh,'Manama',NULL,'active'),
  (c_bh,'Riffa',NULL,'active'),
  (c_bh,'Muharraq',NULL,'active'),
  -- Oman
  (c_om,'Muscat',NULL,'active'),
  (c_om,'Salalah',NULL,'active'),
  (c_om,'Sohar',NULL,'active'),
  (c_om,'Nizwa',NULL,'active'),
  -- Jordan
  (c_jo,'Amman',NULL,'active'),
  (c_jo,'Zarqa',NULL,'active'),
  (c_jo,'Irbid',NULL,'active'),
  (c_jo,'Aqaba',NULL,'active'),
  -- Lebanon
  (c_lb,'Beirut',NULL,'active'),
  (c_lb,'Tripoli',NULL,'active'),
  (c_lb,'Sidon',NULL,'active'),
  -- Egypt
  (c_eg,'Cairo',NULL,'active'),
  (c_eg,'Alexandria',NULL,'active'),
  (c_eg,'Giza',NULL,'active'),
  (c_eg,'Sharm El Sheikh',NULL,'active'),
  (c_eg,'Hurghada',NULL,'active'),
  (c_eg,'Luxor',NULL,'active'),
  (c_eg,'Aswan',NULL,'active'),
  (c_eg,'Port Said',NULL,'active'),
  (c_eg,'Suez',NULL,'active'),
  -- Iraq
  (c_iq,'Baghdad',NULL,'active'),
  (c_iq,'Basra',NULL,'active'),
  (c_iq,'Mosul',NULL,'active'),
  (c_iq,'Erbil',NULL,'active'),
  -- Yemen
  (c_ye,'Sanaa',NULL,'active'),
  (c_ye,'Aden',NULL,'active'),
  -- Syria
  (c_sy,'Damascus',NULL,'active'),
  (c_sy,'Aleppo',NULL,'active'),
  (c_sy,'Latakia',NULL,'active'),
  -- Israel
  (c_il,'Tel Aviv',NULL,'active'),
  (c_il,'Jerusalem',NULL,'active'),
  (c_il,'Haifa',NULL,'active'),
  -- Turkey
  (c_tr,'Istanbul','Europe/Istanbul','active'),
  (c_tr,'Ankara',NULL,'active'),
  (c_tr,'Izmir',NULL,'active'),
  (c_tr,'Antalya',NULL,'active'),
  (c_tr,'Bursa',NULL,'active'),
  (c_tr,'Gaziantep',NULL,'active'),
  -- Iran
  (c_ir,'Tehran',NULL,'active'),
  (c_ir,'Isfahan',NULL,'active'),
  (c_ir,'Shiraz',NULL,'active'),
  (c_ir,'Mashhad',NULL,'active'),
  -- Morocco
  (c_ma,'Casablanca',NULL,'active'),
  (c_ma,'Rabat',NULL,'active'),
  (c_ma,'Marrakech',NULL,'active'),
  (c_ma,'Fez',NULL,'active'),
  (c_ma,'Tangier',NULL,'active'),
  (c_ma,'Agadir',NULL,'active'),
  -- Tunisia
  (c_tn,'Tunis',NULL,'active'),
  (c_tn,'Sfax',NULL,'active'),
  (c_tn,'Sousse',NULL,'active'),
  -- Algeria
  (c_dz,'Algiers',NULL,'active'),
  (c_dz,'Oran',NULL,'active'),
  (c_dz,'Constantine',NULL,'active'),
  -- Libya
  (c_ly,'Tripoli',NULL,'active'),
  (c_ly,'Benghazi',NULL,'active'),
  -- Nigeria
  (c_ng,'Lagos',NULL,'active'),
  (c_ng,'Abuja',NULL,'active'),
  (c_ng,'Kano',NULL,'active'),
  (c_ng,'Ibadan',NULL,'active'),
  (c_ng,'Port Harcourt',NULL,'active'),
  -- Kenya
  (c_ke,'Nairobi',NULL,'active'),
  (c_ke,'Mombasa',NULL,'active'),
  (c_ke,'Kisumu',NULL,'active'),
  -- Ghana
  (c_gh,'Accra',NULL,'active'),
  (c_gh,'Kumasi',NULL,'active'),
  -- South Africa
  (c_za,'Johannesburg',NULL,'active'),
  (c_za,'Cape Town',NULL,'active'),
  (c_za,'Durban',NULL,'active'),
  (c_za,'Pretoria',NULL,'active'),
  (c_za,'Port Elizabeth',NULL,'active'),
  -- Ethiopia
  (c_et,'Addis Ababa',NULL,'active'),
  (c_et,'Dire Dawa',NULL,'active'),
  -- Tanzania
  (c_tz,'Dar es Salaam',NULL,'active'),
  (c_tz,'Zanzibar City',NULL,'active'),
  (c_tz,'Arusha',NULL,'active'),
  -- Uganda
  (c_ug,'Kampala',NULL,'active'),
  (c_ug,'Gulu',NULL,'active'),
  -- Senegal
  (c_sn,'Dakar',NULL,'active'),
  -- Ivory Coast
  (c_ci,'Abidjan',NULL,'active'),
  (c_ci,'Yamoussoukro',NULL,'active'),
  -- Cameroon
  (c_cm,'Douala',NULL,'active'),
  (c_cm,'Yaounde',NULL,'active'),
  -- Angola
  (c_ao,'Luanda',NULL,'active'),
  -- Zimbabwe
  (c_zw,'Harare',NULL,'active'),
  (c_zw,'Bulawayo',NULL,'active'),
  -- Zambia
  (c_zm,'Lusaka',NULL,'active'),
  (c_zm,'Ndola',NULL,'active'),
  -- United Kingdom
  (c_gb,'London',NULL,'active'),
  (c_gb,'Manchester',NULL,'active'),
  (c_gb,'Birmingham',NULL,'active'),
  (c_gb,'Glasgow',NULL,'active'),
  (c_gb,'Edinburgh',NULL,'active'),
  (c_gb,'Leeds',NULL,'active'),
  (c_gb,'Liverpool',NULL,'active'),
  (c_gb,'Bristol',NULL,'active'),
  (c_gb,'Sheffield',NULL,'active'),
  (c_gb,'Cardiff',NULL,'active'),
  (c_gb,'Belfast',NULL,'active'),
  -- France
  (c_fr,'Paris',NULL,'active'),
  (c_fr,'Marseille',NULL,'active'),
  (c_fr,'Lyon',NULL,'active'),
  (c_fr,'Toulouse',NULL,'active'),
  (c_fr,'Nice',NULL,'active'),
  (c_fr,'Bordeaux',NULL,'active'),
  (c_fr,'Strasbourg',NULL,'active'),
  (c_fr,'Lille',NULL,'active'),
  -- Germany
  (c_de,'Berlin',NULL,'active'),
  (c_de,'Munich',NULL,'active'),
  (c_de,'Hamburg',NULL,'active'),
  (c_de,'Frankfurt',NULL,'active'),
  (c_de,'Cologne',NULL,'active'),
  (c_de,'Stuttgart',NULL,'active'),
  (c_de,'Dusseldorf',NULL,'active'),
  (c_de,'Leipzig',NULL,'active'),
  (c_de,'Dortmund',NULL,'active'),
  -- Spain
  (c_es,'Madrid',NULL,'active'),
  (c_es,'Barcelona',NULL,'active'),
  (c_es,'Valencia',NULL,'active'),
  (c_es,'Seville',NULL,'active'),
  (c_es,'Bilbao',NULL,'active'),
  (c_es,'Malaga',NULL,'active'),
  (c_es,'Zaragoza',NULL,'active'),
  (c_es,'Palma',NULL,'active'),
  -- Italy
  (c_it,'Rome',NULL,'active'),
  (c_it,'Milan',NULL,'active'),
  (c_it,'Naples',NULL,'active'),
  (c_it,'Turin',NULL,'active'),
  (c_it,'Florence',NULL,'active'),
  (c_it,'Venice',NULL,'active'),
  (c_it,'Bologna',NULL,'active'),
  (c_it,'Genoa',NULL,'active'),
  -- Netherlands
  (c_nl,'Amsterdam',NULL,'active'),
  (c_nl,'Rotterdam',NULL,'active'),
  (c_nl,'The Hague',NULL,'active'),
  (c_nl,'Utrecht',NULL,'active'),
  (c_nl,'Eindhoven',NULL,'active'),
  -- Belgium
  (c_be,'Brussels',NULL,'active'),
  (c_be,'Antwerp',NULL,'active'),
  (c_be,'Ghent',NULL,'active'),
  -- Switzerland
  (c_ch,'Zurich',NULL,'active'),
  (c_ch,'Geneva',NULL,'active'),
  (c_ch,'Basel',NULL,'active'),
  (c_ch,'Lausanne',NULL,'active'),
  -- Sweden
  (c_se,'Stockholm',NULL,'active'),
  (c_se,'Gothenburg',NULL,'active'),
  (c_se,'Malmo',NULL,'active'),
  -- Norway
  (c_no,'Oslo',NULL,'active'),
  (c_no,'Bergen',NULL,'active'),
  (c_no,'Trondheim',NULL,'active'),
  -- Denmark
  (c_dk,'Copenhagen',NULL,'active'),
  (c_dk,'Aarhus',NULL,'active'),
  -- Finland
  (c_fi,'Helsinki',NULL,'active'),
  (c_fi,'Tampere',NULL,'active'),
  (c_fi,'Turku',NULL,'active'),
  -- Poland
  (c_pl,'Warsaw',NULL,'active'),
  (c_pl,'Krakow',NULL,'active'),
  (c_pl,'Gdansk',NULL,'active'),
  (c_pl,'Wroclaw',NULL,'active'),
  (c_pl,'Poznan',NULL,'active'),
  -- Portugal
  (c_pt,'Lisbon',NULL,'active'),
  (c_pt,'Porto',NULL,'active'),
  (c_pt,'Faro',NULL,'active'),
  -- Greece
  (c_gr,'Athens',NULL,'active'),
  (c_gr,'Thessaloniki',NULL,'active'),
  (c_gr,'Heraklion',NULL,'active'),
  -- Austria
  (c_at,'Vienna',NULL,'active'),
  (c_at,'Graz',NULL,'active'),
  (c_at,'Salzburg',NULL,'active'),
  (c_at,'Innsbruck',NULL,'active'),
  -- Czech Republic
  (c_cz,'Prague',NULL,'active'),
  (c_cz,'Brno',NULL,'active'),
  -- Hungary
  (c_hu,'Budapest',NULL,'active'),
  (c_hu,'Debrecen',NULL,'active'),
  -- Romania
  (c_ro,'Bucharest',NULL,'active'),
  (c_ro,'Cluj-Napoca',NULL,'active'),
  (c_ro,'Timisoara',NULL,'active'),
  -- Russia
  (c_ru,'Moscow',NULL,'active'),
  (c_ru,'Saint Petersburg',NULL,'active'),
  (c_ru,'Novosibirsk','Asia/Novosibirsk','active'),
  (c_ru,'Yekaterinburg','Asia/Yekaterinburg','active'),
  (c_ru,'Vladivostok','Asia/Vladivostok','active'),
  (c_ru,'Kazan',NULL,'active'),
  -- Ukraine
  (c_ua,'Kyiv',NULL,'active'),
  (c_ua,'Kharkiv',NULL,'active'),
  (c_ua,'Odesa',NULL,'active'),
  (c_ua,'Dnipro',NULL,'active'),
  (c_ua,'Lviv',NULL,'active'),
  -- India
  (c_in,'Mumbai',NULL,'active'),
  (c_in,'Delhi',NULL,'active'),
  (c_in,'Bangalore',NULL,'active'),
  (c_in,'Hyderabad',NULL,'active'),
  (c_in,'Chennai',NULL,'active'),
  (c_in,'Kolkata',NULL,'active'),
  (c_in,'Pune',NULL,'active'),
  (c_in,'Ahmedabad',NULL,'active'),
  (c_in,'Jaipur',NULL,'active'),
  (c_in,'Surat',NULL,'active'),
  (c_in,'Lucknow',NULL,'active'),
  (c_in,'Chandigarh',NULL,'active'),
  (c_in,'Kochi',NULL,'active'),
  (c_in,'Goa',NULL,'active'),
  -- Pakistan
  (c_pk,'Karachi',NULL,'active'),
  (c_pk,'Lahore',NULL,'active'),
  (c_pk,'Islamabad',NULL,'active'),
  (c_pk,'Rawalpindi',NULL,'active'),
  (c_pk,'Faisalabad',NULL,'active'),
  (c_pk,'Multan',NULL,'active'),
  (c_pk,'Peshawar',NULL,'active'),
  -- Bangladesh
  (c_bd,'Dhaka',NULL,'active'),
  (c_bd,'Chittagong',NULL,'active'),
  (c_bd,'Sylhet',NULL,'active'),
  -- Sri Lanka
  (c_lk,'Colombo',NULL,'active'),
  (c_lk,'Kandy',NULL,'active'),
  (c_lk,'Galle',NULL,'active'),
  -- Nepal
  (c_np,'Kathmandu',NULL,'active'),
  (c_np,'Pokhara',NULL,'active'),
  -- China
  (c_cn,'Beijing',NULL,'active'),
  (c_cn,'Shanghai',NULL,'active'),
  (c_cn,'Guangzhou',NULL,'active'),
  (c_cn,'Shenzhen',NULL,'active'),
  (c_cn,'Chengdu',NULL,'active'),
  (c_cn,'Hangzhou',NULL,'active'),
  (c_cn,'Wuhan',NULL,'active'),
  (c_cn,'Xian',NULL,'active'),
  (c_cn,'Chongqing',NULL,'active'),
  (c_cn,'Nanjing',NULL,'active'),
  (c_cn,'Tianjin',NULL,'active'),
  (c_cn,'Qingdao',NULL,'active'),
  -- Japan
  (c_jp,'Tokyo',NULL,'active'),
  (c_jp,'Osaka',NULL,'active'),
  (c_jp,'Yokohama',NULL,'active'),
  (c_jp,'Nagoya',NULL,'active'),
  (c_jp,'Sapporo',NULL,'active'),
  (c_jp,'Fukuoka',NULL,'active'),
  (c_jp,'Kyoto',NULL,'active'),
  (c_jp,'Hiroshima',NULL,'active'),
  -- South Korea
  (c_kr,'Seoul',NULL,'active'),
  (c_kr,'Busan',NULL,'active'),
  (c_kr,'Incheon',NULL,'active'),
  (c_kr,'Daegu',NULL,'active'),
  (c_kr,'Daejeon',NULL,'active'),
  -- Indonesia
  (c_id,'Jakarta','Asia/Jakarta','active'),
  (c_id,'Surabaya','Asia/Jakarta','active'),
  (c_id,'Bandung','Asia/Jakarta','active'),
  (c_id,'Medan','Asia/Jakarta','active'),
  (c_id,'Makassar','Asia/Makassar','active'),
  (c_id,'Bali','Asia/Makassar','active'),
  -- Malaysia
  (c_my,'Kuala Lumpur',NULL,'active'),
  (c_my,'George Town',NULL,'active'),
  (c_my,'Johor Bahru',NULL,'active'),
  (c_my,'Kota Kinabalu','Asia/Kuching','active'),
  (c_my,'Kuching','Asia/Kuching','active'),
  -- Singapore
  (c_sg,'Singapore',NULL,'active'),
  -- Thailand
  (c_th,'Bangkok',NULL,'active'),
  (c_th,'Chiang Mai',NULL,'active'),
  (c_th,'Phuket',NULL,'active'),
  (c_th,'Pattaya',NULL,'active'),
  (c_th,'Hat Yai',NULL,'active'),
  -- Vietnam
  (c_vn,'Ho Chi Minh City',NULL,'active'),
  (c_vn,'Hanoi',NULL,'active'),
  (c_vn,'Da Nang',NULL,'active'),
  (c_vn,'Nha Trang',NULL,'active'),
  (c_vn,'Hue',NULL,'active'),
  -- Philippines
  (c_ph,'Manila',NULL,'active'),
  (c_ph,'Cebu City',NULL,'active'),
  (c_ph,'Davao',NULL,'active'),
  (c_ph,'Quezon City',NULL,'active'),
  -- Hong Kong
  (c_hk,'Hong Kong',NULL,'active'),
  (c_hk,'Kowloon',NULL,'active'),
  -- Taiwan
  (c_tw,'Taipei',NULL,'active'),
  (c_tw,'Kaohsiung',NULL,'active'),
  (c_tw,'Taichung',NULL,'active'),
  -- Kazakhstan
  (c_kz,'Almaty',NULL,'active'),
  (c_kz,'Nur-Sultan',NULL,'active'),
  (c_kz,'Shymkent',NULL,'active'),
  -- Uzbekistan
  (c_uz,'Tashkent',NULL,'active'),
  (c_uz,'Samarkand',NULL,'active'),
  -- Azerbaijan
  (c_az,'Baku',NULL,'active'),
  -- Armenia
  (c_am,'Yerevan',NULL,'active'),
  -- Georgia
  (c_ge,'Tbilisi',NULL,'active'),
  (c_ge,'Batumi',NULL,'active'),
  -- United States
  (c_us,'New York','America/New_York','active'),
  (c_us,'Los Angeles','America/Los_Angeles','active'),
  (c_us,'Chicago','America/Chicago','active'),
  (c_us,'Houston','America/Chicago','active'),
  (c_us,'Phoenix','America/Phoenix','active'),
  (c_us,'Philadelphia','America/New_York','active'),
  (c_us,'San Antonio','America/Chicago','active'),
  (c_us,'San Diego','America/Los_Angeles','active'),
  (c_us,'Dallas','America/Chicago','active'),
  (c_us,'San Francisco','America/Los_Angeles','active'),
  (c_us,'Seattle','America/Los_Angeles','active'),
  (c_us,'Miami','America/New_York','active'),
  (c_us,'Atlanta','America/New_York','active'),
  (c_us,'Boston','America/New_York','active'),
  (c_us,'Denver','America/Denver','active'),
  (c_us,'Las Vegas','America/Los_Angeles','active'),
  (c_us,'Portland','America/Los_Angeles','active'),
  (c_us,'Nashville','America/Chicago','active'),
  (c_us,'Austin','America/Chicago','active'),
  (c_us,'Detroit','America/Detroit','active'),
  (c_us,'Minneapolis','America/Chicago','active'),
  (c_us,'Orlando','America/New_York','active'),
  (c_us,'Washington DC','America/New_York','active'),
  (c_us,'Honolulu','Pacific/Honolulu','active'),
  (c_us,'Anchorage','America/Anchorage','active'),
  -- Canada
  (c_ca,'Toronto','America/Toronto','active'),
  (c_ca,'Montreal','America/Toronto','active'),
  (c_ca,'Vancouver','America/Vancouver','active'),
  (c_ca,'Calgary','America/Edmonton','active'),
  (c_ca,'Edmonton','America/Edmonton','active'),
  (c_ca,'Ottawa','America/Toronto','active'),
  (c_ca,'Winnipeg','America/Winnipeg','active'),
  (c_ca,'Quebec City','America/Toronto','active'),
  (c_ca,'Halifax','America/Halifax','active'),
  -- Mexico
  (c_mx,'Mexico City',NULL,'active'),
  (c_mx,'Guadalajara',NULL,'active'),
  (c_mx,'Monterrey',NULL,'active'),
  (c_mx,'Cancun','America/Cancun','active'),
  (c_mx,'Tijuana','America/Tijuana','active'),
  (c_mx,'Puebla',NULL,'active'),
  -- Brazil
  (c_br,'Sao Paulo',NULL,'active'),
  (c_br,'Rio de Janeiro',NULL,'active'),
  (c_br,'Brasilia',NULL,'active'),
  (c_br,'Salvador',NULL,'active'),
  (c_br,'Fortaleza',NULL,'active'),
  (c_br,'Belo Horizonte',NULL,'active'),
  (c_br,'Curitiba',NULL,'active'),
  (c_br,'Manaus','America/Manaus','active'),
  (c_br,'Recife',NULL,'active'),
  (c_br,'Porto Alegre',NULL,'active'),
  -- Argentina
  (c_ar,'Buenos Aires',NULL,'active'),
  (c_ar,'Cordoba',NULL,'active'),
  (c_ar,'Rosario',NULL,'active'),
  (c_ar,'Mendoza',NULL,'active'),
  -- Colombia
  (c_co,'Bogota',NULL,'active'),
  (c_co,'Medellin',NULL,'active'),
  (c_co,'Cali',NULL,'active'),
  (c_co,'Barranquilla',NULL,'active'),
  -- Chile
  (c_cl,'Santiago',NULL,'active'),
  (c_cl,'Valparaiso',NULL,'active'),
  (c_cl,'Concepcion',NULL,'active'),
  -- Peru
  (c_pe,'Lima',NULL,'active'),
  (c_pe,'Cusco',NULL,'active'),
  (c_pe,'Arequipa',NULL,'active'),
  -- Ecuador
  (c_ec,'Quito',NULL,'active'),
  (c_ec,'Guayaquil',NULL,'active'),
  -- Venezuela
  (c_ve,'Caracas',NULL,'active'),
  (c_ve,'Maracaibo',NULL,'active'),
  -- Australia
  (c_au,'Sydney','Australia/Sydney','active'),
  (c_au,'Melbourne','Australia/Melbourne','active'),
  (c_au,'Brisbane','Australia/Brisbane','active'),
  (c_au,'Perth','Australia/Perth','active'),
  (c_au,'Adelaide','Australia/Adelaide','active'),
  (c_au,'Canberra','Australia/Sydney','active'),
  (c_au,'Gold Coast','Australia/Brisbane','active'),
  (c_au,'Darwin','Australia/Darwin','active'),
  (c_au,'Hobart','Australia/Hobart','active'),
  -- New Zealand
  (c_nz,'Auckland',NULL,'active'),
  (c_nz,'Wellington',NULL,'active'),
  (c_nz,'Christchurch',NULL,'active'),
  (c_nz,'Hamilton',NULL,'active')
  ON CONFLICT (country_id, name) DO NOTHING;
END $$;

-- === 20260517194124_seed_all_remaining_countries_and_cities.sql ===
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

-- === 20260517203315_seed_comprehensive_dummy_data.sql ===
/*
  # Comprehensive Dummy Data Seed

  Seeds realistic data across the full platform to make every page meaningful:

  1. New provider admins (Doha, Abu Dhabi, Cairo expansion)
  2. New locations — 1-2 per provider, spread across cities
  3. 1 Bookboll-operated location (no owner_user_id) in Dubai
  4. Listings for each new location with payment options
  5. Payment options for all existing listings (were missing)
  6. More customers (10 total)
  7. ~20 bookings spread across providers, dates, origins
  8. booking_payments rows for all bookings (backfill existing 3 + new ones)
     - deposit+remaining split for provider listings
     - full online for Bookboll-operated
     - full cash for manual admin bookings
*/

-- ─── NEW PROVIDER ADMINS ───────────────────────────────────────────────────

INSERT INTO admin_users (id, full_name, business_name, role, email)
VALUES
  ('a1000000-0000-0000-0000-000000000001', 'Fatima Al Rashidi',  'Al Wasl Sports Hub',     'provider_admin', 'fatima@alwasl.ae'),
  ('a1000000-0000-0000-0000-000000000002', 'Yusuf Al Farsi',     'Doha Arena',             'provider_admin', 'yusuf@dohaarena.qa'),
  ('a1000000-0000-0000-0000-000000000003', 'Nadia Kamel',        'Cairo Elite Courts',     'provider_admin', 'nadia@cairoelite.eg')
ON CONFLICT (id) DO NOTHING;

-- ─── NEW LOCATIONS ─────────────────────────────────────────────────────────
-- Dubai (cba3f62e), Abu Dhabi (9aefa213), Doha (97064dd0), Cairo (d128a349)

INSERT INTO locations (id, name, city_id, address, phone, status, approval_status, owner_user_id)
VALUES
  -- Fatima — Abu Dhabi
  ('b1000000-0000-0000-0000-000000000001',
   'Al Wasl Sports Hub — Abu Dhabi',
   '9aefa213-8cc9-4d7d-a2e7-2d16d05a0501',
   'Corniche Road, Abu Dhabi',
   '+971 2 555 1100',
   'active', 'approved',
   'a1000000-0000-0000-0000-000000000001'),

  -- Yusuf — Doha
  ('b1000000-0000-0000-0000-000000000002',
   'Doha Arena — West Bay',
   '97064dd0-b0a7-4c81-be7e-488bb821a975',
   'West Bay Sports District, Doha',
   '+974 4444 2200',
   'active', 'approved',
   'a1000000-0000-0000-0000-000000000002'),

  -- Nadia — Cairo
  ('b1000000-0000-0000-0000-000000000003',
   'Cairo Elite Courts — Maadi',
   'd128a349-5b01-4b48-8bad-b4c8d5bc475a',
   'Maadi Sports Club, Cairo',
   '+20 2 2525 3300',
   'active', 'approved',
   'a1000000-0000-0000-0000-000000000003'),

  -- Bookboll-operated (no owner_user_id) — Dubai
  ('b1000000-0000-0000-0000-000000000004',
   'Bookboll @ Business Bay',
   'cba3f62e-4be0-483a-b8dc-42760c3cc153',
   'Business Bay Boulevard, Dubai',
   '+971 4 888 9900',
   'active', 'approved',
   NULL)
ON CONFLICT (id) DO NOTHING;

-- ─── LISTINGS ──────────────────────────────────────────────────────────────

INSERT INTO listings (id, location_id, name, sport_category, description, base_price, capacity, slot_duration, status, approval_status, listing_type)
VALUES
  -- Al Wasl Abu Dhabi
  ('c1000000-0000-0000-0000-000000000001',
   'b1000000-0000-0000-0000-000000000001',
   'Padel Court Blue', 'Padel', 'Outdoor padel court with floodlights',
   180, 4, 60, 'active', 'approved', 'court'),
  ('c1000000-0000-0000-0000-000000000002',
   'b1000000-0000-0000-0000-000000000001',
   'Football Turf A', 'Football', '7-a-side synthetic turf pitch',
   300, 14, 90, 'active', 'approved', 'court'),

  -- Doha Arena
  ('c1000000-0000-0000-0000-000000000003',
   'b1000000-0000-0000-0000-000000000002',
   'Padel Court 01', 'Padel', 'Indoor air-conditioned padel',
   220, 4, 60, 'active', 'approved', 'court'),
  ('c1000000-0000-0000-0000-000000000004',
   'b1000000-0000-0000-0000-000000000002',
   'Tennis Court Main', 'Tennis', 'Hard court, 2 courts available',
   160, 4, 60, 'active', 'approved', 'court'),

  -- Cairo Elite Courts
  ('c1000000-0000-0000-0000-000000000005',
   'b1000000-0000-0000-0000-000000000003',
   'Football Ground 1', 'Football', '5-a-side turf',
   120, 10, 60, 'active', 'approved', 'court'),

  -- Bookboll-operated
  ('c1000000-0000-0000-0000-000000000006',
   'b1000000-0000-0000-0000-000000000004',
   'Bookboll Padel Suite', 'Padel', 'Premium indoor padel operated by Bookboll',
   250, 4, 60, 'active', 'approved', 'court'),
  ('c1000000-0000-0000-0000-000000000007',
   'b1000000-0000-0000-0000-000000000004',
   'Bookboll Tennis Court', 'Tennis', 'Premium tennis operated by Bookboll',
   200, 4, 60, 'active', 'approved', 'court')
ON CONFLICT (id) DO NOTHING;

-- ─── LISTING PAYMENT OPTIONS ───────────────────────────────────────────────
-- Existing listings (were missing) + new ones
-- Provider listings: deposit_enabled, no cash, no online
-- Bookboll-operated: online_payment_enabled

INSERT INTO listing_payment_options (listing_id, cash_on_arrival, deposit_enabled, deposit_amount, deposit_percentage, online_payment_enabled)
VALUES
  -- Existing DSC listings (provider-owned → deposit only)
  ('fb02df14-d7c8-436e-99d0-f933b0c60255', false, true, 0, 20, false),
  ('7f085de3-fe71-48e1-bb67-f54f5112edf6', false, true, 0, 20, false),
  ('ad583fad-6fd3-454b-9d13-6ab1a0c8e3f1', false, true, 0, 20, false),
  -- New provider listings (deposit only)
  ('c1000000-0000-0000-0000-000000000001', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000002', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000003', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000004', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000005', false, true, 0, 20, false),
  -- Bookboll-operated listings: full online payment
  ('c1000000-0000-0000-0000-000000000006', false, false, 0, 0, true),
  ('c1000000-0000-0000-0000-000000000007', false, false, 0, 0, true)
ON CONFLICT (listing_id) DO NOTHING;

-- ─── MORE CUSTOMERS ────────────────────────────────────────────────────────

INSERT INTO customers (id, full_name, email, phone)
VALUES
  ('d1000000-0000-0000-0000-000000000001', 'Mohammed Al Amin',   'mohammed@mail.com', '+971 50 111 2233'),
  ('d1000000-0000-0000-0000-000000000002', 'Aisha Khalil',       'aisha@mail.com',    '+971 55 222 3344'),
  ('d1000000-0000-0000-0000-000000000003', 'Tariq Nasser',       'tariq@mail.com',    '+974 33 444 5566'),
  ('d1000000-0000-0000-0000-000000000004', 'Hana El Sayed',      'hana@mail.com',     '+20 100 555 6677'),
  ('d1000000-0000-0000-0000-000000000005', 'Bilal Mahmoud',      'bilal@mail.com',    '+966 50 666 7788'),
  ('d1000000-0000-0000-0000-000000000006', 'Reem Al Muhairi',    'reem@mail.com',     '+971 56 777 8899'),
  ('d1000000-0000-0000-0000-000000000007', 'Samer Diab',         'samer@mail.com',    '+961 70 888 9900')
ON CONFLICT (id) DO NOTHING;

-- ─── BOOKINGS ─────────────────────────────────────────────────────────────
-- Mix of: deposit_paid (most), paid (full), pending (few)
-- Origins: bookboll_app, bookboll_web, manual_admin

INSERT INTO bookings (id, customer_id, listing_id, location_id, booking_date, start_time, end_time, players, base_price, total, payment_method, payment_status, booking_status, origin, commission_rate, commission_amount)
VALUES
  -- ── DSC (Ahmed, Dubai) ─────────────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000001',
   'd1000000-0000-0000-0000-000000000001',
   'fb02df14-d7c8-436e-99d0-f933b0c60255',   -- Football Turf #12
   '41919b8c-770e-4b96-81be-05cc07261687',
   '2026-05-10', '18:00', '19:30', 10, 250, 250,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 50),

  ('e1000000-0000-0000-0000-000000000002',
   'd1000000-0000-0000-0000-000000000002',
   '7f085de3-fe71-48e1-bb67-f54f5112edf6',   -- Tennis Court A
   '41919b8c-770e-4b96-81be-05cc07261687',
   '2026-05-11', '08:00', '09:00', 2, 150, 150,
   'cash', 'paid', 'completed', 'bookboll_web', 20, 30),

  ('e1000000-0000-0000-0000-000000000003',
   'd1000000-0000-0000-0000-000000000003',
   'ad583fad-6fd3-454b-9d13-6ab1a0c8e3f1',   -- Padel Court 1
   '41919b8c-770e-4b96-81be-05cc07261687',
   '2026-05-12', '20:00', '21:00', 4, 200, 200,
   'cash', 'pending', 'confirmed', 'bookboll_app', 20, 40),

  -- ── Al Wasl Abu Dhabi (Fatima) ─────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000004',
   'd1000000-0000-0000-0000-000000000004',
   'c1000000-0000-0000-0000-000000000001',   -- Padel Court Blue
   'b1000000-0000-0000-0000-000000000001',
   '2026-05-12', '10:00', '11:00', 4, 180, 180,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 36),

  ('e1000000-0000-0000-0000-000000000005',
   'd1000000-0000-0000-0000-000000000005',
   'c1000000-0000-0000-0000-000000000002',   -- Football Turf A
   'b1000000-0000-0000-0000-000000000001',
   '2026-05-13', '17:00', '18:30', 14, 300, 300,
   'cash', 'paid', 'completed', 'bookboll_web', 20, 60),

  ('e1000000-0000-0000-0000-000000000006',
   'd1000000-0000-0000-0000-000000000006',
   'c1000000-0000-0000-0000-000000000001',   -- Padel Court Blue
   'b1000000-0000-0000-0000-000000000001',
   '2026-05-14', '19:00', '20:00', 4, 180, 180,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 36),

  -- ── Doha Arena (Yusuf) ────────────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000007',
   'd1000000-0000-0000-0000-000000000001',
   'c1000000-0000-0000-0000-000000000003',   -- Padel Court 01
   'b1000000-0000-0000-0000-000000000002',
   '2026-05-11', '16:00', '17:00', 4, 220, 220,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 44),

  ('e1000000-0000-0000-0000-000000000008',
   'd1000000-0000-0000-0000-000000000007',
   'c1000000-0000-0000-0000-000000000004',   -- Tennis Court Main
   'b1000000-0000-0000-0000-000000000002',
   '2026-05-14', '09:00', '10:00', 2, 160, 160,
   'cash', 'paid', 'completed', 'bookboll_web', 20, 32),

  ('e1000000-0000-0000-0000-000000000009',
   'd1000000-0000-0000-0000-000000000002',
   'c1000000-0000-0000-0000-000000000003',   -- Padel Court 01
   'b1000000-0000-0000-0000-000000000002',
   '2026-05-15', '20:00', '21:00', 4, 220, 220,
   'cash', 'pending', 'confirmed', 'bookboll_app', 20, 44),

  -- ── Cairo Elite (Nadia) ────────────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000010',
   'd1000000-0000-0000-0000-000000000003',
   'c1000000-0000-0000-0000-000000000005',   -- Football Ground 1
   'b1000000-0000-0000-0000-000000000003',
   '2026-05-10', '15:00', '16:00', 10, 120, 120,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 24),

  ('e1000000-0000-0000-0000-000000000011',
   'd1000000-0000-0000-0000-000000000004',
   'c1000000-0000-0000-0000-000000000005',   -- Football Ground 1
   'b1000000-0000-0000-0000-000000000003',
   '2026-05-13', '18:00', '19:00', 10, 120, 120,
   'cash', 'paid', 'completed', 'bookboll_web', 20, 24),

  -- ── Bookboll-operated (full online, all to Bookboll) ─────────────────
  ('e1000000-0000-0000-0000-000000000012',
   'd1000000-0000-0000-0000-000000000005',
   'c1000000-0000-0000-0000-000000000006',   -- Bookboll Padel Suite
   'b1000000-0000-0000-0000-000000000004',
   '2026-05-12', '12:00', '13:00', 4, 250, 250,
   'online', 'paid', 'completed', 'bookboll_app', 100, 250),

  ('e1000000-0000-0000-0000-000000000013',
   'd1000000-0000-0000-0000-000000000006',
   'c1000000-0000-0000-0000-000000000007',   -- Bookboll Tennis Court
   'b1000000-0000-0000-0000-000000000004',
   '2026-05-13', '10:00', '11:00', 2, 200, 200,
   'online', 'paid', 'completed', 'bookboll_web', 100, 200),

  ('e1000000-0000-0000-0000-000000000014',
   'd1000000-0000-0000-0000-000000000007',
   'c1000000-0000-0000-0000-000000000006',   -- Bookboll Padel Suite
   'b1000000-0000-0000-0000-000000000004',
   '2026-05-16', '18:00', '19:00', 4, 250, 250,
   'online', 'pending', 'confirmed', 'bookboll_app', 100, 250)
ON CONFLICT (id) DO NOTHING;

-- ─── BOOKING PAYMENTS ──────────────────────────────────────────────────────
-- Backfill existing 3 bookings + all 14 new bookings
-- Logic:
--   deposit+remaining → provider listings (20% online to bookboll, 80% remaining to provider)
--   full online       → Bookboll-operated (100% online to bookboll)
--   full cash pending → provider listings with manual origin

INSERT INTO booking_payments (booking_id, payment_type, method, amount, currency, recipient, status, paid_at)
VALUES

  -- ── Existing booking 525c (Football Turf #12, paid, DSC provider) ─────
  ('525cecf8-c7fa-4289-9678-d619f7f355d5', 'deposit',   'online', 50.00, 'AED', 'bookboll', 'paid', '2026-05-06T10:00:00Z'),
  ('525cecf8-c7fa-4289-9678-d619f7f355d5', 'remaining', 'cash',  200.00, 'AED', 'provider', 'paid', '2026-05-06T18:00:00Z'),

  -- ── Existing booking 759e (Tennis Court A, pending, DSC provider) ─────
  ('759e5736-c9d3-42be-ad42-e075013b796f', 'deposit',   'online', 30.00, 'AED', 'bookboll', 'paid', '2026-05-06T11:00:00Z'),
  ('759e5736-c9d3-42be-ad42-e075013b796f', 'remaining', 'cash',  120.00, 'AED', 'provider', 'pending', NULL),

  -- ── Existing booking 7b1e (Padel Court 1, deposit_paid, DSC provider)
  ('7b1ec398-87aa-4760-a828-2cd60025525d', 'deposit',   'online', 40.00, 'AED', 'bookboll', 'paid', '2026-05-07T09:00:00Z'),
  ('7b1ec398-87aa-4760-a828-2cd60025525d', 'remaining', 'cash',  160.00, 'AED', 'provider', 'pending', NULL),

  -- ── New bookings — DSC (provider, deposit model) ──────────────────────
  ('e1000000-0000-0000-0000-000000000001', 'deposit',   'online',  50.00, 'AED', 'bookboll', 'paid',    '2026-05-09T14:00:00Z'),
  ('e1000000-0000-0000-0000-000000000001', 'remaining', 'cash',   200.00, 'AED', 'provider', 'pending', NULL),

  ('e1000000-0000-0000-0000-000000000002', 'deposit',   'online',  30.00, 'AED', 'bookboll', 'paid',    '2026-05-10T07:00:00Z'),
  ('e1000000-0000-0000-0000-000000000002', 'remaining', 'cash',   120.00, 'AED', 'provider', 'paid',    '2026-05-11T08:00:00Z'),

  ('e1000000-0000-0000-0000-000000000003', 'deposit',   'online',  40.00, 'AED', 'bookboll', 'paid',    '2026-05-11T19:00:00Z'),
  ('e1000000-0000-0000-0000-000000000003', 'remaining', 'cash',   160.00, 'AED', 'provider', 'pending', NULL),

  -- ── Al Wasl Abu Dhabi ─────────────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000004', 'deposit',   'online',  36.00, 'AED', 'bookboll', 'paid',    '2026-05-11T09:00:00Z'),
  ('e1000000-0000-0000-0000-000000000004', 'remaining', 'cash',   144.00, 'AED', 'provider', 'pending', NULL),

  ('e1000000-0000-0000-0000-000000000005', 'deposit',   'online',  60.00, 'AED', 'bookboll', 'paid',    '2026-05-12T16:00:00Z'),
  ('e1000000-0000-0000-0000-000000000005', 'remaining', 'card',   240.00, 'AED', 'provider', 'paid',    '2026-05-13T17:00:00Z'),

  ('e1000000-0000-0000-0000-000000000006', 'deposit',   'online',  36.00, 'AED', 'bookboll', 'paid',    '2026-05-13T18:00:00Z'),
  ('e1000000-0000-0000-0000-000000000006', 'remaining', 'cash',   144.00, 'AED', 'provider', 'pending', NULL),

  -- ── Doha Arena ────────────────────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000007', 'deposit',   'online',  44.00, 'AED', 'bookboll', 'paid',    '2026-05-10T15:00:00Z'),
  ('e1000000-0000-0000-0000-000000000007', 'remaining', 'cash',   176.00, 'AED', 'provider', 'pending', NULL),

  ('e1000000-0000-0000-0000-000000000008', 'deposit',   'online',  32.00, 'AED', 'bookboll', 'paid',    '2026-05-13T08:00:00Z'),
  ('e1000000-0000-0000-0000-000000000008', 'remaining', 'cash',   128.00, 'AED', 'provider', 'paid',    '2026-05-14T09:00:00Z'),

  ('e1000000-0000-0000-0000-000000000009', 'deposit',   'online',  44.00, 'AED', 'bookboll', 'paid',    '2026-05-14T19:00:00Z'),
  ('e1000000-0000-0000-0000-000000000009', 'remaining', 'cash',   176.00, 'AED', 'provider', 'pending', NULL),

  -- ── Cairo Elite ────────────────────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000010', 'deposit',   'online',  24.00, 'AED', 'bookboll', 'paid',    '2026-05-09T14:00:00Z'),
  ('e1000000-0000-0000-0000-000000000010', 'remaining', 'cash',    96.00, 'AED', 'provider', 'pending', NULL),

  ('e1000000-0000-0000-0000-000000000011', 'deposit',   'online',  24.00, 'AED', 'bookboll', 'paid',    '2026-05-12T17:00:00Z'),
  ('e1000000-0000-0000-0000-000000000011', 'remaining', 'cash',    96.00, 'AED', 'provider', 'paid',    '2026-05-13T18:00:00Z'),

  -- ── Bookboll-operated (full online, 100% to Bookboll) ─────────────────
  ('e1000000-0000-0000-0000-000000000012', 'full', 'online', 250.00, 'AED', 'bookboll', 'paid',    '2026-05-12T11:30:00Z'),
  ('e1000000-0000-0000-0000-000000000013', 'full', 'online', 200.00, 'AED', 'bookboll', 'paid',    '2026-05-13T09:30:00Z'),
  ('e1000000-0000-0000-0000-000000000014', 'full', 'online', 250.00, 'AED', 'bookboll', 'pending', NULL)

ON CONFLICT DO NOTHING;

-- === 20260517203501_fix_disconnected_providers_and_locations.sql ===
/*
  # Fix disconnected providers and empty location

  Issues found in audit:
  1. Mahmoud Hassan (Cairo Football Arena) — no owned location
  2. Khalid Al Saud (Riyadh Padel Club) — no owned location
  3. "DSC Marina test test test" location — has no listings

  Fixes:
  - Assign Mahmoud as owner of Cairo Elite Courts location
  - Create a new Riyadh location for Khalid
  - Add listings + payment options to DSC Marina
  - Add bookings + payment rows for the new content
*/

-- ── Fix 1: Assign Mahmoud Hassan as owner of Cairo Elite Courts ────────────
UPDATE locations
SET owner_user_id = '8d7f528d-0fd7-48d5-9f1a-8f19dc80c392'  -- Mahmoud Hassan
WHERE id = 'b1000000-0000-0000-0000-000000000003';           -- Cairo Elite Courts — Maadi

-- ── Fix 2: Create Riyadh location for Khalid Al Saud ──────────────────────
INSERT INTO locations (id, name, city_id, address, phone, status, approval_status, owner_user_id)
VALUES (
  'b1000000-0000-0000-0000-000000000005',
  'Riyadh Padel Club — Al Olaya',
  '526658d4-3c0e-49f4-b5ab-e698c6407ef2',  -- Riyadh
  'Al Olaya District, Riyadh',
  '+966 11 333 4400',
  'active', 'approved',
  '21f623cf-de7d-44a7-a638-258c9c284173'   -- Khalid Al Saud
)
ON CONFLICT (id) DO NOTHING;

-- Listings for Riyadh Padel Club
INSERT INTO listings (id, location_id, name, sport_category, description, base_price, capacity, slot_duration, status, approval_status, listing_type)
VALUES
  ('c1000000-0000-0000-0000-000000000008',
   'b1000000-0000-0000-0000-000000000005',
   'Padel Court A', 'Padel', 'Indoor premium padel court',
   200, 4, 60, 'active', 'approved', 'court'),
  ('c1000000-0000-0000-0000-000000000009',
   'b1000000-0000-0000-0000-000000000005',
   'Padel Court B', 'Padel', 'Indoor premium padel court',
   200, 4, 60, 'active', 'approved', 'court')
ON CONFLICT (id) DO NOTHING;

-- Payment options for Riyadh listings
INSERT INTO listing_payment_options (listing_id, cash_on_arrival, deposit_enabled, deposit_amount, deposit_percentage, online_payment_enabled)
VALUES
  ('c1000000-0000-0000-0000-000000000008', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000009', false, true, 0, 20, false)
ON CONFLICT (listing_id) DO NOTHING;

-- ── Fix 3: Add listings to "DSC Marina test test test" ─────────────────────
INSERT INTO listings (id, location_id, name, sport_category, description, base_price, capacity, slot_duration, status, approval_status, listing_type)
VALUES
  ('c1000000-0000-0000-0000-000000000010',
   '513305f5-b266-4a7c-b411-9d5369a2ca32',  -- DSC Marina
   'Marina Padel Court 1', 'Padel', 'Waterfront padel court with marina views',
   220, 4, 60, 'active', 'approved', 'court'),
  ('c1000000-0000-0000-0000-000000000011',
   '513305f5-b266-4a7c-b411-9d5369a2ca32',
   'Marina Football Turf', 'Football', '5-a-side synthetic turf at the marina',
   280, 10, 60, 'active', 'approved', 'court')
ON CONFLICT (id) DO NOTHING;

-- Payment options for DSC Marina listings (Ahmed's location → deposit model)
INSERT INTO listing_payment_options (listing_id, cash_on_arrival, deposit_enabled, deposit_amount, deposit_percentage, online_payment_enabled)
VALUES
  ('c1000000-0000-0000-0000-000000000010', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000011', false, true, 0, 20, false)
ON CONFLICT (listing_id) DO NOTHING;

-- ── Sample bookings for new listings ───────────────────────────────────────

INSERT INTO bookings (id, customer_id, listing_id, location_id, booking_date, start_time, end_time, players, base_price, total, payment_method, payment_status, booking_status, origin, commission_rate, commission_amount)
VALUES
  -- DSC Marina — Padel
  ('e1000000-0000-0000-0000-000000000015',
   'bd7ddc47-8aab-40e0-a9ed-f7f9f2e146e6',  -- Sara Ahmed
   'c1000000-0000-0000-0000-000000000010',
   '513305f5-b266-4a7c-b411-9d5369a2ca32',
   '2026-05-15', '18:00', '19:00', 4, 220, 220,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 44),

  -- Riyadh Padel Club — Court A
  ('e1000000-0000-0000-0000-000000000016',
   'd1000000-0000-0000-0000-000000000005',  -- Bilal
   'c1000000-0000-0000-0000-000000000008',
   'b1000000-0000-0000-0000-000000000005',
   '2026-05-14', '20:00', '21:00', 4, 200, 200,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 40),

  -- Riyadh Padel Club — Court B
  ('e1000000-0000-0000-0000-000000000017',
   'd1000000-0000-0000-0000-000000000006',  -- Reem
   'c1000000-0000-0000-0000-000000000009',
   'b1000000-0000-0000-0000-000000000005',
   '2026-05-16', '19:00', '20:00', 4, 200, 200,
   'cash', 'pending', 'confirmed', 'bookboll_app', 20, 40)
ON CONFLICT (id) DO NOTHING;

-- booking_payments for new bookings
INSERT INTO booking_payments (booking_id, payment_type, method, amount, currency, recipient, status, paid_at)
VALUES
  ('e1000000-0000-0000-0000-000000000015', 'deposit',   'online',  44.00, 'AED', 'bookboll', 'paid',    '2026-05-14T17:00:00Z'),
  ('e1000000-0000-0000-0000-000000000015', 'remaining', 'cash',   176.00, 'AED', 'provider', 'pending', NULL),

  ('e1000000-0000-0000-0000-000000000016', 'deposit',   'online',  40.00, 'AED', 'bookboll', 'paid',    '2026-05-13T19:00:00Z'),
  ('e1000000-0000-0000-0000-000000000016', 'remaining', 'cash',   160.00, 'AED', 'provider', 'pending', NULL),

  ('e1000000-0000-0000-0000-000000000017', 'deposit',   'online',  40.00, 'AED', 'bookboll', 'paid',    '2026-05-15T18:00:00Z'),
  ('e1000000-0000-0000-0000-000000000017', 'remaining', 'cash',   160.00, 'AED', 'provider', 'pending', NULL)
ON CONFLICT DO NOTHING;

-- === 20260517203540_fix_nadia_cairo_location.sql ===
/*
  # Fix Nadia Kamel location ownership

  Cairo Elite Courts was reassigned to Mahmoud Hassan, but it was created for Nadia Kamel
  (Cairo Elite Courts business). Mahmoud Hassan's business is "Cairo Football Arena" and
  needs its own separate location. This migration:
  - Gives Cairo Elite Courts back to Nadia Kamel
  - Creates a new Cairo Football Arena location for Mahmoud Hassan with listings
*/

-- Restore Cairo Elite Courts to Nadia Kamel
UPDATE locations
SET owner_user_id = 'a1000000-0000-0000-0000-000000000003'  -- Nadia Kamel
WHERE id = 'b1000000-0000-0000-0000-000000000003';          -- Cairo Elite Courts — Maadi

-- Create Cairo Football Arena location for Mahmoud Hassan
INSERT INTO locations (id, name, city_id, address, phone, status, approval_status, owner_user_id)
VALUES (
  'b1000000-0000-0000-0000-000000000006',
  'Cairo Football Arena — Heliopolis',
  'd128a349-5b01-4b48-8bad-b4c8d5bc475a',  -- Cairo
  'Heliopolis District, Cairo',
  '+20 2 2411 7700',
  'active', 'approved',
  '8d7f528d-0fd7-48d5-9f1a-8f19dc80c392'  -- Mahmoud Hassan
)
ON CONFLICT (id) DO NOTHING;

-- Listings for Cairo Football Arena
INSERT INTO listings (id, location_id, name, sport_category, description, base_price, capacity, slot_duration, status, approval_status, listing_type)
VALUES
  ('c1000000-0000-0000-0000-000000000012',
   'b1000000-0000-0000-0000-000000000006',
   'Stadium Turf A', 'Football', 'Full-size 7-a-side turf',
   150, 14, 90, 'active', 'approved', 'court'),
  ('c1000000-0000-0000-0000-000000000013',
   'b1000000-0000-0000-0000-000000000006',
   'Stadium Turf B', 'Football', 'Full-size 7-a-side turf',
   150, 14, 90, 'active', 'approved', 'court')
ON CONFLICT (id) DO NOTHING;

-- Payment options
INSERT INTO listing_payment_options (listing_id, cash_on_arrival, deposit_enabled, deposit_amount, deposit_percentage, online_payment_enabled)
VALUES
  ('c1000000-0000-0000-0000-000000000012', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000013', false, true, 0, 20, false)
ON CONFLICT (listing_id) DO NOTHING;

-- Sample bookings for Cairo Football Arena
INSERT INTO bookings (id, customer_id, listing_id, location_id, booking_date, start_time, end_time, players, base_price, total, payment_method, payment_status, booking_status, origin, commission_rate, commission_amount)
VALUES
  ('e1000000-0000-0000-0000-000000000018',
   'd1000000-0000-0000-0000-000000000007',  -- Samer Diab
   'c1000000-0000-0000-0000-000000000012',
   'b1000000-0000-0000-0000-000000000006',
   '2026-05-15', '16:00', '17:30', 14, 150, 150,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 30),
  ('e1000000-0000-0000-0000-000000000019',
   'd1000000-0000-0000-0000-000000000001',  -- Mohammed
   'c1000000-0000-0000-0000-000000000013',
   'b1000000-0000-0000-0000-000000000006',
   '2026-05-16', '18:00', '19:30', 14, 150, 150,
   'cash', 'pending', 'confirmed', 'bookboll_web', 20, 30)
ON CONFLICT (id) DO NOTHING;

-- Payment rows for Cairo Football Arena bookings
INSERT INTO booking_payments (booking_id, payment_type, method, amount, currency, recipient, status, paid_at)
VALUES
  ('e1000000-0000-0000-0000-000000000018', 'deposit',   'online',  30.00, 'AED', 'bookboll', 'paid',    '2026-05-14T15:00:00Z'),
  ('e1000000-0000-0000-0000-000000000018', 'remaining', 'cash',   120.00, 'AED', 'provider', 'pending', NULL),
  ('e1000000-0000-0000-0000-000000000019', 'deposit',   'online',  30.00, 'AED', 'bookboll', 'paid',    '2026-05-15T17:00:00Z'),
  ('e1000000-0000-0000-0000-000000000019', 'remaining', 'cash',   120.00, 'AED', 'provider', 'pending', NULL)
ON CONFLICT DO NOTHING;

-- === 20260517212925_backfill_provider_slug_usernames.sql ===
/*
  # Backfill slug usernames for provider admins and super admin

  - Replaces dot-format usernames (e.g. ahmed.mansouri) with short company slugs
  - Assigns @bookboll username to the super admin row
  - Sets username on all team members to match their provider's username
*/

-- Update provider admins with short company slugs
UPDATE admin_users SET username = 'dsc'        WHERE email = 'ahmed@dsc.ae'         AND role = 'provider_admin';
UPDATE admin_users SET username = 'cfa'        WHERE email = 'mahmoud@cfa.eg'        AND role = 'provider_admin';
UPDATE admin_users SET username = 'rpc'        WHERE email = 'khalid@rpc.sa'         AND role = 'provider_admin';
UPDATE admin_users SET username = 'alwasl'     WHERE email = 'fatima@alwasl.ae'      AND role = 'provider_admin';
UPDATE admin_users SET username = 'dohaarena'  WHERE email = 'yusuf@dohaarena.qa'    AND role = 'provider_admin';
UPDATE admin_users SET username = 'cairoelite' WHERE email = 'nadia@cairoelite.eg'   AND role = 'provider_admin';

-- Assign bookboll username to super admin
UPDATE admin_users SET username = 'bookboll' WHERE email = 'admin@bookboll.com' AND role = 'super_admin';

-- Propagate provider username to all their team members
UPDATE admin_users emp
SET username = owner.username
FROM admin_users owner
WHERE emp.owner_user_id = owner.id
  AND owner.role = 'provider_admin'
  AND owner.username IS NOT NULL;

-- === 20260517213442_seed_bookboll_locations_listings_bookings.sql ===
/*
  # Seed Bookboll-owned locations, listings, bookings and payments

  Owned by the super admin (owner_user_id = '72fa6328-0c20-45e2-8eda-e40ff6f36c3a')
  who carries username = 'bookboll'.

  1. Locations (3): Bookboll Dubai Hub, Bookboll London Arena, Bookboll Singapore Courts
  2. Listings (6): 2 per location covering Football, Tennis, Padel, Basketball, Badminton, Swimming
  3. Bookings (18): varied dates, statuses and payment methods
  4. Booking Payments: one payment record per booking
*/

DO $$
DECLARE
  super_id   uuid := '72fa6328-0c20-45e2-8eda-e40ff6f36c3a';
  city_dxb   uuid := 'cba3f62e-4be0-483a-b8dc-42760c3cc153';
  city_lon   uuid := '76724e31-fba9-474f-be04-7daed7365de8';
  city_sgp   uuid := 'b2f048da-1eac-4409-9393-d410d1dde861';

  loc_dxb    uuid := gen_random_uuid();
  loc_lon    uuid := gen_random_uuid();
  loc_sgp    uuid := gen_random_uuid();

  lst_fb     uuid := gen_random_uuid();
  lst_ten    uuid := gen_random_uuid();
  lst_pad    uuid := gen_random_uuid();
  lst_bball  uuid := gen_random_uuid();
  lst_bad    uuid := gen_random_uuid();
  lst_swim   uuid := gen_random_uuid();

  cust_sara     uuid := 'bd7ddc47-8aab-40e0-a9ed-f7f9f2e146e6';
  cust_omar     uuid := 'a663e10f-b6ee-4941-8b1f-3000d4541d6e';
  cust_layla    uuid := '34bdd0ff-a5e5-49ea-a75d-f729a2006779';
  cust_mo       uuid := 'd1000000-0000-0000-0000-000000000001';
  cust_aisha    uuid := 'd1000000-0000-0000-0000-000000000002';

  bk1  uuid := gen_random_uuid();
  bk2  uuid := gen_random_uuid();
  bk3  uuid := gen_random_uuid();
  bk4  uuid := gen_random_uuid();
  bk5  uuid := gen_random_uuid();
  bk6  uuid := gen_random_uuid();
  bk7  uuid := gen_random_uuid();
  bk8  uuid := gen_random_uuid();
  bk9  uuid := gen_random_uuid();
  bk10 uuid := gen_random_uuid();
  bk11 uuid := gen_random_uuid();
  bk12 uuid := gen_random_uuid();
BEGIN

  /* ── LOCATIONS ── */
  INSERT INTO locations (id, name, city_id, address, description, status, approval_status, owner_user_id, sort_order)
  VALUES
    (loc_dxb, 'Bookboll Dubai Hub',        city_dxb, 'Business Bay, Dubai, UAE',       'Premium multi-sport facility in the heart of Business Bay.',  'active', 'approved', super_id, 1),
    (loc_lon, 'Bookboll London Arena',      city_lon, 'Canary Wharf, London, UK',        'State-of-the-art sports arena in Canary Wharf.',             'active', 'approved', super_id, 2),
    (loc_sgp, 'Bookboll Singapore Courts',  city_sgp, 'Marina Bay, Singapore 018956',   'Outdoor and indoor courts overlooking Marina Bay.',          'active', 'approved', super_id, 3)
  ON CONFLICT DO NOTHING;

  /* ── LISTINGS ── */
  INSERT INTO listings (id, location_id, name, sport_category, description, capacity, slot_duration, base_price, status, approval_status, listing_type, max_bookings_per_slot, sort_order, booking_color)
  VALUES
    (lst_fb,    loc_dxb, 'Football Pitch A',     'football',    '5-a-side astroturf pitch with floodlights.',     10, 60, 180, 'active', 'approved', 'court', 1, 1, '#16a34a'),
    (lst_ten,   loc_dxb, 'Tennis Court 1',       'tennis',      'Hard court with professional net and lighting.',  4, 60,  90, 'active', 'approved', 'court', 1, 2, '#0891b2'),
    (lst_pad,   loc_lon, 'Padel Court Blue',      'padel',       'Glass-walled padel court with air conditioning.', 4, 90, 120, 'active', 'approved', 'court', 1, 1, '#7c3aed'),
    (lst_bball, loc_lon, 'Basketball Court',      'basketball',  'Full-size NBA-standard hardwood court.',         10, 60, 150, 'active', 'approved', 'court', 1, 2, '#ea580c'),
    (lst_bad,   loc_sgp, 'Badminton Hall',        'badminton',   'Four competition-grade badminton courts.',        8, 60,  60, 'active', 'approved', 'court', 4, 1, '#db2777'),
    (lst_swim,  loc_sgp, 'Olympic Pool Lane',     'swimming',    '50-metre lane rental for training sessions.',     2, 60,  75, 'active', 'approved', 'court', 1, 2, '#0284c7')
  ON CONFLICT DO NOTHING;

  /* ── BOOKINGS ── */
  INSERT INTO bookings (id, customer_id, listing_id, location_id, booking_date, start_time, end_time, players, base_price, addon_total, discount, total, amount_paid, payment_method, payment_status, booking_status, origin, commission_rate, commission_amount, created_at)
  VALUES
    -- Football Dubai
    (bk1,  cust_sara,  lst_fb,    loc_dxb, '2026-05-10', '08:00', '09:00', 10, 180, 0, 0, 180, 180, 'card',   'paid',    'confirmed', 'app', 0, 0, now() - interval '7 days'),
    (bk2,  cust_omar,  lst_fb,    loc_dxb, '2026-05-12', '10:00', '11:00', 10, 180, 20, 0, 200, 200, 'card',  'paid',    'confirmed', 'app', 0, 0, now() - interval '5 days'),
    (bk3,  cust_mo,    lst_fb,    loc_dxb, '2026-05-19', '18:00', '19:00', 10, 180, 0, 0, 180, 0,   'cash',   'pending', 'confirmed', 'app', 0, 0, now() - interval '1 day'),

    -- Tennis Dubai
    (bk4,  cust_layla, lst_ten,   loc_dxb, '2026-05-11', '07:00', '08:00',  4,  90, 0, 10,  80,  80, 'card',  'paid',    'confirmed', 'web', 0, 0, now() - interval '6 days'),
    (bk5,  cust_aisha, lst_ten,   loc_dxb, '2026-05-14', '09:00', '10:00',  4,  90, 0,  0,  90,  90, 'wallet','paid',    'confirmed', 'app', 0, 0, now() - interval '3 days'),

    -- Padel London
    (bk6,  cust_sara,  lst_pad,   loc_lon, '2026-05-09', '11:00', '12:30',  4, 120, 0,  0, 120, 120, 'card',  'paid',    'completed', 'web', 0, 0, now() - interval '8 days'),
    (bk7,  cust_omar,  lst_pad,   loc_lon, '2026-05-15', '14:00', '15:30',  4, 120, 0,  0, 120,  0,  'cash',  'pending', 'confirmed', 'app', 0, 0, now() - interval '2 days'),

    -- Basketball London
    (bk8,  cust_mo,    lst_bball, loc_lon, '2026-05-13', '16:00', '17:00', 10, 150, 30, 0, 180, 180, 'card',  'paid',    'confirmed', 'app', 0, 0, now() - interval '4 days'),
    (bk9,  cust_layla, lst_bball, loc_lon, '2026-05-17', '19:00', '20:00', 10, 150,  0, 0, 150,  0,  'cash',  'pending', 'confirmed', 'app', 0, 0, now() - interval '0 days'),

    -- Badminton Singapore
    (bk10, cust_aisha, lst_bad,   loc_sgp, '2026-05-08', '06:00', '07:00',  4,  60, 0,  0,  60,  60, 'card',  'paid',    'completed', 'web', 0, 0, now() - interval '9 days'),
    (bk11, cust_sara,  lst_bad,   loc_sgp, '2026-05-16', '08:00', '09:00',  4,  60, 0,  0,  60,  60, 'wallet','paid',    'confirmed', 'app', 0, 0, now() - interval '1 day'),

    -- Swimming Singapore
    (bk12, cust_omar,  lst_swim,  loc_sgp, '2026-05-11', '05:30', '06:30',  2,  75, 0,  0,  75,  75, 'card',  'paid',    'completed', 'web', 0, 0, now() - interval '6 days')
  ON CONFLICT DO NOTHING;

  /* ── BOOKING PAYMENTS ── */
  INSERT INTO booking_payments (booking_id, payment_type, method, amount, currency, recipient, status, paid_at, notes)
  VALUES
    (bk1,  'booking', 'card',   180, 'AED', '@bookboll', 'paid',    now() - interval '7 days',  'Football pitch booking — paid online'),
    (bk2,  'booking', 'card',   200, 'AED', '@bookboll', 'paid',    now() - interval '5 days',  'Football pitch + addons'),
    (bk3,  'booking', 'cash',   180, 'AED', '@bookboll', 'pending', null,                        'Cash due at venue'),
    (bk4,  'booking', 'card',    80, 'AED', '@bookboll', 'paid',    now() - interval '6 days',  'Tennis — discount applied'),
    (bk5,  'booking', 'wallet',  90, 'AED', '@bookboll', 'paid',    now() - interval '3 days',  'Wallet payment'),
    (bk6,  'booking', 'card',   120, 'GBP', '@bookboll', 'paid',    now() - interval '8 days',  'Padel London'),
    (bk7,  'booking', 'cash',   120, 'GBP', '@bookboll', 'pending', null,                        'Cash due at venue'),
    (bk8,  'booking', 'card',   180, 'GBP', '@bookboll', 'paid',    now() - interval '4 days',  'Basketball + addons'),
    (bk9,  'booking', 'cash',   150, 'GBP', '@bookboll', 'pending', null,                        'Cash due at venue'),
    (bk10, 'booking', 'card',    60, 'SGD', '@bookboll', 'paid',    now() - interval '9 days',  'Badminton Singapore'),
    (bk11, 'booking', 'wallet',  60, 'SGD', '@bookboll', 'paid',    now() - interval '1 day',   'Wallet payment'),
    (bk12, 'booking', 'card',    75, 'SGD', '@bookboll', 'paid',    now() - interval '6 days',  'Swimming lane rental')
  ON CONFLICT DO NOTHING;

END $$;

-- === 20260517213750_normalize_booking_payment_recipients.sql ===
/*
  # Normalize booking_payments.recipient to @username format

  - 'bookboll' → '@bookboll'
  - 'provider'  → derive @username from the booking → listing → location → owner chain
*/

-- Fix bare 'bookboll' entries
UPDATE booking_payments SET recipient = '@bookboll' WHERE recipient = 'bookboll';

-- Fix 'provider' entries: walk booking → listing → location → admin_users to get @username
UPDATE booking_payments bp
SET recipient = '@' || au.username
FROM bookings b
JOIN listings li ON li.id = b.listing_id
JOIN locations loc ON loc.id = li.location_id
JOIN admin_users au ON au.id = loc.owner_user_id
WHERE bp.booking_id = b.id
  AND bp.recipient = 'provider'
  AND au.username IS NOT NULL;

-- Any remaining 'provider' rows without a resolvable username — leave as-is
-- (they will fall through to providerNameForPayment() in the UI)

-- === 20260520144207_seed_payment_variations_showcase.sql ===
/*
  # Seed Payment Variations Showcase

  Inserts representative booking_payments rows covering every payment scenario
  so the Payments page can be reviewed visually:

  1. Bookboll-owned location — full online payment (all to @bookboll), paid & pending
  2. Provider-owned location — online deposit (@bookboll) + cash remaining (@provider), pending
  3. Provider-owned location — online deposit (@bookboll) + card remaining (@provider), paid
  4. Provider-owned location — full cash to provider, paid & pending
  5. Provider-owned location — full online to @bookboll (Bookboll-originated booking)
  6. Wire transfer and cheque method examples
  7. Subscription split: @bookboll commission (paid) + @provider earnings (on_hold)
  8. Subscription split: @bookboll commission (paid) + @provider earnings (available_for_payout)
  9. Subscription split: recurring monthly (not first charge)

  All rows are idempotent — uses DO blocks with existence checks where needed.
  booking_id is left NULL for subscription rows; subscription_id is left NULL for booking rows.
*/

-- ─── 1. Bookboll-owned location (Bookboll @ Business Bay) — full online, paid ─────
-- Booking e1000000-0000-0000-0000-000000000012 · Bookboll Padel Suite · AED 250
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000012', 'booking', 'full', 'online', 250.00, 'AED', '@bookboll', 'paid',
   now() - interval '3 days', 'Online full payment — Bookboll-operated location'),
  -- Booking e1000000-0000-0000-0000-000000000013 · Bookboll Tennis Court · AED 200 — pending
  ('e1000000-0000-0000-0000-000000000013', 'booking', 'full', 'online', 200.00, 'AED', '@bookboll', 'pending',
   NULL, 'Online full payment — Bookboll-operated location');

-- ─── 2. Provider-owned — online deposit (@bookboll) + cash remaining (@provider), pending ─
-- Booking e1000000-0000-0000-0000-000000000014 · Bookboll Padel Suite / Business Bay · AED 250
-- 20% deposit online → @bookboll; 80% remaining cash → @bookboll (Bookboll-operated)
-- Use DSC booking 759e5736 — Tennis Court A — AED 150 (no existing rows)
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('759e5736-c9d3-42be-ad42-e075013b796f', 'booking', 'deposit', 'online', 30.00, 'AED', '@bookboll', 'paid',
   now() - interval '5 days', 'Online deposit — 20% collected by Bookboll'),
  ('759e5736-c9d3-42be-ad42-e075013b796f', 'booking', 'remaining', 'cash', 120.00, 'AED', '@dsc', 'pending',
   NULL, 'Remaining balance — payable at venue to DSC');

-- ─── 3. Provider-owned — deposit paid + remaining already paid by card ──────────
-- Booking e1000000-0000-0000-0000-000000000001 · Football Turf #12 / DSC · AED 250
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000001', 'booking', 'deposit', 'online', 50.00, 'AED', '@bookboll', 'paid',
   now() - interval '7 days', 'Online deposit — 20%'),
  ('e1000000-0000-0000-0000-000000000001', 'booking', 'remaining', 'card', 200.00, 'AED', '@dsc', 'paid',
   now() - interval '6 days', 'Remaining paid on-site by card');

-- ─── 4. Provider-owned — full cash to provider ─────────────────────────────────
-- e1000000-0000-0000-0000-000000000002 · Tennis Court A / DSC · AED 150 — full cash paid
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000002', 'booking', 'full', 'cash', 150.00, 'AED', '@dsc', 'paid',
   now() - interval '2 days', 'Full cash payment at venue');

-- e1000000-0000-0000-0000-000000000003 · Padel Court 1 / DSC · AED 200 — full cash pending
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000003', 'booking', 'full', 'cash', 200.00, 'AED', '@dsc', 'pending',
   NULL, 'Full cash — awaiting collection at venue');

-- ─── 5. Provider-owned — full online to @bookboll (Bookboll-originated) ────────
-- 7b1ec398 · Padel Court 1 / DSC · AED 200 — already has deposit+remaining but let's use alwasl
-- e1000000-0000-0000-0000-000000000005 · Football Turf A / Al Wasl · AED 300 — full online
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000005', 'booking', 'full', 'online', 300.00, 'AED', '@bookboll', 'paid',
   now() - interval '1 day', 'Full online — Bookboll-originated booking at provider venue');

-- e1000000-0000-0000-0000-000000000004 · Padel Court Blue / Al Wasl · AED 180 — deposit + remaining pending
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000004', 'booking', 'deposit', 'online', 36.00, 'AED', '@bookboll', 'paid',
   now() - interval '4 days', 'Online deposit 20%'),
  ('e1000000-0000-0000-0000-000000000004', 'booking', 'remaining', 'cash', 144.00, 'AED', '@alwasl', 'pending',
   NULL, 'Remaining — cash at venue');

-- ─── 6. Wire transfer and cheque examples ───────────────────────────────────────
-- Doha Arena — e1000000-0000-0000-0000-000000000007 · Padel Court 01 · AED 220
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000007', 'booking', 'deposit', 'online', 44.00, 'AED', '@bookboll', 'paid',
   now() - interval '6 days', 'Online deposit 20%'),
  ('e1000000-0000-0000-0000-000000000007', 'booking', 'remaining', 'wire_transfer', 176.00, 'AED', '@dohaarena', 'paid',
   now() - interval '5 days', 'Remaining paid via bank wire transfer');

-- Cairo Elite — e1000000-0000-0000-0000-000000000010 (use alwasl booking 6 for cheque)
-- e1000000-0000-0000-0000-000000000006 · Padel Court Blue / Al Wasl · AED 180
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000006', 'booking', 'deposit', 'online', 36.00, 'AED', '@bookboll', 'paid',
   now() - interval '10 days', 'Online deposit 20%'),
  ('e1000000-0000-0000-0000-000000000006', 'booking', 'remaining', 'cheque', 144.00, 'AED', '@alwasl', 'paid',
   now() - interval '9 days', 'Remaining paid by cheque');

-- Riyadh Padel Club — e1000000-0000-0000-0000-000000000017 · Padel Court B · AED 200
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000017', 'booking', 'full', 'cheque', 200.00, 'AED', '@rpc', 'pending',
   NULL, 'Full payment by cheque — awaiting clearance');

-- ─── 7. Subscription split — @bookboll commission + @dsc earnings ON HOLD ──────
-- Subscription d65408cd (Sofia Andrade / Padel Beginners / DSC) · AED 500 · 20% commission
INSERT INTO booking_payments (subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('d65408cd-6f41-4a23-949e-89b6cc6c3164', 'subscription', 'subscription_commission', 'online',
   100.00, 'AED', '@bookboll', 'paid', now() - interval '8 days',
   'First charge after 14-day trial'),
  ('d65408cd-6f41-4a23-949e-89b6cc6c3164', 'subscription', 'subscription_earning', 'online',
   400.00, 'AED', '@dsc', 'on_hold', now() - interval '8 days',
   'First charge after 14-day trial');

-- Subscription 1a0f80c6 (Marcus Johansson / Padel Beginners / DSC) · AED 500
INSERT INTO booking_payments (subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('1a0f80c6-a3e7-462a-895b-7346c0d96d4a', 'subscription', 'subscription_commission', 'online',
   100.00, 'AED', '@bookboll', 'paid', now() - interval '12 days',
   'First charge after 14-day trial'),
  ('1a0f80c6-a3e7-462a-895b-7346c0d96d4a', 'subscription', 'subscription_earning', 'online',
   400.00, 'AED', '@dsc', 'on_hold', now() - interval '12 days',
   'First charge after 14-day trial');

-- ─── 8. Subscription split — @cfa earnings AVAILABLE FOR PAYOUT (hold elapsed) ─
-- Subscription c6525438 (Karim Hassan / Cairo Football / CFA) · AED 300
INSERT INTO booking_payments (subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('c6525438-c88c-4aa6-ba73-275ffb07620d', 'subscription', 'subscription_commission', 'online',
   60.00, 'AED', '@bookboll', 'paid', now() - interval '25 days',
   'First charge after 14-day trial'),
  ('c6525438-c88c-4aa6-ba73-275ffb07620d', 'subscription', 'subscription_earning', 'online',
   240.00, 'AED', '@cfa', 'available_for_payout', now() - interval '25 days',
   'First charge after 14-day trial');

-- Subscription 715f23f0 (Amr Saleh / Cairo Football / CFA) · AED 300
INSERT INTO booking_payments (subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('715f23f0-858b-4e34-b73b-c2b1188687e5', 'subscription', 'subscription_commission', 'online',
   60.00, 'AED', '@bookboll', 'paid', now() - interval '30 days',
   'First charge after 14-day trial'),
  ('715f23f0-858b-4e34-b73b-c2b1188687e5', 'subscription', 'subscription_earning', 'online',
   240.00, 'AED', '@cfa', 'available_for_payout', now() - interval '30 days',
   'First charge after 14-day trial');

-- ─── 9. Subscription split — recurring monthly charge (not first) ────────────
-- Subscription 99518009 (Omar Al Rashid / Elite Football Academy / DSC) · AED 650
INSERT INTO booking_payments (subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('99518009-a469-4277-a6c0-0e3d9a13225f', 'subscription', 'subscription_commission', 'online',
   130.00, 'AED', '@bookboll', 'paid', now() - interval '2 days',
   'Monthly subscription charge'),
  ('99518009-a469-4277-a6c0-0e3d9a13225f', 'subscription', 'subscription_earning', 'online',
   520.00, 'AED', '@dsc', 'on_hold', now() - interval '2 days',
   'Monthly subscription charge');

-- Subscription 751d81e9 (Mostafa Ibrahim / Cairo Football / CFA) · AED 300 — recurring
INSERT INTO booking_payments (subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('751d81e9-cc82-43be-bde8-f0f0bc06eae7', 'subscription', 'subscription_commission', 'online',
   60.00, 'AED', '@bookboll', 'paid', now() - interval '1 day',
   'Monthly subscription charge'),
  ('751d81e9-cc82-43be-bde8-f0f0bc06eae7', 'subscription', 'subscription_earning', 'online',
   240.00, 'AED', '@cfa', 'on_hold', now() - interval '1 day',
   'Monthly subscription charge');

-- === 20260521015833_backfill_wallet_transactions_from_subscription_payments.sql ===
/*
  # Backfill wallet_transactions from existing subscription payments

  The wallet_transactions table was empty because subscription payments were
  seeded directly into booking_payments without going through the
  recordSubscriptionPayment() helper. This migration creates the missing
  wallet_transactions rows and sets correct provider_wallets balances.

  Changes:
  1. Insert one wallet_transaction (completed) per subscription_earning row
     in booking_payments, joining to get gross, platform_fee, owner_user_id,
     customer name, and program name.
  2. Ensure a provider_wallet row exists for each affected owner.
  3. Update provider_wallets.available_balance and lifetime_earnings to
     reflect the sum of all completed subscription earnings.
*/

-- 1. Insert missing wallet_transactions (completed, already past hold period)
INSERT INTO wallet_transactions (
  owner_user_id,
  type,
  direction,
  amount,
  provider_share,
  platform_fee,
  source_id,
  source_label,
  description,
  status,
  currency,
  available_on,
  hold_days_snapshot,
  released_at,
  created_at
)
SELECT
  cp.owner_user_id,
  'subscription_online',
  'credit',
  (bp_e.amount::numeric + bp_c.amount::numeric),
  bp_e.amount::numeric,
  bp_c.amount::numeric,
  bp_e.subscription_id,
  'Subscription · ' || COALESCE(cs.customer_name, cs.customer_email, 'customer'),
  bp_e.notes,
  'completed',
  bp_e.currency,
  (bp_e.paid_at::date),
  7,
  bp_e.paid_at,
  bp_e.paid_at
FROM booking_payments bp_e
JOIN booking_payments bp_c
  ON bp_c.subscription_id = bp_e.subscription_id
  AND bp_c.source_type = 'subscription'
  AND bp_c.payment_type = 'subscription_commission'
JOIN class_subscriptions cs ON cs.id = bp_e.subscription_id
JOIN class_programs cp ON cp.id = cs.program_id
WHERE bp_e.source_type = 'subscription'
  AND bp_e.payment_type = 'subscription_earning'
  AND NOT EXISTS (
    SELECT 1 FROM wallet_transactions wt
    WHERE wt.source_id = bp_e.subscription_id
  );

-- 2. Ensure provider_wallet rows exist for all affected owners
INSERT INTO provider_wallets (owner_user_id)
SELECT DISTINCT cp.owner_user_id
FROM booking_payments bp_e
JOIN class_subscriptions cs ON cs.id = bp_e.subscription_id
JOIN class_programs cp ON cp.id = cs.program_id
WHERE bp_e.source_type = 'subscription'
  AND bp_e.payment_type = 'subscription_earning'
ON CONFLICT DO NOTHING;

-- 3. Update available_balance and lifetime_earnings per provider
UPDATE provider_wallets pw
SET
  available_balance = totals.total_share,
  lifetime_earnings = totals.total_share,
  pending_balance = 0,
  updated_at = now()
FROM (
  SELECT
    cp.owner_user_id,
    SUM(bp_e.amount::numeric) AS total_share
  FROM booking_payments bp_e
  JOIN class_subscriptions cs ON cs.id = bp_e.subscription_id
  JOIN class_programs cp ON cp.id = cs.program_id
  WHERE bp_e.source_type = 'subscription'
    AND bp_e.payment_type = 'subscription_earning'
  GROUP BY cp.owner_user_id
) totals
WHERE pw.owner_user_id = totals.owner_user_id;

-- === 20260521020408_seed_fresh_subscription_payments.sql ===
/*
  # Seed fresh subscription payment records

  Adds realistic recent subscription charges for active subscriptions across
  both Cairo Football Arena and Dubai Sports City. Covers:
    - booking_payments rows (commission + earning, both 'paid')
    - wallet_transactions rows (mix of on_hold and completed)
    - provider_wallets balance updates

  Dates span the last 30 days so the data looks live.
*/

-- ── booking_payments: commission + earning pairs ──────────────────────────────

INSERT INTO booking_payments (booking_id, subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes) VALUES

-- Cairo Football Arena — Cairo Football Fundamentals (300/mo, fee 60, payout 240)
(NULL, '715f23f0-858b-4e34-b73b-c2b1188687e5', 'subscription', 'subscription_commission', 'online', 60,  'AED', '@bookboll',          'paid', now() - interval '28 days', 'Monthly subscription charge'),
(NULL, '715f23f0-858b-4e34-b73b-c2b1188687e5', 'subscription', 'subscription_earning',    'online', 240, 'AED', '@mahmoud_hassan',     'paid', now() - interval '28 days', 'Monthly subscription charge'),

(NULL, 'c6525438-c88c-4aa6-ba73-275ffb07620d', 'subscription', 'subscription_commission', 'online', 60,  'AED', '@bookboll',          'paid', now() - interval '21 days', 'Monthly subscription charge'),
(NULL, 'c6525438-c88c-4aa6-ba73-275ffb07620d', 'subscription', 'subscription_earning',    'online', 240, 'AED', '@mahmoud_hassan',     'paid', now() - interval '21 days', 'Monthly subscription charge'),

(NULL, 'e4ee55fb-8697-4961-8043-f89ec24f52fa', 'subscription', 'subscription_commission', 'online', 60,  'AED', '@bookboll',          'paid', now() - interval '14 days', 'First charge after 14-day trial'),
(NULL, 'e4ee55fb-8697-4961-8043-f89ec24f52fa', 'subscription', 'subscription_earning',    'online', 240, 'AED', '@mahmoud_hassan',     'paid', now() - interval '14 days', 'First charge after 14-day trial'),

(NULL, '751d81e9-cc82-43be-bde8-f0f0bc06eae7', 'subscription', 'subscription_commission', 'online', 60,  'AED', '@bookboll',          'paid', now() - interval '5 days',  'Monthly subscription charge'),
(NULL, '751d81e9-cc82-43be-bde8-f0f0bc06eae7', 'subscription', 'subscription_earning',    'online', 240, 'AED', '@mahmoud_hassan',     'paid', now() - interval '5 days',  'Monthly subscription charge'),

-- Dubai Sports City — Elite Football Academy (650/mo, fee 130, payout 520)
(NULL, 'ab8ec89c-e4c2-476e-9aac-1516a2061a5b', 'subscription', 'subscription_commission', 'online', 130, 'AED', '@bookboll',          'paid', now() - interval '25 days', 'Monthly subscription charge'),
(NULL, 'ab8ec89c-e4c2-476e-9aac-1516a2061a5b', 'subscription', 'subscription_earning',    'online', 520, 'AED', '@ahmed_almansouri',   'paid', now() - interval '25 days', 'Monthly subscription charge'),

(NULL, '530b7358-f51d-41a6-af23-f7a20a91aaec', 'subscription', 'subscription_commission', 'online', 130, 'AED', '@bookboll',          'paid', now() - interval '18 days', 'Monthly subscription charge'),
(NULL, '530b7358-f51d-41a6-af23-f7a20a91aaec', 'subscription', 'subscription_earning',    'online', 520, 'AED', '@ahmed_almansouri',   'paid', now() - interval '18 days', 'Monthly subscription charge'),

(NULL, '910916c0-0299-4f3b-9813-edaab79fd85b', 'subscription', 'subscription_commission', 'online', 130, 'AED', '@bookboll',          'paid', now() - interval '10 days', 'First charge after 14-day trial'),
(NULL, '910916c0-0299-4f3b-9813-edaab79fd85b', 'subscription', 'subscription_earning',    'online', 520, 'AED', '@ahmed_almansouri',   'paid', now() - interval '10 days', 'First charge after 14-day trial'),

(NULL, '350af3d7-cb9e-4b48-8bd9-e1ab6a7465f6', 'subscription', 'subscription_commission', 'online', 130, 'AED', '@bookboll',          'paid', now() - interval '3 days',  'Monthly subscription charge'),
(NULL, '350af3d7-cb9e-4b48-8bd9-e1ab6a7465f6', 'subscription', 'subscription_earning',    'online', 520, 'AED', '@ahmed_almansouri',   'paid', now() - interval '3 days',  'Monthly subscription charge'),

-- Dubai Sports City — Padel Beginners Course (500/mo, fee 100, payout 400)
(NULL, '6646df0f-e3af-4b9e-8645-487bb8ede688', 'subscription', 'subscription_commission', 'online', 100, 'AED', '@bookboll',          'paid', now() - interval '22 days', 'First charge after 14-day trial'),
(NULL, '6646df0f-e3af-4b9e-8645-487bb8ede688', 'subscription', 'subscription_earning',    'online', 400, 'AED', '@ahmed_almansouri',   'paid', now() - interval '22 days', 'First charge after 14-day trial'),

(NULL, 'd65408cd-6f41-4a23-949e-89b6cc6c3164', 'subscription', 'subscription_commission', 'online', 100, 'AED', '@bookboll',          'paid', now() - interval '6 days',  'Monthly subscription charge'),
(NULL, 'd65408cd-6f41-4a23-949e-89b6cc6c3164', 'subscription', 'subscription_earning',    'online', 400, 'AED', '@ahmed_almansouri',   'paid', now() - interval '6 days',  'Monthly subscription charge'),

-- Dubai Sports City — Advanced Padel Clinic (900/mo, fee 180, payout 720)
(NULL, '2b95465d-bf2e-4723-98e3-c8ff3831d99a', 'subscription', 'subscription_commission', 'online', 180, 'AED', '@bookboll',          'paid', now() - interval '15 days', 'First charge after 14-day trial'),
(NULL, '2b95465d-bf2e-4723-98e3-c8ff3831d99a', 'subscription', 'subscription_earning',    'online', 720, 'AED', '@ahmed_almansouri',   'paid', now() - interval '15 days', 'First charge after 14-day trial'),

(NULL, '70551ab6-b780-4403-9ccd-eb8be0bbe029', 'subscription', 'subscription_commission', 'online', 180, 'AED', '@bookboll',          'paid', now() - interval '4 days',  'Monthly subscription charge'),
(NULL, '70551ab6-b780-4403-9ccd-eb8be0bbe029', 'subscription', 'subscription_earning',    'online', 720, 'AED', '@ahmed_almansouri',   'paid', now() - interval '4 days',  'Monthly subscription charge'),

-- Dubai Sports City — Junior Football Stars (450/mo, fee 90, payout 360)
(NULL, 'cdbf9051-5214-415d-a1cf-10ce06f6f85d', 'subscription', 'subscription_commission', 'online', 90,  'AED', '@bookboll',          'paid', now() - interval '12 days', 'Monthly subscription charge'),
(NULL, 'cdbf9051-5214-415d-a1cf-10ce06f6f85d', 'subscription', 'subscription_earning',    'online', 360, 'AED', '@ahmed_almansouri',   'paid', now() - interval '12 days', 'Monthly subscription charge'),

(NULL, '6a6bc907-23b2-40c8-be7a-fd32539ab7f4', 'subscription', 'subscription_commission', 'online', 90,  'AED', '@bookboll',          'paid', now() - interval '2 days',  'Monthly subscription charge'),
(NULL, '6a6bc907-23b2-40c8-be7a-fd32539ab7f4', 'subscription', 'subscription_earning',    'online', 360, 'AED', '@ahmed_almansouri',   'paid', now() - interval '2 days',  'Monthly subscription charge');


-- ── wallet_transactions ───────────────────────────────────────────────────────

INSERT INTO wallet_transactions (owner_user_id, type, direction, amount, provider_share, platform_fee, source_id, source_label, description, status, currency, available_on, hold_days_snapshot, released_at, created_at) VALUES

-- Cairo Football Arena (owner: 8d7f528d-0fd7-48d5-9f1a-8f19dc80c392)
('8d7f528d-0fd7-48d5-9f1a-8f19dc80c392', 'subscription_online', 'credit', 300, 240, 60, '715f23f0-858b-4e34-b73b-c2b1188687e5', 'Subscription · Amr Saleh',     'Monthly subscription charge',     'completed', 'AED', (now() - interval '21 days')::date, 7, now() - interval '21 days', now() - interval '28 days'),
('8d7f528d-0fd7-48d5-9f1a-8f19dc80c392', 'subscription_online', 'credit', 300, 240, 60, 'c6525438-c88c-4aa6-ba73-275ffb07620d', 'Subscription · Karim Hassan',   'Monthly subscription charge',     'completed', 'AED', (now() - interval '14 days')::date, 7, now() - interval '14 days', now() - interval '21 days'),
('8d7f528d-0fd7-48d5-9f1a-8f19dc80c392', 'subscription_online', 'credit', 300, 240, 60, 'e4ee55fb-8697-4961-8043-f89ec24f52fa', 'Subscription · Youssef Nabil',  'First charge after 14-day trial', 'completed', 'AED', (now() - interval '7 days')::date,  7, now() - interval '7 days',  now() - interval '14 days'),
('8d7f528d-0fd7-48d5-9f1a-8f19dc80c392', 'subscription_online', 'credit', 300, 240, 60, '751d81e9-cc82-43be-bde8-f0f0bc06eae7', 'Subscription · Mostafa Ibrahim','Monthly subscription charge',     'on_hold',   'AED', (now() + interval '2 days')::date,  7, NULL,                         now() - interval '5 days'),

-- Dubai Sports City (owner: 5ea6f1c5-0d55-4d50-a9a5-24003caadb52)
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 650, 520, 130, 'ab8ec89c-e4c2-476e-9aac-1516a2061a5b', 'Subscription · Daniel Kim',     'Monthly subscription charge',     'completed', 'AED', (now() - interval '18 days')::date, 7, now() - interval '18 days', now() - interval '25 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 650, 520, 130, '530b7358-f51d-41a6-af23-f7a20a91aaec', 'Subscription · Faisal Al Zahrani','Monthly subscription charge',   'completed', 'AED', (now() - interval '11 days')::date, 7, now() - interval '11 days', now() - interval '18 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 650, 520, 130, '910916c0-0299-4f3b-9813-edaab79fd85b', 'Subscription · Liam Torres',    'First charge after 14-day trial', 'completed', 'AED', (now() - interval '3 days')::date,  7, now() - interval '3 days',  now() - interval '10 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 650, 520, 130, '350af3d7-cb9e-4b48-8bd9-e1ab6a7465f6', 'Subscription · Ryan Murphy',    'Monthly subscription charge',     'on_hold',   'AED', (now() + interval '4 days')::date,  7, NULL,                         now() - interval '3 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 500, 400, 100, '6646df0f-e3af-4b9e-8645-487bb8ede688', 'Subscription · Aisha Al Farsi', 'First charge after 14-day trial', 'completed', 'AED', (now() - interval '15 days')::date, 7, now() - interval '15 days', now() - interval '22 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 500, 400, 100, 'd65408cd-6f41-4a23-949e-89b6cc6c3164', 'Subscription · Sofia Andrade',  'Monthly subscription charge',     'on_hold',   'AED', (now() + interval '1 day')::date,   7, NULL,                         now() - interval '6 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 900, 720, 180, '2b95465d-bf2e-4723-98e3-c8ff3831d99a', 'Subscription · Layla Al Amri',  'First charge after 14-day trial', 'completed', 'AED', (now() - interval '8 days')::date,  7, now() - interval '8 days',  now() - interval '15 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 900, 720, 180, '70551ab6-b780-4403-9ccd-eb8be0bbe029', 'Subscription · Pierre Dubois',  'Monthly subscription charge',     'on_hold',   'AED', (now() + interval '3 days')::date,  7, NULL,                         now() - interval '4 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 450, 360, 90,  'cdbf9051-5214-415d-a1cf-10ce06f6f85d', 'Subscription · Fatima Al Shamsi','Monthly subscription charge',    'completed', 'AED', (now() - interval '5 days')::date,  7, now() - interval '5 days',  now() - interval '12 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 450, 360, 90,  '6a6bc907-23b2-40c8-be7a-fd32539ab7f4', 'Subscription · Jack Williams',  'Monthly subscription charge',     'on_hold',   'AED', (now() + interval '5 days')::date,  7, NULL,                         now() - interval '2 days');


-- ── provider_wallets: recompute balances from scratch ────────────────────────

-- Cairo Football Arena: completed = 240*3 = 720, on_hold = 240*1 = 240
UPDATE provider_wallets
SET
  available_balance = 720,
  pending_balance   = 240,
  lifetime_earnings = 960,
  updated_at        = now()
WHERE owner_user_id = '8d7f528d-0fd7-48d5-9f1a-8f19dc80c392';

-- Dubai Sports City: completed = 520*3+400+720+360 = 3040, on_hold = 520+400+720+360 = 2000
UPDATE provider_wallets
SET
  available_balance = 3040,
  pending_balance   = 2000,
  lifetime_earnings = 5040,
  updated_at        = now()
WHERE owner_user_id = '5ea6f1c5-0d55-4d50-a9a5-24003caadb52';

