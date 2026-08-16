<?php

/**
 * Emits app/Domain/Localization/Countries.php.
 *
 * Names and currencies are declared here (ISO 3166-1 alpha-2 / ISO 4217).
 * Timezones are read from PHP's own tz database rather than typed out, because
 * that file is maintained and a hand-copied one is wrong within a year.
 */

$map = [
    'AD' => ['Andorra', 'EUR'],
    'AE' => ['United Arab Emirates', 'AED'],
    'AF' => ['Afghanistan', 'AFN'],
    'AG' => ['Antigua and Barbuda', 'XCD'],
    'AI' => ['Anguilla', 'XCD'],
    'AL' => ['Albania', 'ALL'],
    'AM' => ['Armenia', 'AMD'],
    'AO' => ['Angola', 'AOA'],
    'AR' => ['Argentina', 'ARS'],
    'AS' => ['American Samoa', 'USD'],
    'AT' => ['Austria', 'EUR'],
    'AU' => ['Australia', 'AUD'],
    'AW' => ['Aruba', 'AWG'],
    'AX' => ['Åland Islands', 'EUR'],
    'AZ' => ['Azerbaijan', 'AZN'],
    'BA' => ['Bosnia and Herzegovina', 'BAM'],
    'BB' => ['Barbados', 'BBD'],
    'BD' => ['Bangladesh', 'BDT'],
    'BE' => ['Belgium', 'EUR'],
    'BF' => ['Burkina Faso', 'XOF'],
    'BG' => ['Bulgaria', 'BGN'],
    'BH' => ['Bahrain', 'BHD'],
    'BI' => ['Burundi', 'BIF'],
    'BJ' => ['Benin', 'XOF'],
    'BL' => ['Saint Barthélemy', 'EUR'],
    'BM' => ['Bermuda', 'BMD'],
    'BN' => ['Brunei', 'BND'],
    'BO' => ['Bolivia', 'BOB'],
    'BQ' => ['Caribbean Netherlands', 'USD'],
    'BR' => ['Brazil', 'BRL'],
    'BS' => ['Bahamas', 'BSD'],
    'BT' => ['Bhutan', 'BTN'],
    'BW' => ['Botswana', 'BWP'],
    'BY' => ['Belarus', 'BYN'],
    'BZ' => ['Belize', 'BZD'],
    'CA' => ['Canada', 'CAD'],
    'CC' => ['Cocos (Keeling) Islands', 'AUD'],
    'CD' => ['Congo (DRC)', 'CDF'],
    'CF' => ['Central African Republic', 'XAF'],
    'CG' => ['Congo (Republic)', 'XAF'],
    'CH' => ['Switzerland', 'CHF'],
    'CI' => ["Côte d'Ivoire", 'XOF'],
    'CK' => ['Cook Islands', 'NZD'],
    'CL' => ['Chile', 'CLP'],
    'CM' => ['Cameroon', 'XAF'],
    'CN' => ['China', 'CNY'],
    'CO' => ['Colombia', 'COP'],
    'CR' => ['Costa Rica', 'CRC'],
    'CU' => ['Cuba', 'CUP'],
    'CV' => ['Cape Verde', 'CVE'],
    'CW' => ['Curaçao', 'XCG'],
    'CX' => ['Christmas Island', 'AUD'],
    'CY' => ['Cyprus', 'EUR'],
    'CZ' => ['Czechia', 'CZK'],
    'DE' => ['Germany', 'EUR'],
    'DJ' => ['Djibouti', 'DJF'],
    'DK' => ['Denmark', 'DKK'],
    'DM' => ['Dominica', 'XCD'],
    'DO' => ['Dominican Republic', 'DOP'],
    'DZ' => ['Algeria', 'DZD'],
    'EC' => ['Ecuador', 'USD'],
    'EE' => ['Estonia', 'EUR'],
    'EG' => ['Egypt', 'EGP'],
    'EH' => ['Western Sahara', 'MAD'],
    'ER' => ['Eritrea', 'ERN'],
    'ES' => ['Spain', 'EUR'],
    'ET' => ['Ethiopia', 'ETB'],
    'FI' => ['Finland', 'EUR'],
    'FJ' => ['Fiji', 'FJD'],
    'FK' => ['Falkland Islands', 'FKP'],
    'FM' => ['Micronesia', 'USD'],
    'FO' => ['Faroe Islands', 'DKK'],
    'FR' => ['France', 'EUR'],
    'GA' => ['Gabon', 'XAF'],
    'GB' => ['United Kingdom', 'GBP'],
    'GD' => ['Grenada', 'XCD'],
    'GE' => ['Georgia', 'GEL'],
    'GF' => ['French Guiana', 'EUR'],
    'GG' => ['Guernsey', 'GBP'],
    'GH' => ['Ghana', 'GHS'],
    'GI' => ['Gibraltar', 'GIP'],
    'GL' => ['Greenland', 'DKK'],
    'GM' => ['Gambia', 'GMD'],
    'GN' => ['Guinea', 'GNF'],
    'GP' => ['Guadeloupe', 'EUR'],
    'GQ' => ['Equatorial Guinea', 'XAF'],
    'GR' => ['Greece', 'EUR'],
    'GT' => ['Guatemala', 'GTQ'],
    'GU' => ['Guam', 'USD'],
    'GW' => ['Guinea-Bissau', 'XOF'],
    'GY' => ['Guyana', 'GYD'],
    'HK' => ['Hong Kong', 'HKD'],
    'HN' => ['Honduras', 'HNL'],
    'HR' => ['Croatia', 'EUR'],
    'HT' => ['Haiti', 'HTG'],
    'HU' => ['Hungary', 'HUF'],
    'ID' => ['Indonesia', 'IDR'],
    'IE' => ['Ireland', 'EUR'],
    'IL' => ['Israel', 'ILS'],
    'IM' => ['Isle of Man', 'GBP'],
    'IN' => ['India', 'INR'],
    'IO' => ['British Indian Ocean Territory', 'USD'],
    'IQ' => ['Iraq', 'IQD'],
    'IR' => ['Iran', 'IRR'],
    'IS' => ['Iceland', 'ISK'],
    'IT' => ['Italy', 'EUR'],
    'JE' => ['Jersey', 'GBP'],
    'JM' => ['Jamaica', 'JMD'],
    'JO' => ['Jordan', 'JOD'],
    'JP' => ['Japan', 'JPY'],
    'KE' => ['Kenya', 'KES'],
    'KG' => ['Kyrgyzstan', 'KGS'],
    'KH' => ['Cambodia', 'KHR'],
    'KI' => ['Kiribati', 'AUD'],
    'KM' => ['Comoros', 'KMF'],
    'KN' => ['Saint Kitts and Nevis', 'XCD'],
    'KP' => ['North Korea', 'KPW'],
    'KR' => ['South Korea', 'KRW'],
    'KW' => ['Kuwait', 'KWD'],
    'KY' => ['Cayman Islands', 'KYD'],
    'KZ' => ['Kazakhstan', 'KZT'],
    'LA' => ['Laos', 'LAK'],
    'LB' => ['Lebanon', 'LBP'],
    'LC' => ['Saint Lucia', 'XCD'],
    'LI' => ['Liechtenstein', 'CHF'],
    'LK' => ['Sri Lanka', 'LKR'],
    'LR' => ['Liberia', 'LRD'],
    'LS' => ['Lesotho', 'LSL'],
    'LT' => ['Lithuania', 'EUR'],
    'LU' => ['Luxembourg', 'EUR'],
    'LV' => ['Latvia', 'EUR'],
    'LY' => ['Libya', 'LYD'],
    'MA' => ['Morocco', 'MAD'],
    'MC' => ['Monaco', 'EUR'],
    'MD' => ['Moldova', 'MDL'],
    'ME' => ['Montenegro', 'EUR'],
    'MF' => ['Saint Martin', 'EUR'],
    'MG' => ['Madagascar', 'MGA'],
    'MH' => ['Marshall Islands', 'USD'],
    'MK' => ['North Macedonia', 'MKD'],
    'ML' => ['Mali', 'XOF'],
    'MM' => ['Myanmar', 'MMK'],
    'MN' => ['Mongolia', 'MNT'],
    'MO' => ['Macao', 'MOP'],
    'MP' => ['Northern Mariana Islands', 'USD'],
    'MQ' => ['Martinique', 'EUR'],
    'MR' => ['Mauritania', 'MRU'],
    'MS' => ['Montserrat', 'XCD'],
    'MT' => ['Malta', 'EUR'],
    'MU' => ['Mauritius', 'MUR'],
    'MV' => ['Maldives', 'MVR'],
    'MW' => ['Malawi', 'MWK'],
    'MX' => ['Mexico', 'MXN'],
    'MY' => ['Malaysia', 'MYR'],
    'MZ' => ['Mozambique', 'MZN'],
    'NA' => ['Namibia', 'NAD'],
    'NC' => ['New Caledonia', 'XPF'],
    'NE' => ['Niger', 'XOF'],
    'NF' => ['Norfolk Island', 'AUD'],
    'NG' => ['Nigeria', 'NGN'],
    'NI' => ['Nicaragua', 'NIO'],
    'NL' => ['Netherlands', 'EUR'],
    'NO' => ['Norway', 'NOK'],
    'NP' => ['Nepal', 'NPR'],
    'NR' => ['Nauru', 'AUD'],
    'NU' => ['Niue', 'NZD'],
    'NZ' => ['New Zealand', 'NZD'],
    'OM' => ['Oman', 'OMR'],
    'PA' => ['Panama', 'PAB'],
    'PE' => ['Peru', 'PEN'],
    'PF' => ['French Polynesia', 'XPF'],
    'PG' => ['Papua New Guinea', 'PGK'],
    'PH' => ['Philippines', 'PHP'],
    'PK' => ['Pakistan', 'PKR'],
    'PL' => ['Poland', 'PLN'],
    'PM' => ['Saint Pierre and Miquelon', 'EUR'],
    'PN' => ['Pitcairn Islands', 'NZD'],
    'PR' => ['Puerto Rico', 'USD'],
    'PS' => ['Palestine', 'ILS'],
    'PT' => ['Portugal', 'EUR'],
    'PW' => ['Palau', 'USD'],
    'PY' => ['Paraguay', 'PYG'],
    'QA' => ['Qatar', 'QAR'],
    'RE' => ['Réunion', 'EUR'],
    'RO' => ['Romania', 'RON'],
    'RS' => ['Serbia', 'RSD'],
    'RU' => ['Russia', 'RUB'],
    'RW' => ['Rwanda', 'RWF'],
    'SA' => ['Saudi Arabia', 'SAR'],
    'SB' => ['Solomon Islands', 'SBD'],
    'SC' => ['Seychelles', 'SCR'],
    'SD' => ['Sudan', 'SDG'],
    'SE' => ['Sweden', 'SEK'],
    'SG' => ['Singapore', 'SGD'],
    'SH' => ['Saint Helena', 'SHP'],
    'SI' => ['Slovenia', 'EUR'],
    'SJ' => ['Svalbard and Jan Mayen', 'NOK'],
    'SK' => ['Slovakia', 'EUR'],
    'SL' => ['Sierra Leone', 'SLE'],
    'SM' => ['San Marino', 'EUR'],
    'SN' => ['Senegal', 'XOF'],
    'SO' => ['Somalia', 'SOS'],
    'SR' => ['Suriname', 'SRD'],
    'SS' => ['South Sudan', 'SSP'],
    'ST' => ['São Tomé and Príncipe', 'STN'],
    'SV' => ['El Salvador', 'USD'],
    'SX' => ['Sint Maarten', 'XCG'],
    'SY' => ['Syria', 'SYP'],
    'SZ' => ['Eswatini', 'SZL'],
    'TC' => ['Turks and Caicos Islands', 'USD'],
    'TD' => ['Chad', 'XAF'],
    'TG' => ['Togo', 'XOF'],
    'TH' => ['Thailand', 'THB'],
    'TJ' => ['Tajikistan', 'TJS'],
    'TK' => ['Tokelau', 'NZD'],
    'TL' => ['Timor-Leste', 'USD'],
    'TM' => ['Turkmenistan', 'TMT'],
    'TN' => ['Tunisia', 'TND'],
    'TO' => ['Tonga', 'TOP'],
    'TR' => ['Türkiye', 'TRY'],
    'TT' => ['Trinidad and Tobago', 'TTD'],
    'TV' => ['Tuvalu', 'AUD'],
    'TW' => ['Taiwan', 'TWD'],
    'TZ' => ['Tanzania', 'TZS'],
    'UA' => ['Ukraine', 'UAH'],
    'UG' => ['Uganda', 'UGX'],
    'US' => ['United States', 'USD'],
    'UY' => ['Uruguay', 'UYU'],
    'UZ' => ['Uzbekistan', 'UZS'],
    'VA' => ['Vatican City', 'EUR'],
    'VC' => ['Saint Vincent and the Grenadines', 'XCD'],
    'VE' => ['Venezuela', 'VES'],
    'VG' => ['British Virgin Islands', 'USD'],
    'VI' => ['U.S. Virgin Islands', 'USD'],
    'VN' => ['Vietnam', 'VND'],
    'VU' => ['Vanuatu', 'VUV'],
    'WF' => ['Wallis and Futuna', 'XPF'],
    'WS' => ['Samoa', 'WST'],
    'YE' => ['Yemen', 'YER'],
    'YT' => ['Mayotte', 'EUR'],
    'ZA' => ['South Africa', 'ZAR'],
    'ZM' => ['Zambia', 'ZMW'],
    'ZW' => ['Zimbabwe', 'ZWG'],
];

