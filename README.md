# Aadhunik Jana Cooperative Website — PHP/MySQL Edition

This package converts the supplied React/TypeScript website into a PHP 8.2+ / MySQL 8 / HTML / CSS / vanilla JavaScript application for XAMPP and cPanel.

## Included
- Responsive bilingual public website (Nepali default; English switch)
- Database-driven savings products, loan products, notices, downloads and gallery
- Contact form saved to MySQL with CSRF protection and validation
- Admin login and dashboard
- Admin notice publishing, popup notification toggle, inquiry viewing
- PDO prepared statements, output escaping, secure session settings, audit logging
- Original supplied image assets and database schema

## Local installation (XAMPP)
1. Copy this folder into `C:\\xampp\\htdocs\\aadhunik`.
2. Start Apache and MySQL from XAMPP.
3. Open phpMyAdmin and import `database/database.sql`. It creates the `aadhunik_cooperative` database and tables.
4. Edit `config/database.php` only if your MySQL host, database, username or password differ. Defaults are XAMPP: `127.0.0.1`, database `aadhunik_cooperative`, user `root`, blank password.
5. Visit `http://localhost/aadhunik/`.

## Admin panel
- URL: `http://localhost/aadhunik/admin/` (on this machine Apache runs on port 121: `http://localhost:121/aadhunik-main/admin/`)
- Sign in with your admin username (or email) and password.

Everything on the public website is managed from the admin panel:

| Section | What it controls |
|---|---|
| Homepage / About Page | Hero text, highlights, statistics, call to action, vision, mission, values, chairperson message and photos |
| Hero Sliders | Rotating homepage slides (image, title, button) |
| Notices | Notices, attachments, homepage popup |
| News | News articles with images |
| Messages | Leadership messages (Chairperson, Vice Chairperson, General Manager, Secretary…): homepage tabs, About page cards and the Messages page `index.php?page=messages`. Table `leadership_messages` (`database/messages.sql`, created automatically) |
| Testimonials | Member quotes with photo, name, description and optional star rating, shown as a sliding row of cards on the homepage (hidden until at least one is active). Table `testimonials` (`database/testimonials.sql`, created automatically) |
| Board & Team | Board, committees and management shown on the About page |
| FAQs | Frequently asked questions (FAQ page `index.php?page=faq`; ones marked "Show on homepage" also appear on the homepage). Existing databases get the table automatically; it is also in `database/faqs.sql` |
| Gallery / Gallery Albums | Photos grouped into albums |
| Downloads | Forms, reports and documents (download counts are tracked) |
| Savings / Loan Products | Rates, features, requirements, order and visibility |
| Members / Member Requests | Member records and member-portal passwords; review requests |
| Membership / Loan Applications | Applications sent from the website forms (`index.php?page=membership-form`, `index.php?page=loan-form`). Review documents, set a decision, print an A4 copy, and turn an approved membership application into a member record. Uploaded documents are kept in `storage/` (blocked from the web) and open only for signed-in staff. Tables: `database/applications.sql` (created automatically) |
| Contact Inquiries | Messages from the website contact form |
| Admin Users / Roles & Permissions | Who can sign in and which sections each role can open |
| Site Settings | Organisation name, logo, address, phones, emails, map, social links |
| Tools → Cash Sheet | Daily Receipt & Payment sheet (BS + AD date): receipts and bank withdrawals vs. payments and bank deposits, opening/closing cash denomination counters (notes + bank balances, cash in hand, advances, pending), OK / short / excess status, today's totals, bank balances after the day, finalize & lock, A4 print with Prepared / Checked / Approved By. Each day's opening comes from the previous closing. Heads, banks and deposit modes: Site Settings → Cash sheet. Permission: "Daily cash sheet". Tables `cash_sheets`, `cash_sheet_lines` (`database/cash_sheets.sql`) |
| Tools → Date Converter (BS ⇄ AD) | Converts Bikram Sambat and English dates both ways (2000–2090 BS). Also on the website at `index.php?page=date-converter`. The cash sheet fills the BS/AD date automatically. Calendar table: `includes/nepali_date.php` and `assets/js/nepali-date.js` (same data) |
| Tools → Note Denomination | Cash note counter for staff: pick a member (search by code, name or phone) or type a walk-in name, choose the transaction (deposit, withdrawal, share, loan installment), count each Nepali note, then **Save & print** a numbered slip (CS-YYYY-00001) with office and member copies on one A4 page. Recent slips can be reprinted. Table `cash_slips` (`database/cash_slips.sql`, created automatically). Public version at `index.php?page=note-counter` |
| Site Settings → Chat buttons | Floating chat button on every page: WhatsApp (with a ready-made first message), Facebook Messenger (m.me link), Viber and a Call button; greeting text, position, on/off |
| Appearance (Theme) | Website look: 7 colour presets (Default = original design) or your own 3 colours; page width, header, top bar, banner height, corners, cards, buttons, footer, font (Mukta, Poppins, Hind, Noto Sans). Live preview for signed-in admins; Save / Reset to default. Colours are CSS variables with the original colour as fallback (`includes/theme.php`) |
| Visitor Statistics | Anonymous unique visitor counter: today, yesterday, this BS month, total, page views, 30-day chart; Dashboard card; optional footer counter (Site Settings → Visitor counter, with a starting number). Bots and admin pages are not counted; no IP addresses are stored (random ID in cookie `aj_vid`). Tables `visitors`, `visitor_days` (`database/visitors.sql`) |
| Policy Pages | Privacy Policy (`index.php?page=privacy-policy`), Terms & Conditions (`index.php?page=terms-and-conditions`) and any other page you add; linked in the footer and from the forms. The starting text is a template — have it reviewed by your legal adviser. Table `pages` (`database/pages.sql`, created automatically) |
| Activity Log | Who changed what and when |

