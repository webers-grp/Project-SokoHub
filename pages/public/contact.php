<?php
/**
 * SokoHub Contact Us Page (Upgraded from Main/Contact us.html)
 * Stores support inquiries into contact_messages with client + server validation.
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();

$errors = [];
$old = [
    'name'    => current_user()['full_name'] ?? '',
    'email'   => current_user()['email'] ?? '',
    'subject' => '',
    'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please refresh the page and try again.';
    } else {
        $old['name'] = trim((string) ($_POST['name'] ?? ''));
        $old['email'] = trim((string) ($_POST['email'] ?? ''));
        $old['subject'] = trim((string) ($_POST['subject'] ?? ''));
        $old['message'] = trim((string) ($_POST['message'] ?? ''));

        if (mb_strlen($old['name']) < 2) {
            $errors[] = 'Please enter your full name (at least 2 characters).';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (mb_strlen($old['subject']) < 3) {
            $errors[] = 'Please provide a brief subject (at least 3 characters).';
        }
        if (mb_strlen($old['message']) < 10) {
            $errors[] = 'Message must be at least 10 characters long.';
        }

        if (empty($errors)) {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare(
                'INSERT INTO contact_messages (name, email, subject, message) VALUES (:name, :email, :subject, :message)'
            );
            $stmt->execute([
                ':name'    => $old['name'],
                ':email'   => $old['email'],
                ':subject' => $old['subject'],
                ':message' => $old['message'],
            ]);

            set_flash('success', 'Thank you! Your inquiry has been saved and our SokoHub support team will respond shortly.');
            redirect('pages/public/contact.php');
        }
    }
}

$pageTitle = 'SokoHub | Contact Support';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="split-grid" style="align-items: start;">
        <div class="card">
            <h1 style="color: var(--green-dark);">Contact SokoHub Support</h1>
            <p class="muted" style="margin-bottom: 1.25rem;">We are here to help! Send a question about orders, seller onboarding, or delivery partnerships.</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="<?= e(url('pages/public/contact.php')) ?>" method="POST" data-validate="true">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" class="form-control" value="<?= e($old['name']) ?>" required data-minlength="2" placeholder="Enter your full name">
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= e($old['email']) ?>" required placeholder="you@example.com">
                </div>

                <div class="form-group">
                    <label for="subject">Subject</label>
                    <input type="text" id="subject" name="subject" class="form-control" value="<?= e($old['subject']) ?>" required data-minlength="3" placeholder="Regarding my order, seller inquiry, etc.">
                </div>

                <div class="form-group">
                    <label for="message">Your Message</label>
                    <textarea id="message" name="message" class="form-control" rows="5" required data-minlength="10" placeholder="Type your detailed message here..."><?= e($old['message']) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <?= icon('send') ?>
                    <span>Send Message</span>
                </button>
            </form>
        </div>

        <div>
            <div class="card">
                <h2 style="color: var(--amber-primary); margin-bottom: 0.75rem;">Direct Contact Desk</h2>
                <p style="margin-top: 0.5rem;"><?= icon('mail', 'lucide-xs', 'blue') ?> <strong>Email:</strong> support@sokohub.co.ke</p>
                <p style="margin-top: 0.4rem;"><?= icon('phone', 'lucide-xs', 'emerald') ?> <strong>Phone:</strong> +254 700 123 456</p>
                <p style="margin-top: 0.4rem;"><?= icon('map-pin', 'lucide-xs', 'rose') ?> <strong>Office &amp; Shop:</strong> 123 Market Street, Kutus, Kirinyaga County, Kenya</p>
                <p style="margin-top: 0.4rem;"><?= icon('clock', 'lucide-xs', 'amber') ?> <strong>Support Hours:</strong> Mon - Sat, 8:00 AM - 6:00 PM EAT</p>
            </div>

            <!-- Google Map of SokoHub Central Hub & Shop Location -->
            <div class="card" style="padding: 0; overflow: hidden;">
                <div style="padding: 0.75rem 1rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 0.4rem;">
                    <?= icon('map-pin', 'lucide-xs', 'rose') ?>
                    <span>SokoHub Main Office &amp; Central Dispatch Hub</span>
                </div>
                <div style="height: 240px; position: relative;">
                    <iframe src="<?= e(google_map_embed_url('123 Market Street, Kutus, Kirinyaga County, Kenya')) ?>" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="SokoHub Kutus Office Location"></iframe>
                </div>
                <div style="padding: 0.5rem 1rem; background: #fff; text-align: right;">
                    <a href="<?= e(google_map_search_url('123 Market Street, Kutus, Kirinyaga County, Kenya')) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.8rem;">
                        <?= icon('external-link', 'lucide-xs') ?>
                        <span>View on Google Maps</span>
                    </a>
                </div>
            </div>

            <div class="card">
                <img src="<?= e(url('assets/img/delivery-rider.jpg')) ?>" alt="SokoHub Delivery Network" class="rounded-img" style="max-height: 200px; width: 100%; object-fit: cover; background: #fff;">
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
