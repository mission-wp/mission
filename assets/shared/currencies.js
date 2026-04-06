/**
 * Stripe-supported currencies.
 *
 * Full list of ISO 4217 currency codes that Stripe accepts for charges.
 * @see https://docs.stripe.com/currencies
 */

/**
 * Zero-decimal currencies — amounts are already in the smallest unit.
 * e.g. ¥500 is passed as amount: 500.
 */
export const ZERO_DECIMAL_CURRENCIES = new Set( [
  'BIF',
  'CLP',
  'DJF',
  'GNF',
  'JPY',
  'KMF',
  'KRW',
  'MGA',
  'PYG',
  'RWF',
  'UGX',
  'VND',
  'VUV',
  'XAF',
  'XOF',
  'XPF',
] );

/**
 * Three-decimal currencies — smallest unit is 1/1000.
 * e.g. 1.500 BHD is passed as amount: 1500.
 */
export const THREE_DECIMAL_CURRENCIES = new Set( [
  'BHD',
  'JOD',
  'KWD',
  'OMR',
  'TND',
] );

/**
 * Get the number of decimal places for a currency.
 *
 * @param {string} code Uppercase ISO 4217 currency code.
 * @return {number} 0, 2, or 3.
 */
export function getCurrencyDecimals( code ) {
  if ( ZERO_DECIMAL_CURRENCIES.has( code ) ) {
    return 0;
  }
  if ( THREE_DECIMAL_CURRENCIES.has( code ) ) {
    return 3;
  }
  return 2;
}

/**
 * Convert a minor-units integer to a display value.
 *
 * @param {number} minorUnits Amount in smallest currency unit.
 * @param {string} code       Uppercase ISO 4217 currency code.
 * @return {number} Display value (e.g. 4500 → 45.00 for USD, 500 → 500 for JPY).
 */
export function minorToMajor( minorUnits, code ) {
  const decimals = getCurrencyDecimals( code );
  if ( decimals === 0 ) {
    return minorUnits;
  }
  return minorUnits / 10 ** decimals;
}

/**
 * Convert a display value to minor units.
 *
 * @param {number} displayValue Amount in major units (e.g. 45.00 for USD, 500 for JPY).
 * @param {string} code         Uppercase ISO 4217 currency code.
 * @return {number} Amount in smallest currency unit.
 */
export function majorToMinor( displayValue, code ) {
  const decimals = getCurrencyDecimals( code );
  return Math.round( displayValue * 10 ** decimals );
}

/**
 * All Stripe-supported currencies, sorted by code.
 *
 * Each entry: { label: 'CODE — Name', value: 'CODE' }
 */
