<?php

class RTBCampaignManager
{
    private $bidRequest;
    private $campaigns;

    public function __construct($bidRequest, array $campaigns)
    {
        $this->bidRequest = $bidRequest;
        $this->campaigns = $campaigns;
    }

    public function handleBidRequest(): false|string
    {
        // Parse bid request
        $bidRequestData = $this->parseBidRequest();

        // Validate and extract relevant parameters
        $validationErrors = [];
        $device = $this->validateDevice($bidRequestData, $validationErrors);
        $geo = $this->validateGeo($bidRequestData, $validationErrors);
        $adFormatArr = $this->validateAdFormat($bidRequestData, $validationErrors);
        $bidFloor = $this->validateBidFloor($bidRequestData, $validationErrors);

        if ($validationErrors) {
            return json_encode([
                'error' => 'Invalid bid request: ' . implode(', ', $validationErrors),
            ]);
        }


        // Select the most suitable campaign
        $selectedCampaign = $this->selectCampaign($device, $geo, $adFormatArr, $bidFloor);
        if (empty($selectedCampaign)) {
            return json_encode([
                'error' => 'No banner found: ',
            ]);
        }
        // Generate JSON response
        return $this->generateResponse($selectedCampaign);
    }

    private function parseBidRequest(): array
    {
        // Use a JSON parsing library like json_decode
        return (array)json_decode($this->bidRequest);
    }

    private function validateDevice(array $bidRequestData, array &$validationErrors): array
    {
        // Extract device information
        $device = (array)$bidRequestData['device'];

        // Validate device make
        $deviceMakeFilter = explode(',', $device['make']);
        if ($deviceMakeFilter[0] !== 'No Filter' && !in_array($device['make'], $deviceMakeFilter)) {
            $validationErrors[] = 'Invalid device make';
            return [];
        }
        // Validate device OS and version
        $os = $device['os'];
        if (!$os) {
            $validationErrors[] = 'Invalid device os';
        }

        return $device;
    }

    private function validateGeo(array $bidRequestData, array &$validationErrors): array
    {
        // Extract geo information
        $geo = (array)$bidRequestData['device']->geo;
        // Validate geo parameters (country)

        if (empty($geo['country'])) {
            $validationErrors[] = 'Missing country information';
        }

        return $geo;
    }

    private function validateAdFormat(array $bidRequestData, array &$validationErrors): array
    {
        // Extract ad format information
        $adFormat = $bidRequestData['imp'][0]->banner->format;
        $adFormatArr = [];
        foreach ($adFormat as $item) {
            // Validate ad format dimensions (w, h)
            if (
                !property_exists($item, 'w')
                || !property_exists($item, 'h') ||
                !is_numeric($item->w) || !is_numeric($item->h)
            ) {
                $validationErrors[] = 'Invalid ad format dimensions';
                return [];
            }
            // If dimensions are valid, add them to the array
            $adFormatArr[] = ['w' => $item->w, 'h' => $item->h];
        }

        return $adFormatArr;
    }

    private function validateBidFloor(array $bidRequestData, array &$validationErrors): float
    {
        // Extract bid floor

        $bidFloor = $bidRequestData['imp'][0]->bidfloor ?? 0.00;

        // Validate bid floor value
        if ($bidFloor < 0) {
            $validationErrors[] = 'Invalid bid floor';
        }

        return $bidFloor;
    }

    private function selectCampaign(array $device, array $geo, array $adFormat, float $bidFloor)
    {
        $selectedCampaign = null;
        $highestBid = 0;

        foreach ($this->campaigns as $campaign) {
            // Check device compatibility
            $device_make = explode(',', strtolower($campaign['device_make']));
            if (count($device_make) > 0 && !in_array(strtolower('No Filter'), $device_make)) {
                if (!in_array(strtolower($device['make']), explode(',', strtolower($device['device_make'])))) {
                    continue;
                }
            }
            if (!in_array(strtolower($device['os']), explode(',', strtolower($campaign['hs_os'])))) {
                continue;
            }
            // Check geographical targeting
            if ($campaign['country'] !== $geo['country']) {
                continue;
            } else {
                if (!empty($campaign['city']) && !empty($geo['city'])) {
                    if (strtolower($campaign['city']) !== strtolower($geo['city'])) {
                        continue;
                    }
                }
                if ($campaign['lat'] && !empty($geo['lat'])) {
                    if ($campaign['lat'] !== $geo['lat']) {
                        continue;
                    }
                }

                if ($campaign['lng'] && !empty($geo['lon'])) {
                    if ($campaign['lon'] !== $geo['lon']) {
                        continue;
                    }
                }
            }
            // Check ad format compatibility
            $campaignDimensions = explode('x', $campaign['dimension']);

            if (!in_array(
                $campaignDimensions,
                array_map(function ($format) {
                    return [$format['w'], $format['h']];
                }, $adFormat)
            )) {
                continue;
            }
            // Check bid floor
            if ($campaign['price'] < $bidFloor) {
                continue;
            }
            // Update selected campaign if bid is higher
            if ($campaign['bidtype'] === 'CPM' && $campaign['price'] > $highestBid) {
                $selectedCampaign = $campaign;
                $highestBid = $campaign['price'];
            }
        }

        return $selectedCampaign;
    }

    private function generateResponse(array $selectedCampaign): false|string
    {
        if (!$selectedCampaign) {
            return false; // No campaign matched
        }

        $response = [
            // Banner response
            'id' => $selectedCampaign['code'],
            'imp_id' => $selectedCampaign['creative_id'],
            'price' => $selectedCampaign['price'],
            "adomain" => [$selectedCampaign["tld"]],
            'adm' => [
                'campaign_name' => $selectedCampaign['campaignname'],
                'app_id' => $selectedCampaign['appid'],
                'billing_id' => $selectedCampaign['billing_id'],
                'price' => $selectedCampaign['price'],
                'advertiser' => $selectedCampaign['advertiser'],
                'creative_type' => $selectedCampaign['creative_type'],
                'image_url' => $selectedCampaign['image_url'],
                'landing_page_url' => $selectedCampaign['url'],

            ],
        ];
        // Convert the response array to JSON format
        return json_encode($response);
    }
}