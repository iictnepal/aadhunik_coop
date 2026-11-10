<?php
/** Receives the online membership, loan and job application forms */
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/applications.php';

$type = in_array($_POST['form'] ?? '', ['loan', 'job'], true) ? $_POST['form'] : 'membership';
$back = match ($type) {
    'loan' => 'index.php?page=loan-form',
    'job' => 'index.php?page=notices&cat=vacancy',
    default => 'index.php?page=membership-form',
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit;
}

/** Re-show the form with errors and the visitor's answers (files must be chosen again) */
function fail(array $errors, string $back): never {
    $old = $_POST;
    unset($old['csrf_token'], $old['website'], $old['form_ts']);
    $_SESSION['apply_old'] = $old;
    $_SESSION['apply_errors'] = $errors;
    header('Location: ' . $back . '#applyForm');
    exit;
}

if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    fail(['_' => tr('The files are too large to send together. Please use smaller photos (under 5 MB each).', 'फाइलहरू धेरै ठूला भए। ५ MB भन्दा साना फोटो प्रयोग गर्नुहोस्।')], $back);
}
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    fail(['_' => tr('Your session expired. Please submit the form again.', 'तपाईंको सत्र समाप्त भयो। कृपया फारम फेरि बुझाउनुहोस्।')], $back);
}
// Spam guards: hidden "website" field must stay empty; humans need a few seconds to fill the form
$ts = (int)($_SESSION['apply_form_ts'][$type] ?? 0);
if (trim((string)($_POST['website'] ?? '')) !== '' || ($ts && time() - $ts < 4)) {
    $_SESSION['apply_done'] = ['type' => $type, 'ref' => null];
    header('Location: index.php?page=apply-success');
    exit;
}

ensure_application_tables($pdo);
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$table = ['loan' => 'loan_applications', 'job' => 'job_applications'][$type] ?? 'membership_applications';
$st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE ip_address=? AND created_at > (NOW() - INTERVAL 1 HOUR)");
$st->execute([$ip]);
if ($st->fetchColumn() >= 5) {
    fail(['_' => tr('Too many applications were sent from this connection. Please try again later or visit our office.', 'यस इन्टरनेटबाट धेरै आवेदन पठाइयो। केही समयपछि प्रयास गर्नुहोस् वा कार्यालयमा आउनुहोस्।')], $back);
}

