#!/usr/bin/env php
<?php

$baseDir = dirname(__DIR__);
require_once $baseDir . '/services/WebPushNotificationService.php';

$keys = WebPushNotificationService::createVapidKeys();

echo "WEB_PUSH_VAPID_PUBLIC_KEY=" . ($keys['publicKey'] ?? '') . PHP_EOL;
echo "WEB_PUSH_VAPID_PRIVATE_KEY=" . ($keys['privateKey'] ?? '') . PHP_EOL;
echo "WEB_PUSH_VAPID_SUBJECT=mailto:info@planrun.ru" . PHP_EOL;