export const CURRENCIES = [
  { label: 'AED — United Arab Emirates Dirham', value: 'AED' },
  { label: 'AFN — Afghan Afghani', value: 'AFN' },
  { label: 'ALL — Albanian Lek', value: 'ALL' },
  { label: 'AMD — Armenian Dram', value: 'AMD' },
  { label: 'ANG — Netherlands Antillean Gulden', value: 'ANG' },
  { label: 'AOA — Angolan Kwanza', value: 'AOA' },
  { label: 'ARS — Argentine Peso', value: 'ARS' },
  { label: 'AUD — Australian Dollar', value: 'AUD' },
  { label: 'AWG — Aruban Florin', value: 'AWG' },
  { label: 'AZN — Azerbaijani Manat', value: 'AZN' },
  { label: 'BAM — Bosnia & Herzegovina Convertible Mark', value: 'BAM' },
  { label: 'BBD — Barbadian Dollar', value: 'BBD' },
  { label: 'BDT — Bangladeshi Taka', value: 'BDT' },
  { label: 'BGN — Bulgarian Lev', value: 'BGN' },
  { label: 'BHD — Bahraini Dinar', value: 'BHD' },
  { label: 'BIF — Burundian Franc', value: 'BIF' },
  { label: 'BMD — Bermudian Dollar', value: 'BMD' },
  { label: 'BND — Brunei Dollar', value: 'BND' },
  { label: 'BOB — Bolivian Boliviano', value: 'BOB' },
  { label: 'BRL — Brazilian Real', value: 'BRL' },
  { label: 'BSD — Bahamian Dollar', value: 'BSD' },
  { label: 'BWP — Botswana Pula', value: 'BWP' },
  { label: 'BZD — Belize Dollar', value: 'BZD' },
  { label: 'CAD — Canadian Dollar', value: 'CAD' },
  { label: 'CDF — Congolese Franc', value: 'CDF' },
  { label: 'CHF — Swiss Franc', value: 'CHF' },
  { label: 'CLP — Chilean Peso', value: 'CLP' },
  { label: 'CNY — Chinese Renminbi Yuan', value: 'CNY' },
  { label: 'COP — Colombian Peso', value: 'COP' },
  { label: 'CRC — Costa Rican Colon', value: 'CRC' },
  { label: 'CVE — Cape Verdean Escudo', value: 'CVE' },
  { label: 'CZK — Czech Koruna', value: 'CZK' },
  { label: 'DJF — Djiboutian Franc', value: 'DJF' },
  { label: 'DKK — Danish Krone', value: 'DKK' },
  { label: 'DOP — Dominican Peso', value: 'DOP' },
  { label: 'DZD — Algerian Dinar', value: 'DZD' },
  { label: 'EGP — Egyptian Pound', value: 'EGP' },
  { label: 'ETB — Ethiopian Birr', value: 'ETB' },
  { label: 'EUR — Euro', value: 'EUR' },
  { label: 'FJD — Fijian Dollar', value: 'FJD' },
  { label: 'FKP — Falkland Islands Pound', value: 'FKP' },
  { label: 'GBP — British Pound', value: 'GBP' },
  { label: 'GEL — Georgian Lari', value: 'GEL' },
  { label: 'GIP — Gibraltar Pound', value: 'GIP' },
  { label: 'GMD — Gambian Dalasi', value: 'GMD' },
  { label: 'GNF — Guinean Franc', value: 'GNF' },
  { label: 'GTQ — Guatemalan Quetzal', value: 'GTQ' },
  { label: 'GYD — Guyanese Dollar', value: 'GYD' },
  { label: 'HKD — Hong Kong Dollar', value: 'HKD' },
  { label: 'HNL — Honduran Lempira', value: 'HNL' },
  { label: 'HRK — Croatian Kuna', value: 'HRK' },
  { label: 'HTG — Haitian Gourde', value: 'HTG' },
  { label: 'HUF — Hungarian Forint', value: 'HUF' },
  { label: 'IDR — Indonesian Rupiah', value: 'IDR' },
  { label: 'ILS — Israeli New Sheqel', value: 'ILS' },
  { label: 'INR — Indian Rupee', value: 'INR' },
  { label: 'ISK — Icelandic Krona', value: 'ISK' },
  { label: 'JMD — Jamaican Dollar', value: 'JMD' },
  { label: 'JOD — Jordanian Dinar', value: 'JOD' },
  { label: 'JPY — Japanese Yen', value: 'JPY' },
  { label: 'KES — Kenyan Shilling', value: 'KES' },
  { label: 'KGS — Kyrgyzstani Som', value: 'KGS' },
  { label: 'KHR — Cambodian Riel', value: 'KHR' },
  { label: 'KMF — Comorian Franc', value: 'KMF' },
  { label: 'KRW — South Korean Won', value: 'KRW' },
  { label: 'KWD — Kuwaiti Dinar', value: 'KWD' },
  { label: 'KYD — Cayman Islands Dollar', value: 'KYD' },
  { label: 'KZT — Kazakhstani Tenge', value: 'KZT' },
  { label: 'LAK — Lao Kip', value: 'LAK' },
  { label: 'LKR — Sri Lankan Rupee', value: 'LKR' },
  { label: 'LRD — Liberian Dollar', value: 'LRD' },
  { label: 'LSL — Lesotho Loti', value: 'LSL' },
  { label: 'MAD — Moroccan Dirham', value: 'MAD' },
  { label: 'MDL — Moldovan Leu', value: 'MDL' },
  { label: 'MGA — Malagasy Ariary', value: 'MGA' },
  { label: 'MKD — Macedonian Denar', value: 'MKD' },
  { label: 'MMK — Myanmar Kyat', value: 'MMK' },
  { label: 'MNT — Mongolian Togrog', value: 'MNT' },
  { label: 'MOP — Macanese Pataca', value: 'MOP' },
  { label: 'MRO — Mauritanian Ouguiya', value: 'MRO' },
  { label: 'MUR — Mauritian Rupee', value: 'MUR' },
  { label: 'MVR — Maldivian Rufiyaa', value: 'MVR' },
  { label: 'MWK — Malawian Kwacha', value: 'MWK' },
  { label: 'MXN — Mexican Peso', value: 'MXN' },
  { label: 'MYR — Malaysian Ringgit', value: 'MYR' },
  { label: 'MZN — Mozambican Metical', value: 'MZN' },
  { label: 'NAD — Namibian Dollar', value: 'NAD' },
  { label: 'NGN — Nigerian Naira', value: 'NGN' },
  { label: 'NIO — Nicaraguan Cordoba', value: 'NIO' },
  { label: 'NOK — Norwegian Krone', value: 'NOK' },
  { label: 'NPR — Nepalese Rupee', value: 'NPR' },
  { label: 'NZD — New Zealand Dollar', value: 'NZD' },
  { label: 'OMR — Omani Rial', value: 'OMR' },
  { label: 'PAB — Panamanian Balboa', value: 'PAB' },
  { label: 'PEN — Peruvian Sol', value: 'PEN' },
  { label: 'PGK — Papua New Guinean Kina', value: 'PGK' },
  { label: 'PHP — Philippine Peso', value: 'PHP' },
  { label: 'PKR — Pakistani Rupee', value: 'PKR' },
  { label: 'PLN — Polish Zloty', value: 'PLN' },
  { label: 'PYG — Paraguayan Guarani', value: 'PYG' },
  { label: 'QAR — Qatari Riyal', value: 'QAR' },
  { label: 'RON — Romanian Leu', value: 'RON' },
  { label: 'RSD — Serbian Dinar', value: 'RSD' },
  { label: 'RUB — Russian Ruble', value: 'RUB' },
  { label: 'RWF — Rwandan Franc', value: 'RWF' },
  { label: 'SAR — Saudi Riyal', value: 'SAR' },
  { label: 'SBD — Solomon Islands Dollar', value: 'SBD' },
  { label: 'SCR — Seychellois Rupee', value: 'SCR' },
  { label: 'SEK — Swedish Krona', value: 'SEK' },
  { label: 'SGD — Singapore Dollar', value: 'SGD' },
  { label: 'SHP — Saint Helenian Pound', value: 'SHP' },
  { label: 'SLL — Sierra Leonean Leone', value: 'SLL' },
  { label: 'SOS — Somali Shilling', value: 'SOS' },
  { label: 'SRD — Surinamese Dollar', value: 'SRD' },
  { label: 'STD — Sao Tome and Principe Dobra', value: 'STD' },
  { label: 'SVC — Salvadoran Colon', value: 'SVC' },
  { label: 'SZL — Swazi Lilangeni', value: 'SZL' },
  { label: 'THB — Thai Baht', value: 'THB' },
  { label: 'TJS — Tajikistani Somoni', value: 'TJS' },
  { label: 'TND — Tunisian Dinar', value: 'TND' },
  { label: "TOP — Tongan Pa'anga", value: 'TOP' },
  { label: 'TRY — Turkish Lira', value: 'TRY' },
  { label: 'TTD — Trinidad and Tobago Dollar', value: 'TTD' },
  { label: 'TWD — New Taiwan Dollar', value: 'TWD' },
  { label: 'TZS — Tanzanian Shilling', value: 'TZS' },
  { label: 'UAH — Ukrainian Hryvnia', value: 'UAH' },
  { label: 'UGX — Ugandan Shilling', value: 'UGX' },
  { label: 'USD — US Dollar', value: 'USD' },
  { label: 'UYU — Uruguayan Peso', value: 'UYU' },
  { label: 'UZS — Uzbekistani Som', value: 'UZS' },
  { label: 'VND — Vietnamese Dong', value: 'VND' },
  { label: 'VUV — Vanuatu Vatu', value: 'VUV' },
  { label: 'WST — Samoan Tala', value: 'WST' },
  { label: 'XAF — Central African CFA Franc', value: 'XAF' },
  { label: 'XCD — East Caribbean Dollar', value: 'XCD' },
  { label: 'XOF — West African CFA Franc', value: 'XOF' },
  { label: 'XPF — CFP Franc', value: 'XPF' },
  { label: 'YER — Yemeni Rial', value: 'YER' },
  { label: 'ZAR — South African Rand', value: 'ZAR' },
  { label: 'ZMW — Zambian Kwacha', value: 'ZMW' },
];
