<?php

declare(strict_types=1);

require_once '../../users/init.php';
$nav_section = 'guides';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;

if (!securePage($php_self)) {
    Redirect::to($us_url_root . '403.php');
}

$title       = 'Car Transfer FAQ';
$icon        = 'fa-question-circle';
$description = 'Frequently asked questions about transfers';

$htmlContent = <<<'GUIDEHTML'
<!-- Table of contents -->
<div class="card mb-4">
    <div class="card-header"><h4 class="mb-0">Contents</h4></div>
    <div class="card-body py-2">
        <div class="row">
            <div class="col-sm-6">
                <ol class="mb-0">
                    <li><a href="#general-questions">General Questions</a></li>
                    <li><a href="#before-requesting-a-transfer">Before Requesting a Transfer</a></li>
                    <li><a href="#the-transfer-process">The Transfer Process</a></li>
                    <li><a href="#current-owner-responses">Current Owner Responses</a></li>
                    <li><a href="#after-transfer">After Transfer</a></li>
                </ol>
            </div>
            <div class="col-sm-6">
                <ol start="6" class="mb-0">
                    <li><a href="#technical-issues">Technical Issues</a></li>
                    <li><a href="#special-situations">Special Situations</a></li>
                    <li><a href="#support-and-troubleshooting">Support and Troubleshooting</a></li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- 1. General Questions -->
<h2 id="general-questions">General Questions</h2>
<div class="accordion mb-4" id="faq-general">

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-g1">
                What is a car ownership transfer request?
            </button>
        </h3>
        <div id="faq-g1" class="accordion-collapse collapse">
            <div class="accordion-body">
                A transfer request allows you to request ownership of a car that&rsquo;s currently registered to another member in the Lotus Elan Registry. This updates the registry to reflect current ownership after a sale or ownership change.
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-g2">
                When should I use the transfer request system?
            </button>
        </h3>
        <div id="faq-g2" class="accordion-collapse collapse">
            <div class="accordion-body">
                <p class="mb-2">Use transfer requests when:</p>
                <ul class="mb-0">
                    <li>You&rsquo;ve purchased a car from another registry member</li>
                    <li>You need to claim ownership of a car with duplicate chassis entries</li>
                    <li>A car ownership has changed but the registry still shows a previous owner</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-g3">
                Do I need an account to request a transfer?
            </button>
        </h3>
        <div id="faq-g3" class="accordion-collapse collapse">
            <div class="accordion-body">
                Yes, you must have an active Lotus Elan Registry account and be logged in to submit transfer requests.
            </div>
        </div>
    </div>

</div>

<!-- 2. Before Requesting a Transfer -->
<h2 id="before-requesting-a-transfer">Before Requesting a Transfer</h2>
<div class="accordion mb-4" id="faq-before">

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-b1">
                How do I find the car I want to transfer?
            </button>
        </h3>
        <div id="faq-b1" class="accordion-collapse collapse">
            <div class="accordion-body">
                Use the registry search function to locate the car by chassis number, year, or model. Verify the details match your vehicle before requesting transfer.
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-b2">
                What information do I need before making a request?
            </button>
        </h3>
        <div id="faq-b2" class="accordion-collapse collapse">
            <div class="accordion-body">
                <p class="mb-2">You need:</p>
                <ul class="mb-0">
                    <li>Accurate chassis number</li>
                    <li>Car year and model details</li>
                    <li>Reason for the transfer (purchase, etc.)</li>
                    <li>Any supporting documentation</li>
                    <li>Current contact information</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-b3">
                Can I request transfer of any car in the registry?
            </button>
        </h3>
        <div id="faq-b3" class="accordion-collapse collapse">
            <div class="accordion-body">
                No, you should only request transfers for cars you legitimately own or have purchased. False transfer requests may result in account restrictions.
            </div>
        </div>
    </div>

</div>

