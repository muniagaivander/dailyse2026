<?php
require __DIR__ . '/bootstrap.php';
session_destroy();
redirect('login.php');

