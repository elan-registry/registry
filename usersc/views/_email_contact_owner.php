<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lotus Elan Registry - Owner to Owner Message</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #029acf; color: white; padding: 20px; text-align: center; }
        .logo { width: 48px; height: 48px; margin-bottom: 10px; }
        .content { padding: 30px; }
        .owner-box { background-color: #f8f9fa; border: 2px solid #029acf; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .owner-details { background-color: #fff; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; margin: 15px 0; }
        .detail-row { display: flex; margin-bottom: 10px; }
        .detail-label { font-weight: bold; min-width: 100px; color: #469408; }
        .detail-value { flex: 1; }
        .message-content { background-color: #f8f9fa; border-left: 4px solid #469408; padding: 15px; margin: 20px 0; white-space: pre-wrap; }
        .footer { background-color: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #dee2e6; color: #6b7280; font-size: 14px; }
        .lotus-green { color: #469408; }
        .lotus-blue { color: #029acf; }
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
            <p>Owner to Owner Message</p>
        </div>
        
        <div class="content">
            <p>Hello <strong><?= htmlspecialchars($to) ?></strong>,</p>
            
            <p>Another Elan owner has sent you a message through the <strong class="lotus-green">Lotus Elan Registry</strong>.</p>
            
            <div class="owner-box">
                <h3 class="lotus-blue">From Owner</h3>
                <div class="owner-details">
                    <div class="detail-row">
                        <div class="detail-label">Name:</div>
                        <div class="detail-value"><?= htmlspecialchars($from) ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Email:</div>
                        <div class="detail-value"><?= htmlspecialchars($fromEmail) ?></div>
                    </div>
                </div>
            </div>
            
            <div class="owner-box">
                <h3 class="lotus-blue">Message</h3>
                <div class="message-content"><?= htmlspecialchars($message) ?></div>
            </div>
            
            <p><strong>To respond to <?= htmlspecialchars($from) ?>, please reply using their email address shown above.</strong></p>
        </div>
        
        <div class="footer">
            <p><strong>The Lotus Elan Registry</strong></p>
            <p><a href="<?php echo getBaseUrl(); ?>"><?php echo getBaseUrl(); ?></a></p>
            <p>Preserving the legacy of Colin Chapman's masterpiece since 2003</p>
            <hr style="border: none; border-top: 1px solid #dee2e6; margin: 15px 0;">
            <p><small>This message was sent through the registry contact system.</small></p>
        </div>
    </div>
</body>
</html>