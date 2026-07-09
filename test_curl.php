<?php
if (function_exists('curl_version')) {
    echo "✅ cURL is ENABLED in your browser!<br>";
    print_r(curl_version());
} else {
    echo "❌ cURL is still DISABLED in your browser.";
}
?>