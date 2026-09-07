<?php

declare(strict_types=1);

/**
 * privacy.php
 * Displays the privacy policy for the Lotus Elan Registry project.
 *
 * Privacy policy content is inlined as static HTML in the $policy heredoc below.
 * To update, edit the heredoc directly.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */

// A policy page, not a search destination in its own right (#1371).
$pageRobots = 'noindex, follow';

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

if (!securePage($php_self)) {
    die();
}

$policy = <<<'GUIDEHTML'
<h1><a id="privacy-policy" href="#privacy-policy" class="" aria-hidden="true" title="Permalink"></a>Privacy Policy</h1>
<p><strong>Effective: September 6, 2026</strong></p>
<p>The Elan Registry is a community database for Lotus Elan cars, run by enthusiasts for enthusiasts. This page explains what information we collect, what we do with it, and what rights you have over your data.</p>
<p>The short version: we collect what's needed to run the registry, we keep your personal details private, and you can change or delete your data at any time. If you decide to leave, we'll remove your personal information but may keep the car records themselves so the registry stays useful to the community.</p>
<h2><a id="what-we-collect" href="#what-we-collect" class="" aria-hidden="true" title="Permalink"></a>What we collect</h2>
<p>When you sign up, we ask for a username and password (the password is hashed, never stored as plain text), your first and last name, an email address, and a city, state, and country. We deliberately fuzz the location data so it isn't pinpoint accurate. Nobody needs to know exactly where your Elan lives. We also note when you registered.</p>
<p>For each car you add, we store the usual specs: model, year, chassis number, color, engine, plus dates of purchase or sale and any notes you want to include.</p>
<p>If you upload photos, we strip all EXIF metadata before saving them. That includes GPS coordinates, camera details, and timestamps.</p>
<p>Your city, state, and country are also what lets other members browse cars by region.</p>
<p>On the technical side, we log IP addresses for security, use a session cookie to keep you logged in, and keep system logs for debugging. Standard stuff.</p>
<p>There's also an internal messaging system. If another registered user wants to contact you, the first message goes through our site so your email address stays hidden. After that you can take the conversation offsite if you both want to.</p>
<p>Your name, email, location, and website are copied onto every car you own whenever your profile changes. Clearing a field clears it on every car too. Your website and first name appear on that car's page for other registered members to see; your last name, email, and exact location do not.</p>
<h2><a id="what-we-do-with-it" href="#what-we-do-with-it" class="" aria-hidden="true" title="Permalink"></a>What we do with it</h2>
<p>We use your information to run the registry, let users contact each other safely, power search and browsing, and keep the site secure.</p>
<p>We use Brevo, a third-party email delivery service based in the EU, to send transactional email: password resets, contact messages, and transfer notifications. Brevo receives the recipient's email address and the content of that one message; it does not receive your other registry data. We use Brevo because there's no way to reliably deliver email without a processor like it.</p>
<p>Some of what follows (car-verification request email itself, and delivery/bounce/spam tracking) is rolling out over the next few releases. This section describes the end-to-end design now so it doesn't need rewriting as each piece lands.</p>
<p>Once live, car-verification requests will also go through Brevo. We'll keep a record of whether verification and other transactional emails to your address were delivered, bounced, or marked as spam, for as long as we need it to keep the registry's contact information accurate and to detect addresses that are no longer working. If your email address bounces or is marked as spam, that status will be visible to registry administrators (not to other members or the public) so we can avoid repeatedly emailing an address that doesn't work.</p>
<p>Every verification email will include a one-click link to stop receiving them, no login required. If your email is marked as spam-suppressed, you'll be able to turn verification emails back on yourself from Account Settings at any time. If your email bounced because the address itself no longer works, verification emails will resume automatically as soon as you confirm a working email address; there's no separate switch to flip for that case.</p>
<p>What we don't do: sell your data, show your last name or email publicly, track your exact location, or keep detailed browsing histories.</p>
<h2><a id="security" href="#security" class="" aria-hidden="true" title="Permalink"></a>Security</h2>
<p>Passwords are hashed. Personal information is hidden from other users. We apply security updates and monitor the site for issues. Nothing is ever 100% secure on the internet, but we take reasonable steps to protect what you've trusted us with.</p>
<h2><a id="your-rights-under-gdpr" href="#your-rights-under-gdpr" class="" aria-hidden="true" title="Permalink"></a>Your rights under GDPR</h2>
<p>You can ask for a copy of the personal data we hold on you, correct anything that's wrong, request deletion, restrict how we process your data, object to certain kinds of processing, or get an exportable copy of everything. To do any of these, email <a href="mailto:registrar@elanregistry.org">registrar@elanregistry.org</a>.</p>
<h2><a id="what-happens-if-you-delete-your-account" href="#what-happens-if-you-delete-your-account" class="" aria-hidden="true" title="Permalink"></a>What happens if you delete your account</h2>
<p>We try to balance two things here: your right to be forgotten and the registry's value as a historical record.</p>
<p>When you ask us to delete your account, your personal data is removed permanently. That means your login, name, email, location, and contact preferences. The cars you registered, however, are reassigned to a system account called &quot;noowner&quot; and stay in the database as anonymized records. The technical specifications and history remain available to the community, but any link between you and them is gone.</p>
<p>We do it this way because the registry's usefulness depends on being reasonably complete, and a car's history doesn't really belong to any one owner over the long run. If you'd rather have your cars removed entirely along with your account, just say so when you make your deletion request and we'll handle it that way instead.</p>
<p>All deletion actions are logged for our own audit purposes.</p>
<h2><a id="contact" href="#contact" class="" aria-hidden="true" title="Permalink"></a>Contact</h2>
<p>Questions about this policy, or want to exercise any of the rights above? Email <a href="mailto:registrar@elanregistry.org">registrar@elanregistry.org</a>.</p>
<h2><a id="updates" href="#updates" class="" aria-hidden="true" title="Permalink"></a>Updates</h2>
<p>If we change this policy, we'll update the effective date at the top of the page and post the new version here. Anything significant, we'll announce to users.</p>
<hr />
<p><em>Last updated: September 6, 2026. This update adds disclosures about our email delivery provider, delivery-status tracking, and how your information is kept in sync across cars you own.</em></p>

GUIDEHTML;
?>
<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
            <div class="row">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header card-header-er-primary">
                            <h2 class="mb-0 card-header-er-primary-text">Privacy Policy</h2>
                        </div>
                        <div class="card-body">
                            <div class="content-wrapper">
                                <?php echo $policy; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php

require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
?>
