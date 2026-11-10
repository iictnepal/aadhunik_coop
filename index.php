<?php
require_once __DIR__ . '/config/config.php';
if (empty($_GET['theme_preview'])) {
    track_visitor($pdo);   // anonymous unique-visitor counter (skips bots and admin theme previews)
}

function rows(string $sql, array $p = []): array {
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

/** FAQ rows; returns [] if the FAQ table has not been created yet (admin panel creates it). */
function faq_rows(string $where = '', array $p = []): array {
    try {
        return rows("SELECT * FROM faqs WHERE status='active' {$where} ORDER BY FIELD(category,'general','membership','savings','loans','online'), display_order, id", $p);
    } catch (PDOException $e) {
        return [];
    }
}

$view = (string)($_GET['page'] ?? 'home');
$L = $current_lang;
$orgName = setting('org_name');
$phone = setting('phone_primary');
$email = setting('email_official');
$logo = setting('logo');
$pageTitle = '';
$langUrl = function (string $lang): string {
    $q = $_GET;
    $q['lang'] = $lang;
    return 'index.php?' . http_build_query($q);
};

/* -------- data per page -------- */
$popup = null;
switch ($view) {
    case 'home':
        $slides = rows("SELECT * FROM sliders WHERE status='active' ORDER BY display_order, id");
        $savings = rows("SELECT * FROM savings_products WHERE status='active' ORDER BY display_order, id LIMIT 3");
        $loans = rows("SELECT * FROM loan_products WHERE status='active' ORDER BY display_order, id LIMIT 3");
        $notices = rows("SELECT * FROM notices WHERE status='published' AND published_date<=CURDATE() ORDER BY is_featured DESC, published_date DESC, id DESC LIMIT 3");
        $news = rows("SELECT * FROM news WHERE status='published' AND published_date<=CURDATE() ORDER BY published_date DESC, id DESC LIMIT 3");
        $homeFaqs = array_slice(faq_rows('AND is_featured=1'), 0, 6);
        $homeMessages = leadership_messages($pdo, true);
        $homeTestimonials = testimonials($pdo, true);
        $homeVideos = videos($pdo, 3);
        $homeGallery = rows("SELECT * FROM gallery_images ORDER BY is_featured DESC, id DESC LIMIT 3");
        $popup = rows("SELECT * FROM notices WHERE status='published' AND is_popup=1 AND published_date<=CURDATE() ORDER BY published_date DESC, id DESC LIMIT 1")[0] ?? null;
        break;
    case 'about':
        $pageTitle = setting('about_title');
        $leaders = leadership_messages($pdo);
        $teamGroups = team_groups($pdo);
        $team = rows("SELECT * FROM board_members WHERE status='active' ORDER BY " . team_group_order_sql($teamGroups) . ", display_order, id");
        break;
    case 'savings':
    case 'loans':
        $pageTitle = $view === 'savings' ? tr('Savings Products', 'बचत योजनाहरू') : tr('Loan Facilities', 'कर्जा सुविधाहरू');
        $items = rows("SELECT * FROM " . ($view === 'savings' ? 'savings_products' : 'loan_products') . " WHERE status='active' ORDER BY display_order, id");
        break;
    case 'notices':
        $pageTitle = tr('Notices & Announcements', 'सूचना तथा जानकारी');
        $cat = (string)($_GET['cat'] ?? '');
        $noticeCats = ['general' => tr('General', 'सामान्य'), 'agm' => tr('AGM', 'साधारण सभा'), 'financial' => tr('Financial', 'वित्तीय'), 'member' => tr('Member', 'सदस्य'), 'vacancy' => tr('Vacancy', 'रोजगारी'), 'holiday' => tr('Holiday', 'बिदा')];
        $items = isset($noticeCats[$cat])
            ? rows("SELECT * FROM notices WHERE status='published' AND published_date<=CURDATE() AND category=? ORDER BY published_date DESC, id DESC", [$cat])
            : rows("SELECT * FROM notices WHERE status='published' AND published_date<=CURDATE() ORDER BY published_date DESC, id DESC");
        if ($cat === 'vacancy') {   // job application form under the vacancy list
            require_once APP_ROOT . '/includes/applications.php';
            ensure_application_tables($pdo);
            $vacancies = open_vacancies($pdo);
            $old = $_SESSION['apply_old'] ?? [];
            $errs = $_SESSION['apply_errors'] ?? [];
            unset($_SESSION['apply_old'], $_SESSION['apply_errors']);
            $_SESSION['apply_form_ts']['job'] = time();
        }
        break;
    case 'notice':
        $item = rows("SELECT * FROM notices WHERE id=? AND status='published'", [(int)($_GET['id'] ?? 0)])[0] ?? null;
        if ($item) {
            $pdo->prepare("UPDATE notices SET views_count=views_count+1 WHERE id=?")->execute([$item['id']]);
            $pageTitle = lf($item, 'title');
        }
        break;
    case 'news':
        $pageTitle = tr('News & Events', 'समाचार तथा गतिविधि');
        $items = rows("SELECT * FROM news WHERE status='published' AND published_date<=CURDATE() ORDER BY published_date DESC, id DESC");
        break;
    case 'article':
        $item = rows("SELECT * FROM news WHERE slug=? AND status='published'", [(string)($_GET['slug'] ?? '')])[0] ?? null;
        $pageTitle = $item ? lf($item, 'title') : '';
        break;
    case 'gallery':
        $pageTitle = tr('Photo Gallery', 'फोटो ग्यालरी');
        $albums = rows("SELECT c.*, COUNT(i.id) n FROM gallery_categories c JOIN gallery_images i ON i.category_id=c.id GROUP BY c.id ORDER BY c.id");
        $album = (int)($_GET['album'] ?? 0);
        $items = $album
            ? rows("SELECT * FROM gallery_images WHERE category_id=? ORDER BY is_featured DESC, id DESC", [$album])
            : rows("SELECT * FROM gallery_images ORDER BY is_featured DESC, id DESC");
        break;
    case 'videos':
        $pageTitle = tr('Video Gallery', 'भिडियो ग्यालरी');
        $items = videos($pdo);
        break;
    case 'downloads':
        $pageTitle = tr('Downloads Center', 'डाउनलोड केन्द्र');
        $dlCats = ['forms' => tr('Forms', 'फारमहरू'), 'reports' => tr('Reports', 'प्रतिवेदनहरू'), 'bylaws' => tr('Bylaws & Policies', 'विधान तथा नीति'), 'financials' => tr('Financial Statements', 'वित्तीय विवरण'), 'other' => tr('Other', 'अन्य')];
        $items = rows("SELECT * FROM downloads WHERE status='active' ORDER BY FIELD(category,'forms','reports','bylaws','financials','other'), id DESC");
        break;
    case 'contact':
        $pageTitle = tr('Contact Us', 'सम्पर्क गर्नुहोस्');
        break;
    case 'faq':
        $pageTitle = tr('Frequently Asked Questions', 'बारम्बार सोधिने प्रश्नहरू');
        $faqs = faq_rows();
        break;
    case 'apply':
        $pageTitle = tr('Apply Online', 'अनलाइन आवेदन');
        break;
    case 'date-converter':
        $pageTitle = tr('Nepali Date Converter (BS ⇄ AD)', 'मिति रूपान्तरण (वि.सं. ⇄ ई.सं.)');
        $metaDescription = tr('Convert Nepali (Bikram Sambat) dates to English dates and back.', 'विक्रम संवत् र इस्वी संवत् मिति एकअर्कामा रूपान्तरण गर्नुहोस्।');
        break;
    case 'note-counter':
        $pageTitle = tr('Cash Note Counter', 'नोट गन्ती क्याल्कुलेटर');
        $metaDescription = tr('Count Nepali rupee notes quickly and see the total in figures and words.', 'नेपाली रुपैयाँका नोट छिटो गन्नुहोस् र जम्मा रकम अंक तथा अक्षरमा हेर्नुहोस्।');
        break;
    case 'messages':
        $pageTitle = tr('Messages From Our Leadership', 'नेतृत्वको सन्देश');
        $leaders = leadership_messages($pdo);
        break;
    case 'membership-form':
    case 'loan-form':
        require_once APP_ROOT . '/includes/applications.php';
        $formType = $view === 'loan-form' ? 'loan' : 'membership';
        $pageTitle = $formType === 'loan' ? tr('Online Loan Application', 'अनलाइन कर्जा आवेदन') : tr('Online Membership Application', 'अनलाइन सदस्यता आवेदन');
        $old = $_SESSION['apply_old'] ?? [];
        $errs = $_SESSION['apply_errors'] ?? [];
        unset($_SESSION['apply_old'], $_SESSION['apply_errors']);
        $_SESSION['apply_form_ts'][$formType] = time();
        $loanProducts = $formType === 'loan' ? rows("SELECT id, name_en, name_ne, interest_rate, max_tenure_months, service_charge FROM loan_products WHERE status='active' ORDER BY display_order, id") : [];
        break;
    case 'apply-success':
        $done = $_SESSION['apply_done'] ?? null;
        unset($_SESSION['apply_done']);
        $pageTitle = tr('Application received', 'आवेदन प्राप्त भयो');
        break;
    default:
        $cmsPage = find_page($pdo, $view);
        if ($cmsPage) {
            $pageTitle = lf($cmsPage, 'title');
            $metaDescription = lf($cmsPage, 'summary');
            $view = 'cms';
            break;
        }
        http_response_code(404);
        $view = '404';
        $pageTitle = tr('Page not found', 'पृष्ठ भेटिएन');
}

$nav = [
    'home' => tr('Home', 'गृहपृष्ठ'), 'about' => tr('About', 'हाम्रो बारेमा'), 'savings' => tr('Savings', 'बचत'), 'loans' => tr('Loans', 'कर्जा'),
    'notices' => tr('Notices', 'सूचना'), 'news' => tr('News', 'समाचार'), 'gallery' => tr('Gallery', 'ग्यालरी'), 'downloads' => tr('Downloads', 'डाउनलोड'), 'contact' => tr('Contact', 'सम्पर्क'),
];
$activeNav = ['videos' => 'gallery', 'notice' => 'notices', 'article' => 'news', 'membership-form' => 'apply', 'loan-form' => 'apply', 'apply-success' => 'apply'][$view] ?? $view;
$social = social_links();

/** First part of a message for previews; returns [text, wasCut] */
function excerpt(string $text, int $len): array {
    $text = trim($text);
    if (mb_strlen($text) <= $len) return [$text, false];
    $cut = mb_substr($text, 0, $len);
    $sp = max(mb_strrpos($cut, ' ') ?: 0, mb_strrpos($cut, '।') ?: 0);
    return [rtrim(mb_substr($cut, 0, $sp ?: $len), " ,।") . '…', true];
}

/** Photo of a leader, or a circle with their initial */
function leader_photo(array $m, string $class): string {
    if (!empty($m['photo_path'])) {
        return '<img class="' . $class . '" src="' . e(media_url($m['photo_path'])) . '" alt="' . e(lf($m, 'name')) . '" loading="lazy">';
    }
    return '<span class="' . $class . ' leaderInitial" aria-hidden="true">' . e(mb_substr(lf($m, 'name'), 0, 1)) . '</span>';
}

function page_hero(string $title, string $sub = ''): void { ?>
<section class="pageHero"><div class="wrap"><h1><?= e($title) ?></h1><?php if ($sub): ?><p><?= e($sub) ?></p><?php endif; ?></div></section>
<?php }
?>
<!doctype html>
<html lang="<?= e($L) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(($pageTitle ? $pageTitle . ' | ' : '') . $orgName) ?></title>
<meta name="description" content="<?= e(!empty($metaDescription) ? $metaDescription : setting('org_tagline')) ?>">
<?= favicon_tags() ?>
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
<?= theme_head_tags() ?>
</head>
<body class="<?= e(theme_body_class()) ?>">
<div class="top"><div class="wrap topIn">
  <span><?= e(setting('address')) ?><?php if (setting('office_hours')): ?> · <?= e(setting('office_hours')) ?><?php endif; ?></span>
  <span><?php if ($phone): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>">☎ <?= e($phone) ?></a><?php endif; ?><?php if ($email): ?> <a href="mailto:<?= e($email) ?>">✉ <?= e($email) ?></a><?php endif; ?></span>
</div></div>
<header class="header"><div class="wrap head">
  <a class="brand" href="index.php"><?php if ($logo): ?><img src="<?= e(media_url($logo)) ?>" alt="<?= e($orgName) ?>"><?php endif; ?><b><?= e($orgName) ?></b></a>
  <button class="menu" id="menu" aria-label="Menu">☰</button>
  <nav id="nav">
    <?php foreach ($nav as $k => $label): ?><a href="index.php<?= $k === 'home' ? '' : '?page=' . $k ?>" class="<?= $activeNav === $k ? 'current' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
    
    <a class="login" href="admin/"><?= tr('Staff login', 'कर्मचारी लगइन') ?></a>
     
    <span class="langSwitch"><a href="<?= e($langUrl('ne')) ?>" class="<?= $L === 'ne' ? 'on' : '' ?>">ने</a><a href="<?= e($langUrl('en')) ?>" class="<?= $L === 'en' ? 'on' : '' ?>">EN</a></span>
  </nav>
</div></header>
<?php if (isset($_SESSION['flash'])): ?><div class="wrap alert"><?= e($_SESSION['flash']); unset($_SESSION['flash']); ?></div><?php endif; ?>
<main>
<?php if ($view === 'home'): ?>

  <!-- ===================== HOMEPAGE: HERO SLIDER (falls back to static hero banner) ===================== -->
  
  <?php if ($slides): ?>
  <section class="slider" id="slider">
    <?php foreach ($slides as $i => $s): ?>
    <div class="slide <?= $i === 0 ? 'active' : '' ?>" style="background-image:linear-gradient(90deg,color-mix(in srgb,var(--c-overlay,#082e25) 87%,transparent),color-mix(in srgb,var(--c-overlay,#082e25) 25%,transparent)),url('<?= e(media_url($s['image_path'])) ?>')">
      <div class="wrap heroText">
        <h1><?= e(lf($s, 'title')) ?></h1>
        <?php if (lf($s, 'subtitle')): ?><p><?= e(lf($s, 'subtitle')) ?></p><?php endif; ?>
        <?php if (lf($s, 'button_text') && $s['button_link']): ?><a class="btn" href="<?= e($s['button_link']) ?>"><?= e(lf($s, 'button_text')) ?></a><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (count($slides) > 1): ?>
    <div class="dots"><?php foreach ($slides as $i => $s): ?><button class="<?= $i === 0 ? 'on' : '' ?>" data-i="<?= $i ?>" aria-label="Slide <?= $i + 1 ?>"></button><?php endforeach; ?></div>
    <?php endif; ?>
  </section>
  <?php else: ?>
  <section class="hero" style="background-image:linear-gradient(90deg,color-mix(in srgb,var(--c-overlay,#082e25) 87%,transparent),color-mix(in srgb,var(--c-overlay,#082e25) 33%,transparent)),url('<?= e(media_url(setting('hero_image'))) ?>')"><div class="wrap heroText">
    <span class="eyebrow"><?= e(setting('hero_eyebrow')) ?></span>
    <h1><?= e(setting('hero_title')) ?></h1>
    <p><?= e(setting('hero_subtitle')) ?></p>
    <?php if (setting('hero_button_text')): ?><a class="btn" href="<?= e(setting('hero_button_link')) ?>"><?= e(setting('hero_button_text')) ?></a><?php endif; ?>
  </div></section>
  <?php endif; ?>


<!-- ===================== HOMEPAGE: STATISTICS COUNTER ===================== -->
  <?php $statCount = 0; for ($i = 1; $i <= 4; $i++) { if (setting("stat_{$i}_value")) $statCount++; } if ($statCount): ?>
  <section class="numbers"><div class="wrap numGrid">
    <?php for ($i = 1; $i <= 4; $i++): if (!setting("stat_{$i}_value")) continue; ?>
      <div><b class="counter"><?= e($L === 'ne' ? to_nepali_number(setting("stat_{$i}_value")) : setting("stat_{$i}_value")) ?></b><span><?= e(setting("stat_{$i}_label")) ?></span></div>
    <?php endfor; ?>
  </div></section>
  <?php endif; ?>


  
  <!-- ===================== HOMEPAGE: HIGHLIGHTS ===================== -->
  <section class="wrap stats">
    <?php for ($i = 1; $i <= 3; $i++): if (!setting("highlight_{$i}_title")) continue; ?>
      <div><b><?= e(setting("highlight_{$i}_title")) ?></b><small><?= e(setting("highlight_{$i}_text")) ?></small></div>
    <?php endfor; ?>
  </section>


<!-- ===================== HOMEPAGE: ABOUT US ===================== -->
  <?php if (setting('about_intro')): [$aboutEx] = excerpt(setting('about_intro'), 600); ?>
  <section class="section wrap two homeAbout">
    <?php if (setting('about_image')): ?><img class="feature" src="<?= e(media_url(setting('about_image'))) ?>" alt="<?= e(setting('about_title')) ?>" loading="lazy"><?php endif; ?>
    <div>
      <div class="sectionHead"><div><small><?= tr('ABOUT US', 'हाम्रो बारेमा') ?></small><h2><?= e(setting('about_title')) ?></h2></div></div>
      <p><?= nl2br(e($aboutEx)) ?></p>
      <?php $aboutPoints = array_slice(lines(setting('core_values')), 0, 4); if ($aboutPoints): ?><ul class="ticks"><?php foreach ($aboutPoints as $v): ?><li><?= e($v) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <a class="btn" href="?page=about"><?= tr('Read more about us →', 'हाम्रो बारेमा थप पढ्नुहोस् →') ?></a>
    </div>
  </section>
  <?php endif; ?>

<!-- ===================== HOMEPAGE: LEADERSHIP MESSAGES ===================== -->
  <?php if ($homeMessages): ?>
  <section class="section wrap msgHome" id="leaderMessages">
    <div class="sectionHead"><div><small><?= tr('MESSAGES', 'सन्देश') ?></small><h2><?= tr('From Our Leadership', 'हाम्रो नेतृत्वबाट') ?></h2></div><?php if (count($homeMessages) > 1): ?><a href="?page=messages"><?= tr('All messages →', 'सबै सन्देश →') ?></a><?php endif; ?></div>
    <?php if (count($homeMessages) > 1): ?>
    <div class="msgTabs" role="tablist">
      <?php foreach ($homeMessages as $i => $m): ?>
      <a class="msgTab<?= $i === 0 ? ' on' : '' ?>" href="?page=messages#msg-<?= (int)$m['id'] ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="msgPanel-<?= (int)$m['id'] ?>" data-panel="msgPanel-<?= (int)$m['id'] ?>"><?= leader_photo($m, 'msgTabImg') ?><span><b><?= e(lf($m, 'name')) ?></b><small><?= e(lf($m, 'designation')) ?></small></span></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php foreach ($homeMessages as $i => $m): [$ex, $cut] = excerpt(lf($m, 'message'), 520); ?>
    <div class="two chair msgPanel" id="msgPanel-<?= (int)$m['id'] ?>" role="tabpanel"<?= $i > 0 ? ' hidden' : '' ?>>
      <?= leader_photo($m, 'feature leaderPhoto') ?>
      <div><h3 class="msgTitle"><?= e(lf($m, 'title')) ?></h3><blockquote><?= nl2br(e($ex)) ?></blockquote><p class="msgSign"><b><?= e(lf($m, 'name')) ?></b><span><?= e(lf($m, 'designation')) ?></span></p><?php if ($cut): ?><a class="btn small" href="?page=messages#msg-<?= (int)$m['id'] ?>"><?= tr('Read full message', 'पूरा सन्देश पढ्नुहोस्') ?> →</a><?php endif; ?></div>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>


<!-- ===================== HOMEPAGE: SAVINGS PRODUCTS ===================== -->
  <?php if ($savings): ?>
  <section class="section wrap"><div class="sectionHead"><div><small><?= tr('FINANCIAL SOLUTIONS', 'वित्तीय समाधान') ?></small><h2><?= tr('Our Savings Products', 'हाम्रा बचत योजनाहरू') ?></h2></div><a href="?page=savings"><?= tr('View all →', 'सबै हेर्नुहोस् →') ?></a></div>
    <div class="cards"><?php foreach ($savings as $p): ?><article class="card"><span class="rate"><?= e($L === 'ne' ? to_nepali_number($p['interest_rate']) : $p['interest_rate']) ?>% <?= tr('p.a.', 'वार्षिक') ?></span><h3><?= e(lf($p, 'name')) ?></h3><p><?= e(lf($p, 'summary')) ?></p><small><?= tr('Minimum balance', 'न्यूनतम मौज्दात') ?>: <?= e(format_money((float)$p['min_balance'], $L)) ?></small></article><?php endforeach; ?></div>
  </section>
  <?php endif; ?>

<!-- ===================== HOMEPAGE: PHOTO GALLERY ===================== -->
  <?php if ($homeGallery): ?>
  <section class="section wrap homeGallery">
    <div class="sectionHead"><div><small><?= tr('GALLERY', 'ग्यालरी') ?></small><h2><?= tr('Photo Gallery', 'फोटो ग्यालरी') ?></h2></div></div>
    <div class="gallery"><?php foreach ($homeGallery as $g): ?><figure><a href="<?= e(media_url($g['image_path'])) ?>" class="lightbox"><img src="<?= e(media_url($g['image_path'])) ?>" alt="<?= e(lf($g, 'title')) ?>" loading="lazy"></a><figcaption><?= e(lf($g, 'title')) ?></figcaption></figure><?php endforeach; ?></div>
    <p class="moreBtn"><a class="btn" href="?page=gallery"><?= tr('View more photos', 'थप फोटो हेर्नुहोस्') ?></a></p>
  </section>
  <?php endif; ?>

  <!-- ===================== HOMEPAGE: LOAN FACILITIES ===================== -->
  <?php if ($loans): ?>
  <section class="section wrap"><div class="sectionHead"><div><small><?= tr('CREDIT FACILITIES', 'कर्जा सुविधा') ?></small><h2><?= tr('Loan Facilities', 'कर्जा सुविधाहरू') ?></h2></div><a href="?page=loans"><?= tr('View all →', 'सबै हेर्नुहोस् →') ?></a></div>
    <div class="cards"><?php foreach ($loans as $p): ?><article class="card"><span class="rate"><?= e($L === 'ne' ? to_nepali_number($p['interest_rate']) : $p['interest_rate']) ?>%</span><h3><?= e(lf($p, 'name')) ?></h3><p><?= e(lf($p, 'summary')) ?></p><small><?= tr('Maximum tenure', 'अधिकतम अवधि') ?>: <?= e($L === 'ne' ? to_nepali_number($p['max_tenure_months']) : $p['max_tenure_months']) ?> <?= tr('months', 'महिना') ?></small></article><?php endforeach; ?></div>
  </section>
  <?php endif; ?>

  <!-- ===================== HOMEPAGE: NOTICES & LATEST NEWS ===================== -->
  <section class="section pale"><div class="wrap two topAlign">
    <div>
      <div class="sectionHead"><div><small><?= tr('LATEST UPDATES', 'ताजा जानकारी') ?></small><h2><?= tr('Notices', 'सूचनाहरू') ?></h2></div><a href="?page=notices"><?= tr('All notices →', 'सबै सूचना →') ?></a></div>
      <?php foreach ($notices as $n): ?><a class="noticeRow" href="?page=notice&id=<?= (int)$n['id'] ?>"><span class="date"><?= e(format_date($n['published_date'])) ?></span><b><?= e(lf($n, 'title')) ?></b></a><?php endforeach; ?>
      <?php if (!$notices): ?><p class="muted"><?= tr('No notices at the moment.', 'हाल कुनै सूचना छैन।') ?></p><?php endif; ?>
    </div>
    <div>
      <div class="sectionHead"><div><small><?= tr('NEWS & EVENTS', 'समाचार तथा गतिविधि') ?></small><h2><?= tr('Latest News', 'ताजा समाचार') ?></h2></div><a href="?page=news"><?= tr('All news →', 'सबै समाचार →') ?></a></div>
      <?php foreach ($news as $n): ?><a class="newsRow" href="?page=article&slug=<?= e(urlencode($n['slug'])) ?>"><?php if ($n['featured_image']): ?><img src="<?= e(media_url($n['featured_image'])) ?>" alt="" loading="lazy"><?php endif; ?><span><small><?= e(format_date($n['published_date'])) ?></small><b><?= e(lf($n, 'title')) ?></b></span></a><?php endforeach; ?>
      <?php if (!$news): ?><p class="muted"><?= tr('No news yet.', 'हालसम्म कुनै समाचार छैन।') ?></p><?php endif; ?>
    </div>
  </div></section>

  

  <!-- ===================== HOMEPAGE: TESTIMONIALS ===================== -->
  <?php if ($homeTestimonials): ?>
  <section class="section testi" aria-labelledby="testiTitle"><div class="wrap">
    <div class="sectionHead"><div><small><?= tr('TESTIMONIALS', 'सदस्यहरूको अनुभव') ?></small><h2 id="testiTitle"><?= tr('What Our Members Say', 'हाम्रा सदस्यहरू के भन्छन्') ?></h2></div>
      <?php if (count($homeTestimonials) > 1): ?><div class="testiNav"><button type="button" class="testiArrow" data-dir="-1" aria-label="<?= tr('Previous', 'अघिल्लो') ?>">‹</button><button type="button" class="testiArrow" data-dir="1" aria-label="<?= tr('Next', 'अर्को') ?>">›</button></div><?php endif; ?>
    </div>
    <div class="testiTrack" id="testiTrack" tabindex="0" aria-label="<?= tr('Member testimonials', 'सदस्यहरूको अनुभव') ?>">
      <?php foreach ($homeTestimonials as $tm): $stars = (int)$tm['rating']; ?>
      <figure class="testiCard">
        <span class="testiQuote" aria-hidden="true">“</span>
        <?php if ($stars): ?><div class="testiStars" aria-label="<?= $stars ?> <?= tr('out of 5 stars', 'मध्ये ५ तारा') ?>"><?= str_repeat('★', $stars) ?><span><?= str_repeat('★', 5 - $stars) ?></span></div><?php endif; ?>
        <blockquote><?= nl2br(e(lf($tm, 'message'))) ?></blockquote>
        <figcaption>
          <?php if ($tm['photo_path']): ?><img src="<?= e(media_url($tm['photo_path'])) ?>" alt="" loading="lazy"><?php else: ?><span class="testiInitial" aria-hidden="true"><?= e(mb_substr(lf($tm, 'name'), 0, 1)) ?></span><?php endif; ?>
          <span><b><?= e(lf($tm, 'name')) ?></b><?php if (lf($tm, 'role')): ?><small><?= e(lf($tm, 'role')) ?></small><?php endif; ?></span>
        </figcaption>
      </figure>
      <?php endforeach; ?>
    </div>
  </div></section>
  <?php endif; ?>

  

  <!-- ===================== HOMEPAGE: VIDEO GALLERY ===================== -->
  <?php if ($homeVideos): ?>
  <section class="section wrap homeVideos">
    <div class="sectionHead"><div><small><?= tr('VIDEOS', 'भिडियो') ?></small><h2><?= e(setting('videos_title')) ?></h2></div></div>
    <div class="videoGrid"><?php foreach ($homeVideos as $v): ?><figure class="videoItem"><div class="videoBox"><?= video_player($v) ?></div><figcaption><?= e(lf($v, 'title')) ?></figcaption></figure><?php endforeach; ?></div>
    <p class="moreBtn"><a class="btn" href="?page=videos"><?= tr('View all videos', 'सबै भिडियो हेर्नुहोस्') ?></a></p>
  </section>
  <?php endif; ?>

  <!-- ===================== HOMEPAGE: FAQs ===================== -->
  <?php if ($homeFaqs): ?>
  <section class="section pale"><div class="wrap faqHome">
    <div class="sectionHead"><div><small><?= tr('HAVE QUESTIONS?', 'प्रश्नहरू छन्?') ?></small><h2><?= tr('Frequently Asked Questions', 'बारम्बार सोधिने प्रश्नहरू') ?></h2></div><a href="?page=faq"><?= tr('All questions →', 'सबै प्रश्न →') ?></a></div>
    <div class="faqList">
      <?php foreach ($homeFaqs as $f): ?>
      <details class="faqItem"><summary><?= e(lf($f, 'question')) ?></summary><div class="faqAnswer"><?= nl2br(e(lf($f, 'answer'))) ?></div></details>
      <?php endforeach; ?>
    </div>
  </div></section>
  <?php endif; ?>

  <!-- ===================== HOMEPAGE: CALL TO ACTION ===================== -->
  <section class="cta"><div class="wrap ctaIn"><div><h2><?= e(setting('cta_title')) ?></h2><p><?= e(setting('cta_text')) ?></p></div><a class="btn light" href="?page=contact"><?= tr('Contact Us', 'सम्पर्क गर्नुहोस्') ?></a></div></section>

<?php elseif ($view === 'about'): page_hero(setting('about_title'), setting('about_subtitle')); ?>
  <section class="wrap section two">
    <?php if (setting('about_image')): ?><img class="feature" src="<?= e(media_url(setting('about_image'))) ?>" alt=""><?php endif; ?>
    <div><h2><?= tr('Who We Are', 'हाम्रो परिचय') ?></h2><p><?= nl2br(e(setting('about_intro'))) ?></p><?php if (setting('registration_number')): ?><p class="muted"><?= e(setting('registration_number')) ?> · <?= e(setting('pan_vat_number')) ?></p><?php endif; ?></div>
  </section>
  <section class="section pale"><div class="wrap cards">
    <article class="card"><h3><?= tr('Our Vision', 'हाम्रो दृष्टिकोण') ?></h3><p><?= nl2br(e(setting('vision'))) ?></p></article>
    <article class="card"><h3><?= tr('Our Mission', 'हाम्रो लक्ष्य') ?></h3><p><?= nl2br(e(setting('mission'))) ?></p></article>
    <article class="card"><h3><?= tr('Core Values', 'मूल मान्यता') ?></h3><ul class="ticks"><?php foreach (lines(setting('core_values')) as $v): ?><li><?= e($v) ?></li><?php endforeach; ?></ul></article>
  </div></section>
  <?php if ($leaders): ?>
  <section class="section wrap"><div class="sectionHead"><div><small><?= tr('MESSAGES', 'सन्देश') ?></small><h2><?= tr('From Our Leadership', 'हाम्रो नेतृत्वबाट') ?></h2></div><a href="?page=messages"><?= tr('Read all messages →', 'सबै सन्देश पढ्नुहोस् →') ?></a></div>
    <div class="leaderCards">
    <?php foreach ($leaders as $m): [$ex] = excerpt(lf($m, 'message'), 200); ?>
      <a class="card leaderCard" href="?page=messages#msg-<?= (int)$m['id'] ?>"><?= leader_photo($m, 'leaderCardImg') ?><b><?= e(lf($m, 'name')) ?></b><small><?= e(lf($m, 'designation')) ?></small><p><?= e($ex) ?></p><span class="readMore"><?= tr('Read message', 'सन्देश पढ्नुहोस्') ?> →</span></a>
    <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
  <?php if ($team):
      /* One coloured panel per group, like the printed committee chart.
         The first member (lowest display order) is the head: on a row of their own when the group
         has "head on top" ticked, otherwise in the raised middle spot in groups of three. */
      $teamCard = function (array $t, bool $lead = false): string {
          $name = lf($t, 'name');
          $photo = $t['photo_path']
              ? '<img src="' . e(media_url($t['photo_path'])) . '" alt="' . e($name) . '" loading="lazy">'
              : '<span class="tmInitial">' . e(mb_substr($name, 0, 1)) . '</span>';
          return '<figure class="tmCard' . ($lead ? ' lead' : '') . '"><div class="tmPhoto">' . $photo . '</div><figcaption><b>' . e($name) . '</b><small>' . e(lf($t, 'designation')) . '</small></figcaption></figure>';
      };
      $panels = [];
      foreach ($teamGroups as $tg) {
          $members = array_values(array_filter($team, fn($t) => $t['type'] === $tg['slug']));
          if ($members) $panels[] = [$tg, $members];
      } ?>
  <section class="section teamChart" id="team"><div class="wrap">
    <div class="sectionHead teamChartHead"><div><small><?= tr('OUR TEAM', 'हाम्रो टिम') ?></small><h2><?= tr('Committees & Team', 'समिति तथा टिम') ?></h2></div></div>
    <div class="teamPanels">
    <?php foreach ($panels as $i => [$tg, $members]): $n = count($members); ?>
      <article class="teamPanel tint<?= $i % 5 ?><?= $n >= 5 ? ' wide' : '' ?>">
        <h3 class="teamPanelTitle"><span><?= e(tr($tg['name_en'], $tg['name_ne'])) ?></span></h3>
        <div class="teamPanelBody">
        <?php if ($n >= 2 && !empty($tg['head_on_top'])): ?>
          <div class="tmRow"><?= $teamCard($members[0], true) ?></div>
          <?php $rest = array_slice($members, 1); $perRow = (int)ceil(count($rest) / ceil(count($rest) / 6)); // even rows, at most 6 each
          foreach (array_chunk($rest, $perRow) as $chunk): ?><div class="tmRow"><?php foreach ($chunk as $t) echo $teamCard($t); ?></div><?php endforeach; ?>
        <?php elseif ($n === 3): ?>
          <div class="tmRow raised"><?= $teamCard($members[1]) . $teamCard($members[0], true) . $teamCard($members[2]) ?></div>
        <?php else: ?>
          <div class="tmRow"><?php foreach ($members as $t) echo $teamCard($t); ?></div>
        <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  </div></section>
  <?php endif; ?>

<?php elseif ($view === 'savings' || $view === 'loans'): page_hero($pageTitle); ?>
  <section class="wrap section"><div class="cards">
  <?php foreach ($items as $p): $list = lines($view === 'savings' ? lf($p, 'features') : lf($p, 'requirements')); ?>
    <article class="card product">
      <span class="rate"><?= e($L === 'ne' ? to_nepali_number($p['interest_rate']) : $p['interest_rate']) ?>% <?= tr('p.a.', 'वार्षिक') ?></span>
      <h3><?= e(lf($p, 'name')) ?></h3>
      <p><?= e(lf($p, 'summary')) ?></p>
      <?php if ($list): ?><b class="listHead"><?= $view === 'savings' ? tr('Features', 'विशेषताहरू') : tr('Requirements', 'आवश्यक कागजात') ?></b><ul class="ticks"><?php foreach ($list as $li): ?><li><?= e($li) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if ($view === 'savings' && lf($p, 'eligibility')): ?><b class="listHead"><?= tr('Eligibility', 'योग्यता') ?></b><p><?= e(lf($p, 'eligibility')) ?></p><?php endif; ?>
      <div class="meta">
      <?php if ($view === 'savings'): ?>
        <span><?= tr('Minimum balance', 'न्यूनतम मौज्दात') ?><b><?= e(format_money((float)$p['min_balance'], $L)) ?></b></span>
        <span><?= tr('Interest payout', 'ब्याज भुक्तानी') ?><b><?= e($L === 'ne' ? $p['interest_payout_ne'] : $p['interest_payout']) ?></b></span>
      <?php else: ?>
        <span><?= tr('Maximum tenure', 'अधिकतम अवधि') ?><b><?= e($L === 'ne' ? to_nepali_number($p['max_tenure_months']) : $p['max_tenure_months']) ?> <?= tr('months', 'महिना') ?></b></span>
        <span><?= tr('Service charge', 'सेवा शुल्क') ?><b><?= e($L === 'ne' ? to_nepali_number($p['service_charge']) : $p['service_charge']) ?>%</b></span>
      <?php endif; ?>
      </div>
      <a class="btn small cardBtn" href="?page=<?= $view === 'loans' ? 'loan-form&product=' . (int)$p['id'] : 'membership-form' ?>"><?= $view === 'loans' ? tr('Apply for this loan', 'यो कर्जाका लागि आवेदन') : tr('Become a member to open', 'खाता खोल्न सदस्य बन्नुहोस्') ?> →</a>
    </article>
  <?php endforeach; ?>
  </div><?php if (!$items): ?><p class="muted"><?= tr('Information will be available soon.', 'जानकारी चाँडै उपलब्ध हुनेछ।') ?></p><?php endif; ?></section>

<?php elseif ($view === 'notices'): page_hero($pageTitle); ?>
  <section class="wrap section">
    <div class="filters"><a href="?page=notices" class="<?= !isset($noticeCats[$cat]) ? 'on' : '' ?>"><?= tr('All', 'सबै') ?></a><?php foreach ($noticeCats as $k => $l): ?><a href="?page=notices&cat=<?= $k ?>" class="<?= $cat === $k ? 'on' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></div>
    <?php foreach ($items as $n): ?>
      <a class="notice" href="?page=notice&id=<?= (int)$n['id'] ?>"><small><?= e(format_date($n['published_date'])) ?> · <?= e($noticeCats[$n['category']] ?? '') ?></small><h3><?= e(lf($n, 'title')) ?></h3><p><?= e(mb_strimwidth(lf($n, 'content'), 0, 200, '…')) ?></p></a>
    <?php endforeach; ?>
    <?php if (!$items): ?><p class="muted"><?= $cat === 'vacancy' ? tr('There are no open vacancies at the moment. You can still send us a general application below.', 'हाल कुनै रिक्त पदको सूचना छैन। तल सामान्य आवेदन भने पठाउन सक्नुहुन्छ।') : tr('No notices found.', 'कुनै सूचना भेटिएन।') ?></p><?php endif; ?>
  </section>
  <?php if ($cat === 'vacancy'):
    $ov = fn(string $k, string $d = '') => e((string)($old[$k] ?? $d));
    $err = fn(string $k) => isset($errs[$k]) ? '<small class="fErr">' . e($errs[$k]) . '</small>' : '';
    $cls = fn(string $k, string $extra = '') => 'fRow ' . $extra . (isset($errs[$k]) ? ' hasErr' : '');
    $req = '<i class="reqMark">*</i>';
    $fileHint = tr('JPG, PNG, WEBP or PDF · max 5 MB', 'JPG, PNG, WEBP वा PDF · बढीमा ५ MB');
    $pick = (string)($old['notice_id'] ?? ($_GET['apply'] ?? (count($vacancies) === 1 ? $vacancies[0]['id'] : ''))); ?>
  <section class="section pale jobApply" id="applyForm"><div class="wrap applyWrap">
    <div class="sectionHead"><div><small><?= tr('CAREERS', 'रोजगारी') ?></small><h2><?= tr('Apply for a Job', 'रोजगारीका लागि आवेदन') ?></h2><p class="muted"><?= tr('Fill in the form and attach your documents. Fields marked * are required. Shortlisted candidates will be contacted by phone.', 'फारम भरी कागजात संलग्न गर्नुहोस्। * चिन्ह भएका विवरण अनिवार्य छन्। छनोट भएका उम्मेदवारलाई फोनबाट सम्पर्क गरिनेछ।') ?></p></div></div>
    <?php if ($errs): ?><div class="formAlert" role="alert"><?= e($errs['_'] ?? tr('Please correct the highlighted fields below. Attached files need to be chosen again.', 'तल चिन्ह लगाइएका विवरण सच्याउनुहोस्। संलग्न फाइलहरू फेरि छान्नुपर्छ।')) ?></div><?php endif; ?>
    <form class="applyForm card" method="post" action="apply-submit.php" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="job">
      <div class="hpField" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

      <fieldset><legend><span>1</span><?= tr('Position', 'पद') ?></legend><div class="fGrid">
        <div class="<?= $cls('notice_id', 'wide') ?>"><label for="j0"><?= tr('Position applied for', 'आवेदन दिने पद') ?> <?= $req ?></label>
          <select id="j0" name="notice_id" required><option value=""><?= tr('— Choose position —', '— पद छान्नुहोस् —') ?></option>
          <?php foreach ($vacancies as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $pick === (string)$v['id'] ? 'selected' : '' ?>><?= e(lf($v, 'title')) ?> (<?= e(format_date($v['published_date'])) ?>)</option><?php endforeach; ?>
          <option value="0" <?= $pick === '0' ? 'selected' : '' ?>><?= tr('General application (no specific vacancy)', 'सामान्य आवेदन (कुनै विशेष पद बिना)') ?></option></select><?= $err('notice_id') ?></div>
        <div class="<?= $cls('position_other', 'wide') ?>" id="jobOther" <?= $pick === '0' ? '' : 'hidden' ?>><label for="j0b"><?= tr('Which kind of job are you interested in?', 'कस्तो पदमा रुचि छ?') ?></label><input id="j0b" name="position_other" value="<?= $ov('position_other') ?>" maxlength="150" placeholder="<?= tr('e.g. Cashier, Field officer', 'जस्तै: क्यासियर, फिल्ड सहायक') ?>"></div>
      </div></fieldset>

      <fieldset><legend><span>2</span><?= tr('Personal details', 'व्यक्तिगत विवरण') ?></legend><div class="fGrid">
        <div class="<?= $cls('full_name') ?>"><label for="j1"><?= tr('Full name', 'पूरा नाम') ?> <?= $req ?></label><input id="j1" name="full_name" value="<?= $ov('full_name') ?>" maxlength="150" required autocomplete="name"><?= $err('full_name') ?></div>
        <div class="<?= $cls('gender') ?>"><span class="fLabel"><?= tr('Gender', 'लिङ्ग') ?> <?= $req ?></span><div class="radios"><?php foreach (['male' => tr('Male', 'पुरुष'), 'female' => tr('Female', 'महिला'), 'other' => tr('Other', 'अन्य')] as $g => $gl): ?><label><input type="radio" name="gender" value="<?= $g ?>" <?= ($old['gender'] ?? '') === $g ? 'checked' : '' ?> required> <?= e($gl) ?></label><?php endforeach; ?></div><?= $err('gender') ?></div>
        <div class="<?= $cls('date_of_birth') ?>"><label for="j2"><?= tr('Date of birth (BS)', 'जन्म मिति (वि.सं.)') ?> <?= $req ?></label><input id="j2" name="date_of_birth" value="<?= $ov('date_of_birth') ?>" placeholder="2055-05-12" inputmode="numeric" maxlength="10" required><?= $err('date_of_birth') ?: '<small class="fHint">' . tr('Format YYYY-MM-DD', 'ढाँचा: साल-महिना-गते') . '</small>' ?></div>
        <div class="<?= $cls('phone') ?>"><label for="j3"><?= tr('Mobile number', 'मोबाइल नम्बर') ?> <?= $req ?></label><input id="j3" type="tel" name="phone" value="<?= $ov('phone') ?>" maxlength="30" required autocomplete="tel"><?= $err('phone') ?></div>
        <div class="<?= $cls('email') ?>"><label for="j4"><?= tr('Email', 'इमेल') ?></label><input id="j4" type="email" name="email" value="<?= $ov('email') ?>" maxlength="120" autocomplete="email"><?= $err('email') ?></div>
        <div class="<?= $cls('address') ?>"><label for="j5"><?= tr('Address', 'ठेगाना') ?> <?= $req ?></label><input id="j5" name="address" value="<?= $ov('address') ?>" maxlength="255" placeholder="<?= tr('Municipality-Ward, District', 'नगरपालिका-वडा, जिल्ला') ?>" required><?= $err('address') ?></div>
      </div></fieldset>

      <fieldset><legend><span>3</span><?= tr('Education & experience', 'शिक्षा तथा अनुभव') ?></legend><div class="fGrid">
        <div class="<?= $cls('qualification', 'wide') ?>"><label for="j6"><?= tr('Highest education', 'उच्चतम शैक्षिक योग्यता') ?> <?= $req ?></label><input id="j6" name="qualification" value="<?= $ov('qualification') ?>" maxlength="255" placeholder="<?= tr('e.g. BBS, Tribhuvan University, 2079', 'जस्तै: बी.बी.एस., त्रिभुवन विश्वविद्यालय, २०७९') ?>" required><?= $err('qualification') ?></div>
        <div class="<?= $cls('experience', 'wide') ?>"><label for="j7"><?= tr('Work experience', 'कार्य अनुभव') ?></label><textarea id="j7" name="experience" rows="3" maxlength="3000" placeholder="<?= tr('Organisation, position and years — or leave empty if none', 'संस्था, पद र अवधि — नभए खाली छोड्नुहोस्') ?>"><?= $ov('experience') ?></textarea></div>
        <div class="<?= $cls('cover_letter', 'wide') ?>"><label for="j8"><?= tr('Why do you want to join us? (optional)', 'तपाईं किन हामीसँग काम गर्न चाहनुहुन्छ? (ऐच्छिक)') ?></label><textarea id="j8" name="cover_letter" rows="4" maxlength="5000"><?= $ov('cover_letter') ?></textarea></div>
      </div></fieldset>

      <fieldset><legend><span>4</span><?= tr('Documents', 'कागजातहरू') ?></legend><div class="fGrid">
        <div class="<?= $cls('cv_path') ?>"><label for="j9"><?= tr('CV / Résumé', 'बायोडाटा (CV)') ?> <?= $req ?></label><input id="j9" type="file" name="cv_path" accept="image/*,application/pdf" required><?= $err('cv_path') ?: '<small class="fHint">' . tr('PDF preferred', 'PDF उत्तम') . ' · ' . $fileHint . '</small>' ?></div>
        <div class="<?= $cls('photo_path') ?>"><label for="j10"><?= tr('Passport-size photo', 'पासपोर्ट साइजको फोटो') ?> <?= $req ?></label><input id="j10" type="file" name="photo_path" accept="image/jpeg,image/png,image/webp" required><?= $err('photo_path') ?: '<small class="fHint">JPG, PNG, WEBP · ' . tr('max 5 MB', 'बढीमा ५ MB') . '</small>' ?></div>
        <div class="<?= $cls('citizenship_path') ?>"><label for="j11"><?= tr('Citizenship copy', 'नागरिकताको प्रतिलिपि') ?> <?= $req ?></label><input id="j11" type="file" name="citizenship_path" accept="image/*,application/pdf" required><?= $err('citizenship_path') ?: '<small class="fHint">' . $fileHint . '</small>' ?></div>
        <div class="<?= $cls('certificate_path') ?>"><label for="j12"><?= tr('Academic certificate / transcript', 'शैक्षिक प्रमाणपत्र') ?></label><input id="j12" type="file" name="certificate_path" accept="image/*,application/pdf"><?= $err('certificate_path') ?: '<small class="fHint">' . $fileHint . '</small>' ?></div>
      </div><p class="fNote">🔒 <?= tr('Your documents are stored privately and are seen only by authorised cooperative staff.', 'तपाईंका कागजात गोप्य रूपमा राखिन्छन् र अधिकृत कर्मचारीले मात्र हेर्न सक्छन्।') ?></p></fieldset>

      <div class="<?= $cls('agree', 'declare') ?>"><label><input type="checkbox" name="agree" value="1" <?= !empty($old['agree']) ? 'checked' : '' ?> required> <?= tr('I confirm that the information and documents above are true. I understand that false information can cancel my application.', 'माथिको विवरण तथा कागजात सत्य हुन् भनी पुष्टि गर्छु। झुटो विवरण भएमा आवेदन रद्द हुन सक्छ भन्ने मलाई थाहा छ।') ?>
          <?= tr('I have read the', 'मैले') ?> <a href="?page=privacy-policy" target="_blank"><?= tr('Privacy Policy', 'गोपनीयता नीति') ?></a><?= tr('.', ' पढेको छु।') ?></label><?= $err('agree') ?></div>
      <div class="fActions"><button class="btn" type="submit"><?= tr('Submit job application', 'रोजगारी आवेदन बुझाउनुहोस्') ?></button><span class="muted"><?= tr('Takes about 5 minutes', 'करिब ५ मिनेट लाग्छ') ?></span></div>
    </form>
  </div></section>
  <script>document.getElementById('j0').addEventListener('change',function(){document.getElementById('jobOther').hidden=this.value!=='0';});</script>
  <?php endif; ?>

<?php elseif ($view === 'notice'): ?>
  <?php if (!$item): page_hero(tr('Notice not found', 'सूचना भेटिएन')); else: page_hero(lf($item, 'title'), format_date($item['published_date'])); ?>
  <article class="wrap section article">
    <?php if ($item['featured_image']): ?><img class="feature" src="<?= e(media_url($item['featured_image'])) ?>" alt=""><?php endif; ?>
    <div class="prose"><?= nl2br(e(lf($item, 'content'))) ?></div>
    <?php if ($item['file_attachment'] || $item['category'] === 'vacancy'): ?><p class="noticeBtns">
      <?php if ($item['category'] === 'vacancy'): ?><a class="btn" href="?page=notices&amp;cat=vacancy&amp;apply=<?= (int)$item['id'] ?>#applyForm"><?= tr('Apply online for this job', 'यो पदका लागि अनलाइन आवेदन') ?> →</a><?php endif; ?>
      <?php if ($item['file_attachment']): ?><a class="btn<?= $item['category'] === 'vacancy' ? ' secondary' : '' ?>" href="<?= e(media_url($item['file_attachment'])) ?>" target="_blank"><?= tr('Download attachment', 'संलग्न फाइल डाउनलोड') ?> ↓</a><?php endif; ?>
    </p><?php endif; ?>
    <p><a href="?page=notices">← <?= tr('All notices', 'सबै सूचना') ?></a></p>
  </article>
  <?php endif; ?>

<?php elseif ($view === 'news'): page_hero($pageTitle); ?>
  <section class="wrap section"><div class="cards">
    <?php foreach ($items as $n): ?>
      <a class="card newsCard" href="?page=article&slug=<?= e(urlencode($n['slug'])) ?>"><?php if ($n['featured_image']): ?><img src="<?= e(media_url($n['featured_image'])) ?>" alt="" loading="lazy"><?php endif; ?><small><?= e(format_date($n['published_date'])) ?></small><h3><?= e(lf($n, 'title')) ?></h3><p><?= e(lf($n, 'summary')) ?></p></a>
    <?php endforeach; ?>
  </div><?php if (!$items): ?><p class="muted"><?= tr('No news yet.', 'हालसम्म कुनै समाचार छैन।') ?></p><?php endif; ?></section>

<?php elseif ($view === 'article'): ?>
  <?php if (!$item): page_hero(tr('Article not found', 'समाचार भेटिएन')); else: page_hero(lf($item, 'title'), format_date($item['published_date'])); ?>
  <article class="wrap section article">
    <?php if ($item['featured_image']): ?><img class="feature" src="<?= e(media_url($item['featured_image'])) ?>" alt=""><?php endif; ?>
    <p class="lead"><?= e(lf($item, 'summary')) ?></p>
    <div class="prose"><?= nl2br(e(lf($item, 'content'))) ?></div>
    <p><a href="?page=news">← <?= tr('All news', 'सबै समाचार') ?></a></p>
  </article>
  <?php endif; ?>

<?php elseif ($view === 'gallery'): page_hero($pageTitle); ?>
  <section class="wrap section">
    <div class="galleryTabs"><a href="?page=gallery" class="<?= $view === 'gallery' ? 'on' : '' ?>"><?= tr('Photos', 'फोटो') ?></a><a href="?page=videos" class="<?= $view === 'videos' ? 'on' : '' ?>"><?= tr('Videos', 'भिडियो') ?></a></div>
    <?php if ($albums): ?><div class="filters"><a href="?page=gallery" class="<?= !$album ? 'on' : '' ?>"><?= tr('All photos', 'सबै फोटो') ?></a><?php foreach ($albums as $a): ?><a href="?page=gallery&album=<?= (int)$a['id'] ?>" class="<?= $album === (int)$a['id'] ? 'on' : '' ?>"><?= e(lf($a, 'name')) ?> (<?= (int)$a['n'] ?>)</a><?php endforeach; ?></div><?php endif; ?>
    <div class="gallery"><?php foreach ($items as $g): ?><figure><a href="<?= e(media_url($g['image_path'])) ?>" class="lightbox"><img src="<?= e(media_url($g['image_path'])) ?>" alt="<?= e(lf($g, 'title')) ?>" loading="lazy"></a><figcaption><?= e(lf($g, 'title')) ?></figcaption></figure><?php endforeach; ?></div>
    <?php if (!$items): ?><p class="muted"><?= tr('Photos will be added soon.', 'फोटोहरू चाँडै थपिनेछन्।') ?></p><?php endif; ?>
  </section>

<?php elseif ($view === 'videos'): page_hero($pageTitle); ?>
  <section class="wrap section">
    <div class="galleryTabs"><a href="?page=gallery" class="<?= $view === 'gallery' ? 'on' : '' ?>"><?= tr('Photos', 'फोटो') ?></a><a href="?page=videos" class="<?= $view === 'videos' ? 'on' : '' ?>"><?= tr('Videos', 'भिडियो') ?></a></div>
    <?php if ($items): ?>
    <div class="videoGrid"><?php foreach ($items as $v): ?><figure class="videoItem"><div class="videoBox"><?= video_player($v) ?></div><figcaption><?= e(lf($v, 'title')) ?></figcaption></figure><?php endforeach; ?></div>
    <?php else: ?><p class="muted"><?= tr('No videos yet.', 'हालसम्म कुनै भिडियो छैन।') ?></p><?php endif; ?>
    <?php if ($yt = setting('youtube_url', 'en')): ?><p class="moreBtn"><a class="btn" href="<?= e($yt) ?>" target="_blank" rel="noopener"><?= tr('Visit our YouTube channel', 'हाम्रो युट्युब च्यानल हेर्नुहोस्') ?></a></p><?php endif; ?>
  </section>

<?php elseif ($view === 'downloads'): page_hero($pageTitle); ?>
  <section class="wrap section">
    <?php $last = null; foreach ($items as $d): if ($d['category'] !== $last): $last = $d['category']; ?><h3 class="dlHead"><?= e($dlCats[$last] ?? $last) ?></h3><?php endif; ?>
      <div class="download"><span><b><?= e(lf($d, 'title')) ?></b><small><?= e($d['file_format']) ?> · <?= e($d['file_size']) ?></small></span><a class="btn small" href="download.php?id=<?= (int)$d['id'] ?>"><?= tr('Download', 'डाउनलोड') ?> ↓</a></div>
    <?php endforeach; ?>
    <?php if (!$items): ?><p class="muted"><?= tr('No downloads available.', 'कुनै डाउनलोड उपलब्ध छैन।') ?></p><?php endif; ?>
  </section>

<?php elseif ($view === 'contact'): page_hero($pageTitle); ?>
  <section class="wrap section two topAlign">
    <div>
      <h2><?= tr('Office Information', 'कार्यालय विवरण') ?></h2>
      <ul class="contactList">
        <li><span>📍</span><?= e(setting('address')) ?></li>
        <?php if ($phone): ?><li><span>☎</span><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a><?php if (setting('phone_secondary')): ?>, <a href="tel:<?= e(preg_replace('/[^\d+]/', '', setting('phone_secondary'))) ?>"><?= e(setting('phone_secondary')) ?></a><?php endif; ?></li><?php endif; ?>
        <?php if ($email): ?><li><span>✉</span><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li><?php endif; ?>
        <?php if (setting('office_hours')): ?><li><span>🕘</span><?= e(setting('office_hours')) ?></li><?php endif; ?>
        <li><span>❓</span><a href="?page=faq"><?= tr('Read our frequently asked questions', 'बारम्बार सोधिने प्रश्नहरू हेर्नुहोस्') ?></a></li>
      </ul>
      <?php $mapSrc = map_embed_src(setting('maps_embed_url'));
      if ($mapSrc):
          parse_str((string)parse_url($mapSrc, PHP_URL_QUERY), $mq);
          $mapOpen = !empty($mq['q']) ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mq['q']) : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(setting('address', 'en')); ?>
        <iframe class="map" src="<?= e($mapSrc) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="<?= e(tr('Office location map', 'कार्यालयको नक्सा')) ?>" allowfullscreen></iframe>
        <p><a class="btn small" href="<?= e($mapOpen) ?>" target="_blank" rel="noopener"><?= tr('Open in Google Maps', 'गुगल नक्सामा खोल्नुहोस्') ?> ↗</a></p>
      <?php endif; ?>
    </div>
    <form class="form card" method="post" action="contact-submit.php"><h2><?= tr('Send us a message', 'सन्देश पठाउनुहोस्') ?></h2><?= csrf_field() ?><input name="full_name" placeholder="<?= tr('Full name', 'पूरा नाम') ?>" required maxlength="150"><input name="email" type="email" placeholder="<?= tr('Email', 'इमेल') ?>" required maxlength="120"><input name="phone" placeholder="<?= tr('Phone', 'फोन नम्बर') ?>" required maxlength="30"><input name="subject" placeholder="<?= tr('Subject', 'विषय') ?>" required maxlength="255"><textarea name="message" placeholder="<?= tr('Your message', 'तपाईंको सन्देश') ?>" required></textarea><button class="btn"><?= tr('Send Message', 'सन्देश पठाउनुहोस्') ?></button><small class="formPrivacy"><?= tr('We use your details only to reply to you. See our', 'तपाईंको विवरण जवाफ दिन मात्र प्रयोग गरिन्छ। हेर्नुहोस्:') ?> <a href="?page=privacy-policy"><?= tr('Privacy Policy', 'गोपनीयता नीति') ?></a>.</small></form>
  </section>

<?php elseif ($view === 'faq'):
    page_hero($pageTitle, tr('Quick answers about membership, savings, loans and our services.', 'सदस्यता, बचत, कर्जा र हाम्रा सेवाबारे छोटो जवाफहरू।'));
    $faqCats = ['general' => tr('General', 'सामान्य'), 'membership' => tr('Membership', 'सदस्यता'), 'savings' => tr('Savings', 'बचत'), 'loans' => tr('Loans', 'कर्जा'), 'online' => tr('Online Services', 'अनलाइन सेवा')];
    $usedCats = array_unique(array_column($faqs, 'category')); ?>
  <section class="wrap section faqPage">
    <?php if ($faqs): ?>
    <div class="faqTools">
      <input type="search" id="faqSearch" placeholder="<?= tr('Search questions…', 'प्रश्न खोज्नुहोस्…') ?>" aria-label="<?= tr('Search questions', 'प्रश्न खोज्नुहोस्') ?>">
      <?php if (count($usedCats) > 1): ?>
      <div class="filters faqFilters"><a href="#" class="on" data-cat=""><?= tr('All', 'सबै') ?></a><?php foreach ($faqCats as $k => $l): if (!in_array($k, $usedCats, true)) continue; ?><a href="#" data-cat="<?= e($k) ?>"><?= e($l) ?></a><?php endforeach; ?></div>
      <?php endif; ?>
    </div>
    <?php $last = null; foreach ($faqs as $f): if ($f['category'] !== $last): if ($last !== null) echo '</div>'; $last = $f['category']; ?>
      <div class="faqGroup" data-cat="<?= e($last) ?>"><h2 class="faqGroupTitle"><?= e($faqCats[$last] ?? $last) ?></h2>
    <?php endif; ?>
      <details class="faqItem" id="faq-<?= (int)$f['id'] ?>"><summary><?= e(lf($f, 'question')) ?></summary><div class="faqAnswer"><?= nl2br(e(lf($f, 'answer'))) ?></div></details>
    <?php endforeach; echo '</div>'; ?>
    <p class="muted faqEmpty" id="faqEmpty" hidden><?= tr('No questions match your search.', 'तपाईंको खोजसँग मिल्ने प्रश्न भेटिएन।') ?></p>
    <?php else: ?>
      <p class="muted"><?= tr('Questions and answers will be added soon.', 'प्रश्न र उत्तरहरू चाँडै थपिनेछन्।') ?></p>
    <?php endif; ?>
    <div class="faqAsk card"><div><h3><?= tr('Still have a question?', 'अझै प्रश्न छ?') ?></h3><p><?= tr('Our team is happy to help.', 'हाम्रो टोली सहयोग गर्न तयार छ।') ?><?php if ($phone): ?> <?= tr('Call', 'फोन') ?> <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a><?php endif; ?></p></div><a class="btn" href="?page=contact"><?= tr('Ask us', 'सोध्नुहोस्') ?></a></div>
  </section>
  <?php if ($faqs): ?>
  <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => lf($f, 'question'), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => lf($f, 'answer')]], $faqs)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php endif; ?>

