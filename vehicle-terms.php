<?php

/**
 * Static vehicle brand / model / variant lookup + AI output validators.
 *
 * Vehicle names are proper nouns with fixed forms in both languages and must
 * never be sent to an AI. "ماكان" (Macan) reads as "ma kan" — Arabic for "what
 * was" — and came back as "It was not" / "What it was" / "What was S". Two-word
 * model names also came back as "Please provide the Arabic text you would like
 * me to translate." 4,022 rows across 173 distinct values were wrong before this.
 *
 * A miss returns the source value unchanged and is recorded in the
 * erpgulf_gt_vehicle_unmapped option, so a gap shows up as untranslated rather
 * than as corrupt text. Review that option and extend the map below.
 */
if (!defined('ABSPATH')) {
    exit;
}

function erpgulf_gt_vehicle_map(): array
{
    static $m = null;
    if ($m !== null) {
        return $m;
    }

    $m = array(
        // ── brands ──────────────────────────────────────────────
        'شيفروليه' => 'Chevrolet',
        'تويوتا' => 'Toyota',
        'لكزس' => 'Lexus',
        'مرسيدس بنز' => 'Mercedes-Benz',
        'بي ام دبليو' => 'BMW',
        'دودج' => 'Dodge',
        'نيسان' => 'Nissan',
        'أودي' => 'Audi',
        'انفينيتي' => 'Infiniti',
        'كاديلاك' => 'Cadillac',
        'لاند روفر' => 'Land Rover',
        'جي ام سي' => 'GMC',
        'جي إم سي' => 'GMC',
        'فولكس فاجن' => 'Volkswagen',
        'فورد' => 'Ford',
        'كيا' => 'Kia',
        'جيب' => 'Jeep',
        'بورش' => 'Porsche',
        'كرايسلر' => 'Chrysler',
        'هيونداي' => 'Hyundai',
        'هوندا' => 'Honda',
        'لينكولن' => 'Lincoln',
        'مازيراتي' => 'Maserati',
        'جينيسيس' => 'Genesis',
        'هامر' => 'Hummer',
        'جاكوار' => 'Jaguar',
        'مازدا' => 'Mazda',
        'ميتسوبيشي' => 'Mitsubishi',
        'بنتلي' => 'Bentley',
        'ام جي' => 'MG',
        'شانجان' => 'Changan',
        'ميركوري' => 'Mercury',
        'تسلا' => 'Tesla',
        'رام' => 'Ram',
        'هافال' => 'Haval',
        'ايسوزو' => 'Isuzu',
        'جيلي' => 'Geely',
        'شيري' => 'Chery',
        'فيراري' => 'Ferrari',
        'لوسيد' => 'Lucid',
        'ألبينا' => 'Alpina',
        'سانغ يونغ' => 'SsangYong',
        // ── models ──────────────────────────────────────────────
        'كابرس' => 'Caprice',
        'تشارجر' => 'Charger',
        'تشالنجر' => 'Challenger',
        'لومينا' => 'Lumina',
        'كامري' => 'Camry',
        'كامارو' => 'Camaro',
        'افالون' => 'Avalon',
        'توارك' => 'Touareg',
        'رينج روفر سبورت' => 'Range Rover Sport',
        'رينج روفر ايفوك' => 'Range Rover Evoque',
        'رينج روفر' => 'Range Rover',
        'سييرا' => 'Sierra',
        'سيلفرادو' => 'Silverado',
        'ماليبو' => 'Malibu',
        'كايين' => 'Cayenne',
        'جراند شيروكي' => 'Grand Cherokee',
        'شيروكي' => 'Cherokee',
        'اوريون' => 'Aurion',
        'لاندركروزر' => 'Land Cruiser',
        'باثفايندر' => 'Pathfinder',
        'تاهو' => 'Tahoe',
        'سوبربان' => 'Suburban',
        'اكورد' => 'Accord',
        'يوكن' => 'Yukon',
        'افلانش' => 'Avalanche',
        'كورفيت' => 'Corvette',
        'اسكاليد' => 'Escalade',
        'دورانجو' => 'Durango',
        'تريل بليزر' => 'TrailBlazer',
        'باناميرا' => 'Panamera',
        'اكسبدشن' => 'Expedition',
        'نافيغيتور' => 'Navigator',
        'سكويا' => 'Sequoia',
        'انفوي' => 'Envoy',
        'جينسيس' => 'Genesis',
        'جينسيس كوبيه' => 'Genesis Coupe',
        'جينيسيس كوبيه' => 'Genesis Coupe',
        'ماكسيما' => 'Maxima',
        'غيبلي' => 'Ghibli',
        'التيما' => 'Altima',
        'مورانو' => 'Murano',
        'سبورتاج' => 'Sportage',
        'باترول' => 'Patrol',
        'امبالا' => 'Impala',
        'اكسبلورر' => 'Explorer',
        'تورس' => 'Taurus',
        'كورولا' => 'Corolla',
        'باجيرو' => 'Pajero',
        'كواتروبورتي' => 'Quattroporte',
        'رانجلر' => 'Wrangler',
        'تندرا' => 'Tundra',
        'اوبتيما' => 'Optima',
        'سوناتا' => 'Sonata',
        'جولف' => 'Golf',
        'جيتا' => 'Jetta',
        'سافانا' => 'Savana',
        'برادو' => 'Prado',
        'اكستيرا' => 'Xterra',
        'سانتا في' => 'Santa Fe',
        'سيفيك' => 'Civic',
        'النترا' => 'Elantra',
        'توسان' => 'Tucson',
        'ايدج' => 'Edge',
        'اكاديا' => 'Acadia',
        'ترافيرس' => 'Traverse',
        'فلاينج سبير' => 'Flying Spur',
        'كونتيننتال' => 'Continental',
        'بايلوت' => 'Pilot',
        'كروز' => 'Cruze',
        'ازيرا' => 'Azera',
        'كادينزا' => 'Cadenza',
        'جوك' => 'Juke',
        'ماكان' => 'Macan',
        'ماكان S' => 'Macan S',
        'بيتل' => 'Beetle',
        'ستينجر' => 'Stinger',
        'اكسنت' => 'Accent',
        'فيوجن' => 'Fusion',
        'ارمادا' => 'Armada',
        'كوماندر' => 'Commander',
        'موستنج' => 'Mustang',
        'فلكس' => 'Flex',
        'سورينتو' => 'Sorento',
        'اكوينوكس' => 'Equinox',
        'تيرين' => 'Terrain',
        'ديسكفري' => 'Discovery',
        'ديسكفري سبورت' => 'Discovery Sport',
        'ديسكفري 4' => 'Discovery 4',
        'ديسكفري 5' => 'Discovery 5',
        'تيرامونت' => 'Teramont',
        'باسات' => 'Passat',
        'فوكس' => 'Focus',
        'سيلتوس' => 'Seltos',
        'مونتينير' => 'Mountaineer',
        'مازدا 6' => 'Mazda 6',
        'مازدا 3' => 'Mazda 3',
        'رينيجيد' => 'Renegade',
        'كومباس' => 'Compass',
        'ديفندر' => 'Defender',
        'فورتشنر' => 'Fortuner',
        'اوديسي' => 'Odyssey',
        'هايلكس' => 'Hilux',
        'اكستريل' => 'X-Trail',
        'سنترا' => 'Sentra',
        'غران توريزمو' => 'GranTurismo',
        'غران كابريو' => 'GranCabrio',
        'اكسبريس' => 'Express',
        'كارينز' => 'Carens',
        'كريتا' => 'Creta',
        'كراون فكتوريا' => 'Crown Victoria',
        'تاون كار' => 'Town Car',
        'جراند ماركيز' => 'Grand Marquis',
        'بريوس' => 'Prius',
        'إمجراند' => 'Emgrand',
        'ايمجراند GT' => 'Emgrand GT',
        'كولراي' => 'Coolray',
        'إنوفا' => 'Innova',
        'سول' => 'Soul',
        'مارانيلو 575M' => '575M Maranello',
        'إير' => 'Air',
        'كولورادو' => 'Colorado',
        'كانيون' => 'Canyon',
        'ريو' => 'Rio',
        'لانسر' => 'Lancer',
        'مونتيرو' => 'Montero',
        'ماجنتيس' => 'Magentis',
        'ليبرتي' => 'Liberty',
        'تيغوان' => 'Tiguan',
        'موهافي' => 'Mohave',
        'موسو' => 'Musso',
        'دارت' => 'Dart',
        'تيتان' => 'Titan',
        'إيكوس' => 'Equus',
        'جرانديز' => 'Grandis',
        'كوبيه' => 'Coupe',
        // ── variants ────────────────────────────────────────────
        // Wording follows the English values already in the catalogue
        // (Gasoline 65,382 / Electricity 21) — not the more natural "Petrol"
        // and "Electric", because a facet with two spellings for one fuel type
        // splits the product set and each entry matches only half.
        'بنزين' => 'Gasoline',
        'ديزل' => 'Diesel',
        'هجين' => 'Hybrid',
        'كهرباء' => 'Electricity',
    );

    return $m;
}

