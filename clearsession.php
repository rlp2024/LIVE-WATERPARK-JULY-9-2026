<?php
session_start();
session_unset();
session_destroy();
echo "Session Cleared! Pwede mo nang burahin ang file na ito.";
?>