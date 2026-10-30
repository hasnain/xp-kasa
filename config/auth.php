<?php

/**
 * Hardcoded login for this internal tool (no database).
 * Change the username/password here, or set XPUNCH_USER / XPUNCH_PASS in .env
 * and leave password_hash empty to use a plain password from .env (hashed at runtime check).
 */
return [
    // Default local login — change before putting on a public server.
    'username' => getenv('XPUNCH_USER') ?: 'admin',
    // password: xpunch2026
    'password_hash' => getenv('XPUNCH_PASS_HASH') ?: '$2y$10$NZNqbhhjmoRLHqrb/AfYueBpeyYGChV6pUH5MStD8aLXowUv.s4f.',
    // Optional plain password from env (used only if password_hash is empty).
    'password' => getenv('XPUNCH_PASS') ?: '',
];
