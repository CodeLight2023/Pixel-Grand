<?php
// send_contact.php — Pixel Grand Tech Hub
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =====================================================================
// SPAM PROTECTION — SERVER-SIDE
// Never rely only on client JS; a determined spammer can bypass it.
// These server checks are the true gatekeepers.
// =====================================================================

// --- Blocklist: spam keywords found in message/name ---
$SPAM_KEYWORDS = [
    'seo', 'search engine optimization', 'search engine ranking',
    'google ranking', 'page rank', 'backlink', 'link building',
    'keyword ranking', 'organic traffic', 'digital marketing agency',
    'web design agency', 'website redesign', 'website development service',
    'improve your website', 'optimize your website', 'website audit',
    'i noticed your website', 'your website needs', 'i checked your site',
    'your site is not optimized', 'rank higher on google',
    'increase your online presence', 'boost your traffic',
    'affordable seo', 'cheap seo', 'guaranteed seo',
    'we specialize in seo', 'our seo services', 'our marketing services',
    'we can help you grow', 'we can help your business',
    'we offer web design', 'we offer seo',
    'hello, i came across', 'i visited your website',
    'i found your website', 'i was browsing your site',
    'dear website owner', 'dear business owner',
    'dear sir/madam', 'to whom it may concern',
    'crypto', 'bitcoin', 'investment opportunity',
    'make money online', 'passive income', 'earn from home',
    'click here', 'free trial', 'limited offer',
    'unsubscribe', 'opt out', 'guest post', 'sponsored post',
    'buy traffic', 'ppc campaign', 'ad campaign', 'google ads service',
    'social media marketing', 'content marketing service'
];

// reCAPTCHA secret key — matches the site key used in the HTML
define('RECAPTCHA_SECRET', '6LftOecsAAAAAE4agr7SvHDreQY1PJOtpZd7ZBpG'); // <-- Replace with your actual secret key

// Helper: sanitize input
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Helper: redirect with message
function redirect_with_message($status, $message) {
    header('Location: contact.html?status=' . $status . '&message=' . urlencode($message));
    exit;
}

// Helper: check if text contains any spam keyword
function contains_spam($text, $keywords) {
    $lower = strtolower($text);
    foreach ($keywords as $kw) {
        if (strpos($lower, strtolower($kw)) !== false) {
            return true;
        }
    }
    return false;
}

// ---- Only handle POST ----
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header('Location: contact.html');
    exit;
}

// =====================================================================
// LAYER 1: Honeypot check
// If the hidden "website" field is filled, it's a bot — silently drop.
// =====================================================================
$honeypot = $_POST['website'] ?? '';
if (!empty(trim($honeypot))) {
    // Fake success — don't educate the bot
    redirect_with_message('success', 'Your message has been sent successfully! We will get back to you shortly.');
}

// =====================================================================
// LAYER 2: Timing check
// Legitimate users take at least 3 seconds to fill a form.
// Bots fill and submit instantly.
// =====================================================================
$form_loaded_at = isset($_POST['form_loaded_at']) ? (int)$_POST['form_loaded_at'] : 0;
if ($form_loaded_at > 0) {
    $elapsed_ms = (int)(microtime(true) * 1000) - $form_loaded_at;
    if ($elapsed_ms < 3000) {
        redirect_with_message('error', 'Form submitted too quickly. Please try again.');
    }
}

// =====================================================================
// LAYER 3: Google reCAPTCHA v2 — Server-side verification
// The client sends g-recaptcha-response; we verify it with Google's API.
// =====================================================================
$recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
if (empty($recaptcha_response)) {
    redirect_with_message('error', 'Please complete the reCAPTCHA verification and try again.');
}

$recaptcha_url  = 'https://www.google.com/recaptcha/api/siteverify';
$recaptcha_data = [
    'secret'   => RECAPTCHA_SECRET,
    'response' => $recaptcha_response,
    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
];

