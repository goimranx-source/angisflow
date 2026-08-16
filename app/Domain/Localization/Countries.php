<?php

declare(strict_types=1);

namespace App\Domain\Localization;

/**
 * Every country the tool will sell into, with what it trades in and where its
 * clocks are.
 *
 * ── Why a class constant rather than a table ─────────────────────────────────
 *
 * This is reference data, not tenant data. Nobody edits it, every account sees
 * the same list, and a table would mean a migration and a seeder for something
 * that changes when a country changes its currency — roughly once a year,
 * across the whole world. Deploy is the right update mechanism.
 *
 * The full localization pack — tax regimes, address formats, name order,
 * fiscal calendars, holiday tables — is a separate and much larger job, and
 * will want tables. This is the small part of it needed to open a set of books
 * in the right currency, and is deliberately not trying to be that.
 *
 * ── Generated ────────────────────────────────────────────────────────────────
 *
 * Names and currency codes are ISO 3166-1 alpha-2 and ISO 4217. The timezone
 * lists come from PHP's own tz database rather than being typed out here — a
 * hand-copied zone table is wrong within a year, and the correct one already
 * ships with the runtime.
 */
final class Countries
{
    /**
     * code => [name, currency, [timezone, ...]]
     *
     * The first timezone is the one a new set of books is opened in. For the
     * many countries with exactly one it is the only answer; for the handful
     * with dozens it is a starting point the subscriber can correct, which is
     * a far better first run than an empty field.
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public const ALL = [
        'AD' => ['Andorra', 'EUR', ['Europe/Andorra']],
        'AE' => ['United Arab Emirates', 'AED', ['Asia/Dubai']],
        'AF' => ['Afghanistan', 'AFN', ['Asia/Kabul']],
        'AG' => ['Antigua and Barbuda', 'XCD', ['America/Antigua']],
        'AI' => ['Anguilla', 'XCD', ['America/Anguilla']],
        'AL' => ['Albania', 'ALL', ['Europe/Tirane']],
        'AM' => ['Armenia', 'AMD', ['Asia/Yerevan']],
        'AO' => ['Angola', 'AOA', ['Africa/Luanda']],
        'AR' => ['Argentina', 'ARS', ['America/Argentina/Buenos_Aires', 'America/Argentina/Catamarca', 'America/Argentina/Cordoba', 'America/Argentina/Jujuy', 'America/Argentina/La_Rioja', 'America/Argentina/Mendoza', 'America/Argentina/Rio_Gallegos', 'America/Argentina/Salta', 'America/Argentina/San_Juan', 'America/Argentina/San_Luis', 'America/Argentina/Tucuman', 'America/Argentina/Ushuaia']],
        'AS' => ['American Samoa', 'USD', ['Pacific/Pago_Pago']],
        'AT' => ['Austria', 'EUR', ['Europe/Vienna']],
        'AU' => ['Australia', 'AUD', ['Australia/Sydney', 'Antarctica/Macquarie', 'Australia/Adelaide', 'Australia/Brisbane', 'Australia/Broken_Hill', 'Australia/Darwin', 'Australia/Eucla', 'Australia/Hobart', 'Australia/Lindeman', 'Australia/Lord_Howe', 'Australia/Melbourne', 'Australia/Perth']],
        'AW' => ['Aruba', 'AWG', ['America/Aruba']],
        'AX' => ['Åland Islands', 'EUR', ['Europe/Mariehamn']],
        'AZ' => ['Azerbaijan', 'AZN', ['Asia/Baku']],
        'BA' => ['Bosnia and Herzegovina', 'BAM', ['Europe/Sarajevo']],
        'BB' => ['Barbados', 'BBD', ['America/Barbados']],
        'BD' => ['Bangladesh', 'BDT', ['Asia/Dhaka']],
        'BE' => ['Belgium', 'EUR', ['Europe/Brussels']],
        'BF' => ['Burkina Faso', 'XOF', ['Africa/Ouagadougou']],
        'BG' => ['Bulgaria', 'BGN', ['Europe/Sofia']],
        'BH' => ['Bahrain', 'BHD', ['Asia/Bahrain']],
        'BI' => ['Burundi', 'BIF', ['Africa/Bujumbura']],
        'BJ' => ['Benin', 'XOF', ['Africa/Porto-Novo']],
        'BL' => ['Saint Barthélemy', 'EUR', ['America/St_Barthelemy']],
        'BM' => ['Bermuda', 'BMD', ['Atlantic/Bermuda']],
        'BN' => ['Brunei', 'BND', ['Asia/Brunei']],
        'BO' => ['Bolivia', 'BOB', ['America/La_Paz']],
        'BQ' => ['Caribbean Netherlands', 'USD', ['America/Kralendijk']],
        'BR' => ['Brazil', 'BRL', ['America/Sao_Paulo', 'America/Araguaina', 'America/Bahia', 'America/Belem', 'America/Boa_Vista', 'America/Campo_Grande', 'America/Cuiaba', 'America/Eirunepe', 'America/Fortaleza', 'America/Maceio', 'America/Manaus', 'America/Noronha', 'America/Porto_Velho', 'America/Recife', 'America/Rio_Branco', 'America/Santarem']],
        'BS' => ['Bahamas', 'BSD', ['America/Nassau']],
        'BT' => ['Bhutan', 'BTN', ['Asia/Thimphu']],
        'BW' => ['Botswana', 'BWP', ['Africa/Gaborone']],
        'BY' => ['Belarus', 'BYN', ['Europe/Minsk']],
        'BZ' => ['Belize', 'BZD', ['America/Belize']],
        'CA' => ['Canada', 'CAD', ['America/Toronto', 'America/Atikokan', 'America/Blanc-Sablon', 'America/Cambridge_Bay', 'America/Creston', 'America/Dawson', 'America/Dawson_Creek', 'America/Edmonton', 'America/Fort_Nelson', 'America/Glace_Bay', 'America/Goose_Bay', 'America/Halifax', 'America/Inuvik', 'America/Iqaluit', 'America/Moncton', 'America/Rankin_Inlet', 'America/Regina', 'America/Resolute', 'America/St_Johns', 'America/Swift_Current', 'America/Vancouver', 'America/Whitehorse', 'America/Winnipeg']],
        'CC' => ['Cocos (Keeling) Islands', 'AUD', ['Indian/Cocos']],
        'CD' => ['Congo (DRC)', 'CDF', ['Africa/Kinshasa', 'Africa/Lubumbashi']],
        'CF' => ['Central African Republic', 'XAF', ['Africa/Bangui']],
        'CG' => ['Congo (Republic)', 'XAF', ['Africa/Brazzaville']],
        'CH' => ['Switzerland', 'CHF', ['Europe/Zurich']],
        'CI' => ['Côte d\'Ivoire', 'XOF', ['Africa/Abidjan']],
        'CK' => ['Cook Islands', 'NZD', ['Pacific/Rarotonga']],
        'CL' => ['Chile', 'CLP', ['America/Santiago', 'America/Punta_Arenas', 'Pacific/Easter']],
        'CM' => ['Cameroon', 'XAF', ['Africa/Douala']],
        'CN' => ['China', 'CNY', ['Asia/Shanghai', 'Asia/Urumqi']],
        'CO' => ['Colombia', 'COP', ['America/Bogota']],
        'CR' => ['Costa Rica', 'CRC', ['America/Costa_Rica']],
        'CU' => ['Cuba', 'CUP', ['America/Havana']],
        'CV' => ['Cape Verde', 'CVE', ['Atlantic/Cape_Verde']],
        'CW' => ['Curaçao', 'XCG', ['America/Curacao']],
        'CX' => ['Christmas Island', 'AUD', ['Indian/Christmas']],
        'CY' => ['Cyprus', 'EUR', ['Asia/Nicosia', 'Asia/Famagusta']],
        'CZ' => ['Czechia', 'CZK', ['Europe/Prague']],
        'DE' => ['Germany', 'EUR', ['Europe/Berlin', 'Europe/Busingen']],
        'DJ' => ['Djibouti', 'DJF', ['Africa/Djibouti']],
        'DK' => ['Denmark', 'DKK', ['Europe/Copenhagen']],
        'DM' => ['Dominica', 'XCD', ['America/Dominica']],
        'DO' => ['Dominican Republic', 'DOP', ['America/Santo_Domingo']],
        'DZ' => ['Algeria', 'DZD', ['Africa/Algiers']],
        'EC' => ['Ecuador', 'USD', ['America/Guayaquil', 'Pacific/Galapagos']],
        'EE' => ['Estonia', 'EUR', ['Europe/Tallinn']],
        'EG' => ['Egypt', 'EGP', ['Africa/Cairo']],
        'EH' => ['Western Sahara', 'MAD', ['Africa/El_Aaiun']],
        'ER' => ['Eritrea', 'ERN', ['Africa/Asmara']],
        'ES' => ['Spain', 'EUR', ['Europe/Madrid', 'Africa/Ceuta', 'Atlantic/Canary']],
        'ET' => ['Ethiopia', 'ETB', ['Africa/Addis_Ababa']],
        'FI' => ['Finland', 'EUR', ['Europe/Helsinki']],
        'FJ' => ['Fiji', 'FJD', ['Pacific/Fiji']],
        'FK' => ['Falkland Islands', 'FKP', ['Atlantic/Stanley']],
        'FM' => ['Micronesia', 'USD', ['Pacific/Pohnpei', 'Pacific/Chuuk', 'Pacific/Kosrae']],
        'FO' => ['Faroe Islands', 'DKK', ['Atlantic/Faroe']],
        'FR' => ['France', 'EUR', ['Europe/Paris']],
        'GA' => ['Gabon', 'XAF', ['Africa/Libreville']],
        'GB' => ['United Kingdom', 'GBP', ['Europe/London']],
        'GD' => ['Grenada', 'XCD', ['America/Grenada']],
        'GE' => ['Georgia', 'GEL', ['Asia/Tbilisi']],
        'GF' => ['French Guiana', 'EUR', ['America/Cayenne']],
        'GG' => ['Guernsey', 'GBP', ['Europe/Guernsey']],
        'GH' => ['Ghana', 'GHS', ['Africa/Accra']],
        'GI' => ['Gibraltar', 'GIP', ['Europe/Gibraltar']],
        'GL' => ['Greenland', 'DKK', ['America/Nuuk', 'America/Danmarkshavn', 'America/Scoresbysund', 'America/Thule']],
        'GM' => ['Gambia', 'GMD', ['Africa/Banjul']],
        'GN' => ['Guinea', 'GNF', ['Africa/Conakry']],
        'GP' => ['Guadeloupe', 'EUR', ['America/Guadeloupe']],
        'GQ' => ['Equatorial Guinea', 'XAF', ['Africa/Malabo']],
        'GR' => ['Greece', 'EUR', ['Europe/Athens']],
        'GT' => ['Guatemala', 'GTQ', ['America/Guatemala']],
        'GU' => ['Guam', 'USD', ['Pacific/Guam']],
        'GW' => ['Guinea-Bissau', 'XOF', ['Africa/Bissau']],
        'GY' => ['Guyana', 'GYD', ['America/Guyana']],
        'HK' => ['Hong Kong', 'HKD', ['Asia/Hong_Kong']],
        'HN' => ['Honduras', 'HNL', ['America/Tegucigalpa']],
        'HR' => ['Croatia', 'EUR', ['Europe/Zagreb']],
        'HT' => ['Haiti', 'HTG', ['America/Port-au-Prince']],
        'HU' => ['Hungary', 'HUF', ['Europe/Budapest']],
        'ID' => ['Indonesia', 'IDR', ['Asia/Jakarta', 'Asia/Jayapura', 'Asia/Makassar', 'Asia/Pontianak']],
        'IE' => ['Ireland', 'EUR', ['Europe/Dublin']],
        'IL' => ['Israel', 'ILS', ['Asia/Jerusalem']],
        'IM' => ['Isle of Man', 'GBP', ['Europe/Isle_of_Man']],
        'IN' => ['India', 'INR', ['Asia/Kolkata']],
        'IO' => ['British Indian Ocean Territory', 'USD', ['Indian/Chagos']],
        'IQ' => ['Iraq', 'IQD', ['Asia/Baghdad']],
        'IR' => ['Iran', 'IRR', ['Asia/Tehran']],
        'IS' => ['Iceland', 'ISK', ['Atlantic/Reykjavik']],
        'IT' => ['Italy', 'EUR', ['Europe/Rome']],
        'JE' => ['Jersey', 'GBP', ['Europe/Jersey']],
        'JM' => ['Jamaica', 'JMD', ['America/Jamaica']],
        'JO' => ['Jordan', 'JOD', ['Asia/Amman']],
        'JP' => ['Japan', 'JPY', ['Asia/Tokyo']],
        'KE' => ['Kenya', 'KES', ['Africa/Nairobi']],
        'KG' => ['Kyrgyzstan', 'KGS', ['Asia/Bishkek']],
        'KH' => ['Cambodia', 'KHR', ['Asia/Phnom_Penh']],
        'KI' => ['Kiribati', 'AUD', ['Pacific/Tarawa', 'Pacific/Kanton', 'Pacific/Kiritimati']],
        'KM' => ['Comoros', 'KMF', ['Indian/Comoro']],
        'KN' => ['Saint Kitts and Nevis', 'XCD', ['America/St_Kitts']],
        'KP' => ['North Korea', 'KPW', ['Asia/Pyongyang']],
        'KR' => ['South Korea', 'KRW', ['Asia/Seoul']],
        'KW' => ['Kuwait', 'KWD', ['Asia/Kuwait']],
        'KY' => ['Cayman Islands', 'KYD', ['America/Cayman']],
        'KZ' => ['Kazakhstan', 'KZT', ['Asia/Almaty', 'Asia/Aqtau', 'Asia/Aqtobe', 'Asia/Atyrau', 'Asia/Oral', 'Asia/Qostanay', 'Asia/Qyzylorda']],
        'LA' => ['Laos', 'LAK', ['Asia/Vientiane']],
        'LB' => ['Lebanon', 'LBP', ['Asia/Beirut']],
        'LC' => ['Saint Lucia', 'XCD', ['America/St_Lucia']],
        'LI' => ['Liechtenstein', 'CHF', ['Europe/Vaduz']],
        'LK' => ['Sri Lanka', 'LKR', ['Asia/Colombo']],
        'LR' => ['Liberia', 'LRD', ['Africa/Monrovia']],
        'LS' => ['Lesotho', 'LSL', ['Africa/Maseru']],
        'LT' => ['Lithuania', 'EUR', ['Europe/Vilnius']],
        'LU' => ['Luxembourg', 'EUR', ['Europe/Luxembourg']],
        'LV' => ['Latvia', 'EUR', ['Europe/Riga']],
        'LY' => ['Libya', 'LYD', ['Africa/Tripoli']],
        'MA' => ['Morocco', 'MAD', ['Africa/Casablanca']],
        'MC' => ['Monaco', 'EUR', ['Europe/Monaco']],
        'MD' => ['Moldova', 'MDL', ['Europe/Chisinau']],
        'ME' => ['Montenegro', 'EUR', ['Europe/Podgorica']],
        'MF' => ['Saint Martin', 'EUR', ['America/Marigot']],
        'MG' => ['Madagascar', 'MGA', ['Indian/Antananarivo']],
        'MH' => ['Marshall Islands', 'USD', ['Pacific/Majuro', 'Pacific/Kwajalein']],
        'MK' => ['North Macedonia', 'MKD', ['Europe/Skopje']],
        'ML' => ['Mali', 'XOF', ['Africa/Bamako']],
        'MM' => ['Myanmar', 'MMK', ['Asia/Yangon']],
        'MN' => ['Mongolia', 'MNT', ['Asia/Ulaanbaatar', 'Asia/Choibalsan', 'Asia/Hovd']],
        'MO' => ['Macao', 'MOP', ['Asia/Macau']],
        'MP' => ['Northern Mariana Islands', 'USD', ['Pacific/Saipan']],
        'MQ' => ['Martinique', 'EUR', ['America/Martinique']],
        'MR' => ['Mauritania', 'MRU', ['Africa/Nouakchott']],
        'MS' => ['Montserrat', 'XCD', ['America/Montserrat']],
        'MT' => ['Malta', 'EUR', ['Europe/Malta']],
        'MU' => ['Mauritius', 'MUR', ['Indian/Mauritius']],
        'MV' => ['Maldives', 'MVR', ['Indian/Maldives']],
        'MW' => ['Malawi', 'MWK', ['Africa/Blantyre']],
        'MX' => ['Mexico', 'MXN', ['America/Mexico_City', 'America/Bahia_Banderas', 'America/Cancun', 'America/Chihuahua', 'America/Ciudad_Juarez', 'America/Hermosillo', 'America/Matamoros', 'America/Mazatlan', 'America/Merida', 'America/Monterrey', 'America/Ojinaga', 'America/Tijuana']],
        'MY' => ['Malaysia', 'MYR', ['Asia/Kuala_Lumpur', 'Asia/Kuching']],
        'MZ' => ['Mozambique', 'MZN', ['Africa/Maputo']],
        'NA' => ['Namibia', 'NAD', ['Africa/Windhoek']],
        'NC' => ['New Caledonia', 'XPF', ['Pacific/Noumea']],
        'NE' => ['Niger', 'XOF', ['Africa/Niamey']],
        'NF' => ['Norfolk Island', 'AUD', ['Pacific/Norfolk']],
        'NG' => ['Nigeria', 'NGN', ['Africa/Lagos']],
        'NI' => ['Nicaragua', 'NIO', ['America/Managua']],
        'NL' => ['Netherlands', 'EUR', ['Europe/Amsterdam']],
        'NO' => ['Norway', 'NOK', ['Europe/Oslo']],
        'NP' => ['Nepal', 'NPR', ['Asia/Kathmandu']],
        'NR' => ['Nauru', 'AUD', ['Pacific/Nauru']],
        'NU' => ['Niue', 'NZD', ['Pacific/Niue']],
        'NZ' => ['New Zealand', 'NZD', ['Pacific/Auckland', 'Pacific/Chatham']],
        'OM' => ['Oman', 'OMR', ['Asia/Muscat']],
        'PA' => ['Panama', 'PAB', ['America/Panama']],
        'PE' => ['Peru', 'PEN', ['America/Lima']],
        'PF' => ['French Polynesia', 'XPF', ['Pacific/Tahiti', 'Pacific/Gambier', 'Pacific/Marquesas']],
        'PG' => ['Papua New Guinea', 'PGK', ['Pacific/Port_Moresby', 'Pacific/Bougainville']],
        'PH' => ['Philippines', 'PHP', ['Asia/Manila']],
        'PK' => ['Pakistan', 'PKR', ['Asia/Karachi']],
        'PL' => ['Poland', 'PLN', ['Europe/Warsaw']],
        'PM' => ['Saint Pierre and Miquelon', 'EUR', ['America/Miquelon']],
        'PN' => ['Pitcairn Islands', 'NZD', ['Pacific/Pitcairn']],
        'PR' => ['Puerto Rico', 'USD', ['America/Puerto_Rico']],
        'PS' => ['Palestine', 'ILS', ['Asia/Gaza', 'Asia/Hebron']],
        'PT' => ['Portugal', 'EUR', ['Europe/Lisbon', 'Atlantic/Azores', 'Atlantic/Madeira']],
        'PW' => ['Palau', 'USD', ['Pacific/Palau']],
        'PY' => ['Paraguay', 'PYG', ['America/Asuncion']],
        'QA' => ['Qatar', 'QAR', ['Asia/Qatar']],
        'RE' => ['Réunion', 'EUR', ['Indian/Reunion']],
        'RO' => ['Romania', 'RON', ['Europe/Bucharest']],
        'RS' => ['Serbia', 'RSD', ['Europe/Belgrade']],
        'RU' => ['Russia', 'RUB', ['Europe/Moscow', 'Asia/Anadyr', 'Asia/Barnaul', 'Asia/Chita', 'Asia/Irkutsk', 'Asia/Kamchatka', 'Asia/Khandyga', 'Asia/Krasnoyarsk', 'Asia/Magadan', 'Asia/Novokuznetsk', 'Asia/Novosibirsk', 'Asia/Omsk', 'Asia/Sakhalin', 'Asia/Srednekolymsk', 'Asia/Tomsk', 'Asia/Ust-Nera', 'Asia/Vladivostok', 'Asia/Yakutsk', 'Asia/Yekaterinburg', 'Europe/Astrakhan', 'Europe/Kaliningrad', 'Europe/Kirov', 'Europe/Samara', 'Europe/Saratov', 'Europe/Ulyanovsk', 'Europe/Volgograd']],
        'RW' => ['Rwanda', 'RWF', ['Africa/Kigali']],
        'SA' => ['Saudi Arabia', 'SAR', ['Asia/Riyadh']],
        'SB' => ['Solomon Islands', 'SBD', ['Pacific/Guadalcanal']],
        'SC' => ['Seychelles', 'SCR', ['Indian/Mahe']],
        'SD' => ['Sudan', 'SDG', ['Africa/Khartoum']],
        'SE' => ['Sweden', 'SEK', ['Europe/Stockholm']],
        'SG' => ['Singapore', 'SGD', ['Asia/Singapore']],
        'SH' => ['Saint Helena', 'SHP', ['Atlantic/St_Helena']],
        'SI' => ['Slovenia', 'EUR', ['Europe/Ljubljana']],
        'SJ' => ['Svalbard and Jan Mayen', 'NOK', ['Arctic/Longyearbyen']],
        'SK' => ['Slovakia', 'EUR', ['Europe/Bratislava']],
        'SL' => ['Sierra Leone', 'SLE', ['Africa/Freetown']],
        'SM' => ['San Marino', 'EUR', ['Europe/San_Marino']],
        'SN' => ['Senegal', 'XOF', ['Africa/Dakar']],
        'SO' => ['Somalia', 'SOS', ['Africa/Mogadishu']],
        'SR' => ['Suriname', 'SRD', ['America/Paramaribo']],
        'SS' => ['South Sudan', 'SSP', ['Africa/Juba']],
        'ST' => ['São Tomé and Príncipe', 'STN', ['Africa/Sao_Tome']],
        'SV' => ['El Salvador', 'USD', ['America/El_Salvador']],
        'SX' => ['Sint Maarten', 'XCG', ['America/Lower_Princes']],
        'SY' => ['Syria', 'SYP', ['Asia/Damascus']],
        'SZ' => ['Eswatini', 'SZL', ['Africa/Mbabane']],
        'TC' => ['Turks and Caicos Islands', 'USD', ['America/Grand_Turk']],
        'TD' => ['Chad', 'XAF', ['Africa/Ndjamena']],
        'TG' => ['Togo', 'XOF', ['Africa/Lome']],
        'TH' => ['Thailand', 'THB', ['Asia/Bangkok']],
        'TJ' => ['Tajikistan', 'TJS', ['Asia/Dushanbe']],
        'TK' => ['Tokelau', 'NZD', ['Pacific/Fakaofo']],
        'TL' => ['Timor-Leste', 'USD', ['Asia/Dili']],
        'TM' => ['Turkmenistan', 'TMT', ['Asia/Ashgabat']],
        'TN' => ['Tunisia', 'TND', ['Africa/Tunis']],
        'TO' => ['Tonga', 'TOP', ['Pacific/Tongatapu']],
        'TR' => ['Türkiye', 'TRY', ['Europe/Istanbul']],
        'TT' => ['Trinidad and Tobago', 'TTD', ['America/Port_of_Spain']],
        'TV' => ['Tuvalu', 'AUD', ['Pacific/Funafuti']],
        'TW' => ['Taiwan', 'TWD', ['Asia/Taipei']],
        'TZ' => ['Tanzania', 'TZS', ['Africa/Dar_es_Salaam']],
        'UA' => ['Ukraine', 'UAH', ['Europe/Kyiv', 'Europe/Simferopol']],
        'UG' => ['Uganda', 'UGX', ['Africa/Kampala']],
        'US' => ['United States', 'USD', ['America/New_York', 'America/Adak', 'America/Anchorage', 'America/Boise', 'America/Chicago', 'America/Denver', 'America/Detroit', 'America/Indiana/Indianapolis', 'America/Indiana/Knox', 'America/Indiana/Marengo', 'America/Indiana/Petersburg', 'America/Indiana/Tell_City', 'America/Indiana/Vevay', 'America/Indiana/Vincennes', 'America/Indiana/Winamac', 'America/Juneau', 'America/Kentucky/Louisville', 'America/Kentucky/Monticello', 'America/Los_Angeles', 'America/Menominee', 'America/Metlakatla', 'America/Nome', 'America/North_Dakota/Beulah', 'America/North_Dakota/Center', 'America/North_Dakota/New_Salem', 'America/Phoenix', 'America/Sitka', 'America/Yakutat', 'Pacific/Honolulu']],
        'UY' => ['Uruguay', 'UYU', ['America/Montevideo']],
        'UZ' => ['Uzbekistan', 'UZS', ['Asia/Tashkent', 'Asia/Samarkand']],
        'VA' => ['Vatican City', 'EUR', ['Europe/Vatican']],
        'VC' => ['Saint Vincent and the Grenadines', 'XCD', ['America/St_Vincent']],
        'VE' => ['Venezuela', 'VES', ['America/Caracas']],
        'VG' => ['British Virgin Islands', 'USD', ['America/Tortola']],
        'VI' => ['U.S. Virgin Islands', 'USD', ['America/St_Thomas']],
        'VN' => ['Vietnam', 'VND', ['Asia/Ho_Chi_Minh']],
        'VU' => ['Vanuatu', 'VUV', ['Pacific/Efate']],
        'WF' => ['Wallis and Futuna', 'XPF', ['Pacific/Wallis']],
        'WS' => ['Samoa', 'WST', ['Pacific/Apia']],
        'YE' => ['Yemen', 'YER', ['Asia/Aden']],
        'YT' => ['Mayotte', 'EUR', ['Indian/Mayotte']],
        'ZA' => ['South Africa', 'ZAR', ['Africa/Johannesburg']],
        'ZM' => ['Zambia', 'ZMW', ['Africa/Lusaka']],
        'ZW' => ['Zimbabwe', 'ZWG', ['Africa/Harare']],
    ];

    public static function has(string $code): bool
    {
        return isset(self::ALL[strtoupper($code)]);
    }

    public static function name(string $code): ?string
    {
        return self::ALL[strtoupper($code)][0] ?? null;
    }

    public static function currency(string $code): ?string
    {
        return self::ALL[strtoupper($code)][1] ?? null;
    }

    /** @return list<string> */
    public static function timezones(string $code): array
    {
        return self::ALL[strtoupper($code)][2] ?? [];
    }