<?php elseif ($view === 'date-converter'): page_hero($pageTitle, tr('Find the Nepali or English date in a moment.', 'नेपाली वा अंग्रेजी मिति तुरुन्त थाहा पाउनुहोस्।')); ?>
  <link rel="stylesheet" href="assets/css/date-converter.css?v=<?= filemtime(__DIR__ . '/assets/css/date-converter.css') ?>">
  <section class="wrap section"><?php $dcLang = $L; require APP_ROOT . '/includes/date-converter-ui.php'; ?></section>

<?php elseif ($view === 'note-counter'): page_hero($pageTitle, tr('Counting cash before a deposit? Enter how many of each note you have.', 'जम्मा गर्नुअघि नगद गन्दै हुनुहुन्छ? हरेक नोट कति छन् लेख्नुहोस्।')); ?>
  <link rel="stylesheet" href="assets/css/note-counter.css?v=<?= filemtime(__DIR__ . '/assets/css/note-counter.css') ?>">
  <section class="wrap section">
    <?php $ncLang = $L; $ncStore = 'site'; $ncSlip = false; require APP_ROOT . '/includes/note-counter-ui.php'; ?>
    <p class="muted ncDisclaimer"><?= tr('This tool only helps you count. Cash is always counted again by our cashier when you deposit.', 'यो उपकरणले गन्न मात्र सहयोग गर्छ। रकम जम्मा गर्दा हाम्रा क्यासियरले फेरि गन्नुहुनेछ।') ?></p>
  </section>
  <script src="assets/js/note-counter.js?v=<?= filemtime(__DIR__ . '/assets/js/note-counter.js') ?>" defer></script>

