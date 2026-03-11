<?php

namespace App\Core;

use App\Core\PFService;
use App\Core\Logger;
use CRest;

require __DIR__ . '/../../crest/crest.php';

class BitrixService
{
    public function createContact(array $data): int
    {
        try {
            $contacts = $this->parseContacts($data['contacts'] ?? []);
            $existingContactId = $this->findExistingContact($contacts);

            if ($existingContactId) {
                Logger::log(['event' => 'bitrix.contact.exists', 'contact_id' => $existingContactId]);
                return $existingContactId;
            }

            $fields = ['NAME' => $data['name'] ?? ''];

            if (!empty($contacts['PHONE'])) {
                $fields['PHONE'] = $contacts['PHONE'];
            }
            if (!empty($contacts['EMAIL'])) {
                $fields['EMAIL'] = $contacts['EMAIL'];
            }

            $response = CRest::call('crm.contact.add', ['fields' => $fields]);
            Logger::log(['event' => 'bitrix.create.contact.response', 'response' => $response]);
            $contactId = 0;
            if (is_array($response)) {
                if (isset($response['result']['id'])) {
                    $contactId = (int)$response['result']['id'];
                } elseif (isset($response['result']) && is_int($response['result'])) {
                    $contactId = (int)$response['result'];
                } elseif (isset($response['result']) && is_array($response['result']) && isset($response['result'][0]['ID'])) {
                    $contactId = (int)$response['result'][0]['ID'];
                }
            }
            return $contactId;
        } catch (\Exception $e) {
            Logger::log(['event' => 'bitrix.create.contact.error', 'error' => $e->getMessage()]);
            throw new \Exception('Error creating Bitrix contact: ' . $e->getMessage());
        }
    }