    public static function timezone(string $code): ?string
    {
        return self::ALL[strtoupper($code)][2][0] ?? null;
    }

    /**
     * Every currency any country trades in, deduplicated.
     *
     * The picker offers these rather than the hundred and fifty ISO 4217 has
     * defined, because the rest are metals, funds and codes no shop has ever
     * banked in.
     *
     * @return list<string>
     */
    public static function currencies(): array
    {
        $codes = array_unique(array_column(self::ALL, 1));
        sort($codes);

        return array_values($codes);
    }

    /**
     * The list the client draws, sorted by name rather than by code.
     *
     * Sorted here so every caller gets the same order, and so the sort is done
     * once against a constant rather than in each of them.
     *
     * @return list<array{code: string, name: string, currency: string, timezones: list<string>}>
     */
    public static function toPayload(): array
    {
        $rows = [];

        foreach (self::ALL as $code => [$name, $currency, $timezones]) {
            $rows[] = [
                'code' => $code,
                'name' => $name,
                'currency' => $currency,
                'timezones' => $timezones,
            ];
        }

        usort($rows, static fn (array $a, array $b) => strcmp(
            self::sortKey($a['name']),
            self::sortKey($b['name']),
        ));

        return $rows;
    }

    /**
     * Fold accents so the list reads alphabetically to a person.
     *
     * strcmp works on bytes, which files Åland after Zimbabwe and Türkiye after
     * Tuvalu. There is no intl extension here to do it properly, but the set of
     * accented characters in a country list is small and closed, so folding
     * them by hand is exact rather than approximate.
     */
    private static function sortKey(string $name): string
    {
        return strtolower(strtr($name, [
            'Å' => 'A', 'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Â' => 'A',
            'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Î' => 'I',
            'Ñ' => 'N', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
            'Ú' => 'U', 'Ü' => 'U', 'Û' => 'U',
            'å' => 'a', 'á' => 'a', 'à' => 'a', 'ã' => 'a', 'ä' => 'a', 'â' => 'a',
            'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'í' => 'i', 'î' => 'i',
            'ñ' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'û' => 'u',
        ]));
    }
}