<?php elseif ($view === 'messages'): page_hero($pageTitle); ?>
  <section class="wrap section msgPage">
    <?php if (count($leaders) > 1): ?><nav class="msgJump" aria-label="<?= tr('Messages', 'सन्देशहरू') ?>"><?php foreach ($leaders as $m): ?><a href="#msg-<?= (int)$m['id'] ?>"><?= leader_photo($m, 'msgTabImg') ?><span><b><?= e(lf($m, 'name')) ?></b><small><?= e(lf($m, 'designation')) ?></small></span></a><?php endforeach; ?></nav><?php endif; ?>
    <?php foreach ($leaders as $i => $m): ?>
    <article class="msgFull<?= $i % 2 ? ' flip' : '' ?>" id="msg-<?= (int)$m['id'] ?>">
      <div class="msgFullSide"><?= leader_photo($m, 'feature leaderPhoto') ?><p class="msgSign"><b><?= e(lf($m, 'name')) ?></b><span><?= e(lf($m, 'designation')) ?></span></p></div>
      <div><h2><?= e(lf($m, 'title')) ?></h2><div class="prose msgBody"><?= nl2br(e(lf($m, 'message'))) ?></div></div>
    </article>
    <?php endforeach; ?>
    <?php if (!$leaders): ?><p class="muted"><?= tr('Messages will be added soon.', 'सन्देशहरू चाँडै थपिनेछन्।') ?></p><?php endif; ?>
  </section>

