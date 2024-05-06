<?php

global $campaign_arr;
require_once('application/campaign_array.php');
require_once('application/RTBCampaignManager.php');

// Example usage
$bidRequestJson = file_get_contents(__DIR__ . '/application/bid_request.json');
if ($bidRequestJson === false || !count($campaign_arr)) {
    echo json_encode('Error: Unable to load/read the files.');
    die();
}
$rtbManager = new RtbCampaignManager($bidRequestJson, $campaign_arr);
$response = $rtbManager->handleBidRequest();

if ($response) {
    echo json_encode($response);
} else {
    echo "No eligible campaign found";
}