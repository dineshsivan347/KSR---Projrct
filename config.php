<?php

// Paths and application constants
$dbFile = __DIR__ . '/ksrei.db';
$roomsDir = __DIR__ . '/rooms';
$uploadsDir = __DIR__ . '/uploads';
$collegeName = "K.S.R. College of Engineering (KSREI)";
$collegeLogoUrl = "rooms/logo.png";
$adminSigPath = "uploads/sign.png";

// Ensure directories exist
if (!is_dir($roomsDir)) mkdir($roomsDir, 0755, true);
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);


