/**
 * Build settings (extension/build.js): the Kadrobit the extension talks to and its OAuth client there
 * (config/extension.php).
 */
export const APP_URL = import.meta.env.VITE_EXTENSION_APP_URL.replace(/\/$/, '');

export const CLIENT_ID = import.meta.env.VITE_EXTENSION_CLIENT_ID;

export const SCOPE = 'extension';