$in = fn(string $k, int $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
$errors = [];
$req = function (string $k, string $msgEn, string $msgNe) use (&$errors, $in) {
    if ($in($k) === '') $errors[$k] = tr($msgEn, $msgNe);
};
$phoneOk = fn(string $p) => (bool)preg_match('/^\+?[0-9][0-9 \-]{6,18}$/', $p);
$saved = [];

if ($type === 'membership') {
    $req('full_name_en', 'Please enter your full name.', 'आफ्नो पूरा नाम लेख्नुहोस्।');
    $req('father_name', "Please enter your father's name.", 'बुबाको नाम लेख्नुहोस्।');
    $req('citizenship_no', 'Please enter your citizenship number.', 'नागरिकता नम्बर लेख्नुहोस्।');
    $req('permanent_address', 'Please enter your permanent address.', 'स्थायी ठेगाना लेख्नुहोस्।');
    if (!in_array($_POST['gender'] ?? '', ['male', 'female', 'other'], true)) $errors['gender'] = tr('Please choose gender.', 'लिङ्ग छान्नुहोस्।');
    if (!valid_ymd($in('date_of_birth'))) $errors['date_of_birth'] = tr('Enter date of birth as YYYY-MM-DD, e.g. 2050-05-12.', 'जन्म मिति YYYY-MM-DD ढाँचामा लेख्नुहोस्, जस्तै: २०५०-०५-१२।');
    if (!in_array($in('citizenship_district'), NEPAL_DISTRICTS, true)) $errors['citizenship_district'] = tr('Please choose the district.', 'जिल्ला छान्नुहोस्।');
    if (!$phoneOk($in('phone'))) $errors['phone'] = tr('Please enter a valid phone number.', 'मान्य फोन नम्बर लेख्नुहोस्।');
    if ($in('email') !== '' && !filter_var($in('email'), FILTER_VALIDATE_EMAIL)) $errors['email'] = tr('Please enter a valid email.', 'मान्य इमेल लेख्नुहोस्।');
    $shares = (int)($_POST['share_count'] ?? 0);
    if ($shares < 1 || $shares > 100000) $errors['share_count'] = tr('Enter the number of shares (at least 1).', 'सेयर संख्या लेख्नुहोस् (कम्तीमा १)।');
    if (empty($_POST['agree'])) $errors['agree'] = tr('Please confirm the declaration.', 'घोषणा स्वीकार गर्नुहोस्।');

    if (!$errors) {
        foreach (['photo_path' => [true, true], 'citizenship_front_path' => [false, true], 'citizenship_back_path' => [false, false]] as $field => [$imgOnly, $required]) {
            try {
                $p = store_private_upload($field, 'membership', $imgOnly);
                if ($p) $saved[$field] = $p;
                elseif ($required) $errors[$field] = tr('Please attach this file.', 'यो फाइल संलग्न गर्नुहोस्।');
            } catch (RuntimeException $e) {
                $errors[$field] = $e->getMessage();
            }
        }
    }
    if ($errors) {
        foreach ($saved as $p) delete_private_file($p);
        fail($errors, $back);
    }
    $ref = next_ref_no($pdo, 'membership_applications', 'MA');
    $pdo->prepare("INSERT INTO membership_applications (ref_no, full_name_en, full_name_ne, gender, date_of_birth, father_name, grandfather_name, spouse_name,
        citizenship_no, citizenship_district, phone, email, occupation, permanent_address, temporary_address, nominee_name, nominee_relation,
        share_count, photo_path, citizenship_front_path, citizenship_back_path, ip_address) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$ref, $in('full_name_en', 150), $in('full_name_ne', 150) ?: null, $_POST['gender'], $in('date_of_birth', 20), $in('father_name', 150),
            $in('grandfather_name', 150) ?: null, $in('spouse_name', 150) ?: null, $in('citizenship_no', 50), $in('citizenship_district', 60),
            $in('phone', 30), $in('email', 120) ?: null, $in('occupation', 100) ?: null, $in('permanent_address'), $in('temporary_address') ?: null,
            $in('nominee_name', 150) ?: null, $in('nominee_relation', 60) ?: null, $shares,
            $saved['photo_path'] ?? null, $saved['citizenship_front_path'] ?? null, $saved['citizenship_back_path'] ?? null, $ip]);
    log_audit($pdo, 'system', null, 'MEMBERSHIP_APPLICATION', "New online membership application {$ref} from " . $in('full_name_en', 150));
} elseif ($type === 'job') {
    // position: one of the open vacancy notices, or a general application
    $noticeId = (int)($_POST['notice_id'] ?? 0);
    $position = null;
    foreach (open_vacancies($pdo) as $v) {
        if ((int)$v['id'] === $noticeId) $position = $v['title_en'];
    }
    if ($noticeId === 0 && ($_POST['notice_id'] ?? '') === '0') {
        $position = 'General application' . ($in('position_other', 150) !== '' ? ': ' . $in('position_other', 150) : '');
    }
    if ($position === null) $errors['notice_id'] = tr('Please choose the position you are applying for.', 'आवेदन दिन चाहेको पद छान्नुहोस्।');
    $req('full_name', 'Please enter your full name.', 'आफ्नो पूरा नाम लेख्नुहोस्।');
    $req('address', 'Please enter your address.', 'ठेगाना लेख्नुहोस्।');
    $req('qualification', 'Please enter your highest education.', 'उच्चतम शैक्षिक योग्यता लेख्नुहोस्।');
    if (!in_array($_POST['gender'] ?? '', ['male', 'female', 'other'], true)) $errors['gender'] = tr('Please choose gender.', 'लिङ्ग छान्नुहोस्।');
    if (!valid_ymd($in('date_of_birth'))) $errors['date_of_birth'] = tr('Enter date of birth as YYYY-MM-DD, e.g. 2055-05-12.', 'जन्म मिति YYYY-MM-DD ढाँचामा लेख्नुहोस्, जस्तै: २०५५-०५-१२।');
    if (!$phoneOk($in('phone'))) $errors['phone'] = tr('Please enter a valid phone number.', 'मान्य फोन नम्बर लेख्नुहोस्।');
    if ($in('email') !== '' && !filter_var($in('email'), FILTER_VALIDATE_EMAIL)) $errors['email'] = tr('Please enter a valid email.', 'मान्य इमेल लेख्नुहोस्।');
    if (empty($_POST['agree'])) $errors['agree'] = tr('Please confirm the declaration.', 'घोषणा स्वीकार गर्नुहोस्।');

    if (!$errors) {
        // [images only, required]
        foreach (['cv_path' => [false, true], 'photo_path' => [true, true], 'citizenship_path' => [false, true], 'certificate_path' => [false, false]] as $field => [$imgOnly, $required]) {
            try {
                $p = store_private_upload($field, 'jobs', $imgOnly);
                if ($p) $saved[$field] = $p;
                elseif ($required) $errors[$field] = tr('Please attach this file.', 'यो फाइल संलग्न गर्नुहोस्।');
            } catch (RuntimeException $e) {
                $errors[$field] = $e->getMessage();
            }
        }
    }
    if ($errors) {
        foreach ($saved as $p) delete_private_file($p);
        fail($errors, $back);
    }
    $ref = next_ref_no($pdo, 'job_applications', 'JA');
    $pdo->prepare("INSERT INTO job_applications (ref_no, notice_id, position, full_name, gender, date_of_birth, phone, email, address, qualification,
        experience, cover_letter, cv_path, photo_path, citizenship_path, certificate_path, ip_address) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$ref, $noticeId ?: null, mb_substr($position, 0, 255), $in('full_name', 150), $_POST['gender'], $in('date_of_birth', 20), $in('phone', 30),
            $in('email', 120) ?: null, $in('address'), $in('qualification'), $in('experience', 3000) ?: null, $in('cover_letter', 5000) ?: null,
            $saved['cv_path'] ?? null, $saved['photo_path'] ?? null, $saved['citizenship_path'] ?? null, $saved['certificate_path'] ?? null, $ip]);
    log_audit($pdo, 'system', null, 'JOB_APPLICATION', "New online job application {$ref} from " . $in('full_name', 150) . " for {$position}");
} else {
    $req('applicant_name', 'Please enter your full name.', 'आफ्नो पूरा नाम लेख्नुहोस्।');
    $req('address', 'Please enter your address.', 'ठेगाना लेख्नुहोस्।');
    $req('citizenship_no', 'Please enter your citizenship number.', 'नागरिकता नम्बर लेख्नुहोस्।');
    $req('purpose', 'Please describe the purpose of the loan.', 'कर्जाको उद्देश्य लेख्नुहोस्।');
    if (!$phoneOk($in('phone'))) $errors['phone'] = tr('Please enter a valid phone number.', 'मान्य फोन नम्बर लेख्नुहोस्।');
    if ($in('email') !== '' && !filter_var($in('email'), FILTER_VALIDATE_EMAIL)) $errors['email'] = tr('Please enter a valid email.', 'मान्य इमेल लेख्नुहोस्।');
    $st = $pdo->prepare("SELECT * FROM loan_products WHERE id=? AND status='active'");
    $st->execute([(int)($_POST['loan_product_id'] ?? 0)]);
    $product = $st->fetch();
    if (!$product) $errors['loan_product_id'] = tr('Please choose a loan type.', 'कर्जाको प्रकार छान्नुहोस्।');
    $amount = (float)preg_replace('/[^\d.]/', '', (string)($_POST['amount'] ?? ''));
    if ($amount < 1000 || $amount > 1000000000) $errors['amount'] = tr('Enter the loan amount in rupees (at least 1,000).', 'कर्जा रकम रुपैयाँमा लेख्नुहोस् (कम्तीमा १,०००)।');
    $tenure = (int)($_POST['tenure_months'] ?? 0);
    $maxT = $product ? (int)$product['max_tenure_months'] : 360;
    if ($tenure < 1 || $tenure > $maxT) $errors['tenure_months'] = tr("Enter a period between 1 and {$maxT} months.", "१ देखि " . to_nepali_number($maxT) . " महिनाको अवधि लेख्नुहोस्।");
    $income = trim((string)($_POST['monthly_income'] ?? '')) === '' ? null : (float)preg_replace('/[^\d.]/', '', (string)$_POST['monthly_income']);
    $memberCode = $in('member_code', 50);
    if ($memberCode !== '') {
        $st = $pdo->prepare("SELECT member_code FROM members WHERE member_code=?");
        $st->execute([$memberCode]);
        if (!$st->fetchColumn()) $errors['member_code'] = tr('This member code was not found. Leave it empty if you are not a member yet.', 'यो सदस्य कोड भेटिएन। सदस्य हुनुहुन्न भने खाली छोड्नुहोस्।');
    }
    if (empty($_POST['agree'])) $errors['agree'] = tr('Please confirm the declaration.', 'घोषणा स्वीकार गर्नुहोस्।');

    if (!$errors) {
        foreach (['citizenship_doc_path' => true, 'supporting_doc_path' => false] as $field => $required) {
            try {
                $p = store_private_upload($field, 'loan');
                if ($p) $saved[$field] = $p;
                elseif ($required) $errors[$field] = tr('Please attach this file.', 'यो फाइल संलग्न गर्नुहोस्।');
            } catch (RuntimeException $e) {
                $errors[$field] = $e->getMessage();
            }
        }
    }
    if ($errors) {
        foreach ($saved as $p) delete_private_file($p);
        fail($errors, $back);
    }
    $ref = next_ref_no($pdo, 'loan_applications', 'LA');
    $pdo->prepare("INSERT INTO loan_applications (ref_no, applicant_name, member_code, phone, email, address, citizenship_no, loan_product_id, loan_product_name,
        amount, tenure_months, interest_rate, estimated_emi, purpose, occupation, monthly_income, collateral, guarantor_name, guarantor_phone,
        citizenship_doc_path, supporting_doc_path, ip_address) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$ref, $in('applicant_name', 150), $memberCode ?: null, $in('phone', 30), $in('email', 120) ?: null, $in('address'), $in('citizenship_no', 50),
            $product['id'], $product['name_en'], $amount, $tenure, $product['interest_rate'], loan_emi($amount, (float)$product['interest_rate'], $tenure),
            $in('purpose', 2000), $in('occupation', 100) ?: null, $income, $in('collateral', 2000) ?: null, $in('guarantor_name', 150) ?: null,
            $in('guarantor_phone', 30) ?: null, $saved['citizenship_doc_path'] ?? null, $saved['supporting_doc_path'] ?? null, $ip]);
    log_audit($pdo, 'system', null, 'LOAN_APPLICATION', "New online loan application {$ref} from " . $in('applicant_name', 150) . ' for NPR ' . number_format($amount));
}

unset($_SESSION['apply_old'], $_SESSION['apply_errors'], $_SESSION['apply_form_ts'][$type]);
$_SESSION['apply_done'] = ['type' => $type, 'ref' => $ref];
header('Location: index.php?page=apply-success');
