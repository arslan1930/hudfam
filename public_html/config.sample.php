<?php
/**
 * Copy this file to config.php and fill in Hostinger MySQL details.
 * Or run install.php once in the browser.
 *
 * Mail: used for Admin email verification + Admin password reset only.
 * On Hostinger, set mail_from to an address on your domain (often enough with mail()).
 * If mail() fails, fill SMTP (smtp.hostinger.com, port 465, ssl).
 */
return [
    'db_host' => '127.0.0.1', // Hostinger: use the host from hPanel. Avoid localhost (PHP/MySQL sockets often differ).
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
];