<?php elseif ($view === 'apply'): page_hero($pageTitle, tr('Save a trip to the office — start your application online.', 'कार्यालय नआईकनै अनलाइन आवेदन सुरु गर्नुहोस्।')); ?>
  <section class="wrap section"><div class="applyChoice">
    <a class="card applyCard" href="?page=membership-form"><span class="applyIcon">👤</span><h3><?= tr('Become a Member', 'सदस्य बन्नुहोस्') ?></h3><p><?= tr('Apply for membership and share ownership in the cooperative.', 'सहकारीको सदस्यता र सेयरका लागि आवेदन दिनुहोस्।') ?></p><span class="btn"><?= tr('Membership form', 'सदस्यता फारम') ?> →</span></a>
    <a class="card applyCard" href="?page=loan-form"><span class="applyIcon">💰</span><h3><?= tr('Apply for a Loan', 'कर्जाका लागि आवेदन') ?></h3><p><?= tr('Choose a loan, see the estimated monthly installment and apply.', 'कर्जा छान्नुहोस्, अनुमानित मासिक किस्ता हेर्नुहोस् र आवेदन दिनुहोस्।') ?></p><span class="btn"><?= tr('Loan form', 'कर्जा फारम') ?> →</span></a>
  </div></section>

<?php elseif ($view === 'membership-form' || $view === 'loan-form'):
    page_hero($pageTitle, tr('Fields marked * are required. Our staff will contact you after reviewing your application.', '* चिन्ह भएका विवरण अनिवार्य छन्। आवेदन जाँचपछि हाम्रा कर्मचारीले सम्पर्क गर्नुहुनेछ।'));
    $ov = fn(string $k, string $d = '') => e((string)($old[$k] ?? $d));
    $err = fn(string $k) => isset($errs[$k]) ? '<small class="fErr">' . e($errs[$k]) . '</small>' : '';
    $cls = fn(string $k, string $extra = '') => 'fRow ' . $extra . (isset($errs[$k]) ? ' hasErr' : '');
    $req = '<i class="reqMark">*</i>';
    $fileHint = tr('JPG, PNG, WEBP or PDF · max 5 MB', 'JPG, PNG, WEBP वा PDF · बढीमा ५ MB'); ?>
  <section class="wrap section applyWrap">
    <?php if ($errs): ?><div class="formAlert" role="alert"><?= e($errs['_'] ?? tr('Please correct the highlighted fields below. Attached files need to be chosen again.', 'तल चिन्ह लगाइएका विवरण सच्याउनुहोस्। संलग्न फाइलहरू फेरि छान्नुपर्छ।')) ?></div><?php endif; ?>
    <form class="applyForm card" id="applyForm" method="post" action="apply-submit.php" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="<?= e($formType) ?>">
      <div class="hpField" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

    <?php if ($formType === 'membership'): ?>
      <fieldset><legend><span>1</span><?= tr('Personal details', 'व्यक्तिगत विवरण') ?></legend><div class="fGrid">
        <div class="<?= $cls('full_name_en') ?>"><label for="a1"><?= tr('Full name (English)', 'पूरा नाम (अंग्रेजीमा)') ?> <?= $req ?></label><input id="a1" name="full_name_en" value="<?= $ov('full_name_en') ?>" maxlength="150" required autocomplete="name"><?= $err('full_name_en') ?></div>
        <div class="<?= $cls('full_name_ne') ?>"><label for="a2"><?= tr('Full name (Nepali)', 'पूरा नाम (नेपालीमा)') ?></label><input id="a2" name="full_name_ne" value="<?= $ov('full_name_ne') ?>" maxlength="150" lang="ne"></div>
        <div class="<?= $cls('gender') ?>"><span class="fLabel"><?= tr('Gender', 'लिङ्ग') ?> <?= $req ?></span><div class="radios"><?php foreach (['male' => tr('Male', 'पुरुष'), 'female' => tr('Female', 'महिला'), 'other' => tr('Other', 'अन्य')] as $g => $gl): ?><label><input type="radio" name="gender" value="<?= $g ?>" <?= ($old['gender'] ?? '') === $g ? 'checked' : '' ?> required> <?= e($gl) ?></label><?php endforeach; ?></div><?= $err('gender') ?></div>
        <div class="<?= $cls('date_of_birth') ?>"><label for="a3"><?= tr('Date of birth (BS)', 'जन्म मिति (वि.सं.)') ?> <?= $req ?></label><input id="a3" name="date_of_birth" value="<?= $ov('date_of_birth') ?>" placeholder="2050-05-12" inputmode="numeric" maxlength="10" required><?= $err('date_of_birth') ?: '<small class="fHint">' . tr('Format YYYY-MM-DD', 'ढाँचा: साल-महिना-गते') . '</small>' ?></div>
        <div class="<?= $cls('citizenship_no') ?>"><label for="a4"><?= tr('Citizenship number', 'नागरिकता नम्बर') ?> <?= $req ?></label><input id="a4" name="citizenship_no" value="<?= $ov('citizenship_no') ?>" maxlength="50" required><?= $err('citizenship_no') ?></div>
        <div class="<?= $cls('citizenship_district') ?>"><label for="a5"><?= tr('Issued district', 'जारी जिल्ला') ?> <?= $req ?></label><select id="a5" name="citizenship_district" required><option value=""><?= tr('— Choose district —', '— जिल्ला छान्नुहोस् —') ?></option><?php foreach (NEPAL_DISTRICTS as $d): ?><option <?= ($old['citizenship_district'] ?? '') === $d ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select><?= $err('citizenship_district') ?></div>
        <div class="<?= $cls('occupation') ?>"><label for="a6"><?= tr('Occupation', 'पेशा') ?></label><input id="a6" name="occupation" value="<?= $ov('occupation') ?>" maxlength="100"></div>
      </div></fieldset>

      <fieldset><legend><span>2</span><?= tr('Family details', 'पारिवारिक विवरण') ?></legend><div class="fGrid">
        <div class="<?= $cls('father_name') ?>"><label for="b1"><?= tr("Father's name", 'बुबाको नाम') ?> <?= $req ?></label><input id="b1" name="father_name" value="<?= $ov('father_name') ?>" maxlength="150" required><?= $err('father_name') ?></div>
        <div class="<?= $cls('grandfather_name') ?>"><label for="b2"><?= tr("Grandfather's name", 'हजुरबुबाको नाम') ?></label><input id="b2" name="grandfather_name" value="<?= $ov('grandfather_name') ?>" maxlength="150"></div>
        <div class="<?= $cls('spouse_name') ?>"><label for="b3"><?= tr("Spouse's name", 'पति/पत्नीको नाम') ?></label><input id="b3" name="spouse_name" value="<?= $ov('spouse_name') ?>" maxlength="150"></div>
      </div></fieldset>

      <fieldset><legend><span>3</span><?= tr('Contact & address', 'सम्पर्क तथा ठेगाना') ?></legend><div class="fGrid">
        <div class="<?= $cls('phone') ?>"><label for="c1"><?= tr('Mobile number', 'मोबाइल नम्बर') ?> <?= $req ?></label><input id="c1" type="tel" name="phone" value="<?= $ov('phone') ?>" maxlength="30" required autocomplete="tel"><?= $err('phone') ?></div>
        <div class="<?= $cls('email') ?>"><label for="c2"><?= tr('Email', 'इमेल') ?></label><input id="c2" type="email" name="email" value="<?= $ov('email') ?>" maxlength="120" autocomplete="email"><?= $err('email') ?></div>
        <div class="<?= $cls('permanent_address', 'wide') ?>"><label for="c3"><?= tr('Permanent address', 'स्थायी ठेगाना') ?> <?= $req ?></label><input id="c3" name="permanent_address" value="<?= $ov('permanent_address') ?>" maxlength="255" placeholder="<?= tr('Municipality-Ward, District', 'नगरपालिका-वडा, जिल्ला') ?>" required><?= $err('permanent_address') ?></div>
        <div class="<?= $cls('temporary_address', 'wide') ?>"><label for="c4"><?= tr('Current address (if different)', 'हालको ठेगाना (फरक भए)') ?></label><input id="c4" name="temporary_address" value="<?= $ov('temporary_address') ?>" maxlength="255"></div>
      </div></fieldset>

      <fieldset><legend><span>4</span><?= tr('Nominee & shares', 'इच्छाइएको व्यक्ति तथा सेयर') ?></legend><div class="fGrid">
        <div class="<?= $cls('nominee_name') ?>"><label for="d1"><?= tr('Nominee name', 'इच्छाइएको व्यक्तिको नाम') ?></label><input id="d1" name="nominee_name" value="<?= $ov('nominee_name') ?>" maxlength="150"></div>
        <div class="<?= $cls('nominee_relation') ?>"><label for="d2"><?= tr('Relation with nominee', 'इच्छाइएको व्यक्तिसँगको नाता') ?></label><input id="d2" name="nominee_relation" value="<?= $ov('nominee_relation') ?>" maxlength="60"></div>
        <div class="<?= $cls('share_count') ?>"><label for="d3"><?= tr('Number of shares wanted', 'लिन चाहेको सेयर संख्या') ?> <?= $req ?></label><input id="d3" type="number" min="1" name="share_count" value="<?= $ov('share_count', '10') ?>" required><?= $err('share_count') ?></div>
      </div></fieldset>

      <fieldset><legend><span>5</span><?= tr('Documents', 'कागजातहरू') ?></legend><div class="fGrid">
        <div class="<?= $cls('photo_path') ?>"><label for="e1"><?= tr('Passport-size photo', 'पासपोर्ट साइजको फोटो') ?> <?= $req ?></label><input id="e1" type="file" name="photo_path" accept="image/jpeg,image/png,image/webp" required><?= $err('photo_path') ?: '<small class="fHint">JPG, PNG, WEBP · ' . tr('max 5 MB', 'बढीमा ५ MB') . '</small>' ?></div>
        <div class="<?= $cls('citizenship_front_path') ?>"><label for="e2"><?= tr('Citizenship — front', 'नागरिकता — अगाडि') ?> <?= $req ?></label><input id="e2" type="file" name="citizenship_front_path" accept="image/*,application/pdf" required><?= $err('citizenship_front_path') ?: '<small class="fHint">' . $fileHint . '</small>' ?></div>
        <div class="<?= $cls('citizenship_back_path') ?>"><label for="e3"><?= tr('Citizenship — back', 'नागरिकता — पछाडि') ?></label><input id="e3" type="file" name="citizenship_back_path" accept="image/*,application/pdf"><?= $err('citizenship_back_path') ?: '<small class="fHint">' . $fileHint . '</small>' ?></div>
      </div><p class="fNote">🔒 <?= tr('Your documents are stored privately and are seen only by authorised cooperative staff.', 'तपाईंका कागजात गोप्य रूपमा राखिन्छन् र अधिकृत कर्मचारीले मात्र हेर्न सक्छन्।') ?></p></fieldset>

    <?php else: ?>
      <fieldset><legend><span>1</span><?= tr('Loan details', 'कर्जा विवरण') ?></legend><div class="fGrid">
        <div class="<?= $cls('loan_product_id', 'wide') ?>"><label for="l1"><?= tr('Loan type', 'कर्जाको प्रकार') ?> <?= $req ?></label>
          <select id="l1" name="loan_product_id" required><option value=""><?= tr('— Choose loan —', '— कर्जा छान्नुहोस् —') ?></option>
          <?php $pre = (int)($old['loan_product_id'] ?? ($_GET['product'] ?? 0)); foreach ($loanProducts as $lp): ?><option value="<?= (int)$lp['id'] ?>" data-rate="<?= e($lp['interest_rate']) ?>" data-max="<?= (int)$lp['max_tenure_months'] ?>" data-charge="<?= e($lp['service_charge']) ?>" <?= $pre === (int)$lp['id'] ? 'selected' : '' ?>><?= e(lf($lp, 'name')) ?> — <?= e($L === 'ne' ? to_nepali_number($lp['interest_rate']) : $lp['interest_rate']) ?>%</option><?php endforeach; ?></select><?= $err('loan_product_id') ?></div>
        <div class="<?= $cls('amount') ?>"><label for="l2"><?= tr('Loan amount (NPR)', 'कर्जा रकम (रु.)') ?> <?= $req ?></label><input id="l2" name="amount" value="<?= $ov('amount') ?>" inputmode="numeric" placeholder="500000" required><?= $err('amount') ?></div>
        <div class="<?= $cls('tenure_months') ?>"><label for="l3"><?= tr('Repayment period (months)', 'भुक्तानी अवधि (महिना)') ?> <?= $req ?></label><input id="l3" type="number" min="1" name="tenure_months" value="<?= $ov('tenure_months', '12') ?>" required><?= $err('tenure_months') ?: '<small class="fHint" id="tenureHint"></small>' ?></div>
        <div class="emiBox wide" id="emiBox" hidden><div><small><?= tr('Estimated monthly installment', 'अनुमानित मासिक किस्ता') ?></small><b id="emiVal">—</b></div><div><small><?= tr('Total interest', 'कुल ब्याज') ?></small><b id="emiInt">—</b></div><div><small><?= tr('Service charge', 'सेवा शुल्क') ?></small><b id="emiCharge">—</b></div><p><?= tr('This is only an estimate. The final terms are decided after review.', 'यो अनुमान मात्र हो। अन्तिम सर्त आवेदन जाँचपछि तय हुन्छ।') ?></p></div>
        <div class="<?= $cls('purpose', 'wide') ?>"><label for="l4"><?= tr('Purpose of the loan', 'कर्जाको उद्देश्य') ?> <?= $req ?></label><textarea id="l4" name="purpose" rows="3" maxlength="2000" required><?= $ov('purpose') ?></textarea><?= $err('purpose') ?></div>
        <div class="<?= $cls('collateral', 'wide') ?>"><label for="l5"><?= tr('Collateral offered (if any)', 'धितो (भएमा)') ?></label><textarea id="l5" name="collateral" rows="2" maxlength="2000" placeholder="<?= tr('e.g. land, vehicle, fixed deposit', 'जस्तै: जग्गा, सवारी साधन, मुद्दती निक्षेप') ?>"><?= $ov('collateral') ?></textarea></div>
      </div></fieldset>

      <fieldset><legend><span>2</span><?= tr('Applicant details', 'आवेदकको विवरण') ?></legend><div class="fGrid">
        <div class="<?= $cls('applicant_name') ?>"><label for="m1"><?= tr('Full name', 'पूरा नाम') ?> <?= $req ?></label><input id="m1" name="applicant_name" value="<?= $ov('applicant_name') ?>" maxlength="150" required autocomplete="name"><?= $err('applicant_name') ?></div>
        <div class="<?= $cls('member_code') ?>"><label for="m2"><?= tr('Member code (if already a member)', 'सदस्य कोड (सदस्य भए)') ?></label><input id="m2" name="member_code" value="<?= $ov('member_code') ?>" maxlength="50"><?= $err('member_code') ?></div>
        <div class="<?= $cls('citizenship_no') ?>"><label for="m3"><?= tr('Citizenship number', 'नागरिकता नम्बर') ?> <?= $req ?></label><input id="m3" name="citizenship_no" value="<?= $ov('citizenship_no') ?>" maxlength="50" required><?= $err('citizenship_no') ?></div>
        <div class="<?= $cls('phone') ?>"><label for="m4"><?= tr('Mobile number', 'मोबाइल नम्बर') ?> <?= $req ?></label><input id="m4" type="tel" name="phone" value="<?= $ov('phone') ?>" maxlength="30" required autocomplete="tel"><?= $err('phone') ?></div>
        <div class="<?= $cls('email') ?>"><label for="m5"><?= tr('Email', 'इमेल') ?></label><input id="m5" type="email" name="email" value="<?= $ov('email') ?>" maxlength="120" autocomplete="email"><?= $err('email') ?></div>
        <div class="<?= $cls('address') ?>"><label for="m6"><?= tr('Address', 'ठेगाना') ?> <?= $req ?></label><input id="m6" name="address" value="<?= $ov('address') ?>" maxlength="255" required><?= $err('address') ?></div>
        <div class="<?= $cls('occupation') ?>"><label for="m7"><?= tr('Occupation / business', 'पेशा / व्यवसाय') ?></label><input id="m7" name="occupation" value="<?= $ov('occupation') ?>" maxlength="100"></div>
        <div class="<?= $cls('monthly_income') ?>"><label for="m8"><?= tr('Monthly income (NPR)', 'मासिक आम्दानी (रु.)') ?></label><input id="m8" name="monthly_income" value="<?= $ov('monthly_income') ?>" inputmode="numeric"></div>
      </div></fieldset>

      <fieldset><legend><span>3</span><?= tr('Guarantor', 'जमानी') ?></legend><div class="fGrid">
        <div class="<?= $cls('guarantor_name') ?>"><label for="g1"><?= tr('Guarantor name', 'जमानीको नाम') ?></label><input id="g1" name="guarantor_name" value="<?= $ov('guarantor_name') ?>" maxlength="150"></div>
        <div class="<?= $cls('guarantor_phone') ?>"><label for="g2"><?= tr('Guarantor phone', 'जमानीको फोन') ?></label><input id="g2" type="tel" name="guarantor_phone" value="<?= $ov('guarantor_phone') ?>" maxlength="30"></div>
      </div></fieldset>

      <fieldset><legend><span>4</span><?= tr('Documents', 'कागजातहरू') ?></legend><div class="fGrid">
        <div class="<?= $cls('citizenship_doc_path') ?>"><label for="h1"><?= tr('Citizenship copy', 'नागरिकताको प्रतिलिपि') ?> <?= $req ?></label><input id="h1" type="file" name="citizenship_doc_path" accept="image/*,application/pdf" required><?= $err('citizenship_doc_path') ?: '<small class="fHint">' . $fileHint . '</small>' ?></div>
        <div class="<?= $cls('supporting_doc_path') ?>"><label for="h2"><?= tr('Income or collateral document', 'आम्दानी वा धितोको कागजात') ?></label><input id="h2" type="file" name="supporting_doc_path" accept="image/*,application/pdf"><?= $err('supporting_doc_path') ?: '<small class="fHint">' . $fileHint . '</small>' ?></div>
      </div><p class="fNote">🔒 <?= tr('Your documents are stored privately and are seen only by authorised cooperative staff.', 'तपाईंका कागजात गोप्य रूपमा राखिन्छन् र अधिकृत कर्मचारीले मात्र हेर्न सक्छन्।') ?></p></fieldset>
    <?php endif; ?>

      <div class="<?= $cls('agree', 'declare') ?>"><label><input type="checkbox" name="agree" value="1" <?= !empty($old['agree']) ? 'checked' : '' ?> required> <?= $formType === 'loan'
          ? tr('I confirm that the information above is true. I understand that submitting this form is an application only and the loan is subject to approval.', 'माथिको विवरण सत्य हो भनी पुष्टि गर्छु। यो फारम आवेदन मात्र हो र कर्जा स्वीकृतिमा भर पर्छ भन्ने मलाई थाहा छ।')
          : tr('I confirm that the information above is true and I agree to follow the bylaws and rules of the cooperative.', 'माथिको विवरण सत्य हो र म सहकारीको विधान तथा नियम पालना गर्न सहमत छु भनी पुष्टि गर्छु।') ?>
          <?= tr('I have read the', 'मैले') ?> <a href="?page=privacy-policy" target="_blank"><?= tr('Privacy Policy', 'गोपनीयता नीति') ?></a> <?= tr('and', 'र') ?> <a href="?page=terms-and-conditions" target="_blank"><?= tr('Terms &amp; Conditions', 'नियम तथा सर्तहरू') ?></a><?= tr('.', ' पढेको छु।') ?></label><?= $err('agree') ?></div>
      <div class="fActions"><button class="btn" type="submit" id="applySubmit"><?= tr('Submit application', 'आवेदन बुझाउनुहोस्') ?></button><span class="muted"><?= tr('Takes about 5 minutes', 'करिब ५ मिनेट लाग्छ') ?></span></div>
    </form>
  </section>