/**
 * Translate one vehicle term. Direction comes from $target_lang.
 * Latin input going to English is returned untouched — "TT", "X5",
 * "GLE63 AMG S SUV" are already correct and have no Arabic form.
 */
function erpgulf_gt_vehicle_term(string $value, string $target_lang = 'English'): string
{
    $v = trim($value);
    if ($v === '') {
        return $value;
    }

    $to_en = (erpgulf_gt_lang_name_to_code($target_lang) === 'en');
    $map = erpgulf_gt_vehicle_map();

    if ($to_en) {
        if (isset($map[$v])) {
            return $map[$v];
        }
        if (!preg_match('/\p{Arabic}/u', $v)) {
            return $v;  // already a Latin designation
        }
    } else {
        $rev = array_flip($map);
        if (isset($rev[$v])) {
            return $rev[$v];
        }
        if (preg_match('/\p{Arabic}/u', $v)) {
            return $v;  // already Arabic
        }
    }

    erpgulf_gt_vehicle_note_unmapped($v, $target_lang);
    return $value;  // keep the source: a visible gap, never corrupt text
}

/**
 * Record misses so the table can be extended from real data instead of guesses.
 */
function erpgulf_gt_vehicle_note_unmapped(string $value, string $target_lang): void
{
    $list = get_option('erpgulf_gt_vehicle_unmapped', array());
    if (!is_array($list)) {
        $list = array();
    }

    $k = $target_lang . '|' . $value;
    if (!isset($list[$k])) {
        $list[$k] = array('n' => 0, 'first' => current_time('mysql'));
    }
    $list[$k]['n']++;
    $list[$k]['last'] = current_time('mysql');

    if (count($list) > 500) {
        $list = array_slice($list, -500, null, true);
    }
    update_option('erpgulf_gt_vehicle_unmapped', $list, false);
}

