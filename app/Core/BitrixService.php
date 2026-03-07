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
    public function createLead(array $data, int $contactId = 0): int
    {
        try {
            $payloadEnvelope = $data['data']['payload'] ?? $data;
            $payload = $payloadEnvelope['payload'] ?? $payloadEnvelope;
            $sender = $payload['sender'] ?? [];

            // Use Reference to find the listing in your simplified SPA
            $listingReference = $payload['listing']['reference'] ?? null;
            $listingFromSPA = $this->fetchListingFromSPA($listingReference);

            $channel = $payload['channel'] ?? 'unknown';
            $title = 'Property Finder - ' . ucfirst($channel) . ($listingReference ? " - $listingReference" : "");

            // Build Comment using SPA Community instead of Location
            $community = $listingFromSPA[LOCATION_FIELD_ID] ?? 'N/A';
            $ownerName = $listingFromSPA[OWNER_NAME_FIELD_ID] ?? $listingFromSPA[OWNER_NAME_FIELD_ID_ALT] ?? 'N/A';

            $commentParts = [
                "PF Property Link: https://propertyfinder.ae/go/" . ($payload['listing']['id'] ?? ''),
                "Community: " . $community,
                "Owner Name: " . $ownerName,
                "Client Name: " . ($sender['name'] ?? 'N/A')
            ];

            $fields = [
                'TITLE' => $title,
                'STAGE_ID' => 'NEW',
                'SOURCE_ID' => PF_LEAD_SOURCE_ID,
                'COMMENTS' => implode("\n", $commentParts),
                'CATEGORY_ID' => CATEGORY_ID,
                // Map Owner Name to custom text field instead of ASSIGNED_BY_ID
                LEAD_RESPONSIBLE_PERSON_NAME_FIELD_ID => $ownerName,
                LEAD_REFERENCE_FIELD_ID => $listingReference,
                LEAD_PROPERTY_LINK_FIELD_ID => "https://propertyfinder.ae/go/" . ($payload['listing']['id'] ?? ''),
                LEAD_TRACKING_LINK_FIELD_ID => $payload['responseLink'] ?? '',
                LEAD_CLIENT_NAME_FIELD_ID => $sender['name'] ?? '',
                LEAD_CLIENT_PHONE_FIELD_ID => $this->extractContactValue($sender['contacts'] ?? [], 'phone'),
                LEAD_CLIENT_EMAIL_FIELD_ID => $this->extractContactValue($sender['contacts'] ?? [], 'email'),
            ];

            $response = CRest::call('crm.deal.add', ['fields' => $fields]);
            return (int)($response['result'] ?? 0);
        } catch (\Exception $e) {
            Logger::log(['event' => 'bitrix.create.deal.error', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Fetches the listing from the temporary SPA using the Reference Number
     */
    private function fetchListingFromSPA(?string $reference): ?array
    {
        if (!$reference) return null;

        $params = [
            'entityTypeId' => (int) LISTINGS_ENTITY_TYPE_ID,
            'filter' => [REFERENCE_FIELD_ID => $reference],
            'select' => [LOCATION_FIELD_ID, OWNER_NAME_FIELD_ID, OWNER_NAME_FIELD_ID_ALT]
        ];

        $response = CRest::call("crm.item.list", $params);
        return $response['result']['items'][0] ?? null;
    }

    private function extractContactValue(array $contacts, string $type): string
    {
        foreach ($contacts as $contact) {
            if (strtolower($contact['type'] ?? '') === $type) {
                return $contact['value'] ?? '';
            }
        }
        return '';
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
                // 'filter' => [PF_ID_FIELD_ID => $listingId],
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

    // public function getResponsiblePersonId(?string $propertyReference): ?int
    // {
    //     try {
    //         if (!$propertyReference) return null;

    //         $params = [
    //             'entityTypeId' => (int) LISTINGS_ENTITY_TYPE_ID,
    //             'filter' => [REFERENCE_FIELD_ID => $propertyReference],
    //             // 'select' => [OWNER_ID_FIELD_ID, OWNER_NAME_FIELD_ID, OWNER_NAME_FIELD_ID_ALT,  AGENT_EMAIL_FIELD_ID]
    //         ];

    //         $response = CRest::call("crm.item.list", $params);

    //         Logger::log([
    //             'event' => 'external.contact.api.response',
    //             'reference' => $propertyReference,
    //             'params' => $params,
    //             'response_raw' => $response
    //         ]);

    //         if (!$response || empty($response['result'])) {
    //             Logger::log([
    //                 'event' => 'external.contact.api.error',
    //                 'reference' => $propertyReference,
    //                 'http_code' => null,
    //                 'error' => $response['error'] ?? 'No response',
    //                 'response_raw' => $response
    //             ]);
    //             return null;
    //         }

    //         $contactData = $response['result']['items'][0] ?? [];

    //         if (!empty($contactData[OWNER_ID_FIELD_ID])) {
    //             return (int)$contactData[OWNER_ID_FIELD_ID];
    //         }

    //         $ownerName = $contactData[OWNER_NAME_FIELD_ID] ?? $contactData[OWNER_NAME_FIELD_ID_ALT] ?? null;
    //         $agentEmail = $contactData[AGENT_EMAIL_FIELD_ID] ?? null;

    //         if (!$ownerName && !$agentEmail) {
    //             Logger::log([
    //                 'event' => 'contact.emails.missing',
    //                 'reference' => $propertyReference,
    //                 'response' => $contactData
    //             ]);
    //             return null;
    //         }

    //         // First try ownerName
    //         if ($ownerName) {
    //             $nameParts = preg_split('/\s+/', trim($ownerName));

    //             if (count($nameParts) >= 2) {
    //                 for ($i = 1; $i < count($nameParts); $i++) {
    //                     $firstName = implode(' ', array_slice($nameParts, 0, $i));
    //                     $lastName = implode(' ', array_slice($nameParts, $i));

    //                     $userId = $this->getUserId([
    //                         '%NAME' => $firstName,
    //                         '%LAST_NAME' => $lastName,
    //                         '!ID' => IDS_TO_IGNORE
    //                     ]);

    //                     if ($userId) {
    //                         return $userId;
    //                     }
    //                 }
    //             } else {
    //                 $userId = $this->getUserId([
    //                     '%NAME' => $ownerName,
    //                 ]);

    //                 if ($userId) {
    //                     return $userId;
    //                 }
    //             }
    //         }

    //         // Fallback to agent_email
    //         if ($agentEmail) {
    //             $userId = $this->getUserId([
    //                 '%EMAIL' => $agentEmail,
    //             ]);

    //             if ($userId) {
    //                 return $userId;
    //             }
    //         }

    //         Logger::log([
    //             'event' => 'bitrix.user.not.found.by.email',
    //             'reference' => $propertyReference,
    //             'owner_name' => $ownerName,
    //             'agent_email' => $agentEmail
    //         ]);

    //         return null;
    //     } catch (\Exception $e) {
    //         Logger::log([
    //             'event' => 'get.responsible.person.error',
    //             'error' => $e->getMessage(),
    //             'reference' => $propertyReference
    //         ]);
    //         return null;
    //     }
    // }

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
