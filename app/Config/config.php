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
define('DEFAULT_RESPONSIBLE_PERSON_ID', getenv('DEFAULT_RESPONSIBLE_PERSON_ID') ?: '');
define('LISTINGS_ENTITY_TYPE_ID', getenv('LISTINGS_ENTITY_TYPE_ID') ?: '');
define('REFERENCE_FIELD_ID', getenv('REFERENCE_FIELD_ID') ?: '');
define('OWNER_ID_FIELD_ID', getenv('OWNER_ID_FIELD_ID') ?: '');
define('OWNER_NAME_FIELD_ID', getenv('OWNER_NAME_FIELD_ID') ?: '');
define('OWNER_NAME_FIELD_ID_ALT', getenv('OWNER_NAME_FIELD_ID_ALT') ?: '');
define('AGENT_EMAIL_FIELD_ID', getenv('AGENT_EMAIL_FIELD_ID') ?: '');
define('LOCATION_FIELD_ID', getenv('LOCATION_FIELD_ID') ?: '');
define('PF_ID_FIELD_ID', getenv('PF_ID_FIELD_ID') ?: '');
define('LEAD_REFERENCE_FIELD_ID', getenv('LEAD_REFERENCE_FIELD_ID') ?: '');
define('LEAD_PROPERTY_TYPE_FIELD_ID', getenv('LEAD_PROPERTY_TYPE_FIELD_ID') ?: '');
define('LEAD_BEDROOMS_FIELD_ID', getenv('LEAD_BEDROOMS_FIELD_ID') ?: '');
define('LEAD_BATHROOMS_FIELD_ID', getenv('LEAD_BATHROOMS_FIELD_ID') ?: '');
define('LEAD_AREA_FIELD_ID', getenv('LEAD_AREA_FIELD_ID') ?: '');
define('LEAD_FURNISHING_FIELD_ID', getenv('LEAD_FURNISHING_FIELD_ID') ?: '');
define('LEAD_PROJECT_STATUS_FIELD_ID', getenv('LEAD_PROJECT_STATUS_FIELD_ID') ?: '');
define('LEAD_PRICE_FIELD_ID', getenv('LEAD_PRICE_FIELD_ID') ?: '');
define('LEAD_LOCATION_FIELD_ID', getenv('LEAD_LOCATION_FIELD_ID') ?: '');
define('LEAD_PROPERTY_LINK_FIELD_ID', getenv('LEAD_PROPERTY_LINK_FIELD_ID') ?: '');
define('LEAD_TRACKING_LINK_FIELD_ID', getenv('LEAD_TRACKING_LINK_FIELD_ID') ?: '');
define('LEAD_CLIENT_NAME_FIELD_ID', getenv('LEAD_CLIENT_NAME_FIELD_ID') ?: '');
define('LEAD_CLIENT_PHONE_FIELD_ID', getenv('LEAD_CLIENT_PHONE_FIELD_ID') ?: '');
define('LEAD_CLIENT_EMAIL_FIELD_ID', getenv('LEAD_CLIENT_EMAIL_FIELD_ID') ?: '');
define('IDS_TO_IGNORE', explode(',', getenv('IDS_TO_IGNORE') ?: ''));
define('LEAD_DESTINATION', getenv('LEAD_DESTINATION') ?: 'LEAD');
define('SPA_ENTITY_TYPE_ID', (int)getenv('SPA_ENTITY_TYPE_ID') ?: 'LEAD');
define('LEAD_COMMENT_FIELD', getenv('LEAD_COMMENT_FIELD') ?: '');
define('CATEGORY_ID', getenv('CATEGORY_ID') ?: '');
define('LEAD_FILE', __DIR__ . '/../../processed_leads.txt');
