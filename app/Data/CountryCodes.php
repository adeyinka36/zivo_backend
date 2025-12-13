<?php

namespace App\Data;

class CountryCodes
{
    /**
     * Map of country names to ISO 3166-1 alpha-2 country codes.
     *
     * @return array<string, string>
     */
    public static function getCountryCodeMap(): array
    {
        return [
            'United States' => 'US',
            'Canada' => 'CA',
            'Mexico' => 'MX',
            'Guatemala' => 'GT',
            'Honduras' => 'HN',
            'El Salvador' => 'SV',
            'Nicaragua' => 'NI',
            'Costa Rica' => 'CR',
            'Panama' => 'PA',
            'Cuba' => 'CU',
            'Dominican Republic' => 'DO',
            'Haiti' => 'HT',
            'Jamaica' => 'JM',
            'Trinidad and Tobago' => 'TT',
            'Brazil' => 'BR',
            'Argentina' => 'AR',
            'Colombia' => 'CO',
            'Chile' => 'CL',
            'Peru' => 'PE',
            'Venezuela' => 'VE',
            'Uruguay' => 'UY',
            'Paraguay' => 'PY',
            'Ecuador' => 'EC',
            'Bolivia' => 'BO',
            'Guyana' => 'GY',
            'Suriname' => 'SR',
            'French Guiana' => 'GF',
            'United Kingdom' => 'GB',
            'France' => 'FR',
            'Germany' => 'DE',
            'Italy' => 'IT',
            'Spain' => 'ES',
            'Netherlands' => 'NL',
            'Belgium' => 'BE',
            'Switzerland' => 'CH',
            'Austria' => 'AT',
            'Sweden' => 'SE',
            'Norway' => 'NO',
            'Denmark' => 'DK',
            'Poland' => 'PL',
            'Portugal' => 'PT',
            'Greece' => 'GR',
            'Ireland' => 'IE',
            'Finland' => 'FI',
            'Russia' => 'RU',
            'Ukraine' => 'UA',
            'Czech Republic' => 'CZ',
            'Slovakia' => 'SK',
            'Hungary' => 'HU',
            'Romania' => 'RO',
            'Bulgaria' => 'BG',
            'Serbia' => 'RS',
            'Croatia' => 'HR',
            'Slovenia' => 'SI',
            'Bosnia and Herzegovina' => 'BA',
            'Montenegro' => 'ME',
            'Albania' => 'AL',
            'North Macedonia' => 'MK',
            'Iceland' => 'IS',
            'Luxembourg' => 'LU',
            'Lithuania' => 'LT',
            'Latvia' => 'LV',
            'Estonia' => 'EE',
            'Belarus' => 'BY',
            'Moldova' => 'MD',
            'Malta' => 'MT',
            'China' => 'CN',
            'India' => 'IN',
            'Japan' => 'JP',
            'South Korea' => 'KR',
            'Indonesia' => 'ID',
            'Thailand' => 'TH',
            'Philippines' => 'PH',
            'Malaysia' => 'MY',
            'Singapore' => 'SG',
            'Vietnam' => 'VN',
            'United Arab Emirates' => 'AE',
            'Saudi Arabia' => 'SA',
            'Israel' => 'IL',
            'Turkey' => 'TR',
            'Pakistan' => 'PK',
            'Bangladesh' => 'BD',
            'Sri Lanka' => 'LK',
            'Nepal' => 'NP',
            'Bhutan' => 'BT',
            'Myanmar' => 'MM',
            'Kazakhstan' => 'KZ',
            'Uzbekistan' => 'UZ',
            'Turkmenistan' => 'TM',
            'Kyrgyzstan' => 'KG',
            'Tajikistan' => 'TJ',
            'Afghanistan' => 'AF',
            'Iran' => 'IR',
            'Iraq' => 'IQ',
            'Qatar' => 'QA',
            'Kuwait' => 'KW',
            'Bahrain' => 'BH',
            'Oman' => 'OM',
            'Jordan' => 'JO',
            'Lebanon' => 'LB',
            'Georgia' => 'GE',
            'Armenia' => 'AM',
            'Azerbaijan' => 'AZ',
            'Mongolia' => 'MN',
            'Cambodia' => 'KH',
            'Laos' => 'LA',
            'Brunei' => 'BN',
            'Maldives' => 'MV',
            'South Africa' => 'ZA',
            'Egypt' => 'EG',
            'Nigeria' => 'NG',
            'Kenya' => 'KE',
            'Morocco' => 'MA',
            'Ethiopia' => 'ET',
            'Ghana' => 'GH',
            'Tanzania' => 'TZ',
            'Uganda' => 'UG',
            'Angola' => 'AO',
            'Algeria' => 'DZ',
            'Tunisia' => 'TN',
            'Libya' => 'LY',
            'Sudan' => 'SD',
            'South Sudan' => 'SS',
            'Zimbabwe' => 'ZW',
            'Zambia' => 'ZM',
            'Botswana' => 'BW',
            'Namibia' => 'NA',
            'Mozambique' => 'MZ',
            'Malawi' => 'MW',
            'Rwanda' => 'RW',
            'Burundi' => 'BI',
            'Somalia' => 'SO',
            'Madagascar' => 'MG',
            'DR Congo' => 'CD',
            'Republic of Congo' => 'CG',
            'Senegal' => 'SN',
            'Mali' => 'ML',
            'Guinea' => 'GN',
            'Sierra Leone' => 'SL',
            'Liberia' => 'LR',
            'Benin' => 'BJ',
            'Togo' => 'TG',
            'Niger' => 'NE',
            'Chad' => 'TD',
            'Central African Republic' => 'CF',
            'Eritrea' => 'ER',
            'Gabon' => 'GA',
            'Equatorial Guinea' => 'GQ',
            'Cape Verde' => 'CV',
            'Seychelles' => 'SC',
            'Mauritius' => 'MU',
            'Eswatini (Swaziland)' => 'SZ',
            'Lesotho' => 'LS',
            'Australia' => 'AU',
            'New Zealand' => 'NZ',
            'Fiji' => 'FJ',
            'Papua New Guinea' => 'PG',
            'Samoa' => 'WS',
            'Tonga' => 'TO',
            'Vanuatu' => 'VU',
            'Solomon Islands' => 'SB',
            'Kiribati' => 'KI',
            'Micronesia' => 'FM',
            'Marshall Islands' => 'MH',
            'Palau' => 'PW',
            'Nauru' => 'NR',
            'Tuvalu' => 'TV',
        ];
    }

    /**
     * Get country code for a given country name.
     *
     * @param string $countryName
     * @return string|null
     */
    public static function getCountryCode(string $countryName): ?string
    {
        $map = self::getCountryCodeMap();
        return $map[$countryName] ?? null;
    }

    /**
     * Get country name from country code.
     *
     * @param string $countryCode
     * @return string|null
     */
    public static function getCountryName(string $countryCode): ?string
    {
        $map = self::getCountryCodeMap();
        return array_search($countryCode, $map, true) ?: null;
    }
}