$recaptcha_context = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($recaptcha_data),
        'timeout' => 10
    ]
]);

$recaptcha_result = @file_get_contents($recaptcha_url, false, $recaptcha_context);
if ($recaptcha_result === false) {
    // If the API is unreachable, log and allow through (don't punish real users for network issues)
    error_log('[reCAPTCHA] Could not reach Google verification API.');
} else {
    $recaptcha_json = json_decode($recaptcha_result, true);
    if (!isset($recaptcha_json['success']) || $recaptcha_json['success'] !== true) {
        error_log('[reCAPTCHA FAILED] Response: ' . print_r($recaptcha_json, true));
        redirect_with_message('error', 'reCAPTCHA verification failed. Please try again.');
    }
}

// =====================================================================
// LAYER 4: Rate limiting by IP (max 3 submissions per hour)
// =====================================================================

/**
 * Get the real visitor IP address.
 */
function get_real_ip() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip_list = explode(',', $_SERVER[$header]);
            $ip = trim($ip_list[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                if ($ip === '::1' || $ip === '127.0.0.1') {
                    return 'localhost (::1 — local test)';
                }
                return $ip;
            }
        }
    }
    return 'unknown';
}

$ip         = get_real_ip();
$rate_file  = sys_get_temp_dir() . '/pgth_rate_' . md5($ip) . '.json';
$now        = time();
$window     = 3600; // 1 hour
$max_sends  = 3;

$rate_data  = [];
if (file_exists($rate_file)) {
    $raw = file_get_contents($rate_file);
    $rate_data = json_decode($raw, true) ?: [];
}

// Remove entries older than the window
$rate_data = array_filter($rate_data, fn($ts) => ($now - $ts) < $window);
$rate_data = array_values($rate_data);

if (count($rate_data) >= $max_sends) {
    redirect_with_message('error', 'Too many submissions. Please wait a while before trying again, or contact us directly by email.');
}

// Record this submission
$rate_data[] = $now;
file_put_contents($rate_file, json_encode($rate_data));

// =====================================================================
// LAYER 5: Read and validate form fields
// =====================================================================
$fullname     = sanitize_input($_POST['fullName']      ?? '');
$email        = sanitize_input($_POST['email']         ?? '');
$mobile       = sanitize_input($_POST['mobileNumber']  ?? '');
$service      = sanitize_input($_POST['services']      ?? '');
$message_text = sanitize_input($_POST['message']       ?? '');
$service_display = html_entity_decode($service, ENT_QUOTES, 'UTF-8');

$errors = [];
if (empty($fullname))                                   $errors[] = "Full name is required";
if (empty($email))                                      $errors[] = "Email address is required";
if (!filter_var($email, FILTER_VALIDATE_EMAIL))         $errors[] = "Invalid email format";
if (empty($service))                                    $errors[] = "Please select a service";
if (empty($message_text))                               $errors[] = "Message is required";
if (strlen($message_text) < 20)                         $errors[] = "Message is too short — please provide more details";

if (!empty($errors)) {
    redirect_with_message('error', implode('. ', $errors) . '.');
}

// =====================================================================
// LAYER 6: Keyword spam filter on name + message
// =====================================================================
$combined_text = $fullname . ' ' . $message_text;
if (contains_spam($combined_text, $SPAM_KEYWORDS)) {
    error_log("[SPAM BLOCKED] IP:{$ip} | Name:{$fullname} | Email:{$email} | Msg:" . substr($message_text, 0, 100));
    redirect_with_message('error', 'Your message could not be sent. If this is a genuine inquiry, please contact us directly by email.');
}

