<?php
require_once(__DIR__ . '/../vendor/autoload.php');
$reeditor = new Reeditor();

$input = [
    'reddit_url' => 'https://www.reddit.com/r/MaliciousCompliance/comments/u3668f/our_landlord_told_me_go_ahead_call_the_city/',
    'max_comment_count' => 50,
    'target_image_count' => 10,
];

$output = $reeditor->storyToImages(
    $input['reddit_url'],
    $input['max_comment_count'],
    $input['target_image_count']
);
var_dump($output);




