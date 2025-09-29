<?php
/**
 * Car Transfer Help Redirect
 *
 * This file redirects to the unified documentation system.
 * The car transfer help content is now maintained in markdown format
 * for consistency with the rest of the documentation.
 *
 * @package ElanRegistry
 * @version 2.9.0
 */

// Redirect to the unified documentation system
$redirectUrl = '../docs/view.php?doc=CAR_TRANSFER_USER_GUIDE.md';

// Use JavaScript redirect to maintain browser history
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecting to Car Transfer Guide - Lotus Elan Registry</title>
    <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
    <div style="text-align: center; margin-top: 50px; font-family: Arial, sans-serif;">
        <h2>Redirecting to Car Transfer Guide...</h2>
        <p>You are being redirected to the updated documentation.</p>
        <p>If you are not redirected automatically, <a href="<?= htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') ?>">click here</a>.</p>
    </div>

    <script>
        // Immediate redirect
        window.location.href = '<?= htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') ?>';
    </script>
</body>
</html>
                                    <div class="card-body text-center">
                                        <div class="text-success mb-2">
                                            <i class="fas fa-mouse-pointer fa-2x"></i>
                                        </div>
                                        <h6 class="card-title">2. Click Transfer</h6>
                                        <p class="card-text small">Click "Request Ownership Transfer" button on the car's page</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="card border-warning">
                                    <div class="card-body text-center">
                                        <div class="text-warning mb-2">
                                            <i class="fas fa-edit fa-2x"></i>
                                        </div>
                                        <h6 class="card-title">3. Fill Form</h6>
                                        <p class="card-text small">Complete the transfer request form with required details</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="card border-info">
                                    <div class="card-body text-center">
                                        <div class="text-info mb-2">
                                            <i class="fas fa-paper-plane fa-2x"></i>
                                        </div>
                                        <h6 class="card-title">4. Submit</h6>
                                        <p class="card-text small">Submit your request and wait for current owner's response</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- What to Expect -->
                        <h4><i class="fas fa-clock text-primary"></i> What Happens Next</h4>
                        <div class="timeline">
                            <div class="mb-3">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <span class="badge badge-success rounded-circle p-2">1</span>
                                    </div>
                                    <div class="flex-grow-1 ml-3">
                                        <h6 class="mb-1">Immediate Confirmation</h6>
                                        <p class="text-muted small mb-0">You receive confirmation email with request details</p>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <span class="badge badge-primary rounded-circle p-2">2</span>
                                    </div>
                                    <div class="flex-grow-1 ml-3">
                                        <h6 class="mb-1">Owner Notification</h6>
                                        <p class="text-muted small mb-0">Current owner receives email with your transfer request</p>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <span class="badge badge-warning rounded-circle p-2">3</span>
                                    </div>
                                    <div class="flex-grow-1 ml-3">
                                        <h6 class="mb-1">Decision Wait</h6>
                                        <p class="text-muted small mb-0">Current owner has 7-14 days to approve or deny (typical response time: 1-7 days)</p>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <span class="badge badge-info rounded-circle p-2">4</span>
                                    </div>
                                    <div class="flex-grow-1 ml-3">
                                        <h6 class="mb-1">Final Result</h6>
                                        <p class="text-muted small mb-0">You receive email notification of approval/denial and transfer completes if approved</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Important Information -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card border-info mb-3">
                                    <div class="card-header bg-info text-white">
                                        <h6 class="mb-0"><i class="fas fa-info-circle"></i> Important Notes</h6>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-unstyled mb-0">
                                            <li class="small mb-1"><i class="fas fa-check text-success"></i> You must be logged in to request transfers</li>
                                            <li class="small mb-1"><i class="fas fa-check text-success"></i> Only request transfers for cars you legitimately own</li>
                                            <li class="small mb-1"><i class="fas fa-check text-success"></i> Include clear explanation in comments</li>
                                            <li class="small mb-1"><i class="fas fa-check text-success"></i> Be prepared to provide proof if requested</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-warning mb-3">
                                    <div class="card-header bg-warning text-dark">
                                        <h6 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Common Issues</h6>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-unstyled mb-0">
                                            <li class="small mb-1"><i class="fas fa-times text-danger"></i> Transfer button missing → Contact administrators</li>
                                            <li class="small mb-1"><i class="fas fa-times text-danger"></i> No response from owner → Wait 14 days, then contact admins</li>
                                            <li class="small mb-1"><i class="fas fa-times text-danger"></i> Transfer denied → Contact admins with documentation</li>
                                            <li class="small mb-1"><i class="fas fa-times text-danger"></i> Duplicate chassis → Provide clear ownership proof</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="alert alert-primary">
                            <h6 class="alert-heading"><i class="fas fa-headset"></i> Need Help?</h6>
                            <p class="mb-2">Contact registry administrators if you have questions or issues with transfer requests.</p>
                            <p class="mb-0">
                                <strong>Email Subject:</strong> "Transfer Request - [Chassis Number]"<br>
                                <strong>Include:</strong> Your account info, chassis number, and detailed description of the issue
                            </p>
                        </div>

                        <!-- Quick Links -->
                        <div class="text-center">
                            <a href="#" class="btn btn-primary mr-2"><i class="fas fa-file-alt"></i> Full User Guide</a>
                            <a href="#" class="btn btn-outline-primary"><i class="fas fa-question"></i> FAQ</a>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>