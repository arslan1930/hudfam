<?php
/**
 * Copy this file to config.php and fill in Hostinger MySQL details.
 * Or run install.php once in the browser.
 *
 * Mail: used for Admin email verification + Admin password reset only.
 * On Hostinger, set mail_from to an address on your domain (often enough with mail()).
 * If mail() fails, fill SMTP (smtp.hostinger.com, port 465, ssl).
 *
 * Invoice company block: shown on printable invoices (Admin → Invoices).
 * Leave blank to show empty fields until you fill them in.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'your_database_name',
    'db_user' => 'your_database_user',
    'db_pass' => 'your_database_password',
    'app_name' => 'TechxForm',
    // Public URL of this app (no trailing slash), used in email links
    'app_url' => 'https://your-domain.com',
    'mail_from' => 'noreply@your-domain.com',
    'mail_from_name' => 'TechxForm',
    // Optional SMTP (leave smtp_host empty to use PHP mail())
    'smtp_host' => '',
    'smtp_port' => 465,
    'smtp_user' => '',
    'smtp_pass' => '',
    'smtp_secure' => 'ssl', // ssl or tls

    // Invoice supplier / bank details (Admin → Invoices)
    'invoice_company_name' => 'Your Company Ltd',
    'invoice_company_bic' => 'YOURBICXXX',
    'invoice_company_iban' => 'XX00XXXX000000000000',
    'invoice_company_phone' => '+00 000 000 0000',
    'invoice_company_address' => "Street address\nCity, Country",
    'invoice_company_reg_no' => '00000000',
    'invoice_vat_note' => 'Not VAT registered – no VAT charged.',
];
