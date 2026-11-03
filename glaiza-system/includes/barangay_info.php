<?php
// includes/barangay_info.php — editable reference data (replace the sample values with your barangay's real ones)
$BRGY_HOTLINES = [
  ['Emergency (national)', '911'],
  ['Barangay Talisay Hotline', '(02) 555-0101'],
  ['Barangay Tanod 24/7', '(02) 555-0102'],
  ['Fire (BFP)', '(02) 555-0103'],
  ['Police (PNP)', '(02) 555-0104'],
  ['Health Center / Ambulance', '(02) 555-0105'],
];
$BRGY_EVAC = ['Barangay Talisay Elementary School', 'Barangay Talisay Covered Court', 'Talisay Parish Hall'];
$BRGY_FEES = [ // service, fee, processing time, requirements
  ['Barangay Clearance', '₱50', 'Same day', 'Valid ID'],
  ['Certificate of Residency', '₱30', 'Same day', 'Valid ID, proof of address'],
  ['Certificate of Indigency', 'Free', 'Same day', 'Valid ID'],
  ['Business Permit', '₱300+', '2 working days', "DTI/Mayor's permit, valid ID, lease"],
  ['Barangay ID', '₱100', '3 working days (Tue, Thu)', 'Photo ID, proof of address'],
];
$BRGY_OFFICES = [ // office, person, phone, hours
  ['Punong Barangay', 'Hon. Maria L. Santos', '(02) 555-0101', 'Mon–Fri 8–5'],
  ['Secretary (records)', 'Ramon C. Dela Cruz', '(02) 555-0102', 'Mon–Fri 8–5'],
  ['Treasurer (payments)', 'Elena P. Reyes', '(02) 555-0103', 'Mon–Fri 8–4'],
  ['Lupon (mediation)', 'Atty. Jose Aquino', '(02) 555-0104', 'Mon, Wed, Fri 9–4'],
  ['Health Center', 'Nurse Ana Ramos', '(02) 555-0105', 'Mon–Sat 8–5'],
  ['Tanod', 'Duty officer', '(02) 555-0102', '24/7'],
];
$BRGY_HOURS = [
  ['Mon–Fri', '8–5 General services'],
  ['Tue & Thu', 'Barangay ID applications'],
  ['Sat', '8–12 Health Center only'],
  ['Sun', 'Closed (emergency line open)']
];
function telHref(string $n): string { return 'tel:' . preg_replace('/[^\d+]/', '', $n); }