<?php elseif ($view === 'apply-success'): page_hero($pageTitle); ?>
  <section class="wrap section"><div class="card successCard">
    <span class="successIcon">✓</span>
    <h2><?= tr('Thank you! Your application has been received.', 'धन्यवाद! तपाईंको आवेदन प्राप्त भयो।') ?></h2>
    <?php if (!empty($done['ref'])): ?><p><?= tr('Your reference number is', 'तपाईंको सन्दर्भ नम्बर') ?></p><p class="refNo"><?= e($done['ref']) ?></p><p class="muted"><?= tr('Please keep this number. Mention it when you call or visit the office.', 'यो नम्बर सुरक्षित राख्नुहोस्। फोन गर्दा वा कार्यालय आउँदा यो नम्बर बताउनुहोस्।') ?></p><?php endif; ?>
    <p><?= ($done['type'] ?? '') === 'job'
        ? tr('Thank you for your interest in working with us. We will review your application and contact shortlisted candidates by phone for the next step.', 'हामीसँग काम गर्न रुचि देखाउनुभएकोमा धन्यवाद। आवेदन जाँच गरी छनोट भएका उम्मेदवारलाई अर्को चरणका लागि फोनबाट सम्पर्क गरिनेछ।')
        : (($done['type'] ?? '') === 'loan'
        ? tr('Our loan officer will review your application and contact you. Please keep your original documents ready.', 'हाम्रा कर्जा अधिकृतले तपाईंको आवेदन जाँच गरी सम्पर्क गर्नुहुनेछ। सक्कल कागजात तयार राख्नुहोस्।')
        : tr('Our staff will review your application and contact you to complete the membership at the office (share payment and signature).', 'हाम्रा कर्मचारीले आवेदन जाँच गरी कार्यालयमा सदस्यता पूरा गर्न (सेयर रकम र हस्ताक्षर) सम्पर्क गर्नुहुनेछ।')) ?></p>
    <?php if ($phone): ?><p><?= tr('Questions? Call', 'प्रश्न भए फोन गर्नुहोस्') ?> <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></p><?php endif; ?>
    <a class="btn" href="index.php"><?= tr('Back to homepage', 'गृहपृष्ठमा फर्कनुहोस्') ?></a>
  </div></section>