// =====================================================================
// LAYER 7: Block known disposable/throwaway email domains
// =====================================================================
$disposable_domains = [
    'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwam.com',
    'yopmail.com', 'sharklasers.com', 'guerrillamailblock.com', 'grr.la',
    'trashmail.com', 'trashmail.net', 'dispostable.com', 'maildrop.cc',
    'spamgourmet.com', 'spamgourmet.net', 'spam4.me', 'fakeinbox.com',
    'mailnull.com', 'spamcorpse.com', 'deadaddress.com', 'spamfree24.org',
    'tempinbox.com', 'spambox.us', 'filzmail.com',
    'discard.email', 'mailexpire.com', 'mintemail.com', 'spamgob.com'
];
$email_domain = strtolower(substr(strrchr($email, "@"), 1));
if (in_array($email_domain, $disposable_domains)) {
    redirect_with_message('error', 'Please use a valid email address. Temporary email addresses are not accepted.');
}

// =====================================================================
// ALL CHECKS PASSED — Send the emails
// =====================================================================

$current_date = date('F j, Y, g:i a');
$current_year = date('Y');
$base_url     = 'https://pixelgrandtech.com';

// Brand colors
$brand_gold   = '#CE9928';
$brand_dark   = '#5D481A';

// ========== EMAIL TO OWNER ==========
$mail_to_owner = new PHPMailer(true);

