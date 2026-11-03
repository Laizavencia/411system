<?php
function generateTempPassword(int $length = 10): string {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

/**
 * Reset a resident's password to a new random temporary one and
 * flag the account so the resident is forced to set a permanent
 * password the next time they log in. Returns the plaintext
 * password ONCE — the admin is expected to hand it to the resident
 * directly, right after verifying who they are.
 */
function directResetResidentPassword(int $residentId): string {
    $tempPassword = generateTempPassword();
    dbExecute(
        "UPDATE residents SET password_hash=?, must_change_password=1 WHERE id=?",
        [password_hash($tempPassword, PASSWORD_BCRYPT), $residentId]
    );
    logActivity("Reset password for resident #$residentId (temporary password issued)", 'Password Reset');
    return $tempPassword;
}