<?php elseif ($view === 'cms'):
    [$cmsHtml, $cmsHeads] = render_page_text(lf($cmsPage, 'content'));
    page_hero($pageTitle, lf($cmsPage, 'summary')); ?>
  <section class="wrap section legalWrap">
    <?php if (count($cmsHeads) > 2): ?>
    <aside class="legalToc"><b><?= tr('On this page', 'यस पृष्ठमा') ?></b><ol><?php foreach ($cmsHeads as [$hid, $htxt]): ?><li><a href="#<?= e($hid) ?>"><?= e(preg_replace('/^[\d०-९]+\.\s*/u', '', $htxt)) ?></a></li><?php endforeach; ?></ol></aside>
    <?php endif; ?>
    <article class="legalBody prose">
      <p class="legalMeta"><?= tr('Last updated', 'अन्तिम अद्यावधिक') ?>: <?= e(format_date($cmsPage['updated_at'])) ?> · <a href="#" onclick="window.print();return false"><?= tr('Print', 'प्रिन्ट') ?></a></p>
      <?= $cmsHtml ?>
    </article>
  </section>

<?php else: page_hero($pageTitle); ?>
  <section class="wrap section"><p><a class="btn" href="index.php"><?= tr('Go to homepage', 'गृहपृष्ठमा जानुहोस्') ?></a></p></section>
