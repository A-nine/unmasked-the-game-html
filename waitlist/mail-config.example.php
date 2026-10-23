<?php
/**
 * Email settings for the waitlist.
 *
 * On the server this file is called mail-config.php. Fill in the password of the
 * no-reply@unmasked.ng mailbox (cPanel → Email Accounts). Never commit the real password.
 */
return [
    'host'               => 'mail.unmasked.ng',      // cPanel → Email Accounts → Connect Devices shows this
    'port'               => 465,                     // usually 465 on cPanel (or 587)
    'encryption'         => 'ssl',                   // 'ssl' for port 465, 'tls' for port 587
    'username'           => 'no-reply@unmasked.ng',  // the full email address
    'password'           => 'PUT-THE-MAILBOX-PASSWORD-HERE',
    'verify_certificate' => true,                    // set to false only if the log shows a certificate error
];