/**
 * Where the tz database's alphabetical order puts the wrong zone first.
 *
 * PHP returns them sorted by identifier, which for a country with one zone is
 * fine and for a country with twenty-nine is nonsense — the United States comes
 * back as America/Adak, an Aleutian island of about three hundred people, and
 * Australia as Antarctica/Macquarie. These name the zone most of the country
 * actually keeps time in.
 */
$primary = [
    'AU' => 'Australia/Sydney',
    'BR' => 'America/Sao_Paulo',
    'CA' => 'America/Toronto',
    'CL' => 'America/Santiago',
    'CY' => 'Asia/Nicosia',
    'ES' => 'Europe/Madrid',
    'FM' => 'Pacific/Pohnpei',
    'GL' => 'America/Nuuk',
    'KI' => 'Pacific/Tarawa',
    'MH' => 'Pacific/Majuro',
    'MN' => 'Asia/Ulaanbaatar',
    'MX' => 'America/Mexico_City',
    'PF' => 'Pacific/Tahiti',
    'PG' => 'Pacific/Port_Moresby',
    'PT' => 'Europe/Lisbon',
    'RU' => 'Europe/Moscow',
    'US' => 'America/New_York',
    'UZ' => 'Asia/Tashkent',
];

$rows = [];
$missing = [];
$multi = [];

