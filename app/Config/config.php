<?php
$envPath = __DIR__ . '/../../.env';

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

// Define constants
define('PF_API_KEY', getenv('PF_API_KEY'));
define('PF_API_SECRET', getenv('PF_API_SECRET'));
define('PF_TOKEN_URL', getenv('PF_TOKEN_URL'));
define('PF_LEADS_URL', getenv('PF_LEADS_URL'));
define('PF_LISTINGS_URL', getenv('PF_LISTINGS_URL'));
define('PF_LEAD_SOURCE_ID', getenv('PF_LEAD_SOURCE_ID'));
define('WEBHOOK_URL', getenv('WEBHOOK_URL'));
define('LISTINGS_ENTITY_TYPE_ID', getenv('LISTINGS_ENTITY_TYPE_ID') ?: '');
define('REFERENCE_FIELD_ID', getenv('REFERENCE_FIELD_ID') ?: '');
define('OWNER_NAME_FIELD_ID', getenv('OWNER_NAME_FIELD_ID') ?: '');
define('OWNER_NAME_FIELD_ID_ALT', getenv('OWNER_NAME_FIELD_ID_ALT') ?: '');
define('LOCATION_FIELD_ID', getenv('LOCATION_FIELD_ID') ?: '');
define('LEAD_RESPONSIBLE_PERSON_NAME_FIELD_ID', getenv('LEAD_RESPONSIBLE_PERSON_NAME_FIELD_ID') ?: ''); // New
define('LEAD_REFERENCE_FIELD_ID', getenv('LEAD_REFERENCE_FIELD_ID') ?: '');
define('LEAD_PROPERTY_LINK_FIELD_ID', getenv('LEAD_PROPERTY_LINK_FIELD_ID') ?: '');
define('LEAD_TRACKING_LINK_FIELD_ID', getenv('LEAD_TRACKING_LINK_FIELD_ID') ?: '');
define('LEAD_CLIENT_NAME_FIELD_ID', getenv('LEAD_CLIENT_NAME_FIELD_ID') ?: '');
define('LEAD_CLIENT_PHONE_FIELD_ID', getenv('LEAD_CLIENT_PHONE_FIELD_ID') ?: '');
define('LEAD_CLIENT_EMAIL_FIELD_ID', getenv('LEAD_CLIENT_EMAIL_FIELD_ID') ?: '');
define('LEAD_DESTINATION', getenv('LEAD_DESTINATION') ?: 'DEAL');
define('CATEGORY_ID', getenv('CATEGORY_ID') ?: '');
