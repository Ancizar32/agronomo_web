<?php
session_start();
session_unset();
session_destroy();
setcookie("user_id", null, -1, '/');
setcookie("user_text", null, -1, '/');
setcookie("token_session", null, -1, '/');
setcookie("new_session_id", null, -1, '/');
setcookie("app_web", null, -1, '/');
setcookie("farm", null, -1, '/');
// setcookie("app_web", null, -1, '/');
header('Location: ../');
