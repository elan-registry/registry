<?php

use ElanRegistry\LogCategories;

logger(0, 'Literal', 'message');
logger(0, LogCategories::LOG_CATEGORY_SECURITY, 'message');
logger(0, $flag ? 'Literal' : LogCategories::LOG_CATEGORY_SECURITY, 'message');
logger(0, $entry['category'], 'message');
logger(0, "Interpolated $suffix", 'message');
logger(lognote: 'message', logtype: 'Named', user_id: 0);
logger(0, $state === '42S02' ? LogCategories::LOG_CATEGORY_SECURITY : LogCategories::LOG_CATEGORY_EMAIL_ERROR, 'message');
logger(0, 'Prefix' . $suffix, 'message');
$response->withLogging(1, 'Literal', 'message');
$response?->withLogging(1, LogCategories::LOG_CATEGORY_SECURITY, 'message');
$response->withLogging(1, $category ?? 'Fallback', 'message');
