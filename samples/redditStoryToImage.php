<?php
require_once(__DIR__ . '/../vendor/autoload.php');
$reeditor = new Reeditor();

$input = [
    'reddit_url' => 'https://www.reddit.com/r/AmItheAsshole/comments/mk8w2s/aita_for_making_neighbor_remove_the_eggs_he_put/',
    'max_comment_count' => 15,
    'target_image_count' => 15,
];

$output = $reeditor->storyToImages(
    $input['reddit_url'],
    $input['max_comment_count'],
    $input['target_image_count']
);
var_dump($output);




