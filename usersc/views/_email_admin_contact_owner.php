<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lotus Elan Registry - Administrator Message</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #029acf; color: white; padding: 20px; text-align: center; }
        .logo { width: 48px; height: 48px; margin-bottom: 10px; }
        .content { padding: 30px; }
        .admin-box { background-color: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .admin-details { background-color: #fff; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; margin: 15px 0; }
        .detail-row { display: flex; margin-bottom: 10px; }
        .detail-label { font-weight: bold; min-width: 100px; color: #856404; }
        .detail-value { flex: 1; }
        .message-content { background-color: #f8f9fa; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; white-space: pre-wrap; }
        .data-quality-context { background-color: #e7f3ff; border: 1px solid #029acf; border-radius: 5px; padding: 15px; margin: 15px 0; }
        .footer { background-color: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #dee2e6; color: #6b7280; font-size: 14px; }
        .lotus-green { color: #469408; }
        .lotus-blue { color: #029acf; }
        .admin-gold { color: #856404; }
        @media only screen and (max-width: 600px) {
            .content { padding: 20px; }
            .detail-row { flex-direction: column; }
            .detail-label { min-width: auto; margin-bottom: 5px; }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <img src="<?php echo getBaseUrl(); ?>/usersc/templates/ElanRegistry/assets/images/logo-72x72.png" alt="Lotus Logo" class="logo">
            <h1>Lotus Elan Registry</h1>
            <p>Administrator Message</p>
        </div>

        <div class="content">
            <p>Hello <strong><?= htmlspecialchars($to) ?></strong>,</p>

            <p>A Registry Administrator has sent you a message regarding your car registration in the <strong class="lotus-green">Lotus Elan Registry</strong>.</p>

            <div class="admin-box">
                <h3 class="admin-gold">From Registry Administrator</h3>
                <div class="admin-details">
                    <div class="detail-row">
                        <div class="detail-label">Name:</div>
                        <div class="detail-value"><?= htmlspecialchars($from) ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Role:</div>
                        <div class="detail-value">Registry Administrator</div>
                    </div>
                </div>
            </div>

            <?php if (isset($carContext) && $carContext): ?>
            <div class="data-quality-context">
                <h4 class="lotus-blue">Related to Your Car</h4>
                <div class="detail-row">
                    <div class="detail-label">Car ID:</div>
                    <div class="detail-value"><?= htmlspecialchars($carContext['id']) ?></div>
                </div>
                <?php if (isset($carContext['year']) && isset($carContext['model'])): ?>
                <div class="detail-row">
                    <div class="detail-label">Vehicle:</div>
                    <div class="detail-value"><?= htmlspecialchars($carContext['year'] . ' ' . $carContext['model']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (isset($carContext['chassis'])): ?>
                <div class="detail-row">
                    <div class="detail-label">Chassis:</div>
                    <div class="detail-value"><?= htmlspecialchars($carContext['chassis']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (isset($qualityIssue)): ?>
                <div class="detail-row">
                    <div class="detail-label">Issue:</div>
                    <div class="detail-value"><?= htmlspecialchars($qualityIssue) ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="admin-box">
                <h3 class="admin-gold">Administrator Message</h3>
                <div class="message-content"><?= htmlspecialchars($message) ?></div>
            </div>

            <p><strong>You can reply directly to this email to respond to the Registry Administrator.</strong></p>

            <p>If this message is regarding data quality in your car registration, please consider updating your car details at: <a href="<?php echo getBaseUrl(); ?>"><?php echo getBaseUrl(); ?></a></p>
        </div>

        <div class="footer">
            <p><strong>The Lotus Elan Registry</strong></p>
            <p><a href="<?php echo getBaseUrl(); ?>"><?php echo getBaseUrl(); ?></a></p>
            <p>Preserving the legacy of Colin Chapman's masterpiece since 2003</p>
            <hr style="border: none; border-top: 1px solid #dee2e6; margin: 15px 0;">
            <p><small>This message was sent by a Registry Administrator through the data quality management system.</small></p>
        </div>
    </div>
</body>
</html>