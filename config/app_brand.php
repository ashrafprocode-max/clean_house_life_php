<?php
// ============================================================
// ملف: config/app_brand.php
// الغرض: الاسم الظاهر في رسائل OTP والإشعارات النصية
//
// ⚠️ غيّر $appBrand فقط. لا تغيّر قواعد البيانات ولا مسارات API
// قيم مسموحة: clean_house_life | source_services
// ============================================================

$appBrand = 'clean_house_life';

$appBrands = [
    'clean_house_life' => [
        'id' => 'clean_house_life',
        'name_ar' => 'حياة منزلية نظيفة',
        'name_en' => 'Clean House Life',
    ],
    'source_services' => [
        'id' => 'source_services',
        'name_ar' => 'سورس سيرفيسز',
        'name_en' => 'Source Services',
    ],
];

if (!isset($appBrands[$appBrand])) {
    // الهوية الظاهرة في رسائل OTP؛ غيّر هذا السطر فقط للعودة إلى clean_house_life
    $appBrand = 'source_services';
}

return $appBrands[$appBrand];