<!-- 3. The Transfer Process -->
<h2 id="the-transfer-process">The Transfer Process</h2>
<div class="accordion mb-4" id="faq-process">

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-p1">
                Where do I find the transfer request button?
            </button>
        </h3>
        <div id="faq-p1" class="accordion-collapse collapse">
            <div class="accordion-body">
                The &ldquo;Request Ownership Transfer&rdquo; button is located on the car&rsquo;s details page when you&rsquo;re viewing a car that belongs to someone else.
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-p2">
                What happens after I submit a transfer request?
            </button>
        </h3>
        <div id="faq-p2" class="accordion-collapse collapse">
            <div class="accordion-body">
                <p class="mb-2">Three things happen immediately:</p>
                <ol class="mb-0">
                    <li>You receive a confirmation email about the initiation of the transfer process</li>
                    <li>The current owner receives a notification email</li>
                    <li>Registry administrators are notified for oversight</li>
                </ol>
                <p class="mt-2 mb-0">After a few days you receive an email about the result of the transfer process.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-p3">
                How long does the transfer process take?
            </button>
        </h3>
        <div id="faq-p3" class="accordion-collapse collapse">
            <div class="accordion-body">
                Response time varies (typically 1&ndash;7 days) depending on how quickly the current owner responds. Once approved, transfers complete immediately.
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-p4">
                Can the current owner see my personal information?
            </button>
        </h3>
        <div id="faq-p4" class="accordion-collapse collapse">
            <div class="accordion-body">
                The current owner sees your first name, email address, general location, and any comments you included. Full personal details are not shared.
            </div>
        </div>
    </div>

</div>

<!-- 4. Current Owner Responses -->
<h2 id="current-owner-responses">Current Owner Responses</h2>
<div class="accordion mb-4" id="faq-owner">

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-o1">
                What can the current owner do with my request?
            </button>
        </h3>
        <div id="faq-o1" class="accordion-collapse collapse">
            <div class="accordion-body">
                <p class="mb-2">They have three options:</p>
                <ul class="mb-0">
                    <li><strong>Approve</strong> &mdash; Transfer completes immediately</li>
                    <li><strong>Deny</strong> &mdash; Transfer is declined (reason may be provided)</li>
                    <li><strong>No response</strong> &mdash; Request stays pending</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-o2">
                What if the current owner doesn&rsquo;t respond?
            </button>
        </h3>
        <div id="faq-o2" class="accordion-collapse collapse">
            <div class="accordion-body">
                After 7&ndash;14 days with no response, contact registry administrators. They can help facilitate communication or make decisions based on documentation.
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-o3">
                Can a denied transfer be appealed?
            </button>
        </h3>
        <div id="faq-o3" class="accordion-collapse collapse">
            <div class="accordion-body">
                Yes, contact registry administrators if you believe a transfer was denied in error. Provide documentation supporting your ownership claim.
            </div>
        </div>
    </div>

</div>

<!-- 5. After Transfer -->
<h2 id="after-transfer">After Transfer</h2>
<div class="accordion mb-4" id="faq-after">

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-a1">
                What happens when a transfer is approved?
            </button>
        </h3>
        <div id="faq-a1" class="accordion-collapse collapse">
            <div class="accordion-body">
                <ul class="mb-0">
                    <li>Ownership immediately transfers to you</li>
                    <li>The car appears in your registry account</li>
                    <li>You can edit car information and upload photos</li>
                    <li>The previous owner loses access to the car record</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-a2">
                Will I be notified when the transfer is complete?
            </button>
        </h3>
        <div id="faq-a2" class="accordion-collapse collapse">
            <div class="accordion-body">
                Yes, you&rsquo;ll receive a confirmation email when the transfer is approved and completed.
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-a3">
                Can transfers be reversed?
            </button>
        </h3>
        <div id="faq-a3" class="accordion-collapse collapse">
            <div class="accordion-body">
                Transfers are generally permanent, but contact administrators if there was an error. Provide documentation to support any reversal request.
            </div>
        </div>
    </div>

</div>

<!-- 6. Technical Issues -->
<h2 id="technical-issues">Technical Issues</h2>
<div class="accordion mb-4" id="faq-tech">

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-t1">
                I don&rsquo;t see a transfer request button. Why?
            </button>
        </h3>
        <div id="faq-t1" class="accordion-collapse collapse">
            <div class="accordion-body">
                <p class="mb-2">Possible reasons:</p>
                <ul class="mb-0">
                    <li>You&rsquo;re not logged in</li>
                    <li>You already own this car</li>
                    <li>Technical issue &mdash; contact administrators</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-t2">
                I submitted a request but didn&rsquo;t get a confirmation email. What should I do?
            </button>
        </h3>
        <div id="faq-t2" class="accordion-collapse collapse">
            <div class="accordion-body">
                Check that the email address in your account is accurate, then check your spam/junk folder. If not found, contact administrators to verify your request was received.
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-t3">
                Can I modify or cancel a pending transfer request?
            </button>
        </h3>
        <div id="faq-t3" class="accordion-collapse collapse">
            <div class="accordion-body">
                Contact administrators to modify or cancel pending requests. Include the chassis number and reason for the change.
            </div>
        </div>
    </div>

