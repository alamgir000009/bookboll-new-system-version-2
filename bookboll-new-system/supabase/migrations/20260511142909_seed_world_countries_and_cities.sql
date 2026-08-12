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