try {
    $mail_to_owner->SMTPDebug  = 0;
    $mail_to_owner->isSMTP();
    $mail_to_owner->Host       = 'mail.pixelgrandtech.com';
    $mail_to_owner->SMTPAuth   = true;
    $mail_to_owner->Username   = 'noreply@pixelgrandtech.com';
    $mail_to_owner->Password   = 'Pixel-Grand123';
    $mail_to_owner->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail_to_owner->Port       = 587;
    // $mail_to_owner->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL on port 465
    // $mail_to_owner->Port       = 465;
    $mail_to_owner->CharSet    = 'UTF-8';
    $mail_to_owner->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true
        ]
    ];

    $mail_to_owner->setFrom('noreply@pixelgrandtech.com', 'Pixel Grand Tech Hub');
    $mail_to_owner->addAddress('info@pixelgrandtech.com', 'Pixel Grand Tech Hub Admin');
    $mail_to_owner->addReplyTo($email, $fullname);
    $mail_to_owner->Subject = "New Contact Form Submission | {$fullname}";

    $owner_email_template = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>New Contact Form Submission</title>
        <style>
            body { font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f5f7fb; margin: 0; padding: 40px 20px; }
            .container { max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            .header { background: linear-gradient(135deg, {$brand_gold} 0%, {$brand_dark} 100%); padding: 32px 24px; text-align: center; }
            .header h1 { color: #ffffff; font-size: 22px; font-weight: 700; margin: 0 0 8px 0; letter-spacing: 0.3px; }
            .header p { color: rgba(255,255,255,0.85); font-size: 14px; margin: 0; }
            .content { padding: 32px; }
            .greeting h2 { font-size: 17px; font-weight: 600; color: #222222; margin: 0 0 6px 0; }
            .greeting p { font-size: 14px; color: #777777; margin: 0 0 24px 0; }
            .info-card { background: #faf8f3; border-radius: 14px; padding: 20px; margin-bottom: 24px; border: 1px solid #f0e8d0; }
            .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #f0e8d0; }
            .info-row:last-child { border-bottom: none; padding-bottom: 0; }
            .label { width: 130px; font-weight: 600; color: {$brand_gold}; font-size: 12px; flex-shrink: 0; text-transform: uppercase; letter-spacing: 0.4px; }
            .value { flex: 1; color: #333333; font-size: 14px; }
            .message-box { background: #faf8f3; border-radius: 14px; padding: 20px; margin-bottom: 24px; border: 1px solid #f0e8d0; }
            .message-box h4 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: {$brand_gold}; margin: 0 0 12px 0; }
            .message-box p { font-size: 14px; line-height: 1.7; color: #444444; margin: 0; }
            .reply-btn { text-align: center; margin-bottom: 24px; }
            .reply-btn a { display: inline-block; background: linear-gradient(135deg, {$brand_gold}, {$brand_dark}); color: white; padding: 12px 30px; border-radius: 40px; text-decoration: none; font-weight: 600; font-size: 14px; }
            .footer { background: #fafbfc; padding: 24px; text-align: center; border-top: 1px solid #eef2f6; }
            .footer p { font-size: 11px; color: #8a99aa; margin: 0 0 4px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>📩 New Contact Form Submission</h1>
                <p>You have received a new inquiry — all spam checks passed ✓</p>
            </div>
            <div class='content'>
                <div class='greeting'>
                    <h2>Hello Admin,</h2>
                    <p>A potential client has reached out through the Pixel Grand website contact form. This message passed all spam and reCAPTCHA filters.</p>
                </div>
                <div class='info-card'>
                    <div class='info-row'><div class='label'>Full Name</div><div class='value'>" . htmlspecialchars($fullname) . "</div></div>
                    <div class='info-row'><div class='label'>Email</div><div class='value'>" . htmlspecialchars($email) . "</div></div>
                    " . (!empty($mobile) ? "<div class='info-row'><div class='label'>Phone</div><div class='value'>" . htmlspecialchars($mobile) . "</div></div>" : "") . "
                    <div class='info-row'><div class='label'>Service</div><div class='value'>" . htmlspecialchars($service_display) . "</div></div>
                    <div class='info-row'><div class='label'>Submitted</div><div class='value'>" . $current_date . "</div></div>
                    <div class='info-row'><div class='label'>Sender IP</div><div class='value'>" . htmlspecialchars($ip) . "</div></div>
                </div>
                <div class='message-box'>
                    <h4>Customer Message</h4>
                    <p>" . nl2br(htmlspecialchars($message_text)) . "</p>
                </div>
                <div class='reply-btn'>
                    <a href='mailto:" . htmlspecialchars($email) . "'>Reply to " . htmlspecialchars($fullname) . " &rarr;</a>
                </div>
            </div>
            <div class='footer'>
                <p>&copy; " . $current_year . " Pixel Grand Tech Hub. All rights reserved.</p>
                <p>info@pixelgrandtech.com</p>
            </div>
        </div>
    </body>
    </html>";

    $mail_to_owner->isHTML(true);
    $mail_to_owner->Body    = $owner_email_template;
    $mail_to_owner->AltBody = "New Contact Form Submission (All spam checks passed)\n\nName: {$fullname}\nEmail: {$email}\nPhone: {$mobile}\nService: {$service_display}\nDate: {$current_date}\nIP: {$ip}\n\nMessage:\n{$message_text}";
    $owner_sent = $mail_to_owner->send();

    // ========== EMAIL TO USER ==========
    $mail_to_user = new PHPMailer(true);

    $mail_to_user->SMTPDebug  = 0;
    $mail_to_user->isSMTP();
    $mail_to_user->Host       = 'mail.pixelgrandtech.com';
    $mail_to_user->SMTPAuth   = true;
    $mail_to_user->Username   = 'noreply@pixelgrandtech.com';
    $mail_to_user->Password   = 'Pixel-Grand123';
    // $mail_to_user->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL on port 465
    // $mail_to_user->Port       = 465;
    $mail_to_user->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail_to_user->Port       = 587;
    $mail_to_user->CharSet    = 'UTF-8';
    $mail_to_user->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true
        ]
    ];

    $mail_to_user->setFrom('noreply@pixelgrandtech.com', 'Pixel Grand Tech Hub');
    $mail_to_user->addAddress($email, $fullname);
    $mail_to_user->addCustomHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');
    $mail_to_user->addCustomHeader('Auto-Submitted', 'auto-generated');
    $mail_to_user->Subject = "Thank You for Contacting Pixel Grand Tech Hub";

    $user_email_template = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Thank You</title>
        <style>
            * { box-sizing: border-box; }
            body {
                font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                background-color: #ffffff;
                margin: 0;
                padding: 0;
            }
            .wrapper { width: 100%; background-color: #ffffff; padding: 40px 20px; }
            .container { max-width: 560px; margin: 0 auto; background: #ffffff; }

            /* Gold gradient top bar */
            .top-bar {
                height: 6px;
                background: linear-gradient(90deg, {$brand_gold} 0%, {$brand_dark} 100%);
                border-radius: 4px 4px 0 0;
                margin-bottom: 32px;
            }

            /* Checkmark / logo */
            .logo-wrap { text-align: center; margin-bottom: 20px; }
            .logo-wrap img { width: 60px; height: 60px; display: inline-block; }

            /* Heading */
            h1 { font-size: 28px; font-weight: 700; color: #222222; text-align: center; margin: 0 0 10px 0; }

            /* Sub-message */
            .sub-msg { text-align: center; margin-bottom: 36px; }
            .sub-msg p { font-size: 15px; color: #666666; line-height: 1.7; margin: 0; }

            /* Summary card */
            .summary-card {
                background: #faf8f3;
                border: 1px solid #f0e8d0;
                border-radius: 14px;
                padding: 20px 24px;
                margin-bottom: 32px;
            }
            .summary-card h4 {
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.6px;
                color: {$brand_gold};
                margin: 0 0 14px 0;
            }
            .summary-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0e8d0; font-size: 14px; }
            .summary-row:last-child { border-bottom: none; padding-bottom: 0; }
            .s-label { width: 100px; font-weight: 600; color: #555555; flex-shrink: 0; }
            .s-value { flex: 1; color: #333333; }

            /* Next Steps */
            .steps-section { margin-bottom: 36px; }
            .steps-section h4 { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #333333; text-align: center; margin: 0 0 18px 0; }
            .step-item { display: flex; align-items: flex-start; padding: 11px 0; border-bottom: 1px solid #f5f5f5; font-size: 14px; color: #555555; line-height: 1.5; }
            .step-item:last-child { border-bottom: none; }
            .step-num { font-weight: 700; color: {$brand_gold}; margin-right: 12px; min-width: 22px; }

            /* CTA */
            .cta-wrap { text-align: center; margin: 24px 0 40px 0; }
            .cta-wrap a { display: inline-block; background: linear-gradient(135deg, {$brand_gold} 0%, {$brand_dark} 100%); color: #ffffff !important; padding: 13px 36px; border-radius: 40px; text-decoration: none; font-weight: 600; font-size: 15px; }

            /* Footer */
            .footer-wrap { border-top: 1px solid #eeeeee; padding-top: 28px; }
            .footer-copy { font-size: 12px; color: #999999; text-align: center; margin: 0 0 4px 0; }
            .footer-addr { font-size: 12px; color: #999999; text-align: center; margin: 0 0 18px 0; }

            /* Contact info */
            .contact-table { width: 100%; border-collapse: collapse; margin: 0 0 18px 0; }
            .contact-table td { text-align: center; vertical-align: middle; padding: 4px 10px; font-size: 13px; color: #555555; white-space: nowrap; }

            /* Social icons */
            .social-table { width: 100%; border-collapse: collapse; margin: 0 0 18px 0; }
            .social-table td { text-align: center; vertical-align: middle; padding: 0 8px; }
            .social-table a { display: inline-block; width: 36px; height: 36px; background: #f5f5f5; border-radius: 50%; text-decoration: none; padding: 10px; line-height: 0; font-size: 0; }
            .social-table img { width: 16px; height: 16px; display: block; margin: 0; padding: 0; border: 0; }

            /* No-reply note */
            .noreply-note { text-align: center; font-size: 11px; color: #bbbbbb; margin: 0; }

            @media only screen and (max-width: 600px) {
                .wrapper { padding: 24px 16px; }
                h1 { font-size: 22px; }
                .contact-table td { display: block; padding: 6px 0; white-space: normal; }
                .contact-table { width: auto; margin: 0 auto 18px auto; }
            }
        </style>
    </head>
    <body>
        <div class='wrapper'>
            <div class='container'>

                <!-- Gold top bar -->
                <div class='top-bar'></div>

                <!-- Heading -->
                <h1>Thank You, " . htmlspecialchars($fullname) . "! 🎉</h1>

                <!-- Sub message -->
                <div class='sub-msg'>
                    <p>Your message has been received.<br>Our team will review your inquiry and get back to you very soon.</p>
                </div>

                <!-- Submission summary -->
                <div class='summary-card'>
                    <h4>Your Submission Summary</h4>
                    <div class='summary-row'><span class='s-label'>Service</span><span class='s-value'>" . htmlspecialchars($service_display) . "</span></div>
                    <div class='summary-row'><span class='s-label'>Submitted</span><span class='s-value'>" . $current_date . "</span></div>
                    <div class='summary-row'><span class='s-label'>Reference</span><span class='s-value'>PGT-" . strtoupper(substr(md5($email . $current_date), 0, 8)) . "</span></div>
                </div>

                <!-- What's Next -->
                <div class='steps-section'>
                    <h4>What Happens Next?</h4>
                    <div class='step-item'><span class='step-num'>1.</span><span>Our team will carefully review your project inquiry and goals.</span></div>
                    <div class='step-item'><span class='step-num'>2.</span><span>You'll receive a personalised response with tailored recommendations.</span></div>
                    <div class='step-item'><span class='step-num'>3.</span><span>We'll schedule a free consultation call at your convenience.</span></div>
                </div>

                <!-- CTA Button -->
                <div class='cta-wrap'>
                    <a href='{$base_url}'>Explore Our Work &rarr;</a>
                </div>

                <!-- FOOTER -->
                <div class='footer-wrap'>
                    <p class='footer-copy'>&copy; {$current_year} Pixel Grand Tech Hub. All rights reserved.</p>
                    <p class='footer-addr'>1234 Innovation Drive, Downtown Business District, Tech City</p>

                    <table class='contact-table' role='presentation' cellpadding='0' cellspacing='0'>
                        <tr>
                            <td>✉️ info@pixelgrandtech.com</td>
                        </tr>
                    </table>

                    <p class='noreply-note'>
                        ℹ️ This is an automated confirmation. Please do not reply to this email.
                    </p>
                </div>
                <!-- END FOOTER -->

            </div>
        </div>
    </body>
    </html>";

    $mail_to_user->isHTML(true);
    $mail_to_user->Body    = $user_email_template;
    $mail_to_user->AltBody = "Thank You, {$fullname}!\n\nYour message has been received. Our team will review your inquiry and get back to you very soon.\n\nYOUR SUBMISSION:\nService: {$service_display}\nSubmitted: {$current_date}\n\nWHAT'S NEXT?\n1. Our team will carefully review your project inquiry and goals.\n2. You'll receive a personalised response with tailored recommendations.\n3. We'll schedule a free consultation call at your convenience.\n\nExplore Our Work: {$base_url}\n\n© {$current_year} Pixel Grand Tech Hub. All rights reserved.\nEmail: info@pixelgrandtech.com\n\nThis is an automated confirmation. Please do not reply to this email.";

    $user_sent = $mail_to_user->send();

    if ($owner_sent && $user_sent) {
        redirect_with_message('success', 'Your message has been sent successfully! We will get back to you within 24 hours.');
    } else {
        $error_msg = "";
        if (!$owner_sent) $error_msg .= "Owner email failed. ";
        if (!$user_sent)  $error_msg .= "User email failed. ";
        error_log("Email sending issue: " . $error_msg);
        redirect_with_message('error', 'Your message was received but we could not send a confirmation email. Our team will contact you shortly.');
    }

} catch (Exception $e) {
    error_log("PHPMailer Error: " . $e->getMessage());
    redirect_with_message('error', 'Unable to send your message at this time. Please try again later or contact us directly via email.');
}
?>