</div>

<!-- 7. Special Situations -->
<h2 id="special-situations">Special Situations</h2>
<div class="accordion mb-4" id="faq-special">

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-s1">
                What about cars with duplicate chassis numbers?
            </button>
        </h3>
        <div id="faq-s1" class="accordion-collapse collapse">
            <div class="accordion-body">
                Transfer requests help resolve duplicate entries. Provide clear documentation of ownership, and administrators will help determine the correct owner.
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-s2">
                I bought a car but the seller isn&rsquo;t responding. What can I do?
            </button>
        </h3>
        <div id="faq-s2" class="accordion-collapse collapse">
            <div class="accordion-body">
                <p class="mb-2">Contact administrators with:</p>
                <ul class="mb-0">
                    <li>Purchase documentation (bill of sale, title, etc.)</li>
                    <li>Details of your attempts to contact the seller</li>
                    <li>Any other relevant information</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-s3">
                Can I request transfer for a car that&rsquo;s not currently owned by anyone?
            </button>
        </h3>
        <div id="faq-s3" class="accordion-collapse collapse">
            <div class="accordion-body">
                If a car appears unowned, you typically wouldn&rsquo;t need a transfer request. Contact administrators for guidance on claiming unowned vehicles.
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-s4">
                What if I receive a transfer request for my car?
            </button>
        </h3>
        <div id="faq-s4" class="accordion-collapse collapse">
            <div class="accordion-body">
                Review the request carefully. If legitimate (you sold the car), approve it. If not legitimate or you need more information, you can deny it or contact the requester directly.
            </div>
        </div>
    </div>

</div>

<!-- 8. Support and Troubleshooting -->
<h2 id="support-and-troubleshooting">Support and Troubleshooting</h2>
<div class="accordion mb-4" id="faq-support">

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-su1">
                Who do I contact if I have problems with transfers?
            </button>
        </h3>
        <div id="faq-su1" class="accordion-collapse collapse">
            <div class="accordion-body">
                Contact registry administrators for any transfer-related issues, disputes, or questions.
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-su2">
                How should I format my support request?
            </button>
        </h3>
        <div id="faq-su2" class="accordion-collapse collapse">
            <div class="accordion-body">
                <p class="mb-2">Include:</p>
                <ul class="mb-0">
                    <li>Subject: &ldquo;Transfer Request &mdash; [Chassis Number]&rdquo;</li>
                    <li>Your account information</li>
                    <li>Chassis number of the car</li>
                    <li>Clear description of the issue</li>
                    <li>Any relevant documentation</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-su3">
                What if there&rsquo;s a dispute over car ownership?
            </button>
        </h3>
        <div id="faq-su3" class="accordion-collapse collapse">
            <div class="accordion-body">
                Administrators will review documentation from both parties and make a determination. Provide as much supporting evidence as possible.
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h3 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-su4">
                Can I request multiple transfers at the same time?
            </button>
        </h3>
        <div id="faq-su4" class="accordion-collapse collapse">
            <div class="accordion-body">
                Yes, each car requires a separate transfer request. You can submit multiple requests for different vehicles simultaneously.
            </div>
        </div>
    </div>

</div>


<p class="text-muted"><strong>Still have questions?</strong> Contact the Lotus Elan Registry administrators for personalized assistance with your transfer request situation.</p>

GUIDEHTML;

?>
<div class="page-wrapper">
    <div class='container'>
        <?= DocumentPortalTemplate::renderBreadcrumb('guides', $us_url_root, $title, 'fa-question-circle') ?>

        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => $title,
            'titleIcon'   => $icon,
            'description' => $description,
        ]) ?>

        <div class='row mt-4'>
            <div class='col-12'>
                <div class='card registry-card'>
                    <div class='card-body'>
                        <div class="document-content">
                            <?= $htmlContent ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class='row mt-4 mb-4'>
            <div class='col-12 text-center'>
                <a href='index.php' class='btn btn-outline-primary me-2'><i class='fas fa-arrow-left'></i> Back to Owner Guides</a>
                <a href='<?= $us_url_root ?>' class='btn btn-outline-secondary'><i class='fas fa-home'></i> Registry Home</a>
            </div>
        </div>
    </div>
</div>
<link rel="stylesheet" href="<?= $us_url_root ?>docs/assets/document-content.css">

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
