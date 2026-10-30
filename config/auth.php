<?php

/**
 * Hardcoded login for this internal tool (no database).
 * Change the username/password here, or set XPUNCH_USER / XPUNCH_PASS_HASH in .env
 */
return [
    'username' => getenv('XPUNCH_USER') ?: 'xpadmin',
    'password_hash' => getenv('XPUNCH_PASS_HASH') ?: '$2y$10$NBibSF7SZ54MGE4T5F6FmeuGf8P3tegCi8JgeWz9/JPhc7stlhp62',
    'password' => getenv('XPUNCH_PASS') ?: '',
];
