<?php
/**
 * gate_config.local.php  (KOPYA ito - i-rename to "gate_config.local.php")
 * ---------------------------------------------------------------------------
 * Lokal na config para sa gate integration. HINDI ito naka-commit (nasa
 * .gitignore) para magkaiba ang TEST at PRODUCTION nang hindi binabago ang
 * mga committed na file.
 *
 * Gumawa ng isa kada server:
 *   - floralelementsbyjm.shop (TEST, AWP kunwari) -> ituro sa mock + test keys (gaya sa baba)
 *   - ajmanwaterpark.com (PROD) -> ang totoong ProDynamics URL + AuthKey
 */

// ===== TEST: floralelementsbyjm.shop (AWP) -> faithuae.art (ProDynamics mock) ====
// I-upload ang AWP code dito sa floralelementsbyjm.shop, tapos ituro ang push:
define('GATE_API_URL',       'https://faithuae.art/test-data.php'); // mock = ProDynamics kunwari
define('GATE_AUTH_KEY',      'TESTKEY-AWP-2026');     // push AuthKey (kahit ano sa test)
// RETURN-FLOW TOKEN: dapat TUGMANG-TUGMA sa AWP_WEBHOOK_TOKEN sa loob ng
// gate-scanner.php (nasa faithuae.art). Ito ang sumisigurong galing nga sa
// gate ang mga scan na papasok sa gate_webhook.php.
define('GATE_WEBHOOK_TOKEN', 'AWP-GATE-K7p2Q9fz-2026');
// Pareho na PUBLIC + valid HTTPS ang dalawang site (walang XAMPP/self-signed
// cert issue), kaya itakda sa true. (false LANG kapag bumalik ka sa local/XAMPP.)
define('GATE_VERIFY_SSL',    true);

// ===== PRODUCTION (ajmanwaterpark.com) - i-uncomment kapag go-live ==========
// PUSH: ang GATE_AUTH_KEY ay ibibigay ng ProDynamics (yung tipong
// "LzLsW849cD+uiFttNiQrJuqeRxmk92CpRNeUQ0DfTFj6WodvDCVG8pTTo7dUBYGK").
// WEBHOOK: ang GATE_WEBHOOK_TOKEN sa baba ay yung ipinadala sa ProDynamics
// (nasa AWP_Gate_Webhook_Spec.pdf, Section 3). Dapat EXACTLY ito ang nakaset
// sa kanilang side bilang X-Gate-Token sa scan callbacks nila.
// define('GATE_API_URL',       'http://prodynamicsdxb.dyndns-web.com:5999/Service.svc/OnlineOrder');
// define('GATE_AUTH_KEY',      'PASTE-PRODUCTION-AUTHKEY-MULA-SA-PRODYNAMICS');
// define('GATE_WEBHOOK_TOKEN', 'un3gyWZLb+SNsem8jDL/bIaHt2JDfpZscrYecP30SqED0zf9JOm4CS8oKXQhGFFw');