<?php

namespace App\Domains\Sales\Application;

/**
 * One thing an invoice still needs before it can become an E-Invoice.
 *
 * The builder reports these instead of XML, and the same list feeds the
 * E-Invoice Ready check and the Fallback decision. Each case names a single
 * mandatory input as precisely as the data model allows, so a user can act on
 * it without reading the XML.
 *
 * The string values are stable identifiers: they are what a UI translates and
 * what tests assert on, so they must not change once released.
 */
enum EInvoiceRequirement: string
{
    /** BT-1 — the invoice number identifying the document. */
    case InvoiceNumber = 'invoice_number';

    /** BT-2 — the date the invoice was issued. */
    case InvoiceDate = 'invoice_date';

    /** BT-5 — the ISO 4217 code of the invoice currency. */
    case InvoiceCurrency = 'invoice_currency';

    /** BT-27 — the seller's registered name. */
    case SellerName = 'seller_name';

    /** BT-35 — the first line of the seller's postal address. */
    case SellerStreet = 'seller_street';

    /** BT-38 — the seller's post code. */
    case SellerPostcode = 'seller_postcode';

    /** BT-37 — the seller's city. */
    case SellerCity = 'seller_city';

    /** BT-40 — the seller's country code. */
    case SellerCountry = 'seller_country';

    /**
     * BT-31 — the seller's VAT identifier. A national tax number alone is
     * only BT-32, which BR-CO-26 does not accept as identification.
     */
    case SellerTaxRegistration = 'seller_tax_registration';

    /** BT-84 — the IBAN the payment is to be made to, from the E-Invoice settings. */
    case SellerIban = 'seller_iban';

    /** BT-44 — the buyer's name. */
    case BuyerName = 'buyer_name';

    /** BT-55 — the country code of the buyer's postal address. */
    case BuyerCountry = 'buyer_country';

    /** BG-25 — an invoice without a single line item has nothing to bill. */
    case LineItems = 'line_items';

    /** BT-153 — every line item needs a name. */
    case LineItemName = 'line_item_name';

    /**
     * EN 16931 states every amount net of VAT, so tax-inclusive prices cannot
     * be carried over without inventing a net breakdown the invoice never had.
     */
    case NetPrices = 'net_prices';

    /**
     * BT-118 — a tax whose tax type, and with it its Tax Category Code, is
     * gone. The foreign key on `taxes.tax_type_id` should rule this out; the
     * case exists so a database that lost it reports a gap rather than
     * dereferencing nothing while the requirements are being collected.
     */
    case TaxCategoryCode = 'tax_category_code';

    /** BT-119 — a fixed-amount tax carries no rate a VAT breakdown could state. */
    case TaxRatePercentage = 'tax_rate_percentage';

    /** BG-23 — an invoice with no tax at all has no VAT breakdown to write. */
    case InvoiceTax = 'invoice_tax';

    /** BG-30 — a line carries exactly one VAT category and rate, never several. */
    case SingleTaxRatePerLine = 'single_tax_rate_per_line';

    /** BT-120 — an exempt Tax Category Code needs its exemption reason, e.g. § 19 UStG. */
    case TaxExemptionReason = 'tax_exemption_reason';

    /**
     * BR-27 — an item net price (BT-146) is never negative. A deduction such as
     * a down payment is a line whose *amount* is negative and is carried as a
     * document allowance; a negative price on a positive amount is not.
     */
    case NegativePrice = 'negative_price';

    /**
     * BR-S-05, BR-Z-05, BR-E-05 and their siblings — the rate has to fit the
     * Tax Category Code: a standard-rated tax is above 0 %, every zero-rated,
     * exempt, reverse-charge, intra-community, export or out-of-scope one is 0 %.
     */
    case TaxRateForCategory = 'tax_rate_for_category';

    /**
     * The composed document failed XSD validation (or could not be composed at
     * all). Never reported together with the data requirements above — it is
     * the last gate, so that invalid XML is never handed out as valid.
     */
    case ValidCiiXml = 'valid_cii_xml';
}