foreach ($map as $code => [$name, $currency]) {
    $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $code);

    if ($zones === false || $zones === []) {
        $missing[] = $code;
        continue;
    }

    if (isset($primary[$code])) {
        $lead = $primary[$code];

        if (! in_array($lead, $zones, true)) {
            fwrite(STDERR, "WARNING: {$code} primary {$lead} not in tz list" . PHP_EOL);
        }

        $zones = [$lead, ...array_values(array_filter($zones, static fn ($z) => $z !== $lead))];
    }

    if (count($zones) > 1) {
        $multi[$code] = $zones[0];
    }

    $rows[$code] = [$name, $currency, $zones];
}

ksort($rows);

$out = [];
$out[] = '<?php';
$out[] = '';
$out[] = 'declare(strict_types=1);';
$out[] = '';
$out[] = 'namespace App\Domain\Localization;';
$out[] = '';
$out[] = '/**';
$out[] = ' * Every country the tool will sell into, with what it trades in and where its';
$out[] = ' * clocks are.';
$out[] = ' *';
$out[] = ' * ── Why a class constant rather than a table ─────────────────────────────────';
$out[] = ' *';
$out[] = ' * This is reference data, not tenant data. Nobody edits it, every account sees';
$out[] = ' * the same list, and a table would mean a migration and a seeder for something';
$out[] = ' * that changes when a country changes its currency — roughly once a year,';
$out[] = ' * across the whole world. Deploy is the right update mechanism.';
$out[] = ' *';
$out[] = ' * The full localization pack — tax regimes, address formats, name order,';
$out[] = ' * fiscal calendars, holiday tables — is a separate and much larger job, and';
$out[] = ' * will want tables. This is the small part of it needed to open a set of books';
$out[] = ' * in the right currency, and is deliberately not trying to be that.';
$out[] = ' *';
$out[] = ' * ── Generated ────────────────────────────────────────────────────────────────';
$out[] = ' *';
$out[] = ' * Names and currency codes are ISO 3166-1 alpha-2 and ISO 4217. The timezone';
$out[] = ' * lists come from PHP\'s own tz database rather than being typed out here — a';
$out[] = ' * hand-copied zone table is wrong within a year, and the correct one already';
$out[] = ' * ships with the runtime.';
$out[] = ' */';
$out[] = 'final class Countries';
$out[] = '{';
$out[] = '    /**';
$out[] = '     * code => [name, currency, [timezone, ...]]';
$out[] = '     *';
$out[] = '     * The first timezone is the one a new set of books is opened in. For the';
$out[] = '     * many countries with exactly one it is the only answer; for the handful';
$out[] = '     * with dozens it is a starting point the subscriber can correct, which is';
$out[] = '     * a far better first run than an empty field.';
$out[] = '     *';
$out[] = '     * @var array<string, array{0: string, 1: string, 2: list<string>}>';
$out[] = '     */';
$out[] = '    public const ALL = [';

foreach ($rows as $code => [$name, $currency, $zones]) {
    $zoneList = implode(', ', array_map(static fn (string $z) => "'{$z}'", $zones));
    $safeName = str_replace("'", "\\'", $name);
    $out[] = "        '{$code}' => ['{$safeName}', '{$currency}', [{$zoneList}]],";
}

$out[] = '    ];';
$out[] = '}';

$target = __DIR__ . '/Countries.generated.php';
file_put_contents($target, implode("\n", $out) . "\n");

fwrite(STDERR, 'countries: ' . count($rows) . PHP_EOL);
fwrite(STDERR, 'no tz data (dropped): ' . (($missing === []) ? 'none' : implode(', ', $missing)) . PHP_EOL);
fwrite(STDERR, PHP_EOL . 'multi-zone countries and the default each gets:' . PHP_EOL);

foreach ($multi as $code => $lead) {
    fwrite(STDERR, '  ' . str_pad($code, 4) . $lead . (isset($primary[$code]) ? '  (overridden)' : '') . PHP_EOL);
}