Uploaded files are stored in `uploads/` (scripts cannot run from that folder). Content modules are defined in `admin/inc/modules.php`; website settings fields in `includes/settings_schema.php`.

**Immediately after first login**, change the default password from *My profile*. Also delete or rename any setup utilities if you add them. The SQL file contains the same temporary password hash for the seeded manager account; do not use the manager account in production.

## cPanel deployment
1. Create a MySQL database and database user in cPanel; assign the user ALL PRIVILEGES to the database.
2. Upload/extract this folder into `public_html` (or a subfolder).
3. Import `database/database.sql` using phpMyAdmin. The SQL creates `aadhunik_cooperative`; if your cPanel database prefix prevents that name, edit the SQL database name and the matching `DB_NAME` setting before importing.
4. Edit `config/database.php` or create a `.env` file in the project root with:
   ```
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=cpanelprefix_aadhunik
   DB_USER=cpanelprefix_dbuser
   DB_PASS=your-strong-database-password
   APP_ENV=production
   ```
5. Ensure the `.env` file is outside public access (the included `.htaccess` denies access); keep `config/` protected. Enable SSL in cPanel.
6. Visit your domain and test the contact form, language switch and admin login.

## Important notes
- The source project contained React components and a pre-existing PHP/database scaffold. This conversion implements the core public website and working database-backed admin workflows in PHP. The original React-specific interactive member portal, KYC application workflow, calendar widget, loan eligibility tool and cash-note counter have not been recreated as fully functional PHP modules in this first conversion.
- Financial rates and product descriptions are sample content from the supplied project. Verify all rates, contact details, legal statements and public notices with the cooperative before publishing.
- For production, change the temporary admin password, use HTTPS, use a dedicated least-privilege DB user, keep backups, and test restore procedures.
#   a a d h u n i k - c o o p  
 #   a a d h u n i k - c o o p  
 #   a a d h u n i k - c o o p  
 