<?php endif; ?>
</main>
<footer><div class="wrap foot">
  <div class="footAbout"><?php if ($logo): ?><img src="<?= e(media_url($logo)) ?>" alt="" class="footLogo"><?php endif; ?><h3><?= e($orgName) ?></h3><p><?= e(setting('footer_about')) ?></p><?php if ($social): ?><div class="followUs"><h4><?= tr('Follow us', 'हामीलाई फलो गर्नुहोस्') ?></h4><div class="social"><?php foreach ($social as $k => [$label, $u, $svg]): ?><a class="soc-<?= e($k) ?>" href="<?= e($u) ?>" target="_blank" rel="noopener" aria-label="<?= e($label) ?>" title="<?= e($label) ?>"><?= $svg ?></a><?php endforeach; ?></div></div><?php endif; ?></div>
  <div><h4><?= tr('Quick Links', 'उपयोगी लिङ्क') ?></h4><a href="?page=messages"><?= tr('Leadership Messages', 'नेतृत्वको सन्देश') ?></a><?php foreach (['about', 'savings', 'loans', 'notices', 'downloads'] as $k): ?><a href="?page=<?= $k ?>"><?= e($nav[$k]) ?></a><?php endforeach; ?><a href="?page=faq"><?= tr('FAQs', 'बारम्बार सोधिने प्रश्न') ?></a><a href="?page=note-counter"><?= tr('Cash Note Counter', 'नोट गन्ती') ?></a><a href="?page=date-converter"><?= tr('Date Converter', 'मिति रूपान्तरण') ?></a><a href="member-login.php"><?= tr('Member Login', 'सदस्य लगइन') ?></a></div>
  <div><h4><?= tr('Contact', 'सम्पर्क') ?></h4><p><?= e(setting('address')) ?></p><?php if ($phone): ?><p><?= e($phone) ?></p><?php endif; ?><?php if ($email): ?><p><?= e($email) ?></p><?php endif; ?><p><?= e(setting('office_hours')) ?></p><?php $ioName = setting('info_officer_name'); $ioPhone = setting('info_officer_phone', 'en'); $ioPhoto = setting('info_officer_photo', 'en'); if ($ioName !== '' || $ioPhone !== ''): ?><div class="infoOfficer"><h4>सूचना अधिकारी | Information Officer</h4><div class="ioCard"><?php if ($ioPhoto): ?><img src="<?= e(media_url($ioPhoto)) ?>" alt="<?= e($ioName) ?>" class="ioPhoto"><?php else: ?><span class="ioPhoto ioPlaceholder" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" fill="currentColor"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7z"/></svg></span><?php endif; ?><div><?php if ($ioName !== ''): ?><b><?= e($ioName) ?></b><?php endif; ?><?php if ($ioPhone !== ''): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $ioPhone)) ?>"><?= e($ioPhone) ?></a><?php endif; ?></div></div></div><?php endif; ?></div>
