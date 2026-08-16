<?php

declare(strict_types=1);

namespace App\Domain\Money;

/**
 * Every circulating currency, and how each writes itself.
 *
 * The whole ISO list rather than a shortlist: a shop can open anywhere, and a
 * currency missing from the picker is a currency whose takings cannot be
 * recorded at all. The order is the only concession — nobody in Dhaka should
 * scroll past the Afghan afghani to reach the taka.
 *
 * Kept apart from the service that uses it because it is a table, not
 * behaviour: two hundred constant lines have no business sitting in the middle
 * of the logic that converts money.
 */
final class Currencies
{
    /** Currencies with no minor unit. Showing "¥1,200.00" marks you out. */
    public const ZERO_DECIMAL = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'XAF', 'XOF', 'XPF', 'KMF', 'RWF', 'UGX', 'VUV', 'GNF', 'PYG', 'BIF', 'DJF'];

    /** Currencies with three rather than two. */
    public const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /** code => [name, symbol] — the ones this market meets first, then the rest. */
    public const ALL = [
        'BDT' => ['Bangladeshi Taka', '৳'],
        'USD' => ['US Dollar', '$'],
        'EUR' => ['Euro', '€'],
        'GBP' => ['Pound Sterling', '£'],
        'INR' => ['Indian Rupee', '₹'],
        'AED' => ['UAE Dirham', 'د.إ'],
        'SAR' => ['Saudi Riyal', '﷼'],
        'MYR' => ['Malaysian Ringgit', 'RM'],
        'SGD' => ['Singapore Dollar', 'S$'],
        'AUD' => ['Australian Dollar', 'A$'],
        'CAD' => ['Canadian Dollar', 'C$'],
        'JPY' => ['Japanese Yen', '¥'],
        'CNY' => ['Chinese Yuan', '¥'],
        'PKR' => ['Pakistani Rupee', '₨'],
        'LKR' => ['Sri Lankan Rupee', 'Rs'],
        'NPR' => ['Nepalese Rupee', 'Rs'],
        'THB' => ['Thai Baht', '฿'],
        'IDR' => ['Indonesian Rupiah', 'Rp'],
        'PHP' => ['Philippine Peso', '₱'],
        'VND' => ['Vietnamese Dong', '₫'],
        'KRW' => ['South Korean Won', '₩'],
        'HKD' => ['Hong Kong Dollar', 'HK$'],
        'NZD' => ['New Zealand Dollar', 'NZ$'],
        'CHF' => ['Swiss Franc', 'CHF'],
        'SEK' => ['Swedish Krona', 'kr'],
        'NOK' => ['Norwegian Krone', 'kr'],
        'DKK' => ['Danish Krone', 'kr'],
        'ZAR' => ['South African Rand', 'R'],
        'NGN' => ['Nigerian Naira', '₦'],
        'KES' => ['Kenyan Shilling', 'KSh'],
        'EGP' => ['Egyptian Pound', 'E£'],
        'TRY' => ['Turkish Lira', '₺'],
        'RUB' => ['Russian Ruble', '₽'],
        'BRL' => ['Brazilian Real', 'R$'],
        'MXN' => ['Mexican Peso', '$'],
        'ARS' => ['Argentine Peso', '$'],
        'QAR' => ['Qatari Riyal', 'ر.ق'],
        'KWD' => ['Kuwaiti Dinar', 'KD'],
        'BHD' => ['Bahraini Dinar', '.د.ب'],
        'OMR' => ['Omani Rial', 'ر.ع.'],
        'JOD' => ['Jordanian Dinar', 'JD'],
        'ILS' => ['Israeli New Shekel', '₪'],
        'PLN' => ['Polish Zloty', 'zł'],
        'CZK' => ['Czech Koruna', 'Kč'],
        'HUF' => ['Hungarian Forint', 'Ft'],
        'RON' => ['Romanian Leu', 'lei'],
        'UAH' => ['Ukrainian Hryvnia', '₴'],
        'MMK' => ['Myanmar Kyat', 'K'],
        'MVR' => ['Maldivian Rufiyaa', 'Rf'],
        'BTN' => ['Bhutanese Ngultrum', 'Nu.'],
        'AFN' => ['Afghan Afghani', '؋'],
        'IQD' => ['Iraqi Dinar', 'ع.د'],
        'IRR' => ['Iranian Rial', '﷼'],
        'LBP' => ['Lebanese Pound', 'ل.ل'],
        'MAD' => ['Moroccan Dirham', 'د.م.'],
        'TND' => ['Tunisian Dinar', 'د.ت'],
        'DZD' => ['Algerian Dinar', 'د.ج'],
        'LYD' => ['Libyan Dinar', 'ل.د'],
        'GHS' => ['Ghanaian Cedi', '₵'],
        'TZS' => ['Tanzanian Shilling', 'TSh'],
        'UGX' => ['Ugandan Shilling', 'USh'],
        'ETB' => ['Ethiopian Birr', 'Br'],
        'XOF' => ['West African Franc', 'CFA'],
        'XAF' => ['Central African Franc', 'FCFA'],
        'CLP' => ['Chilean Peso', '$'],
        'COP' => ['Colombian Peso', '$'],
        'PEN' => ['Peruvian Sol', 'S/'],
        'UYU' => ['Uruguayan Peso', '$U'],
        'ISK' => ['Icelandic Krona', 'kr'],
        'BGN' => ['Bulgarian Lev', 'лв'],
        'HRK' => ['Croatian Kuna', 'kn'],
        'RSD' => ['Serbian Dinar', 'дин.'],
        'KZT' => ['Kazakhstani Tenge', '₸'],
        'UZS' => ['Uzbekistani Som', "so'm"],
        'AZN' => ['Azerbaijani Manat', '₼'],
        'GEL' => ['Georgian Lari', '₾'],
        'AMD' => ['Armenian Dram', '֏'],
        'MNT' => ['Mongolian Tugrik', '₮'],
        'KHR' => ['Cambodian Riel', '៛'],
        'LAK' => ['Laotian Kip', '₭'],
        'BND' => ['Brunei Dollar', 'B$'],
        'TWD' => ['New Taiwan Dollar', 'NT$'],
        'MOP' => ['Macanese Pataca', 'MOP$'],
        'FJD' => ['Fijian Dollar', 'FJ$'],
        'PGK' => ['Papua New Guinean Kina', 'K'],
        'MUR' => ['Mauritian Rupee', '₨'],
        'SCR' => ['Seychellois Rupee', '₨'],
        'ZMW' => ['Zambian Kwacha', 'ZK'],
        'BWP' => ['Botswana Pula', 'P'],
        'NAD' => ['Namibian Dollar', 'N$'],
        'MWK' => ['Malawian Kwacha', 'MK'],
        'MZN' => ['Mozambican Metical', 'MT'],
        'AOA' => ['Angolan Kwanza', 'Kz'],
        'JMD' => ['Jamaican Dollar', 'J$'],
        'TTD' => ['Trinidad & Tobago Dollar', 'TT$'],
        'BBD' => ['Barbadian Dollar', '$'],
        'BSD' => ['Bahamian Dollar', '$'],
        'DOP' => ['Dominican Peso', 'RD$'],
        'GTQ' => ['Guatemalan Quetzal', 'Q'],
        'CRC' => ['Costa Rican Colon', '₡'],
        'PAB' => ['Panamanian Balboa', 'B/.'],
        'BOB' => ['Bolivian Boliviano', 'Bs.'],
        'PYG' => ['Paraguayan Guarani', '₲'],
        'HNL' => ['Honduran Lempira', 'L'],
        'NIO' => ['Nicaraguan Cordoba', 'C$'],
        'RWF' => ['Rwandan Franc', 'FRw'],
        'BIF' => ['Burundian Franc', 'FBu'],
        'DJF' => ['Djiboutian Franc', 'Fdj'],
        'SOS' => ['Somali Shilling', 'Sh'],
        'SDG' => ['Sudanese Pound', 'ج.س.'],
        'YER' => ['Yemeni Rial', '﷼'],
        'SYP' => ['Syrian Pound', '£S'],
        'KGS' => ['Kyrgystani Som', 'с'],
        'TJS' => ['Tajikistani Somoni', 'ЅМ'],
        'TMT' => ['Turkmenistani Manat', 'm'],
        'ALL' => ['Albanian Lek', 'L'],
        'MKD' => ['Macedonian Denar', 'ден'],
        'BAM' => ['Bosnia-Herzegovina Mark', 'KM'],
        'MDL' => ['Moldovan Leu', 'L'],
        'BYN' => ['Belarusian Ruble', 'Br'],
        'XPF' => ['CFP Franc', '₣'],
        'XCD' => ['East Caribbean Dollar', 'EC$'],
        'KMF' => ['Comorian Franc', 'CF'],
        'GNF' => ['Guinean Franc', 'FG'],
        'VUV' => ['Vanuatu Vatu', 'VT'],
        'WST' => ['Samoan Tala', 'T'],
        'TOP' => ['Tongan Paanga', 'T$'],
        'SBD' => ['Solomon Islands Dollar', 'SI$'],
        'GYD' => ['Guyanaese Dollar', 'G$'],
        'SRD' => ['Surinamese Dollar', '$'],
        'BZD' => ['Belize Dollar', 'BZ$'],
        'CUP' => ['Cuban Peso', '$'],
        'HTG' => ['Haitian Gourde', 'G'],
        'CVE' => ['Cape Verdean Escudo', '$'],
        'GMD' => ['Gambian Dalasi', 'D'],
        'SLE' => ['Sierra Leonean Leone', 'Le'],
        'LRD' => ['Liberian Dollar', 'L$'],
        'ERN' => ['Eritrean Nakfa', 'Nfk'],
        'SSP' => ['South Sudanese Pound', '£'],
        'LSL' => ['Lesotho Loti', 'L'],
        'SZL' => ['Swazi Lilangeni', 'L'],
        'MGA' => ['Malagasy Ariary', 'Ar'],
        'MRU' => ['Mauritanian Ouguiya', 'UM'],
        'STN' => ['Sao Tomean Dobra', 'Db'],
        'CDF' => ['Congolese Franc', 'FC'],
        'ZWL' => ['Zimbabwean Dollar', 'Z$'],
    ];

    public static function exists(string $code): bool
    {
        return isset(self::ALL[strtoupper($code)]);
    }

    public static function name(string $code): string
    {
        return self::ALL[strtoupper($code)][0] ?? strtoupper($code);
    }

    public static function symbol(string $code): string
    {
        return self::ALL[strtoupper($code)][1] ?? strtoupper($code).' ';
    }

    /** How many minor units make one of this currency. */
    public static function scale(string $code): int
    {
        $code = strtoupper($code);

        return match (true) {
            in_array($code, self::ZERO_DECIMAL, true) => 0,
            in_array($code, self::THREE_DECIMAL, true) => 3,
            default => 2,
        };
    }

    /**
     * The picker's options, in the order the constant declares them.
     *
     * @return list<array{code: string, name: string, symbol: string}>
     */
    public static function options(): array
    {
        $out = [];

        foreach (self::ALL as $code => [$name, $symbol]) {
            $out[] = ['code' => $code, 'name' => $name, 'symbol' => $symbol];
        }

        return $out;
    }
}
