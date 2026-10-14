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
<p><strong>Effective: October 14, 2026</strong></p>
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
<p>We have not started sending car-verification request emails yet. When we do, they will also go through Brevo. How we record the delivery status of all registry email is described in the next section.</p>
<p>Every verification email includes a one-click link to stop receiving them, no login required. If you stopped them, you can turn verification emails back on yourself from Account Settings at any time. If your email bounced because the address itself no longer works, verification emails resume automatically as soon as you confirm a different, working email address. There's no separate switch to flip for that case.</p>
<p>What we don't do: sell your data, show your last name or email publicly, track your exact location, or keep detailed browsing histories.</p>
<h2><a id="email-delivery-records" href="#email-delivery-records" class="" aria-hidden="true" title="Permalink"></a>Email delivery records</h2>
<p>The registry records the delivery status of all registry email sent to your address. The status is delivered, bounced, spam, or unsubscribed. Brevo processes the email and tells us what happened to it.</p>
<p>How long we keep these records:</p>
<ul>
<li>Bounce, block, and complaint records (hard bounce, blocked, invalid, spam, unsubscribe) are kept for 24 months.</li>
<li>Soft-bounce, delivery, send, and other records are kept for 90 days.</li>
<li>For each car, we keep the record of the latest verification email. It keeps its date but has no email address. We keep it as long as the car exists. After the car is deleted, the next nightly clean-up removes the record once it is 90 days old. We also remove it when your account is deleted.</li>
</ul>
<p>Your profile also holds your current bounce, block, and opt-out state, with the last flagged address and its date. A bounce or a block stays until you confirm a different email address or an administrator clears it. The opt-out stays until you resume verification emails in Account Settings or an administrator clears it. This state is removed when your account is deleted.</p>
<p>Your profile keeps a copy of the last email address we recorded for you. We use it to check whether your address changed. This copy is removed when your account is deleted.</p>
<p>When one of these flags changes, the site log records your user ID and the name of the flag. It does not record your email address.</p>
<p>Registry administrators and editors can see this state. Other members and the public cannot. If your email is blocked, you can clear the block by confirming a new email address.</p>
<p>If you delete your account, a spam or unsubscribe block at Brevo stays. This stops mail to an address that asked not to receive it.</p>
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
<p><em>Last updated: October 14, 2026. This update explains that we record the delivery status of all registry email to your address, how long we keep those records, and what we keep when your account is deleted.</em></p>

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