</div>
<?php if (setting('visitor_counter_show', 'en') !== '0'):
    $vs = visitor_stats($pdo);
    $vn = fn(int $n) => $L === 'ne' ? to_nepali_number(number_format($n)) : number_format($n); ?>
<div class="visitorBar" aria-label="<?= tr('Website visitors', 'वेबसाइट आगन्तुक') ?>">
  <span class="vbItem"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20c0-2.8-1.8-4.9-4.5-5.7"/></svg><?= tr('Total visitors', 'कुल आगन्तुक') ?>: <b><?= $vn($vs['total']) ?></b></span>
  <span class="vbItem"><?= tr('Today', 'आज') ?>: <b><?= $vn($vs['today']) ?></b></span>
  <span class="vbItem"><?= tr('This month', 'यो महिना') ?>: <b><?= $vn($vs['month']) ?></b></span>
</div>
<?php endif; ?>
<div class="copyright">© <?= date('Y') ?> <?= e($orgName) ?> <?= tr('All rights reserved.', 'सर्वाधिकार सुरक्षित।') ?> <a href="https://bbgc.com.np"><?= tr('Designed by BBGC', 'Designed by BBGC') ?></a><?php $legal = footer_pages($pdo); if ($legal): ?><span class="legalLinks"><?php foreach ($legal as $lp): ?><a href="?page=<?= e($lp['slug']) ?>"><?= e(lf($lp, 'title')) ?></a><?php endforeach; ?></span><?php endif; ?></div></footer>
<?php if ($popup): ?><div class="modal" id="popup"><div class="modalBox"><button class="close" id="closePopup" aria-label="Close">×</button><?php if ($popup['featured_image']): ?><img src="<?= e(media_url($popup['featured_image'])) ?>" alt="" class="popImg"><?php endif; ?><small><?= e(format_date($popup['published_date'])) ?></small><h2><?= e(lf($popup, 'title')) ?></h2><p><?= nl2br(e(mb_strimwidth(lf($popup, 'content'), 0, 600, '…'))) ?></p><a class="btn" href="?page=notice&id=<?= (int)$popup['id'] ?>"><?= tr('Read more', 'थप पढ्नुहोस्') ?></a></div></div><?php endif; ?>
<?php $chat = chat_channels(); if ($chat): $chatSide = setting('chat_position', 'en') === 'left' ? 'left' : 'right'; ?>
<div class="chatWidget <?= $chatSide ?>" id="chatWidget">
  <div class="chatPanel" id="chatPanel" role="dialog" aria-modal="false" aria-labelledby="chatTitle" hidden>
    <div class="chatHead">
      <?php if ($logo): ?><img src="<?= e(media_url($logo)) ?>" alt="" class="chatLogo"><?php endif; ?>
      <div><b id="chatTitle"><?= e(setting('chat_title')) ?></b><small><span class="chatDot"></span><?= e(setting('office_hours') ?: tr('We reply as soon as possible', 'हामी सकेसम्म छिटो जवाफ दिन्छौँ')) ?></small></div>
      <button type="button" class="chatClose" id="chatClose" aria-label="<?= tr('Close chat', 'च्याट बन्द गर्नुहोस्') ?>">×</button>
    </div>
    <div class="chatBody">
      <p class="chatBubble"><?= nl2br(e(setting('chat_greeting'))) ?></p>
      <?php foreach ($chat as $ck => [$cLabel, $cSub, $cUrl, $cPath]): ?>
      <a class="chatOpt chat-<?= e($ck) ?>" href="<?= e($cUrl) ?>"<?= $ck === 'call' ? '' : ' target="_blank" rel="noopener"' ?> data-channel="<?= e($ck) ?>">
        <span class="chatIcon"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><?= $cPath ?></svg></span>
        <span class="chatTxt"><b><?= e($cLabel) ?></b><small><?= e($cSub) ?></small></span>
        <span class="chatGo" aria-hidden="true">›</span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <button type="button" class="chatToggle" id="chatToggle" aria-expanded="false" aria-controls="chatPanel" aria-label="<?= tr('Chat with us', 'हामीसँग कुरा गर्नुहोस्') ?>">
    <svg class="icoChat" viewBox="0 0 24 24" width="28" height="28" fill="currentColor" aria-hidden="true"><path d="M12 2C6.5 2 2 6 2 11c0 2.6 1.2 4.9 3.2 6.5L4.5 22l4.3-2.4c1 .3 2.1.4 3.2.4 5.5 0 10-4 10-9s-4.5-9-10-9zM7 12.3a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6zm5 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6zm5 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6z"/></svg>
    <svg class="icoClose" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    <span class="chatLabel"><?= tr('Chat with us', 'कुरा गर्नुहोस्') ?></span>
  </button>
</div>
<?php endif; ?>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
