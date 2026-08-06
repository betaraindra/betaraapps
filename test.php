<?php
$clean_notes = 'Aktivitas [COORDS: abc] [PHOTOS: ["uploads/img.jpg"]]';
$coords = '';
$photos_json = '';

if (preg_match('/\[COORDS:\s*(.*?)\]/', $clean_notes, $matches)) {
    $coords = $matches[1];
    $clean_notes = str_replace($matches[0], '', $clean_notes);
}
if (preg_match('/\[PHOTOS:\s*(\[.*?\])\]/', $clean_notes, $matches)) {
    $photos_json = $matches[1];
    $clean_notes = str_replace($matches[0], '', $clean_notes);
}

var_dump($coords);
var_dump($photos_json);