/**
 * True when a response is conversation rather than a translation.
 * Reusable for category names, titles and repeater values.
 */
function erpgulf_gt_looks_like_ai_chatter(string $s): bool
{
    $s = trim($s);
    if ($s === '') {
        return true;
    }
    if (preg_match("/\b(please provide|as an ai|i am sorry|i'm sorry|i cannot|i can't|unable to|would you like|let me know|here is the translation|the translation of|no explanation|there is no text|provide the arabic|provide the text)\b/i", $s)) {
        return true;
    }
    if (stripos($s, 'THINK') !== false) {
        return true;  // reasoning leak
    }
    if (mb_strlen($s) > 80) {
        return true;  // a sentence, not a name
    }
    return false;
}

/**
 * A category name is about to become a permanent taxonomy term. Accept only a
 * plain Latin name — never Arabic (translation never happened), never a
 * sentence, never chat.
 */
function erpgulf_gt_validate_en_term_name(string $name): bool
{
    $n = trim($name);
    if ($n === '') {
        return false;
    }
    if (preg_match('/\p{Arabic}/u', $n)) {
        return false;
    }
    if (!preg_match('/[A-Za-z]/', $n)) {
        return false;
    }
    if (mb_strlen($n) > 60) {
        return false;
    }
    return !erpgulf_gt_looks_like_ai_chatter($n);
}

/**
 * Length-aware check for ordinary fields, where a long result can be legitimate
 * (descriptions) but a short source expanding into a paragraph cannot.
 */
function erpgulf_gt_looks_like_ai_translation_failure(string $out, string $src): bool
{
    $o = trim($out);
    if ($o === '') {
        return true;
    }
    if (preg_match("/\b(please provide|as an ai|i am sorry|i'm sorry|i cannot|i can't|unable to translate|there is no text|no text was provided|provide the arabic|provide the text)\b/i", $o)) {
        return true;
    }
    if (stripos($o, 'THINK') !== false) {
        return true;
    }
    if (mb_strlen($src) <= 40 && mb_strlen($o) > (mb_strlen($src) * 4 + 40)) {
        return true;
    }
    return false;
}
