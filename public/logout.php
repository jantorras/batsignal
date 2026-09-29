<?php
require __DIR__ . '/../app/bootstrap.php';

use BatSignal\Auth;

Auth::logout();
header('Location: login.php');
exit;
