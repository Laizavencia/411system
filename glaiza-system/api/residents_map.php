<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
requireAdminLogin();
header('Content-Type: application/json');
echo json_encode(['residents' => dbFetchAll(
  "SELECT CONCAT(first_name,' ',last_name) AS full_name, zone_purok, latitude, longitude
   FROM residents WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND status<>'Inactive'")]);