    private function findExistingContact(array $contacts): ?int
    {
        try {
            foreach (['PHONE', 'EMAIL'] as $type) {
                foreach ($contacts[$type] ?? [] as $item) {
                    $response = CRest::call('crm.contact.list', [
                        'filter' => [$type => $item['VALUE']],
                        'select' => ['ID']
                    ]);
                    if (!empty($response['result'][0]['ID'])) {
                        return (int)$response['result'][0]['ID'];
                    }
                }
            }
        } catch (\Exception $e) {
            Logger::log(['event' => 'bitrix.find.contact.error', 'error' => $e->getMessage()]);
        }

        return null;
    }
    public function createLead(array $data, int $contactId): int
    {
        try {
            // support payload shapes that may nest under 'data' => 'payload'
            $payloadEnvelope = $data;
            if (isset($data['data']) && is_array($data['data']) && isset($data['data']['payload'])) {
                $payloadEnvelope = $data['data']['payload'];
            }
            // primary payload for lead fields
            $payload = $payloadEnvelope['payload'] ?? $payloadEnvelope;

            $sender = $payload['sender'] ?? [];
            // normalize contacts array (raw array of objects) into associative PHONE/EMAIL arrays
            $sender['contacts'] = $this->parseContacts($sender['contacts'] ?? []);

            // try multiple places for listing id (payload might not include listing key)
            $listingId = $payload['listing']['id'] ?? $payload['entity']['id'] ?? null;
            $channel = $payload['channel'] ?? ($payloadEnvelope['channel'] ?? 'unknown');

            // fetch listing safely; always return array or empty array
            $listing = $listingId ? ($this->fetchListingDetails($listingId) ?? []) : [];
            $listingLocation = $listingId ? ($this->fetchListingLocation($listingId) ?? []) : [];

            $title = $this->composeLeadTitle($channel, $listing);
            if (empty($title)) {
                $title = 'Property Finder - ' . ucfirst($channel);
            }

            $isSpa = LEAD_DESTINATION !== 'LEAD' && LEAD_DESTINATION !== 'DEAL';
            $assignedById = (int) ($this->getResponsiblePersonId($listing['reference'] ?? null) ?? DEFAULT_RESPONSIBLE_PERSON_ID);

            // Build comment parts (safe call) — listing/listingLocation are arrays
            $commentParts = $this->buildCommentParts($payload, $sender, $listing, $listingLocation);
            $commentString = implode("\n", $commentParts);

            // helper to extract phone/email from sender contacts (indexed arrays or parsed shape)
            $phoneValue = $this->extractContactValueFromParsed($sender['contacts'] ?? [], 'PHONE');
            $emailValue = $this->extractContactValueFromParsed($sender['contacts'] ?? [], 'EMAIL');

            // Build fields per destination
            if ($isSpa) {
                $fields = [
                    'title' => $title,
                    'stageId' => 'NEW',
                    'contactId' => $contactId,
                    'sourceId' => PF_LEAD_SOURCE_ID,
                    'assignedById' => $assignedById,
                    LEAD_COMMENT_FIELD => $commentString,
                    LEAD_REFERENCE_FIELD_ID => $listing['reference'] ?? '',
                    LEAD_PROPERTY_LINK_FIELD_ID => "https://propertyfinder.ae/go/" . ($listing['id'] ?? ''),
                    LEAD_TRACKING_LINK_FIELD_ID => $payload['responseLink'] ?? '',
                    LEAD_CLIENT_NAME_FIELD_ID => $sender['name'] ?? '',
                    LEAD_CLIENT_PHONE_FIELD_ID => $phoneValue,
                    LEAD_CLIENT_EMAIL_FIELD_ID => $emailValue
                ];
                if (!empty(CATEGORY_ID)) $fields['categoryId'] = CATEGORY_ID;
            } elseif (LEAD_DESTINATION === 'LEAD') {
                $fields = [
                    'TITLE' => $title,
                    'STATUS_ID' => 'NEW',
                    'CONTACT_ID' => $contactId,
                    'SOURCE_ID' => PF_LEAD_SOURCE_ID,
                    'ASSIGNED_BY_ID' => $assignedById,
                    'COMMENTS' => $commentString,
                    'NAME' => $sender['name'] ?? '',
                    LEAD_REFERENCE_FIELD_ID => $listing['reference'] ?? '',
                    LEAD_PROPERTY_LINK_FIELD_ID => "https://propertyfinder.ae/go/" . ($listing['id'] ?? ''),
                    LEAD_TRACKING_LINK_FIELD_ID => $payload['responseLink'] ?? '',
                    LEAD_CLIENT_NAME_FIELD_ID => $sender['name'] ?? '',
                    LEAD_CLIENT_PHONE_FIELD_ID => $phoneValue,
                    LEAD_CLIENT_EMAIL_FIELD_ID => $emailValue
                ];
                if (!empty(CATEGORY_ID)) $fields['CATEGORY_ID'] = CATEGORY_ID;
                $contacts = $this->parseContacts($sender['contacts'] ?? []);
                if (!empty($contacts['PHONE'])) $fields['PHONE'] = $contacts['PHONE'];
                if (!empty($contacts['EMAIL'])) $fields['EMAIL'] = $contacts['EMAIL'];
            } else { // DEAL
                $formattedPrice = '';
                if (!empty($listing['price']['amounts']) && is_array($listing['price']['amounts'])) {
                    foreach ($listing['price']['amounts'] as $key => $value) {
                        if (!empty($value)) {
                            $type = ucfirst($key);
                            $formattedPrice = number_format($value) . " ({$type})";
                            break;
                        }
                    }
                }

                $formattedArea = !empty($listing['size']) ? $listing['size'] . " sqft" : '';

                $formattedLocation = !empty($listingLocation[LOCATION_FIELD_ID])
                    ? ucfirst($listingLocation[LOCATION_FIELD_ID])
                    : '';

                $fields = [
                    'TITLE' => $title,
                    'STATUS_ID' => 'NEW',
                    'CONTACT_ID' => $contactId,
                    'SOURCE_ID' => PF_LEAD_SOURCE_ID,
                    'ASSIGNED_BY_ID' => $assignedById,
                    'COMMENTS' => $commentString,
                    LEAD_REFERENCE_FIELD_ID => $listing['reference'] ?? '',
                    LEAD_PROPERTY_LINK_FIELD_ID => "https://propertyfinder.ae/go/" . ($listing['id'] ?? ''),
                    LEAD_TRACKING_LINK_FIELD_ID => $payload['responseLink'] ?? '',
                    LEAD_CLIENT_NAME_FIELD_ID => $sender['name'] ?? '',
                    LEAD_CLIENT_PHONE_FIELD_ID => $phoneValue,
                    LEAD_CLIENT_EMAIL_FIELD_ID => $emailValue,

                    LEAD_PROPERTY_TYPE_FIELD_ID   => $listing['type'] ?? '',
                    LEAD_BEDROOMS_FIELD_ID        => $listing['bedrooms'] ?? '',
                    LEAD_BATHROOMS_FIELD_ID       => $listing['bathrooms'] ?? '',
                    LEAD_AREA_FIELD_ID            => $formattedArea,
                    LEAD_FURNISHING_FIELD_ID      => $listing['furnishingType'] ?? '',
                    LEAD_PROJECT_STATUS_FIELD_ID  => $listing['projectStatus'] ?? '',
                    LEAD_PRICE_FIELD_ID           => $formattedPrice,
                    LEAD_LOCATION_FIELD_ID        => $formattedLocation,
                ];
                if (!empty(CATEGORY_ID)) $fields['CATEGORY_ID'] = CATEGORY_ID;
            }

            // determine method & params
            if (LEAD_DESTINATION === 'LEAD') {
                $method = 'crm.lead.add';
            } elseif (LEAD_DESTINATION === 'DEAL') {
                $method = 'crm.deal.add';
            } else {
                $method = 'crm.item.add';
                $entityTypeId = SPA_ENTITY_TYPE_ID;
                if (!$entityTypeId) {
                    throw new \Exception('Entity type ID not found');
                }
            }

            $params = ['fields' => $fields];
            if ($isSpa) $params['entityTypeId'] = $entityTypeId;

            // log params
            Logger::log(['event' => 'bitrix.create.lead', 'method' => $method, 'params' => $params]);

            // call Bitrix
            $response = CRest::call($method, $params);
            Logger::log(['event' => 'bitrix.create.lead.response', 'method' => $method, 'response' => $response]);

            // interpret response robustly and extract ID (always return int)
            $leadId = 0;
            if (is_array($response)) {
                // Bitrix common shapes: ['result' => ['id' => 123]] OR ['result' => 123] OR ['result' => ['item' => ['id'=>123]]]
                if (isset($response['result']['id'])) {
                    $leadId = (int)$response['result']['id'];
                } elseif (isset($response['result']) && is_int($response['result'])) {
                    $leadId = (int)$response['result'];
                } elseif (isset($response['result']['item']['id'])) {
                    $leadId = (int)$response['result']['item']['id'];
                } elseif (isset($response['result']['item']) && is_int($response['result']['item'])) {
                    $leadId = (int)$response['result']['item'];
                }
            }

            // handle Bitrix error explicitly
            if (isset($response['error']) || $leadId === 0) {
                Logger::log(['event' => 'bitrix.create.lead.error', 'response' => $response, 'params' => $params]);
                $msg = $response['error_description'] ?? ($response['error'] ?? 'Unknown Bitrix error');
                throw new \Exception('Error creating Bitrix lead (Bitrix): ' . $msg);
            }

            return $leadId;
        } catch (\Exception $e) {
            Logger::log(['event' => 'bitrix.create.lead.error', 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            throw new \Exception('Error creating Bitrix lead: ' . $e->getMessage());
        }
    }


    private function buildCommentParts(array $payload, array $sender, ?array $listing, ?array $listingLocation): array
    {
        $listing = $listing ?? [];
        $listingLocation = $listingLocation ?? [];
        $commentParts = [];

        // PF Property Link
        if (!empty($listing['id'])) {
            $commentParts[] = "PF Property Link: https://propertyfinder.ae/go/{$listing['id']}";
        }

        // PF Response Link
        if (!empty($payload['responseLink'])) {
            $commentParts[] = "PF Response Link: {$payload['responseLink']}";
        }

        // Sender Info
        if (!empty($sender['name'])) {
            $commentParts[] = "Sender Name: {$sender['name']}";
        }

        // Property Info
        if (!empty($listing['bedrooms'])) {
            $commentParts[] = "Bedrooms: {$listing['bedrooms']}";
        }
        if (!empty($listing['bathrooms'])) {
            $commentParts[] = "Bathrooms: {$listing['bathrooms']}";
        }
        if (!empty($listing['furnishingType'])) {
            $commentParts[] = "Furnishing Type: {$listing['furnishingType']}";
        }
        if (!empty($listing['size'])) {
            $commentParts[] = "Size: {$listing['size']} sqft";
        }

        // Price Info
        if (!empty($listing['price']['amounts']) && is_array($listing['price']['amounts'])) {
            foreach ($listing['price']['amounts'] as $key => $value) {
                if (!empty($value)) {
                    $type = ucfirst($key);
                    $formattedPrice = number_format($value);
                    $commentParts[] = "Price: {$formattedPrice} ({$type})";
                    break;
                }
            }
        }

        if (!empty($listing['projectStatus'])) {
            $commentParts[] = "Project Status: {$listing['projectStatus']}";
        }
        if (!empty($listing['type'])) {
            $commentParts[] = "Property Type: {$listing['type']}";
        }
        if (!empty($listing['uaeEmirate'])) {
            $emirate = ucfirst($listing['uaeEmirate']);
            $commentParts[] = "UAE Emirate: {$emirate}";
        }
        if (!empty($listingLocation[LOCATION_FIELD_ID])) {
            $location = ucfirst($listingLocation[LOCATION_FIELD_ID]);
            $commentParts[] = "Location: {$location}";
        }

        // Contact Info
        $contacts = $this->parseContacts($sender['contacts'] ?? []);
        if (!empty($contacts['PHONE'])) {
            $phones = array_column($contacts['PHONE'], 'VALUE');
            $commentParts[] = "Phone(s): " . implode(', ', $phones);
        }
        if (!empty($contacts['EMAIL'])) {
            $emails = array_column($contacts['EMAIL'], 'VALUE');
            $commentParts[] = "Email(s): " . implode(', ', $emails);
        }

        return $commentParts;
    }

    private function parseContacts(array $contacts): array
    {
        $result = ['PHONE' => [], 'EMAIL' => []];

        foreach ($contacts as $contact) {
            $type = strtolower($contact['type'] ?? '');
            $value = trim($contact['value'] ?? '');

            if (!$value) continue;

            if ($type === 'phone') {
                $result['PHONE'][] = ['VALUE' => $value, 'VALUE_TYPE' => 'WORK'];
            } elseif ($type === 'email') {
                $result['EMAIL'][] = ['VALUE' => $value, 'VALUE_TYPE' => 'WORK'];
            }
        }

        return $result;
    }

    private function fetchListingDetails(?string $listingId): ?array
    {
        if (!$listingId) return null;

        try {
            $pfService = new PFService();
            $results = $pfService->fetchListings(['filter' => ['ids' => $listingId]])['results'] ?? [];

            return $results[0] ?? null;
        } catch (\Exception $e) {
            Logger::log(['event' => 'bitrix.fetch.listing.error', 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchListingLocation(?string $listingId): ?array
    {
        if (!$listingId) return null;

        try {
            $params = [
                'entityTypeId' => (int) LISTINGS_ENTITY_TYPE_ID,
                'filter' => [PF_ID_FIELD_ID => $listingId],
                'select' => [LOCATION_FIELD_ID]
            ];

            $response = CRest::call("crm.item.list", $params);

            Logger::log([
                'event' => 'bitrix.fetch.listing.location',
                'listing_id' => $listingId,
                'params' => $params,
                'response_raw' => $response
            ]);

            if (!$response || empty($response['result'])) {
                Logger::log([
                    'event' => 'bitrix.fetch.listing.location.error',
                    'listing_id' => $listingId,
                    'http_code' => null,
                    'error' => $response['error'] ?? 'No response',
                    'response_raw' => $response
                ]);
                return null;
            }

            return $response['result']['items'][0] ?? null;
        } catch (\Exception $e) {
            Logger::log(['event' => 'bitrix.fetch.listing.location.error', 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function composeLeadTitle(string $channel, ?array $listing): string
    {
        $title = 'Property Finder - ' . ucfirst($channel);
        if (!empty($listing['reference'])) {
            $title .= ' - ' . $listing['reference'];
        }
        return $title;
    }

    private function getUserId(array $filter): ?int
    {
        try {
            $response = CRest::call('user.get', ['FILTER' => $filter]);
            if (!empty($response['result'][0]['ID'])) {
                return (int)$response['result'][0]['ID'];
            }
        } catch (\Exception $e) {
            Logger::log(['event' => 'bitrix.get.user.error', 'error' => $e->getMessage()]);
        }
        return null;
    }

    public function getResponsiblePersonId(?string $propertyReference): ?int
    {
        try {
            if (!$propertyReference) return null;

            $params = [
                'entityTypeId' => (int) LISTINGS_ENTITY_TYPE_ID,
                'filter' => [REFERENCE_FIELD_ID => $propertyReference],
                'select' => [OWNER_ID_FIELD_ID, OWNER_NAME_FIELD_ID, OWNER_NAME_FIELD_ID_ALT,  AGENT_EMAIL_FIELD_ID]
            ];

            $response = CRest::call("crm.item.list", $params);

            Logger::log([
                'event' => 'external.contact.api.response',
                'reference' => $propertyReference,
                'params' => $params,
                'response_raw' => $response
            ]);

            if (!$response || empty($response['result'])) {
                Logger::log([
                    'event' => 'external.contact.api.error',
                    'reference' => $propertyReference,
                    'http_code' => null,
                    'error' => $response['error'] ?? 'No response',
                    'response_raw' => $response
                ]);
                return null;
            }

            $contactData = $response['result']['items'][0] ?? [];

            if (!empty($contactData[OWNER_ID_FIELD_ID])) {
                return (int)$contactData[OWNER_ID_FIELD_ID];
            }

            $ownerName = $contactData[OWNER_NAME_FIELD_ID] ?? $contactData[OWNER_NAME_FIELD_ID_ALT] ?? null;
            $agentEmail = $contactData[AGENT_EMAIL_FIELD_ID] ?? null;

            if (!$ownerName && !$agentEmail) {
                Logger::log([
                    'event' => 'contact.emails.missing',
                    'reference' => $propertyReference,
                    'response' => $contactData
                ]);
                return null;
            }

            // First try ownerName
            if ($ownerName) {
                $nameParts = preg_split('/\s+/', trim($ownerName));

                if (count($nameParts) >= 2) {
                    for ($i = 1; $i < count($nameParts); $i++) {
                        $firstName = implode(' ', array_slice($nameParts, 0, $i));
                        $lastName = implode(' ', array_slice($nameParts, $i));

                        $userId = $this->getUserId([
                            '%NAME' => $firstName,
                            '%LAST_NAME' => $lastName,
                            '!ID' => IDS_TO_IGNORE
                        ]);

                        if ($userId) {
                            return $userId;
                        }
                    }
                } else {
                    $userId = $this->getUserId([
                        '%NAME' => $ownerName,
                    ]);

                    if ($userId) {
                        return $userId;
                    }
                }
            }

            // Fallback to agent_email
            if ($agentEmail) {
                $userId = $this->getUserId([
                    '%EMAIL' => $agentEmail,
                ]);

                if ($userId) {
                    return $userId;
                }
            }

            Logger::log([
                'event' => 'bitrix.user.not.found.by.email',
                'reference' => $propertyReference,
                'owner_name' => $ownerName,
                'agent_email' => $agentEmail
            ]);

            return null;
        } catch (\Exception $e) {
            Logger::log([
                'event' => 'get.responsible.person.error',
                'error' => $e->getMessage(),
                'reference' => $propertyReference
            ]);
            return null;
        }
    }

    private function extractContactValueFromParsed(array $parsedContacts, string $key): string
    {
        // parsedContacts expected shape: ['PHONE'=>[['VALUE'=>'...']], 'EMAIL'=>[['VALUE'=>'...']]]
        if (!is_array($parsedContacts)) return '';
        $key = strtoupper($key);
        if (!empty($parsedContacts[$key]) && is_array($parsedContacts[$key])) {
            $first = $parsedContacts[$key][0] ?? null;
            return $first['VALUE'] ?? '';
        }
        return '';
    }
}
